<?php
namespace app;

// 应用请求对象类
class Request extends \think\Request
{

    /**
     * 控制器标识(点号+下划线形态：system.system_group)
     *
     * @var string
     */
    public $controllerPath = '';
}
