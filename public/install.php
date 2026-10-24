<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// [ 安装文件 ]
// 建议安装完成后移除此文件
namespace think;

// 加载框架引导文件
require __DIR__ . '/../vendor/autoload.php';



// 绑定安装应用，保留原请求方法及参数。
$app = new App();
$app->initialize();
$http = $app->http;
$http->name('install');
$request = $app->request;
$request->setPathinfo('index/index');
$response = $http->run($request);
$response->send();
$http->end($response);
