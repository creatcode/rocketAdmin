<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\middleware\CommonInit;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Env;
use think\facade\Event;
use think\facade\Route;
use think\helper\Str;

/**
 * 插件服务类
 */
class AddonService extends \think\Service
{
    /**
     * 注册服务
     *
     * @return mixed
     */
    public function register()
    {
        // 插件目录
        !defined('ADDON_PATH') && define('ADDON_PATH', app()->getRootPath() . 'addons' . DIRECTORY_SEPARATOR);
        // 如果插件目录不存在则创建
        if (!is_dir(ADDON_PATH)) {
            @mkdir(ADDON_PATH, 0755, true);
        }
        //注册插件路由
        $this->addon_route();
        //注册插件事件
        $this->addon_event();
    }

    // 注册事件
    protected function addon_event()
    {
        $hooks = Env::get('APP_DEBUG') ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks');
            // 初始化钩子
            foreach ($hooks as $key => $values) {
                $values = is_string($values) ? explode(',', $values) : (array)$values;
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addon_class($v), Str::camel($key)];
                }, $values));

                // $values = is_string($values) ? explode(',', $values) : (array)$values;
                // $values = array_filter($values);
                // $hooks[$key] = array_filter(array_map(function ($v) {
                //     return get_addon_class($v);
                // }, $values));
            }
            Cache::set('hooks', $hooks);
        }
        //如果在插件中有定义app_init，则直接执行
        if (isset($hooks['app_init'])) {
            foreach ($hooks['app_init'] as $k => $v) {
                Event::trigger('app_init', $v);
            }
        }
        Event::listenEvents($hooks);
    }

    /**
     * 注册插件路由.
     */
    private function addon_route()
    {
        Route::rule('addons/:addon/[:controller]/[:action]', '\\think\\addons\\Route::execute')
            ->middleware([CommonInit::class, \think\middleware\LoadLangPack::class]);

        //注册路由
        $routeArr = (array) Config::get('addons.route');
        $execute = '\\think\\addons\\Route::execute';
        foreach ($routeArr as $k => $v) {
            if (is_array($v)) {
                $domain = $v['domain'];
                $drules = [];
                foreach ($v['rule'] as $m => $n) {
                    [$addon, $controller, $action] = explode('/', $n);
                    $drules[$m] = [
                        'addon'    => $addon, 'controller' => $controller, 'action' => $action,
                        'indomain' => 1,
                    ];
                }
                Route::domain($domain, function () use ($drules, $execute) {
                    // 动态注册域名的路由规则
                    foreach ($drules as $k => $rule) {
                        Route::rule($k, $execute)
                            ->middleware([CommonInit::class, \think\middleware\LoadLangPack::class])
                            ->name($k)
                            ->completeMatch(true)
                            ->append($rule);
                    }
                });
            } else {
                if (!$v) {
                    continue;
                }
                [$addon, $controller, $action] = explode('/', $v);
                Route::rule($k, $execute)
                    ->middleware([CommonInit::class, \think\middleware\LoadLangPack::class])
                    ->name($k)
                    ->completeMatch(true)
                    ->append(['addon' => $addon, 'controller' => $controller, 'action' => $action]);
            }
        }
    }

    /**
     * 执行服务
     *
     * @return mixed
     */
    public function boot()
    {
        //
    }
}
