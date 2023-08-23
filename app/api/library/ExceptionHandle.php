<?php

namespace app\api\library;

use Exception;
use think\exception\Handle;
use think\Request;
use think\Response;
use Throwable;

/**
 * 自定义API模块的错误显示
 */
class ExceptionHandle extends Handle
{

    public function render($request, Throwable $e): Response
    {
        // 在生产环境下返回code信息
        if (!env('app_debug')) {
            $statuscode = $code = 500;
            $msg = 'An error occurred';
            // 验证异常
            if ($e instanceof \think\exception\ValidateException) {
                $code = 0;
                $statuscode = 200;
                $msg = $e->getError();
            }
            // Http异常
            if ($e instanceof \think\exception\HttpException) {
                $statuscode = $code = $e->getStatusCode();
            }
            return json(['code' => $code, 'msg' => $msg, 'time' => time(), 'data' => null], $statuscode);
        }

        //其它此交由系统处理
        return parent::render($request, $e);
    }
}
