<?php

use think\facade\Route;

Route::group(function () {

    // tp6不支持原先的多级控制器访问方式，需要将类似这种 auth/admin/selectpage 修改为 auth.admin/selectpage，暂时用路由处理一下
    $dirArr = glob(base_path('admin' . DIRECTORY_SEPARATOR . 'controller') . '*', GLOB_ONLYDIR);
    foreach ($dirArr as $key => $value) {
        $name = basename($value);
        Route::group($name, function () use ($name) {
            Route::rule('<controller>/<action>', $name . '.<controller>/<action>', 'post|get');
            Route::get('<controller>', $name . '.<controller>/index');
        });
    }
})->mergeRuleRegex()->completeMatch(false);
