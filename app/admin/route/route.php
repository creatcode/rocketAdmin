<?php

use think\facade\Route;

Route::group(function () {

    // tp6不支持原先的多级控制器访问方式，需要将类似这种 auth/admin/selectpage 修改为 auth.admin/selectpage，暂时用路由处理一下
    $controllerDir = base_path('admin' . DIRECTORY_SEPARATOR . 'controller');
    $dirArr = array_diff(scandir($controllerDir), ['.', '..']);
    foreach ($dirArr as $key => $value) {
        if (is_dir($controllerDir . DIRECTORY_SEPARATOR . $value)) {
            Route::group($value, function () use ($value) {
                Route::rule('<controller>/<action>', $value . '.<controller>/<action>', 'post|get');
                Route::get('<controller>', $value . '.<controller>/index');
            });
        }
    }
})->mergeRuleRegex()->completeMatch(false);
