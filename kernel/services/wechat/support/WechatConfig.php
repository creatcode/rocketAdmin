<?php

declare(strict_types=1);

namespace kernel\services\wechat\support;

use InvalidArgumentException;
use think\facade\Config;

/**
 * 微信配置
 */
class WechatConfig
{
    /**
     * 获取公众号配置
     *
     * @param string $name 账号名称
     * @return array<string, mixed>
     */
    public static function officialAccount(string $name = 'default'): array
    {
        return self::account('official_account', $name);
    }

    /**
     * 获取小程序配置
     *
     * @param string $name 账号名称
     * @return array<string, mixed>
     */
    public static function miniApp(string $name = 'default'): array
    {
        return self::account('mini_app', $name);
    }

    /**
     * 获取微信支付配置
     *
     * @param string $name 商户名称
     * @return array<string, mixed>
     */
    public static function pay(string $name = 'default'): array
    {
        return self::account('pay', $name);
    }

    /**
     * 获取指定类型账号配置
     *
     * @param string $type 配置类型
     * @param string $name 账号名称
     * @return array<string, mixed>
     */
    protected static function account(string $type, string $name): array
    {
        $accounts = Config::get('wechat.' . $type, []);
        if (!is_array($accounts)) {
            $accounts = [];
        }

        if (!array_key_exists($name, $accounts)) {
            throw new InvalidArgumentException(sprintf('微信账号配置不存在：%s.%s', $type, $name));
        }

        $config = $accounts[$name];
        if (!is_array($config)) {
            throw new InvalidArgumentException(sprintf('微信账号配置格式错误：%s.%s', $type, $name));
        }

        return self::mergeCommon($config);
    }

    /**
     * 合并公共配置
     *
     * @param array<string, mixed> $config 账号配置
     * @return array<string, mixed>
     */
    protected static function mergeCommon(array $config): array
    {
        $common = Config::get('wechat.common', []);
        if (!is_array($common)) {
            $common = [];
        }

        return array_replace_recursive($common, $config);
    }
}
