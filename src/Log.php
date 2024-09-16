<?php

namespace Mtech\AwsDevops;

class Log
{
    /**
     * 日志
     *
     * @var array
     */
    public static $loggers = [];

    /**
     * 设置日志
     *
     * @param [type] $loggers
     * @return void
     */
    public static function setLoggers($loggers){
        self::$loggers = $loggers;
    }
    
    /**
     * info log
     *
     * @param [type] $loggers
     * @return void
     */
    public static function info(...$args)
    {
        if (!self::$loggers) {
            return;
        }

        $prefix = "[" . date('Y-m-d H:i:s') . "] Mtech\AwsDevops: ";

        $args[0] = "{$prefix}{$args[0]}";

        foreach (self::$loggers as $logger) {
            call_user_func_array(array($logger, "info"), $args);
        }
    }

    /**
     * error log
     *
     * @param [type] $loggers
     * @return void
     */
    public static function error(...$args)
    {
        if (!self::$loggers) {
            return;
        }

        $prefix = "[" . date('Y-m-d H:i:s') . "] Mtech\AwsDevops: ";

        $args[0] = "{$prefix}{$args[0]}";

        foreach (self::$loggers as $logger) {
            call_user_func_array(array($logger, "error"), $args);
        }
    }
}
