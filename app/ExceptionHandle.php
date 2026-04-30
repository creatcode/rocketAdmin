<?php

namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 * 区分 API 与 Web 请求，提供统一的错误响应格式
 */
class ExceptionHandle extends Handle
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
     * @param Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 非忽略异常，补充请求上下文后记录
        if (!$this->isIgnoreReport($exception)) {
            $request = request();
            // 附加请求上下文便于排查
            Log::record("[{$request->method()}] {$request->url()} IP:{$request->ip()}", 'error');
        }

        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request $request
     * @param Throwable      $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // 验证异常：API 请求返回 JSON 格式错误
        if ($e instanceof ValidateException && $request->isAjax()) {
            return json([
                'code' => 0,
                'msg'  => $e->getMessage(),
                'time' => $request->server('REQUEST_TIME'),
                'data' => null,
            ], 200);
        }

        // 模型数据未找到：API 请求友好返回
        if (($e instanceof ModelNotFoundException || $e instanceof DataNotFoundException) && $request->isAjax()) {
            return json([
                'code' => 0,
                'msg'  => __('No Results were found'),
                'time' => $request->server('REQUEST_TIME'),
                'data' => null,
            ], 200);
        }

        // HTTP 异常：API 请求保留状态码
        if ($e instanceof HttpException && $request->isAjax()) {
            return json([
                'code'  => 0,
                'msg'   => $e->getMessage() ?: __('Request error'),
                'time'  => $request->server('REQUEST_TIME'),
                'data'  => null,
            ], $e->getStatusCode());
        }

        // 其他错误交给系统处理
        return parent::render($request, $e);
    }
}
