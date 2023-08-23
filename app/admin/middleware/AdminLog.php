<?php

declare(strict_types=1);

namespace app\admin\middleware;

use app\admin\model\AdminLog as AdminLogModel;
use think\facade\Config;

class AdminLog
{
    /**
     * 处理请求(后置中间件)
     *
     * @param \think\Request $request
     * @param \Closure       $next
     * @return Response
     */
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        if (($request->isPost()) && Config::get('fastadmin.auto_record_log')) {
            AdminLogModel::record();
        }
        return $response;
    }
}
