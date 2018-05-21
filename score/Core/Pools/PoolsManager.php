<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Pools;

use Swoole\Process;
use Swoole\Table;
use Swoolefy\Core\Timer\TickManager;
use Swoolefy\Core\Table\TableManager;

class PoolsManager {

    use \Swoolefy\Core\SingleTrait;
	
	private static $table_process = [
		// 进程内存表
		'table_process_pools_map' => [
			// 内存表建立的行数,取决于建立的process进程数
			'size' => 256,
			// 字段
			'fields'=> [
				['pid','int', 10]
			]
		],
        // 进程内存表对应的进程number
        'table_process_pools_number' => [
            // 内存表建立的行数,取决于建立的process进程数
            'size' => 256,
            // 字段
            'fields'=> [
                ['pnumber','int', 5]
            ]
        ],
	];

	private static $processList = [];

    private static $processNameList = [];

    private static $process_used = [];

    private static $process_name_list = [];

    private static $channels = [];

    private static $timer_ids = [];

    /**
     * __construct
     * @param  $total_process 进程池总数
     */
	public function __construct(int $total_process = 256) {
        self::$table_process['table_process_pools_map']['size'] = self::$table_process['table_process_pools_number']['size']= $total_process;
		TableManager::getInstance()->createTable(self::$table_process);
	}

	/**
	 * addProcess 添加创建进程
	 * @param string  $processName
	 * @param string  $processClass
     * @param int     $processNumber
	 * @param boolean $async
     * @param boolean $polling 是否是轮训向空闲进程写数据
	 * @param array   $args
	 */
	public static function addProcessPools(string $processName, string $processClass, int $processNumber = 1, $polling = false, int $timer_int= 50, $async = true, array $args = []) {
		if(!TableManager::isExistTable('table_process_pools_map')) {
			TableManager::getInstance()->createTable(self::$table_process);
		}

        if(!empty(self::$processList)) {
            // 剩余可创建进程数
            $count = count(self::$processList);
            $left_process_num = self::$table_process['table_process_pools_map']['size'] - $count;
            if($left_process_num <= 0) {
                throw new \Exception("You have created total process number $count", 1);   
            }
        }else {
            // 可创建的进程数
            $total_process_num = self::$table_process['table_process_pools_map']['size'];
            if($processNumber > $total_process_num) {
                throw new \Exception("You only created process number $total_process_num", 1);
            }
        }

		for($i=1; $i<=$processNumber; $i++) {
            $process_name = $processName.$i;
			$key = md5($process_name);
	        if(!isset(self::$processList[$key])){
	            try{
	                $process = new $processClass($process_name, $async, $args, $i);
	                self::$processList[$key] = $process;
                    self::$process_name_list[$processName][$key] = $process;
                    self::$processNameList[$processName][] = $process_name;
	            }catch (\Exception $e){
	                throw new \Exception($e->getMessage(), 1);       
	            }
	        }else{
	            throw new \Exception("you can not add the same process : $process_name", 1);
	            return false;
	        }
        }

        if($polling) {
            self::registerProcessFinish(self::$process_name_list[$processName], $processName);
            self::$channels[$processName] = new \Swoole\Channel(2 * 1024 * 2014);
            self::loopWrite($processName, $timer_int);
        }

        Process::signal(SIGCHLD, function($signo) use($processName, $processClass, $async, $args) {
	        while($ret = Process::wait(false)) { 
	            if($ret) {
	            	$pid = $ret['pid'];
	            	$process_num = TableManager::getInstance()->getTable('table_process_pools_number')->get($pid, 'pnumber');
                    $process_name = $processName.$process_num;
	                $key = md5($process_name);
                    unset(self::$processList[$key]);
                    unset(self::$process_name_list[$processName][$key]);
                    TableManager::getInstance()->getTable('table_process_pools_number')->del($pid);
                    try{
                        $process = new $processClass($process_name, $async, $args, $process_num);
                        self::$processList[$key] = $process;
                        self::$process_name_list[$processName][$key] = $process;
                        $polling && self::registerProcessFinish([$process], $processName);
                    }catch (\Exception $e){
                        throw new \Exception($e->getMessage(), 1);       
                    }
	           }
            }
	    });
	}

    /**
     * registerProcessFinish
     * @return 
     */
    public static function registerProcessFinish(array $processList = [], string $processName) {
        foreach($processList as $process_class) {
            $process = $process_class->getProcess();
            $processname = $process_class->getProcessName();
            // 默认所有进程空闲
            self::$process_used[$processName][$processname] = 0;
            swoole_event_add($process->pipe, function ($pipe) use($process, $processName) {
                $process_name = $process->read(64 * 1024);
                if(in_array($process_name, self::$processNameList[$processName])) {
                    self::$process_used[$processName][$process_name] = 0;
                }
            });
        }
    }

