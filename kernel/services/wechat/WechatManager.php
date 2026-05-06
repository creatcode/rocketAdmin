<?php

declare(strict_types=1);

namespace kernel\services\wechat;

use kernel\services\wechat\support\MiniAppService;
use kernel\services\wechat\support\OfficialAccountService;
use kernel\services\wechat\support\WechatPayService;

/**
 * 微信服务管理器
 */
class WechatManager
{
    /**
     * @var array<string, OfficialAccountService>
     */
    protected static array $officialAccounts = [];

    /**
     * @var array<string, MiniAppService>
     */
    protected static array $miniApps = [];

    /**
     * @var array<string, WechatPayService>
     */
    protected static array $pays = [];

    /**
     * 获取公众号服务
     *
     * @param string $name 账号名称
     * @return OfficialAccountService
     */
    public static function officialAccount(string $name = 'default'): OfficialAccountService
    {
        return self::$officialAccounts[$name] ??= new OfficialAccountService($name);
    }

    /**
     * 获取小程序服务
     *
     * @param string $name 账号名称
     * @return MiniAppService
     */
    public static function miniApp(string $name = 'default'): MiniAppService
    {
        return self::$miniApps[$name] ??= new MiniAppService($name);
    }

    /**
     * 获取微信支付服务
     *
     * @param string $name 商户名称
     * @return WechatPayService
     */
    public static function pay(string $name = 'default'): WechatPayService
    {
        return self::$pays[$name] ??= new WechatPayService($name);
    }

    /**
     * 清理已缓存实例，适合配置热更新或测试场景
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$officialAccounts = [];
        self::$miniApps = [];
        self::$pays = [];
    }
}
