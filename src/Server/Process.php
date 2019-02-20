<?php

namespace Swover\Server;

use Swover\Utils\Worker;

/**
 * Process Server
 */
class Process extends Base
{
    //child-process index => pid
    private $works = [];

    //child-process index => process
    private $processes = [];

    public function __construct(array $config)
    {
        try {
            parent::__construct($config);


            //使当前进程蜕变为守护进程
            if ($this->daemonize === true) {
                \swoole_process::daemon(true, false);
            }

            //设置进程名字
            $this->_setProcessName('master');

            //设置master进程id
            Worker::setMasterPid(posix_getpid());

            //根据worker_num创建子进程
            for ($i = 0; $i < $this->worker_num; $i++) {
                $this->CreateProcess($i);
            }

            //设置异步信号监听,子进程die回收后重启
            $this->asyncProcessWait();

        } catch (\Exception $e) {
            die('Start error: ' . $e->getMessage());
        }
    }

    /**
     * create process
     */
    private function CreateProcess($index)
    {

        $process = new \swoole_process(function (\swoole_process $worker) use ($index) {

            //设置子进程名字
            $this->_setProcessName('worker_'.$index);

            //设置子进程状态
            Worker::setChildStatus(true);

            //设置信号处理器 -- 进程结束会callback
            pcntl_signal(SIGUSR1, function ($signo) {
                Worker::setChildStatus(false);
            });

            $request_count = 0;
            $signal = 0;
            while (true) {
                $signal = $this->getProcessSignal($request_count);
                if ($signal > 0) {
                    break;
                }

                try {
                    $result = $this->entrance();
                    if ($result === false) {
                        break;
                    }
                } catch (\Exception $e) {
                    $this->log("[Error] worker id: {$worker->pid}, e: " . $e->getMessage());
                    break;
                }
            }
            $this->log("[#{$worker->pid}]\tWorker-{$index}: shutting down by {$signal}..");
            sleep(mt_rand(1,3));
            $worker->exit();
        }, $this->daemonize ? true : false);

        //启动进程后callback 上边的匿名函数
        $pid = $process->start();

        //添加事件监听
        \swoole_event_add($process->pipe, function ($pipe) use ($process) {
            //从管道中获取数据
            $data = $process->read();
            if ($data) {
                $this->log($data);
            }
        });

        $this->processes[$index] = $process;
        $this->works[$index] = $pid;
        return $pid;
    }

    /**
     * get child process sign
     * @return int
     */
    private function getProcessSignal(&$request_count)
    {
        //循环次数大于设置的次数后,进程将被回收重启
        if ($this->max_request > 0) {
            if ($request_count > $this->max_request) {
                return 1;
            }
            $request_count ++;
        }

        //检查master进程是否正常
        if (! Worker::checkMaster() ) {
            return 2;
        }

        //查询子进程状态
        if (Worker::getChildStatus() == false) {
            return 3;
        }

        return 0;
    }

    /**
     * restart child process
     *
     * @param $ret array process info
     * @throws \Exception
     */
    private function restart($ret)
    {
        $pid = $ret['pid'];
        $index = array_search($pid, $this->works);
        if ($index !== false) {

            \swoole_event_del($this->processes[$index]->pipe);
            $this->processes[$index]->close();

            $index = intval($index);
            $new_pid = $this->CreateProcess($index);
            $this->log("[#{$new_pid}]\tWorker-{$index}: restarted..");
            return;
        }
        throw new \Exception('restart process Error: no pid');
    }

    /**
     * async listen SIGCHLD
     */
    private function asyncProcessWait()
    {
        \swoole_process::signal(SIGCHLD, function ($sig) {
            while ($ret = \swoole_process::wait(false)) {
                $this->restart($ret);
            }
        });
    }
}