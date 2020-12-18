<?php
declare(ticks=1);

//预派生子进程处理
class Worker
{
    public $socket;//套接字


    public $onMessage = null; //onMessage事件

    public $onConnect = null; //onConnect事件


    public $workerNum = 2; //进程数
    public $taskNum = 2; //taskworker进程数
    private $address;

    protected $workers = [];
    public $task_workers = [];

    protected $watch_fd; //监控句柄

    public $message_queue; //队列

    /**
     * 绑定socket
     * Worker constructor.
     * @param $address
     */
    public function __construct($address)
    {
        //监听地址+端口
        $this->address = $address;
        $this->master_pid = posix_getpid();
        $key = ftok(__DIR__, 'n');
        $this->message_queue = msg_get_queue($key);
    }

    /**
     * 创建子进程
     */
    public function fork($workerNum)
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $child_pid = pcntl_fork();
            if ($child_pid < 0) {
                var_dump("create pid failure");
            } else if ($child_pid > 0) {
                //父进程空间。返回子进程id
                var_dump("child:" . $child_pid);
                $this->workers[] = $child_pid;
            } else {
                //子进程创建成功， 主进程
                $this->accept();
                exit; //主动结束
            }
        }
        /*  for ($i = 0; $i < $this->workerNum; $i++) {
              //父进程回收进程， 放在父进程空间就可以，阻塞执行
              $waitPid = pcntl_wait($status); //结束的子进程，阻塞状态
              var_dump('子进程回收了' . $waitPid);
          }*/
    }

    /**
     * 创建task进程
     */
    public function forkTask($workerNum)
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $child_pid = pcntl_fork();
            if ($child_pid < 0) {
                var_dump("create pid failure");
            } else if ($child_pid > 0) {
                //父进程空间。返回子进程id
                $this->task_workers[] = $child_pid;
            } else {
                //子进程创建成功， 主进程
                $pid = posix_getpid();
                while (true) {
                    msg_receive($this->message_queue, $pid, $msgType, 1024, $message);
                    var_dump($message);
                }
                exit();
            }
        }
    }


    /**
     * 接收请求，阻塞监听
     */
    public function accept()
    {
        $context = stream_context_create([
            'socket' => [
                'backlog' => 10240, //成功连接等待的个数
            ]
        ]);
        //开启多端口监听. 并且实现负载均衡
        stream_context_set_option($context, 'socket', 'so_reuseport', 1);
        stream_context_set_option($context, 'socket', 'so_reuseaddr', 1); //地址重用
        $this->socket = stream_socket_server($this->address, $errorno, $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if (!$this->socket) {
            var_dump($errstr, $errorno);

            return;
        }
        //加载事件监听
        //1. 监听服务端事件, 一旦监听到可读事件之后会触发
        swoole_event_add($this->socket, function ($fd) {
            var_dump("服务端发生了变化" . $fd . '进程:' . posix_getpid());
            //接收到了客户端socket
            $clientSocket = stream_socket_accept($fd);
            //var_dump("客户端介入：".$clientSocket);
            //监听客户端事件
            swoole_event_add($clientSocket, function ($fd) {
                //获取消息
                $buffer = fread($fd, 1024 * 4);
                if (empty($buffer)) {
                    if (!is_resource($fd) || feof($fd)) {
                        //var_dump($fd."客户端断开连接");
                        fclose($fd);
                    }
                }
                if ($buffer && is_callable($this->onMessage)) {
                    var_dump($this->task_workers);
                    call_user_func($this->onMessage, $this, $fd, $buffer);

                }
            });
        });

        var_dump("非阻塞");
    }

    /**
     * 文件监视,自动重启
     */
    protected function watch()
    {

        $this->watch_fd = inotify_init(); //初始化
        $files = get_included_files();
        foreach ($files as $file) {
            inotify_add_watch($this->watch_fd, $file, IN_MODIFY); //监视相关的文件
        }
        //监听
        swoole_event_add($this->watch_fd, function ($fd) {
            $events = inotify_read($fd);
            if (!empty($events)) {
                //重新启动worker进程
                posix_kill($this->master_pid, SIGUSR1);
            }
        });
    }

    public $master_pid;

    /**
     * 启动服务
     */
    public function start()
    {
        $this->watch();
        $this->forkTask($this->taskNum); //fork task进程
        $this->fork($this->workerNum); //fork工作进程
        $this->monitorWorkers(); //检测进程
    }

    public function monitorWorkers()
    {
        //信号回调事件不会主动触发的
        //reload
        pcntl_signal(SIGUSR1, array($this, 'signalHandler'), false); //重启woker进程信号
        //kill
        //pcntl_signal(SIGKILL, array($this, 'signalHandler'), false);
        //ctrl+c
        pcntl_signal(SIGINT, array($this, 'signalHandler'), false);


        $status = 0;
        while (true) {
            //当信号队列一旦发现有信号就会触发进程绑定事件回调
            pcntl_signal_dispatch();
            $pid = pcntl_wait($status); //当信号到达之后会被中断

            //如果非正常退出，并且不是主进程， 希望能够重新拉起服务
            $index = array_search($pid, $this->workers);
            if ($pid > -1 && !pcntl_wifexited($status) && $pid != $this->master_pid && $index != false) {
                $index = array_search($pid, $this->workers);
                unset($this->workers[$index]);
                var_dump("拉起子进程");
                $this->fork(1);
            }
            pcntl_signal_dispatch(); //进程重启过程中会有新的信号过来，如果没有调用
            // pcntl_signal_dispatch()； ,信号不会被处理
        }
    }

    /**
     * 信号捕获器件， 根据sign去触发事件
     * @param $signal
     */
    public function signalHandler($signal)
    {
        switch ($signal) {
            case SIGUSR1:
                var_dump("reload signal");
                $this->reload();
                // exit();
                break;
            case SIGKILL:
                echo "stop signal";
                $this->stop();
                exit();
                break;
            case SIGINT:
                echo "press ctrl+c to stop workers\r\n";
                $this->stopAll();
                swoole_event_del($this->watch_fd);
                exit();
                break;
        }
    }

    //捕获信号之后重启worker进程
    public function stopAll()
    {
        //关闭工作进程
        foreach ($this->workers as $index => $pid) {
            posix_kill($pid, SIGKILL); //结束进程
            unset($this->workers[$index]);
        }
        //关闭task进程
        foreach ($this->task_workers as $index => $pid) {
            posix_kill($pid, SIGKILL); //结束进程
            unset($this->task_workers[$index]);
        }

    }

    /**
     * 停止服务
     */
    public function stop()
    {
        foreach ($this->workers as $index => $pid) {
            posix_kill($pid, SIGKILL);
            unset($this->workers[$index]);
        }
    }

    /**
     * 重载方法
     */
    public function reload()
    {
        foreach ($this->workers as $index => $pid) {
            posix_kill($pid, SIGKILL);
            unset($this->workers[$index]);
            $this->fork(1); //重新拉起worker
        }

        foreach ($this->task_workers as $index => $pid) {
            posix_kill($pid, SIGKILL);
            unset($this->task_workers[$index]);
            $this->forkTask(1); //重新拉起taskworker
        }
    }


    /**
     * 发送消息
     * @param $message
     * @return bool
     */
    public function sendMsg($message)
    {
        var_dump($this->task_workers);
        $msg_type = $this->task_workers[array_rand($this->task_workers)];
        return msg_send($this->message_queue, $msg_type, $message);
    }

    /*
    * 投递task任务
    */
    public function task($data)
    {
        //指定的是子进程,receive指定的消息类型
        $msg_type = $this->task_workers[array_rand($this->task_workers)];
        return msg_send($this->message_queue, $msg_type, $data);
    }
}


$worker = new Worker("tcp://0.0.0.0:9801");
//开启多进程的端口监听
//$worker->reusePort = true;
//链接事件
$worker->onConnect = function ($args) {
    var_dump("新的链接介入了:" . $args);
};
//消息事件
$worker->onMessage = function ($server, $connect, $message) {
    $server->task("外部消息." . $message);
    //var_dump("链接消息, 链接" . $connect, 'message:' . $message);
    $content = 'hello, world';
    $http_response = "HTTP/1.1 200 OK\r\n";
    $http_response .= "Content-type: text/html; charset=UTF-8\r\n";
    $http_response .= "Connection: keep-alive\r\n";
    $http_response .= "Server: php socket server\r\n";
    $http_response .= "Content-length:" . strlen($content) . "\r\n\r\n";
    $http_response .= $content;
    fwrite($connect, $http_response);
};

$worker->start();

$arguments = func_get_args();
var_dump($arguments);