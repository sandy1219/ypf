<?php
namespace Ypf;

use \Cron;

class Swoole extends Ypf {

	const VERSION = '0.0.2';
	const LISTEN = '127.0.0.1:9002';

	private $serverConfig;
	private $workerConfig;

	public $server;
	private $cache;
	private $worker_pid;
	private $pidFile;

	public function setServerConfigIni($serverConfigIni) {
		if (!is_file($serverConfigIni)) {
			trigger_error('Server Config File Not Exist!', E_USER_ERROR);
		}
		$serverConfig = parse_ini_file($serverConfigIni, true);
		if (empty($serverConfig)) {
			trigger_error('Server Config Content Empty!', E_USER_ERROR);
		}
		$this->serverConfig = $serverConfig;
		$this->pidFile = isset($this->serverConfig['server']['pid_file']) ?
		$this->serverConfig['server']['pid_file'] : '/tmp/ypf.pid';
		\Ypf\Swoole\Cmd::start($serverConfig);
	}

	public function setWorkerConfigPath($path) {
		if (!is_dir($path)) {
			trigger_error('Worker Config Path Not Exist!', E_USER_ERROR);
		}

		foreach (glob($path . '/*.conf') as $config_file) {
			$config = parse_ini_file($config_file, true);
			$name = basename($config_file, '.conf');
			$this->workerConfig[$name] = $config;
		}
	}

	public function &getServer() {
		return $this->server;
	}

	public function setCache(&$cache) {
		$this->cache = $cache;
	}