    /**
     * loopWrite 定时循环向子进程写数据
     * @return   mixed
     */
    public static function loopWrite(string $processName, $timer_int) {
        $timer_id = swoole_timer_tick($timer_int, function($timer_id) use($processName) {
            if(count(self::$process_used[$processName])) {
                $channel= self::$channels[$processName];
                $data = $channel->pop();
                if($data) {
                    // 获取其中一个空闲进程
                    $process_name = array_rand(self::$process_used[$processName]);
                    self::writeByProcessName($process_name, $data);
                    unset(self::$process_used[$processName][$process_name]);
                    return $process_name;
                }
            }
        });
        self::$timer_ids[$processName] = $timer_id;
        return $timer_id;
    }

    /**
     * getTimerId 获取当前的定时器id
     * @param   string  $processName
     * @return  mixed
     */
    public static function getTimerId(string $processName) {
        if(isset(self::$timer_ids[$processName]) && self::$timer_ids[$processName]!== null) {
            return self::$timer_ids[$processName];
        }
        return null;
    }

    /**
     * clearTimer 清除进程内的定时器
     * @param    string  $processName
     * @return   boolean
     */
    public static function clearTimer(string $processName) {
        $timer_id = self::getTimerId($processName);
        if($timer_id) {
            return swoole_timer_clear($timer_id);  
        }
        return false;
    }

    /**
     * getChannel
     * @param    string   $processName
     * @return   object
     */
    public static function getChannel(string $processName) {
        if(is_object(self::$channels[$processName])) {
            return self::$channels[$processName];
        }
        return null;
    }

	/**
	 * getProcessByName 通过名称获取一个进程
	 * @param  string $processName
     * @param  int    $process_num
	 * @return object
	 */
	public static function getProcessByName(string $processName, $process_num = null) {
		$processName = $processName.$process_num;
        $key = md5($processName);
        if(isset(self::$processList[$key])){
            return self::$processList[$key];
        }else{
            return null;
        }
    }

    /**
     * getProcessByPid 通过进程id获取进程
     * @param  int    $pid
     * @return object
     */
    public static function getProcessByPid(int $pid) {
        $table = TableManager::getTable('table_process_pools_map');
        foreach ($table as $key => $item){
            if($item['pid'] == $pid){
                return self::$processList[$key];
            }
        }
        return null;
    }

    /**
     * setProcess 设置一个进程
     * @param string          $processName
     * @param AbstractProcess $process
     */
    public static function setProcess(string $processName, AbstractProcessPools $process) {
        $key = md5($processName);
        self::$processList[$key] = $process;
    }

    /**
     * reboot 重启某个进程
     * @param  string $processName
     * @param  int    $process_num
     * @return boolean
     */
    public static function reboot(string $processName, $process_num = null) {
    	$processName = $processName.$process_num;
        $process = self::getProcessByName($processName)->getProcess();
        $pid = $process->pid;
        if($pid){
            $process->kill($pid, SIGTERM);
            return true;
        }else{
            return false;
        }
    }

    /**
     * writeByProcessName 向某个进程写数据
     * @param  string $name
     * @param  string $data
     * @return boolean
     */
    public static function writeByProcessName(string $name, string $data) {
        $process = self::getProcessByName($name);
        if($process){
            return (bool)$process->getProcess()->write($data);
        }else{
            return false;
        }
    }

    /**
     * writeByRandom 任意方式向进程写数据
     * @param  string $name
     * @param  string $data
     * @return 
     */
    public static function writeByRandom(string $name, string $data) {
        if(self::$processNameList[$name]) {
            $key = array_rand(self::$processNameList[$name], 1);
            $process_name = self::$processNameList[$name][$key];
        }
        self::writeByProcessName($process_name, $data);
        return $process_name;
    }

    /**
     * writeByPolling 轮训方式向空闲进程写数据 
     * @param  string $name
     * @param  string $data
     * @return 
     */
    public static function writeByPolling(string $name, string $data) {
        $channel = self::$channels[$name];
        return $channel->push($data);
    }


    /**
     * readByProcessName 读取某个进程数据
     * @param  string $name
     * @param  float  $timeOut
     * @return mixed
     */
    public static function readByProcessName(string $name, float $timeOut = 0.1) {
        $process = self::getProcessByName($name);
        if($process){
            $process = $process->getProcess();
            $read = array($process);
            $write = [];
            $error = [];
            $ret = swoole_select($read, $write, $error, $timeOut);
            if($ret){
                return $process->read(64 * 1024);
            }else{
                return null;
            }
        }else{
            return null;
        }
    }
}