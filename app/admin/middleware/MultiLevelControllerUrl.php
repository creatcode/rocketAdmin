<?php

namespace app\admin\middleware;

use Closure;
use think\App;
use think\Request;

/**
 * 多级控制器斜杠URL兼容
 *
 * FastAdmin 的JS、菜单和权限规则使用 general/attachment/select 这类斜杠URL，
 * 而 ThinkPHP 6+ 路由默认只把第一段解析为控制器（TP5 支持斜杠多级写法），
 * 这里在路由解析前将其改写为框架原生支持的点号写法 general.attachment/select，
 * 仅当「控制器组目录 + 控制器类文件」真实存在时才改写，其余URL不受影响。
 */
class MultiLevelControllerUrl
{
    /**
     * @var App
     */
    protected $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, Closure $next)
    {
        $pathinfo = ltrim($request->pathinfo(), '/');
        $segs = explode('/', $pathinfo);

        // 多应用模式下 pathinfo 带应用名前缀时先去掉
        $appName = $this->app->http->getName();
        if ($appName && ($segs[0] ?? '') === $appName) {
            array_shift($segs);
        }

        // 形如 group/controller/... 且 group 为控制器子目录、controller 为其下类文件时才改写
        if (count($segs) >= 2) {
            $group = $segs[0];
            $controllerDir = $this->app->getAppPath() . 'controller' . DIRECTORY_SEPARATOR . str_replace('.', '/', $group);
            if (is_dir($controllerDir)) {
                $controllerFile = $controllerDir . DIRECTORY_SEPARATOR . $this->studly($segs[1]) . '.php';
                if (is_file($controllerFile)) {
                    array_shift($segs);
                    $request->setPathinfo($group . '.' . implode('/', $segs));
                }
            }
        }

        return $next($request);
    }

    protected function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
