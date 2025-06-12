<?php

namespace app\common\library;

use Psr\Log\AbstractLogger;

/**
 * 日志记录类
 */
class Log extends AbstractLogger
{

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function log($level, $message, array $context = [])
    {
        \think\facade\Log::write($message);
    }
}