	public function start() {
		$listen = isset($this->serverConfig["server"]["listen"]) ?
		$this->serverConfig["server"]["listen"] : self::LISTEN;
		list($addr, $port) = explode(":", $listen, 2);
		$this->server = new \swoole_http_server($addr, $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
		isset($this->serverConfig['swoole']) && $this->server->set($this->serverConfig['swoole']);
		$this->server->on('Task', [$this, 'onTask']);
		$this->server->on('Finish', [$this, 'onFinish']);
		$this->server->on('Start', [$this, 'onStart']);
		$this->server->on('ManagerStart', [$this, 'onManagerStart']);
		$this->server->on('ManagerStop', [$this, 'onManagerStop']);
		$this->server->on('WorkerStart', [$this, 'onWorkerStart']);
		$this->server->on('WorkerStop', [$this, 'onWorkerStop']);
		$this->server->on('Request', [$this, 'onRequest']);
		$this->server->on('PipeMessage', [$this, 'onPipeMessage']);
		$this->server->on('ShutDown', [$this, 'onShutDown']);
		$this->server->start();
	}

	private function createCustomWorker() {
		if (empty($this->workerConfig)) {
			return;
		}

		foreach ($this->workerConfig as $worker_name => $config) {
			if (!$config["status"] or isset($config["crontab"])) {
				continue;
			}

			$this->spawnCustomWorker($worker_name, $config);
		}
		$this->spawnCrontabWorker($this->workerConfig);
		$this->cache->set('worker_pid', $this->worker_pid);
	}

	public function crontabWorker() {
		$cron_queue = $this->cache->get("worker_cron_queue");
		$cron_ready = $this->cache->get("worker_cron_ready");

		if (empty($cron_queue)) {
			return;
		}

		foreach ($cron_queue as $worker_name => $config) {
			$crontab = \Cron\CronExpression::isValidExpression($config["crontab"]);
			if (!$crontab) {
				$timeNs = intval($config["crontab"]);
			} else {
				$cron = \Cron\CronExpression::factory($config["crontab"]);
				$nextRunTime = $cron->getNextRunDate()->getTimestamp();
				$timeNs = intval($nextRunTime - time());
			}
			if ($timeNs < 1) {
				continue;
			}

			swoole_timer_after(1000 * $timeNs, function () use ($worker_name, $config) {
				self::getInstance()->disPatch($config['action'], array('worker_name' => $worker_name));
				$cron_queue = $this->cache->get("worker_cron_queue");
				$cron_ready = $this->cache->get("worker_cron_ready");
				unset($cron_ready[$worker_name]);
				$cron_queue[$worker_name] = $config;
				$this->cache->set("worker_cron_queue", $cron_queue);
				$this->cache->set("worker_cron_ready", $cron_ready);
			});
			unset($cron_queue[$worker_name]);
			$cron_ready[$worker_name] = $config;
		}
		$this->cache->set("worker_cron_queue", $cron_queue);
		$this->cache->set("worker_cron_ready", $cron_ready);
	}

	private function spawnCrontabWorker($config) {
		$process = new \swoole_process(function (\swoole_process $worker) use ($config) {
			foreach ($config as $k => $v) {
				if (!$v["status"] or !isset($v["crontab"])) {
					unset($config[$k]);
				}
			}
			$this->cache->set("worker_cron_queue", $config);
			\swoole_timer_tick(1000, [$this, 'crontabWorker']);
			$processName = isset($this->serverConfig['server']['cron_worker_process_name']) ?
			$this->serverConfig['server']['cron_worker_process_name'] : 'ypf:swoole-cron-worker';
			\swoole_set_process_name($processName);
		}, false, false);
		$pid = $process->start();

		$this->worker_pid[] = $pid;
		if ($pid > 0) {
			echo ("starting cron worker     [ OK ]\n");
		} else {
			echo ("starting cron worker     [ FAIL ]  '" . \swoole_strerror(swoole_errno()) . "' \n");
		}
	}

	private function spawnCustomWorker($worker_name, $config) {
		$process = new \swoole_process(function (\swoole_process $worker) {
			$recv = $worker->pop();
			$worker_config = unserialize($recv);
			extract($worker_config);

			$name = isset($this->serverConfig['server']['worker_process_name']) ?
			$this->serverConfig['server']['worker_process_name'] : 'ypf:swoole-worker-%d';
			$processName = sprintf("$name:%s", 0, $worker_name);
			\swoole_set_process_name($processName);
			self::getInstance()->disPatch($config['action'], array('worker_name' => $worker_name));
		}, false, false);
		$process->useQueue();
		$pid = $process->start();
		$this->worker_pid[] = $pid;
		$process->push(serialize(array('pid' => $pid, 'worker_name' => $worker_name, 'config' => $config)));

		if ($pid > 0) {
			echo ("starting worker : $worker_name        [ OK ]\n");
		} else {
			echo ("starting worker : $worker_name        [ FAIL ]  '" . \swoole_strerror(swoole_errno()) . "' \n");
		}
	}

	public function onStart(\swoole_http_server $server) {
		$name = isset($this->serverConfig['server']['master_process_name']) ?
		$this->serverConfig['server']['master_process_name'] : 'ypf:swoole-master';
		\swoole_set_process_name($name);
		file_put_contents($this->pidFile, $server->master_pid);
		return true;
	}

	public function onManagerStart(\swoole_http_server $server) {
		$name = isset($this->serverConfig['server']['manager_process_name']) ?
		$this->serverConfig['server']['manager_process_name'] : 'ypf:swoole-manager';
		\swoole_set_process_name($name);
		return true;
	}

	public function onManagerStop(\swoole_http_server $server) {

		return true;
	}

	public function onWorkerStart(\swoole_http_server $server, $worker_id) {
		if ($worker_id >= $server->setting['worker_num']) {
			$name = isset($this->serverConfig['server']['task_worker_process_name']) ?
			$this->serverConfig['server']['task_worker_process_name'] : 'ypf:swoole-task-worker-%d';
			$processName = sprintf($name, $worker_id);
		} else {
			if (!$worker_id) {
				//swoole_timer_after(3000, [$this, "createCustomWorker"]);
				$this->createCustomWorker();
			}
			$name = isset($this->serverConfig['server']['worker_process_name']) ?
			$this->serverConfig['server']['worker_process_name'] : 'ypf:swoole-worker-%d';
			$processName = sprintf($name, $worker_id);
		}
		\swoole_set_process_name($processName);
		return true;
	}

	public function onTask(\swoole_http_server $server, $task_id, $from_id, $data) {
		//echo sprintf("pid=%d, task_id=%d, from_id=%d, data=%s\n", getmypid(), $task_id, $from_id, $data);
		$data = unserialize($data);
		$result = call_user_func_array($data["func"], $data["args"]);
		$results = array('callback' => $data['callback'], 'result' => $result, 'thread' => $data['thread']);
		return $results;
	}

	public function onFinish(\swoole_http_server $server, $task_id, $data) {
		if (!empty($data['thread'])) {
			$key = $data['thread']['task'];
			$value = $this->cache->get($key);
			if (!$value) {
				return;
			}

			$value['results'][$data['thread']['id']] = $data['result'];
			$value['tasks'] -= 1;

			$this->cache->set($key, $value);
		}
		//echo sprintf("pid = %d fnish = %s, value = %s\n",getmypid(), print_r($data, true), print_r($value, true));
		if ($data['callback']) {
			$data["args"] = array('task_id' => $task_id, 'result' => $data['result']);
			call_user_func_array($data["callback"], $data["args"]);
		}
	}

	public function onWorkerStop(\swoole_http_server $server, $worker_id) {
		return true;
	}

	public function onShutDown(\swoole_http_server $server) {
		$this->worker_pid = $this->cache->get('worker_pid');
		foreach ($this->worker_pid as $pid) {
			\swoole_process::kill($pid, 9);
		}
		$this->cache->del('worker_pid');
		@unlink($this->pidFile);
		return true;
	}

	public function onPipeMessage(\swoole_http_server $server, $worker_id, $data) {
		$server->task($data);
	}

	public function onRequest(\swoole_http_request $request, \swoole_http_response $response) {
		$this->request->init($request);
		$this->response->init($response);
		$this->disPatch();
		$this->response->output();
		//$response->end("<h1>Hello Swoole</h1>");
	}
}
