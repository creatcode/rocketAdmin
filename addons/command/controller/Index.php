<?php

namespace addons\command\controller;

use creatcode\easyaddons\addons\Controller;

/**
 * 在线命令插件前台入口
 */
class Index extends Controller
{

    /**
     * 提示插件暂无前台页面
     * @return void
     * @throws \think\exception\HttpResponseException
     */
    public function index()
    {
        $this->error("当前插件暂无前台页面");
    }

}
