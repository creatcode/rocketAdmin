<?php

declare(strict_types=1);

namespace kernel\services\payment\providers;

use BadMethodCallException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use kernel\services\payment\contracts\PaymentProviderInterface;
use think\Response;
use Yansongda\Artful\Rocket;
use Yansongda\Pay\Pay;
use Yansongda\Supports\Collection;

/**
 * 支付宝支付适配器
 */
class AlipayProvider implements PaymentProviderInterface
{
    /**
     * @param array<string, mixed> $config 支付总配置
     */
    public function __construct(
        protected string $channel,
        protected string $account = 'default',
        protected array $config = []
    ) {
        $this->assertConfig();
        $this->boot();
    }

    /**
     * 发起支付宝支付
     *
     * 支持场景：web、h5、app、mini、scan、pos。
     *
     * @param array<string, mixed> $order 支付参数
     */
    public function pay(string $scene, array $order): mixed
    {
        $this->assertScene($scene);

        return $this->normalize(Pay::alipay()->{$scene}($this->withAccount($order)));
    }

    /**
     * 查询支付宝支付订单
     *
     * @param array<string, mixed>|string $order 查询参数或商户订单号
     * @return array<string, mixed>
     */
    public function query(array|string $order): array
    {
        $order = is_array($order) ? $order : ['out_trade_no' => $order];

        return $this->toArray(Pay::alipay()->query($this->withAccount($order)));
    }

    /**
     * 关闭支付宝支付订单
     *
     * @param array<string, mixed>|string $order 关闭参数或商户订单号
     * @return array<string, mixed>
     */
    public function close(array|string $order): array
    {
        $order = is_array($order) ? $order : ['out_trade_no' => $order];

        return $this->toArray(Pay::alipay()->close($this->withAccount($order)));
    }

    /**
     * 发起支付宝退款
     *
     * @param array<string, mixed> $order 退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        return $this->toArray(Pay::alipay()->refund($this->withAccount($order)));
    }

    /**
     * 处理支付宝支付回调
     *
     * @param array<string, mixed>|string|null $contents 回调内容
     * @param array<string, mixed> $headers 请求头，支付宝普通回调暂不使用
     * @param array<string, mixed>|null $params 额外参数
     * @return array<string, mixed>
     */
    public function notify(array|string|null $contents = null, array $headers = [], ?array $params = null): array
    {
        if (is_string($contents)) {
            parse_str($contents, $contents);
        }

        return $this->toArray(Pay::alipay()->callback($contents, $this->withAccount($params ?? [])));
    }

    /**
     * 返回支付宝回调成功响应
     */
    public function success(): Response
    {
        return $this->toThinkResponse(Pay::alipay()->success());
    }

    /**
     * 初始化支付 SDK 配置
     */
    protected function boot(): void
    {
        Pay::config(array_merge($this->config, ['_force' => true]));
    }

    /**
     * 校验支付宝配置
     */
    protected function assertConfig(): void
    {
        if (empty($this->config['alipay'][$this->account])) {
            throw new InvalidArgumentException('支付宝支付配置不存在：' . $this->account);
        }
    }

    /**
     * 校验支付场景
     */
    protected function assertScene(string $scene): void
    {
        if (!in_array($scene, ['web', 'h5', 'app', 'mini', 'scan', 'pos'], true)) {
            throw new BadMethodCallException('支付宝支付不支持场景：' . $scene);
        }
    }

    /**
     * 为多应用配置补齐 _config
     *
     * @param array<string, mixed> $payload 原始参数
     * @return array<string, mixed>
     */
    protected function withAccount(array $payload): array
    {
        return $payload + ['_config' => $this->account];
    }

    /**
     * 标准化 SDK 返回值
     */
    protected function normalize(mixed $result): mixed
    {
        if ($result instanceof ResponseInterface) {
            return $this->toThinkResponse($result);
        }

        return $this->toArray($result);
    }

    /**
     * 转换为数组
     *
     * @return array<string, mixed>
     */
    protected function toArray(mixed $result): array
    {
        if ($result instanceof Collection || $result instanceof Rocket) {
            return $result->toArray();
        }

        if ($result instanceof ResponseInterface) {
            return [
                'status' => $result->getStatusCode(),
                'headers' => $result->getHeaders(),
                'body' => (string) $result->getBody(),
            ];
        }

        return is_array($result) ? $result : ['data' => $result];
    }

    /**
     * PSR-7 响应转 ThinkPHP 响应
     */
    protected function toThinkResponse(ResponseInterface $response): Response
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(',', $values);
        }

        return Response::create((string) $response->getBody(), 'html', $response->getStatusCode())
            ->header($headers);
    }
}
