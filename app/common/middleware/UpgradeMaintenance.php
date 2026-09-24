<?php

namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 升级期间暂停普通 HTTP 请求，并等待已进入的请求结束。
 */
class UpgradeMaintenance
{
    /**
     * 维护标记存在时仅放行升级控制器。
     *
     * @param Request $request 当前请求
     * @param Closure $next 后续中间件
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        if ($this->isUpgradeRequest($request)) {
            return $next($request);
        }

        $root = rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'upgrade';
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            return $next($request);
        }

        $maintenance = $root . DIRECTORY_SEPARATOR . 'maintenance.lock';
        $barrierPath = $root . DIRECTORY_SEPARATOR . 'requests.lock';
        if (is_link($root) || is_link($barrierPath)) {
            return $this->maintenanceResponse($request);
        }

        $handle = @fopen($barrierPath, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return is_file($maintenance) ? $this->maintenanceResponse($request) : $next($request);
        }

        try {
            if (is_file($maintenance)) {
                return $this->maintenanceResponse($request);
            }
            return $next($request);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 识别后台升级页及升级检查、执行、恢复、进度、日志接口。
     *
     * @param Request $request 当前请求
     * @return bool
     */
    private function isUpgradeRequest(Request $request): bool
    {
        $path = trim(strtolower($request->pathinfo()), '/');
        return preg_match('#^(?:[a-z0-9_.-]+/)?upgrade(?:/(?:index|check|run|recover|status|logs))?$#D', $path) === 1;
    }

    /**
     * 返回维护提示，阻止普通页面或接口继续执行。
     *
     * @param Request $request 当前请求
     * @return Response
     */
    private function maintenanceResponse(Request $request)
    {
        $message = '系统升级或恢复期间暂时暂停访问，请稍后刷新。';
        if ($request->isAjax() || strpos((string)$request->header('accept', ''), 'application/json') !== false) {
            return Response::create(['code' => 0, 'msg' => $message], 'json', 503);
        }
        return Response::create(
            '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>系统维护</title><body><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>',
            'html',
            503
        );
    }
}
