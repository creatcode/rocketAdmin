<?php

namespace app\admin\library;

use Error;
use Exception;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class AdminExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof ValidateException && $request->isAjax()) {
            $data = [
                'code' => 0,
                'msg'  => $e->getMessage(),
            ];
            // 仅在调试模式下暴露文件路径和行号，生产环境隐藏敏感信息
            if (env('app_debug')) {
                $data['file'] = $e->getFile();
                $data['line'] = $e->getLine();
            }
            return json($data)->options(['json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE]);
        }

        // 其他错误交给系统处理
        $response = parent::render($request, $e);
        // 调试数据中的非 UTF-8 字符不能导致异常响应再次编码失败
        if (!$e instanceof HttpResponseException && $response instanceof \think\response\Json) {
            $response->options(['json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE]);
        }
        return $response;
    }
}
