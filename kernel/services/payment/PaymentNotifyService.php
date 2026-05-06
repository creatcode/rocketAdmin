<?php

declare(strict_types=1);

namespace kernel\services\payment;

use Throwable;
use think\facade\Config;
use think\facade\Event;
use think\Response;

/**
 * 支付回调统一处理服务
 *
 * 负责验签、解析、触发业务处理，并返回支付平台可识别的响应。
 */
class PaymentNotifyService
{
    public function __construct(
        protected string $channel,
        protected string $account = 'default'
    ) {
    }

    /**
     * 获取指定渠道回调服务
     */
    public static function channel(string $channel, string $account = 'default'): static
    {
        return new static($channel, $account);
    }

    /**
     * 从当前 HTTP 请求中处理支付回调
     */
    public function handleCurrent(?callable $handler = null): Response
    {
        return $this->handle(
            $this->currentContents(),
            $this->currentHeaders(),
            $this->currentParams(),
            $handler
        );
    }

    /**
     * 处理支付回调
     *
     * @param array<string, mixed>|string|null $contents 回调内容
     * @param array<string, mixed> $headers 请求头
     * @param array<string, mixed>|null $params 请求参数
     */
    public function handle(
        array|string|null $contents = null,
        array $headers = [],
        ?array $params = null,
        ?callable $handler = null
    ): Response {
        try {
            $payment = PaymentService::channel($this->channel, $this->account);
            $notify = $payment->notify($contents, $headers, $params);

            $handled = $this->dispatch($notify, $headers, $params ?? [], $handler);
            foreach ($handled as $result) {
                if ($result instanceof Response) {
                    return $result;
                }

                if ($result === false) {
                    return $this->fail('业务处理失败');
                }
            }

            if (empty($handled) && (bool) Config::get('payment.notify.require_listener', false)) {
                return $this->fail('未配置支付回调业务处理器');
            }

            return $payment->success();
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 只解析并验证回调，不返回平台响应
     *
     * @param array<string, mixed>|string|null $contents 回调内容
     * @param array<string, mixed> $headers 请求头
     * @param array<string, mixed>|null $params 请求参数
     * @return array<string, mixed>
     */
    public function verify(array|string|null $contents = null, array $headers = [], ?array $params = null): array
    {
        return PaymentService::channel($this->channel, $this->account)
            ->notify($contents, $headers, $params);
    }

    /**
     * 返回当前渠道成功响应
     */
    public function success(): Response
    {
        return PaymentService::channel($this->channel, $this->account)->success();
    }

    /**
     * 返回当前渠道失败响应
     */
    public function fail(string $message = 'fail'): Response
    {
        if ($this->channel === 'wechat') {
            return Response::create(json_encode([
                'code' => 'FAIL',
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE), 'html', 400)->contentType('application/json');
        }

        return Response::create('fail', 'html', 400);
    }

    /**
     * 触发业务处理器
     *
     * @param array<string, mixed> $notify 验签后的回调数据
     * @param array<string, mixed> $headers 请求头
     * @param array<string, mixed> $params 请求参数
     * @return array<int, mixed>
     */
    protected function dispatch(array $notify, array $headers, array $params, ?callable $handler): array
    {
        $payload = [
            'channel' => $this->channel,
            'account' => $this->account,
            'notify' => $notify,
            'headers' => $headers,
            'params' => $params,
        ];

        if ($handler !== null) {
            return [$handler($payload)];
        }

        return Event::trigger('PaymentNotifyReceived', $payload);
    }

    /**
     * 获取当前请求的回调内容
     */
    protected function currentContents(): array|string|null
    {
        if ($this->channel === 'alipay') {
            return $this->currentParams();
        }

        return file_get_contents('php://input') ?: '';
    }

    /**
     * 获取当前请求头
     *
     * @return array<string, mixed>
     */
    protected function currentHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
                continue;
            }

            if ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
                continue;
            }

            if ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }

        return $headers;
    }

    /**
     * 获取当前请求参数
     *
     * @return array<string, mixed>
     */
    protected function currentParams(): array
    {
        return array_merge($_GET, $_POST);
    }
}
