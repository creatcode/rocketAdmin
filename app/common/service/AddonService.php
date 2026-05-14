<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\middleware\CommonInit;
use creatcode\easyaddons\addons\command\AddonCommand;
use creatcode\easyaddons\addons\command\TenantAddonCommand;
use creatcode\easyaddons\addons\Route as AddonRoute;
use RuntimeException;
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
     * 插件路由执行入口
     */
    private const ADDON_ROUTE_EXECUTE = '\\' . AddonRoute::class . '::execute';

    /**
     * 插件公共中间件
     */
    private const ADDON_MIDDLEWARE = [
        CommonInit::class,
        \think\middleware\LoadLangPack::class,
    ];

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
        if (!is_dir(ADDON_PATH) && !@mkdir(ADDON_PATH, 0755, true) && !is_dir(ADDON_PATH)) {
            throw new RuntimeException('插件目录创建失败：' . ADDON_PATH);
        }
        //注册旧版插件命名空间兼容别名
        $this->registerCompatibilityAliases();
        //注册插件路由
        $this->addon_route();
        //注册插件事件
        $this->addon_event();
    }

    /**
     * 注册插件事件
     */
    protected function addon_event()
    {
        $hooks = Env::get('APP_DEBUG') ? [] : Cache::get('hooks', []);
        $hooks = is_array($hooks) ? $hooks : [];
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks');
            // 初始化钩子
            foreach ($hooks as $key => $values) {
                $values = $this->normalizeHookValues($values);
                $hooks[$key] = array_values(array_filter(array_map(function ($v) use ($key) {
                    $class = get_addon_class($v);
                    return $class ? [$class, Str::camel($key)] : null;
                }, $values)));

                // $values = is_string($values) ? explode(',', $values) : (array)$values;
                // $values = array_filter($values);
                // $hooks[$key] = array_filter(array_map(function ($v) {
                //     return get_addon_class($v);
                // }, $values));
            }
            Cache::set('hooks', $hooks);
        }
        Event::listenEvents($hooks);

        //如果在插件中有定义app_init，则直接执行
        if (!empty($hooks['app_init'])) {
            Event::trigger('app_init');
        }
    }

    /**
     * 注册插件路由.
     */
    private function addon_route()
    {
        Route::rule('addons/:addon/[:controller]/[:action]', self::ADDON_ROUTE_EXECUTE)
            ->middleware(self::ADDON_MIDDLEWARE);

        //注册路由
        $routeArr = (array) Config::get('addons.route');
        foreach ($routeArr as $k => $v) {
            if (is_array($v)) {
                $domain = trim((string)($v['domain'] ?? ''));
                if (!$domain || empty($v['rule']) || !is_array($v['rule'])) {
                    continue;
                }
                $drules = [];
                foreach ($v['rule'] as $m => $n) {
                    $rule = $this->parseRouteTarget($n, true);
                    if (!$rule) {
                        continue;
                    }
                    $drules[(string)$m] = $rule;
                }
                if (!$drules) {
                    continue;
                }
                Route::domain($domain, function () use ($drules) {
                    // 动态注册域名的路由规则
                    foreach ($drules as $k => $rule) {
                        Route::rule($k, self::ADDON_ROUTE_EXECUTE)
                            ->middleware(self::ADDON_MIDDLEWARE)
                            ->name($k)
                            ->completeMatch(true)
                            ->append($rule);
                    }
                });
            } else {
                if (!$v) {
                    continue;
                }
                $rule = $this->parseRouteTarget($v);
                if (!$rule) {
                    continue;
                }
                Route::rule((string)$k, self::ADDON_ROUTE_EXECUTE)
                    ->middleware(self::ADDON_MIDDLEWARE)
                    ->name($k)
                    ->completeMatch(true)
                    ->append($rule);
            }
        }
    }

    /**
     * 规范化插件钩子配置
     */
    private function normalizeHookValues($values): array
    {
        $values = is_string($values) ? explode(',', $values) : (array) $values;
        $values = array_map(function ($value) {
            if (is_array($value)) {
                return '';
            }

            return trim((string) $value);
        }, $values);

        return array_values(array_unique(array_filter($values)));
    }

    /**
     * 解析插件路由目标
     */
    private function parseRouteTarget($target, bool $inDomain = false): array
    {
        $parts = array_map('trim', explode('/', (string) $target));
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return [];
        }

        [$addon, $controller, $action] = $parts;
        $rule = [
            'addon'      => $addon,
            'controller' => $controller,
            'action'     => $action,
        ];

        if ($inDomain) {
            $rule['indomain'] = 1;
        }

        return $rule;
    }

    /**
     * 注册旧版插件命名空间兼容别名
     */
    private function registerCompatibilityAliases(): void
    {
        $aliases = [
            \creatcode\easyaddons\Addons::class => 'think\\Addons',
            \creatcode\easyaddons\addons\Service::class => 'think\\addons\\Service',
            \creatcode\easyaddons\addons\Controller::class => 'think\\addons\\Controller',
            \creatcode\easyaddons\addons\AddonException::class => 'think\\addons\\AddonException',
            \creatcode\easyaddons\addons\Route::class => 'think\\addons\\Route',
        ];

        foreach ($aliases as $class => $alias) {
            if (!class_exists($alias, false) && class_exists($class)) {
                class_alias($class, $alias);
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
        // 注册插件管理命令
        $this->commands([
            AddonCommand::class,
            TenantAddonCommand::class,
        ]);
    }
}
