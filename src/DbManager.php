<?php

namespace EasySwoole\ORM;

use EasySwoole\Component\Singleton;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\ORM\Db\MysqlClient;
use EasySwoole\ORM\Db\Pool;
use EasySwoole\ORM\Db\QueryResult;
use EasySwoole\ORM\Exception\PoolError;
use EasySwoole\Pool\AbstractPool;
use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;
use Swoole\Timer;

class DbManager
{
    use Singleton;

    /** @var callable|null */
    private $onQuery;

    protected $config = [];
    protected $pool = [];
    protected $context = [];


    function addConnection(ConnectionConfig $config):DbManager
    {
        $this->config[$config->getName()] = $config;
        return $this;
    }

    function setOnQuery(?callable $func = null):?callable
    {
        if($func){
            $this->onQuery = $func;
        }
        return $this->onQuery;
    }

    function fastQuery(?string $connectionName = "default"):QueryExecutor
    {
        return (new QueryExecutor())->setConnectionName($connectionName);
    }

    function invoke(callable $call,string $connectionName = "default",float $timeout = 3)
    {
        $obj = $this->getConnectionPool($connectionName)->getObj($timeout);
        if($obj){
            try{
                return call_user_func($call,$obj);
            }catch (\Throwable $exception){
                throw $exception;
            }finally {
                $this->getConnectionPool($connectionName)->recycleObj($obj);
            }
        }else{
            throw new PoolError("connection: {$connectionName} getObj() timeout,pool may be empty");
        }
    }

    function defer(string $connectionName = "default",float $timeout = 3):MysqlClient
    {
        $id = Coroutine::getCid();
        if(isset($this->context[$id][$connectionName])){
            return $this->context[$id][$connectionName];
        }else{
            $obj = $this->getConnectionPool($connectionName)->defer($timeout);
            if($obj){
                $this->context[$id][$connectionName] = $obj;
                Coroutine::defer(function ()use($id){
                    unset($this->context[$id]);
                });
                return $obj;
            }else{
                throw new PoolError("connection: {$connectionName} defer() timeout,pool may be empty");
            }
        }
    }

    function __exec(MysqlClient $client,QueryBuilder $builder,bool $raw = false,float $timeout = 3):QueryResult
    {
        $start = microtime(true);
        $result = $client->execQueryBuilder($builder,$raw,$timeout);
        if($this->onQuery){
            $temp = clone $builder;
            call_user_func($this->onQuery,$result,$temp,$client,$start);
        }
        if(in_array('SQL_CALC_FOUND_ROWS',$builder->getLastQueryOptions())){
            $temp = new QueryBuilder();
            $temp->raw('SELECT FOUND_ROWS() as count');
            $count = $client->execQueryBuilder($builder,false,$timeout);
            if($this->onQuery){
                call_user_func($this->onQuery,$count,$temp,$client,$start);
            }
            $result->setTotalCount($count->getResult()[0]['count']);
        }
        return $result;
    }

    function resetPool(bool $clearTimer = true)
    {
        /**
         * @var  $key
         * @var AbstractPool $pool
         */
        foreach ($this->pool as $key => $pool){
            $pool->reset();
        }
        $this->pool = [];
        if($clearTimer){
            Timer::clearAll();
        }
    }

    function runInMainProcess(callable $func)
    {
        $scheduler = new Scheduler();
        $scheduler->add($func);
        $scheduler->start();
        $this->resetPool();
    }

    private function getConnectionPool(string $connectionName):Pool
    {
        if(isset($this->pool[$connectionName])){
            return $this->pool[$connectionName];
        }
        if(isset($this->config[$connectionName])){
            /** @var ConnectionConfig $conf */
            $conf = $this->config[$connectionName];
            $pool = new Pool($conf);
            $this->pool[$connectionName] = $pool;
            return $pool;
        }else{
            throw new PoolError("connection: {$connectionName} did not register yet");
        }
    }

}