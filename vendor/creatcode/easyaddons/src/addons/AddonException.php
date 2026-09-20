<?php

namespace creatcode\easyaddons\addons;

use think\Exception;
use Throwable;

/**
 * 插件异常处理类
 * @package creatcode\easyaddons\addons
 */
class AddonException extends Exception
{
    /**
     * 构造插件异常
     *
     * @param mixed          $message  异常信息
     * @param mixed          $code     异常码
     * @param mixed          $data     附加调试数据
     * @param Throwable|null $previous 上一个异常
     */
    public function __construct($message, $code = 0, $data = '', Throwable $previous = null)
    {
        parent::__construct((string) $message, (int) $code, $previous);

        if ($data !== '') {
            $this->setData('插件异常数据', is_array($data) ? $data : ['data' => $data]);
        }
    }
}
