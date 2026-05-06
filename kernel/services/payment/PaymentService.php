<?php

declare(strict_types=1);

namespace kernel\services\payment;

use BadMethodCallException;
use InvalidArgumentException;
use kernel\services\payment\contracts\PaymentProviderInterface;
use think\Response;
use think\facade\Config;

/**
 * 支付服务统一入口
 */
class PaymentService
{
    /**
     * 已创建的渠道实例
     *
     * @var array<string, PaymentProviderInterface>
     */
    protected static array $providers = [];

    /**
     * 运行时注册的渠道适配器
     *
     * @var array<string, class-string<PaymentProviderInterface>>
     */
    protected static array $extensions = [];

    public function __construct(
        protected string $channel,
        protected string $account = 'default'
    ) {
    }

    /**
     * 获取指定支付渠道实例
     */
    public static function channel(?string $channel = null, string $account = 'default'): static
    {
        $channel = $channel ?: (string) Config::get('payment.default', 'wechat');

        return new static($channel, $account);
    }

    /**
     * 动态注册支付渠道
     *
     * @param class-string<PaymentProviderInterface> $providerClass Provider 类名
     */
    public static function extend(string $channel, string $providerClass): void
    {
        if (!is_subclass_of($providerClass, PaymentProviderInterface::class)) {
            throw new InvalidArgumentException($providerClass . ' 必须实现 PaymentProviderInterface');
        }

        self::$extensions[$channel] = $providerClass;

        foreach (array_keys(self::$providers) as $key) {
            if (str_starts_with($key, $channel . ':')) {
                unset(self::$providers[$key]);
            }
        }
    }

    /**
     * 发起支付
     *
     * @param array<string, mixed> $order 支付参数
     */
    public function pay(string $scene, array $order): mixed
    {
        return $this->provider()->pay($scene, $order);
    }

    /**
     * 查询支付订单
     *
     * @param array<string, mixed>|string $order 查询参数或商户订单号
     * @return array<string, mixed>
     */
    public function query(array|string $order): array
    {
        return $this->provider()->query($order);
    }

    /**
     * 关闭支付订单
     *
     * @param array<string, mixed>|string $order 关闭参数或商户订单号
     * @return array<string, mixed>
     */
    public function close(array|string $order): array
    {
        return $this->provider()->close($order);
    }

    /**
     * 发起退款
     *
     * @param array<string, mixed> $order 退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        return $this->provider()->refund($order);
    }

    /**
     * 处理并验证支付回调
     *
     * @param array<string, mixed>|string|null $contents 回调内容
     * @param array<string, mixed> $headers 请求头
     * @param array<string, mixed>|null $params 额外参数
     * @return array<string, mixed>
     */
    public function notify(array|string|null $contents = null, array $headers = [], ?array $params = null): array
    {
        return $this->provider()->notify($contents, $headers, $params);
    }

    /**
     * 返回支付平台要求的成功响应
     */
    public function success(): Response
    {
        return $this->provider()->success();
    }

    /**
     * 获取当前渠道 Provider
     */
    protected function provider(): PaymentProviderInterface
    {
        $key = $this->channel . ':' . $this->account;
        if (isset(self::$providers[$key])) {
            return self::$providers[$key];
        }

        $providerClass = self::$extensions[$this->channel]
            ?? Config::get('payment.providers.' . $this->channel);
        if (!is_string($providerClass) || $providerClass === '') {
            throw new InvalidArgumentException('支付渠道未注册：' . $this->channel);
        }

        if (!is_subclass_of($providerClass, PaymentProviderInterface::class)) {
            throw new InvalidArgumentException($providerClass . ' 必须实现 PaymentProviderInterface');
        }

        $config = Config::get('payment.channels', []);
        if (!is_array($config)) {
            $config = [];
        }

        $provider = new $providerClass($this->channel, $this->account, $config);
        if (!$provider instanceof PaymentProviderInterface) {
            throw new BadMethodCallException($providerClass . ' 创建失败');
        }

        return self::$providers[$key] = $provider;
    }
}
