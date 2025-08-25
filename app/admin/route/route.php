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

// ai优化版
Route::group(function () {
    $controllerDir = base_path('admin' . DIRECTORY_SEPARATOR . 'controller');
    $dirArr = array_diff(scandir($controllerDir), ['.', '..']);
    
    foreach ($dirArr as $value) {
        $dirPath = $controllerDir . DIRECTORY_SEPARATOR . $value;
        if (!is_dir($dirPath)) continue;

        // 规范化目录名（小写+字母数字）
        $groupName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $value));
        
        Route::group($groupName, function () use ($groupName) {
            // 单一路由支持可选action
            Route::rule('<controller>[/<action>]', $groupName . '.<controller>/<action>', 'GET|POST')
                ->defaults(['action' => 'index']); // 默认index方法
        })
        ->completeMatch(true)   // 开启完全匹配
        ->pattern([             // 参数格式限制
            'controller' => '[a-zA-Z][a-zA-Z0-9]*', 
            'action' => '[a-zA-Z][a-zA-Z0-9]*'
        ]);
    }
})->mergeRuleRegex(); // 合并正则提升性能
