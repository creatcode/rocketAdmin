<?php

declare(strict_types=1);

namespace kernel\services\wechat\support;

use Closure;
use EasyWeChat\Kernel\Message;
use EasyWeChat\Pay\Application;
use think\Response;

/**
 * 微信支付服务
 */
class WechatPayService
{
    protected Application $app;

    protected array $config;

    protected ?Closure $paidHandler = null;

    protected ?Closure $refundedHandler = null;

    protected bool $serverRegistered = false;

    public function __construct(protected string $name = 'default')
    {
        $this->config = WechatConfig::pay($name);
        $this->app = new Application($this->config);
    }

    /**
     * 获取 EasyWeChat 支付应用实例
     *
     * @return Application
     */
    public function app(): Application
    {
        return $this->app;
    }

    /**
     * 获取支付 HTTP 客户端
     *
     * @return mixed
     */
    public function client(): mixed
    {
        return $this->app->getClient();
    }

    /**
     * JSAPI 下单，公众号和小程序支付都可以使用
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function createJsapiOrder(array $order): array
    {
        return $this->client()->postJson('/v3/pay/transactions/jsapi', $this->withNotifyUrl($order))->toArray(false);
    }

    /**
     * 小程序下单并返回前端调起支付参数
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function createMiniAppOrder(array $order): array
    {
        $result = $this->createJsapiOrder($order);
        $prepayId = (string) ($result['prepay_id'] ?? '');

        return $this->buildMiniAppConfig($prepayId);
    }

    /**
     * Native 扫码支付下单
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function createNativeOrder(array $order): array
    {
        return $this->client()->postJson('/v3/pay/transactions/native', $this->withNotifyUrl($order))->toArray(false);
    }

    /**
     * H5 支付下单
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function createH5Order(array $order): array
    {
        return $this->client()->postJson('/v3/pay/transactions/h5', $this->withNotifyUrl($order))->toArray(false);
    }

    /**
     * App 支付下单
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    public function createAppOrder(array $order): array
    {
        return $this->client()->postJson('/v3/pay/transactions/app', $this->withNotifyUrl($order))->toArray(false);
    }

    /**
     * 根据商户订单号查询订单
     *
     * @param string $outTradeNo 商户订单号
     * @return array<string, mixed>
     */
    public function queryOrderByOutTradeNo(string $outTradeNo): array
    {
        return $this->client()->get('/v3/pay/transactions/out-trade-no/' . $outTradeNo, [
            'mchid' => $this->merchantId(),
        ])->toArray(false);
    }

    /**
     * 根据微信支付订单号查询订单
     *
     * @param string $transactionId 微信支付订单号
     * @return array<string, mixed>
     */
    public function queryOrderByTransactionId(string $transactionId): array
    {
        return $this->client()->get('/v3/pay/transactions/id/' . $transactionId, [
            'mchid' => $this->merchantId(),
        ])->toArray(false);
    }

    /**
     * 关闭订单
     *
     * @param string $outTradeNo 商户订单号
     * @return array<string, mixed>
     */
    public function closeOrder(string $outTradeNo): array
    {
        return $this->client()->postJson('/v3/pay/transactions/out-trade-no/' . $outTradeNo . '/close', [
            'mchid' => $this->merchantId(),
        ])->toArray(false);
    }

    /**
     * 申请退款
     *
     * @param array<string, mixed> $refund 退款参数
     * @return array<string, mixed>
     */
    public function refund(array $refund): array
    {
        if (!isset($refund['notify_url']) && !empty($this->config['refund_notify_url'])) {
            $refund['notify_url'] = $this->config['refund_notify_url'];
        }

        return $this->client()->postJson('/v3/refund/domestic/refunds', $refund)->toArray(false);
    }

    /**
     * 根据商户退款单号查询退款
     *
     * @param string $outRefundNo 商户退款单号
     * @return array<string, mixed>
     */
    public function queryRefund(string $outRefundNo): array
    {
        return $this->client()->get('/v3/refund/domestic/refunds/' . $outRefundNo)->toArray(false);
    }

    /**
     * 构建公众号 JSAPI 支付参数
     *
     * @param string $prepayId 预支付交易会话 ID
     * @param string|null $appId 公众号 appid
     * @return array<string, mixed>
     */
    public function buildBridgeConfig(string $prepayId, ?string $appId = null): array
    {
        return $this->app->getUtils()->buildBridgeConfig($prepayId, $appId ?: $this->appId());
    }

    /**
     * 构建公众号 JSSDK 支付参数
     *
     * @param string $prepayId 预支付交易会话 ID
     * @param string|null $appId 公众号 appid
     * @return array<string, mixed>
     */
    public function buildSdkConfig(string $prepayId, ?string $appId = null): array
    {
        return $this->app->getUtils()->buildSdkConfig($prepayId, $appId ?: $this->appId());
    }

    /**
     * 构建小程序支付参数
     *
     * @param string $prepayId 预支付交易会话 ID
     * @param string|null $appId 小程序 appid
     * @return array<string, mixed>
     */
    public function buildMiniAppConfig(string $prepayId, ?string $appId = null): array
    {
        return $this->app->getUtils()->buildMiniAppConfig($prepayId, $appId ?: $this->miniAppId());
    }

    /**
     * 设置支付成功回调处理器
     *
     * @param callable $handler 处理器
     * @return $this
     */
    public function onPaid(callable $handler): static
    {
        $this->paidHandler = Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 设置退款回调处理器
     *
     * @param callable $handler 处理器
     * @return $this
     */
    public function onRefunded(callable $handler): static
    {
        $this->refundedHandler = Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 处理微信支付回调
     *
     * @return Response
     */
    public function serve(): Response
    {
        $server = $this->app->getServer();

        if (!$this->serverRegistered) {
            $server->handlePaid(function (Message $message) {
                if ($this->paidHandler !== null) {
                    return ($this->paidHandler)($message);
                }

                return null;
            });

            $server->handleRefunded(function (Message $message) {
                if ($this->refundedHandler !== null) {
                    return ($this->refundedHandler)($message);
                }

                return null;
            });

            $this->serverRegistered = true;
        }

        return WechatResponse::toThink($server->serve());
    }

    /**
     * 补齐默认支付回调地址
     *
     * @param array<string, mixed> $order 订单参数
     * @return array<string, mixed>
     */
    protected function withNotifyUrl(array $order): array
    {
        if (!isset($order['notify_url']) && !empty($this->config['notify_url'])) {
            $order['notify_url'] = $this->config['notify_url'];
        }

        return $order;
    }

    /**
     * 获取商户号
     *
     * @return string
     */
    protected function merchantId(): string
    {
        return (string) ($this->config['mch_id'] ?? '');
    }

    /**
     * 获取公众号 appid
     *
     * @return string
     */
    protected function appId(): string
    {
        return (string) ($this->config['app_id'] ?? '');
    }

    /**
     * 获取小程序 appid
     *
     * @return string
     */
    protected function miniAppId(): string
    {
        return (string) ($this->config['mini_app_id'] ?? $this->appId());
    }
}
