<?php

namespace addons\command\library;

/**
 * Class Output
 */
class Output extends \think\console\Output
{

    protected $message = [];

    /**
     * 初始化命令输出驱动
     * @param string $driver 输出驱动名称
     */
    public function __construct($driver = 'console')
    {
        parent::__construct($driver);
    }

    /**
     * 收集命令输出，兼容框架的输出方法签名
     * @param string $style 输出样式
     * @param string $message 输出内容
     * @return void
     */
    protected function block(string $style, string $message): void
    {
        $this->message[] = $message;
    }

    /**
     * 获取已收集的命令输出
     * @return array
     */
    public function getMessage()
    {
        return $this->message;
    }

}
