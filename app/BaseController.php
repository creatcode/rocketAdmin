<?php

declare(strict_types=1);

namespace app;

use think\App;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\facade\Config;
use think\facade\View;
use think\Response;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 视图
     * @var \think\View
     */
    protected $view;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param App $app 应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;
        $this->view    = $this->app->view;

        // 控制器初始化
        $this->initialize();
    }

    /**
     * 初始化（子类按需重写）
     */
    protected function initialize()
    {
    }

    /**
     * 验证数据
     * @access protected
     * @param array        $data     数据
     * @param string|array $validate 验证器名或者验证规则数组
     * @param array        $message  提示信息
     * @param bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            $scene = '';
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = strpos($validate, '\\') !== false ? $validate : $this->app->parseClass('validate', $validate);
            $v = class_exists($class) ? new $class() : new Validate();
            if ($scene && method_exists($v, 'scene')) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

    /**
     * 构建统一响应结构
     * @access private
     * @param int    $code 状态码 1=成功 0=失败
     * @param string $msg  提示信息
     * @param mixed  $data 返回数据
     * @param string|null $url 跳转地址
     * @param int    $wait 等待时间
     * @param array  $header HTTP头
     * @return array 响应数据结构
     */
    private function buildResponse(int $code, string $msg = '', $data = '', ?string $url = null, int $wait = 3, array $header = []): array
    {
        $type = $this->request->isAjax() ? 'json' : 'html';
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
            'url'  => $url ?? '',
            'wait' => $wait,
        ];

        if ($type === 'html') {
            $tmpl = $code === 1
                ? Config::get('app.dispatch_success_tmpl')
                : Config::get('app.dispatch_error_tmpl');
            $result = View::fetch($tmpl, $result);
        }

        $response = Response::create($result, $type)->header($header);
        throw new HttpResponseException($response);
    }

    /**
     * 操作成功跳转的快捷方法
     *
     * @param mixed  $msg    提示信息
     * @param string|null $url 跳转的 URL 地址
     * @param mixed  $data   返回的数据
     * @param int    $wait   跳转等待时间
     * @param array  $header 发送的 Header 信息
     * @throws HttpResponseException
     */
    protected function success($msg = '', $url = null, $data = '', int $wait = 3, array $header = [])
    {
        // 解析跳转地址
        if ($url === null && $this->request->server('HTTP_REFERER')) {
            $url = $this->request->server('HTTP_REFERER');
        } elseif ($url !== '' && $url !== null && strpos($url, '://') === false && strpos($url, '/') !== 0) {
            $url = (string) url($url);
        }

        $this->buildResponse(1, (string) $msg, $data, $url, $wait, $header);
    }

    /**
     * 操作错误跳转的快捷方法
     *
     * @param mixed  $msg    提示信息
     * @param string|null $url 跳转的 URL 地址
     * @param mixed  $data   返回的数据
     * @param int    $wait   跳转等待时间
     * @param array  $header 发送的 Header 信息
     * @throws HttpResponseException
     */
    protected function error($msg = '', $url = null, $data = '', int $wait = 3, array $header = [])
    {
        // 解析跳转地址
        if ($url === null) {
            $url = $this->request->isAjax() ? '' : 'javascript:history.back(-1);';
        } elseif ($url !== '' && strpos($url, '://') === false && strpos($url, '/') !== 0) {
            $url = (string) url($url);
        }

        $this->buildResponse(0, (string) $msg, $data, $url, $wait, $header);
    }

    /**
     * 返回封装后的 API 数据到客户端
     * @access protected
     * @param mixed  $data   要返回的数据
     * @param int    $code   错误码，默认为0
     * @param string $msg    提示信息
     * @param string $type   输出类型，支持json/xml/jsonp
     * @param array  $header 发送的 Header 信息
     * @throws HttpResponseException
     */
    protected function result($data, int $code = 0, string $msg = '', string $type = '', array $header = [])
    {
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'time' => $this->request->server('REQUEST_TIME'),
            'data' => $data,
        ];
        $type = $type ?: ($this->request->isAjax() ? 'json' : 'html');

        $response = Response::create($result, $type)->header($header);
        throw new HttpResponseException($response);
    }

    /**
     * URL 重定向
     *
     * @param string    $url    跳转的 URL 表达式
     * @param array|int $params 其它 URL 参数 或 HTTP code
     * @param int       $code   HTTP code
     * @param array     $with   隐式传参
     * @throws HttpResponseException
     */
    protected function redirect(string $url, $params = [], int $code = 302, array $with = [])
    {
        if (is_int($params)) {
            $code = $params;
        }
        $response = \redirect($url);
        $response->code($code)->with($with);

        throw new HttpResponseException($response);
    }
}
