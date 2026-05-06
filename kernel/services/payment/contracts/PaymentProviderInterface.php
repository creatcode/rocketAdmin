<?php

declare(strict_types=1);

namespace kernel\services\payment\contracts;

use think\Response;

/**
 * 支付渠道适配器接口
 *
 * 每个支付服务商独立实现这个接口，业务层只面向这组稳定方法。
 */
interface PaymentProviderInterface
{
    /**
     * 发起支付
     *
     * @param array<string, mixed> $order 支付参数
     */
    public function pay(string $scene, array $order): mixed;

    /**
     * 查询支付订单
     *
     * @param array<string, mixed>|string $order 查询参数或商户订单号
     * @return array<string, mixed>
     */
    public function query(array|string $order): array;

    /**
     * 关闭支付订单
     *
     * @param array<string, mixed>|string $order 关闭参数或商户订单号
     * @return array<string, mixed>
     */
    public function close(array|string $order): array;

    /**
     * 发起退款
     *
     * @param array<string, mixed> $order 退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array;

    /**
     * 处理并验证支付回调
     *
     * @param array<string, mixed>|string|null $contents 回调内容
     * @param array<string, mixed> $headers 请求头
     * @param array<string, mixed>|null $params 额外参数
     * @return array<string, mixed>
     */
    public function notify(array|string|null $contents = null, array $headers = [], ?array $params = null): array;

    /**
     * 返回支付平台要求的成功响应
     */
    public function success(): Response;
}
