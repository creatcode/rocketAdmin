<?php

namespace creatcode\easyaddons\addons;

use Exception;
use Throwable;

/**
 * 插件异常处理类
 * @package creatcode\easyaddons\addons
 */
class AddonException extends Exception
{
    /** @var mixed 插件操作返回的业务数据，不使用框架的调试数据结构。 */
    protected $data;

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

        $this->data = $data;
    }

    /**
     * 获取插件操作的业务数据。
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}
