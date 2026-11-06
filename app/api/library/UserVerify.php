<?php

namespace app\api\library;

use app\common\model\User;
use think\facade\Validate;

/**
 * 会员账号字段与事件校验
 *
 * 供短信、邮件等接口复用，避免同一套正则与占用判断散落在多个控制器。
 */
class UserVerify
{
    public static function isMobile($mobile): bool
    {
        return Validate::regex($mobile, '/^1\d{10}$/');
    }

    public static function isEmail($email): bool
    {
        return is_scalar($email) && Validate::is($email, 'email');
    }

    public static function isEvent($event): bool
    {
        return is_scalar($event) && preg_match('/^[a-z0-9_\-]{3,30}$/i', (string)$event) === 1;
    }

    /**
     * 手机号占用/存在性校验，通过返回 null。
     */
    public static function checkMobileEvent(string $mobile, string $event): ?string
    {
        $user = User::getByMobile($mobile);
        if ($event === 'register' && $user) {
            return __('已被注册');
        }
        if ($event === 'changemobile' && $user) {
            return __('已被占用');
        }
        if (in_array($event, ['changepwd', 'resetpwd'], true) && !$user) {
            return __('未注册');
        }

        return null;
    }

    /**
     * 邮箱占用/存在性校验，通过返回 null。
     */
    public static function checkEmailEvent(string $email, string $event): ?string
    {
        $user = User::getByEmail($email);
        if ($event === 'register' && $user) {
            return __('已被注册');
        }
        if ($event === 'changeemail' && $user) {
            return __('已被占用');
        }
        if (in_array($event, ['changepwd', 'resetpwd'], true) && !$user) {
            return __('未注册');
        }

        return null;
    }
}
