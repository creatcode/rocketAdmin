<?php

namespace creatcode\easyaddons\addons;

use think\Exception;

/**
 * 插件异常处理类
 * @package creatcode\easyaddons\addons
 */
class AddonException extends Exception
{

    public function __construct($message, $code = 0, $data = '')
    {
        $this->message  = $message;
        $this->code     = $code;
        $this->data     = $data;
    }
}
