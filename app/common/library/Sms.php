<?php

namespace app\common\library;

use think\facade\Db;
use think\facade\Event;
use util\Random;

/**
 * 短信验证码类
 */
class Sms
{

    /**
     * 验证码有效时长
     * @var int
     */
    protected static $expire = 120;

    /**
     * 最大允许检测的次数
     * @var int
     */
    protected static $maxCheckNums = 10;

    /**
     * 获取最后一次手机发送的数据
     *
     * @param   int    $mobile 手机号
     * @param   string $event  事件
     * @return  Sms
     */
    public static function get($mobile, $event = 'default')
    {
        $sms = \app\common\model\Sms::where(['mobile' => $mobile, 'event' => $event])
            ->order('id', 'DESC')
            ->find();
        Event::trigger('sms_get', $sms, true);
        return $sms ?: null;
    }

    /**
     * 发送验证码
     *
     * @param   int    $mobile 手机号
     * @param   int    $code   验证码,为空时自动生成
     * @param   string $event  事件
     * @return  boolean
     */
    public static function send($mobile, $code = null, $event = 'default')
    {
        $connection = Db::connect();
        $scope = $connection->getConfig('database') . ':' . $connection->getConfig('prefix');
        $locks = [];
        try {
            // 使用同一主库连接占用发送名额，跨进程保护 IP 配额和接收地址的发送间隔。
            foreach ([request()->ip(), strtolower((string)$mobile) . ':' . $event] as $identity) {
                $key = 'rocket:sms:' . sha1($scope . ':' . $identity);
                $result = $connection->query('SELECT GET_LOCK(?, 5) AS acquired', [$key], true);
                if ((int)($result[0]['acquired'] ?? 0) !== 1) {
                    return false;
                }
                $locks[] = $key;
            }
            $last = self::get($mobile, $event);
            if (($last && time() - $last['createtime'] < 60)
                || \app\common\model\Sms::where('ip', request()->ip())->where('createtime', '>=', time() - 3600)->count() >= 5) {
                return false;
            }
            $code = is_null($code) ? Random::numeric((int)config('rocket.sms_captcha_length') ?: 6) : $code;
            $time = time();
            $ip = request()->ip();
            $sms = \app\common\model\Sms::create(['event' => $event, 'mobile' => $mobile, 'code' => $code, 'ip' => $ip, 'createtime' => $time]);
        } finally {
            foreach (array_reverse($locks) as $key) {
                $connection->query('SELECT RELEASE_LOCK(?)', [$key], true);
            }
        }
        $result = Event::trigger('sms_send', $sms, true);
        if (!$result) {
            $sms->delete();
            return false;
        }
        return true;
    }

    /**
     * 发送通知
     *
     * @param   mixed  $mobile   手机号,多个以,分隔
     * @param   string $msg      消息内容
     * @param   string $template 消息模板
     * @return  boolean
     */
    public static function notice($mobile, $msg = '', $template = null)
    {
        $params = [
            'mobile'   => $mobile,
            'msg'      => $msg,
            'template' => $template
        ];
        $result = Event::trigger('sms_notice', $params, true);
        return (bool)$result;
    }

    /**
     * 校验验证码
     *
     * @param   int     $mobile 手机号
     * @param   int     $code   验证码
     * @param   string  $event  事件
     * @param   boolean $flush  校验成功是否删除验证码
     * @return  boolean
     */
    public static function check($mobile, $code, $event = 'default', $flush = false)
    {
        if (!$mobile || !$code) {
            return false;
        }
        $expireTime = time() - self::$expire;
        //事务加行锁，避免同一验证码被并发重复校验
        Db::startTrans();
        try {
            $sms = \app\common\model\Sms::where(['mobile' => $mobile, 'event' => $event])
                ->order('id', 'DESC')
                ->lock(true)
                ->find();
            if (!$sms) {
                Db::rollback();
                return false;
            }
            //过期则清空该手机验证码
            if ($sms['createtime'] <= $expireTime) {
                self::flush($mobile, $event);
                Db::commit();
                return false;
            }
            if ($sms['times'] >= self::$maxCheckNums) {
                Db::rollback();
                return false;
            }
            //无论校验成功与否均计数，避免并发绕过次数上限
            \app\common\model\Sms::where('id', $sms['id'])->inc('times')->update();
            if ($code != $sms['code']) {
                Db::commit();
                return false;
            }
            if ($flush) {
                self::flush($mobile, $event);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
        Event::trigger('sms_check', $sms);
        return true;
    }

    /**
     * 清空指定手机号验证码
     *
     * @param   int    $mobile 手机号
     * @param   string $event  事件
     * @return  boolean
     */
    public static function flush($mobile, $event = 'default')
    {
        \app\common\model\Sms::where(['mobile' => $mobile, 'event' => $event])
            ->delete();
        Event::trigger('sms_flush');
        return true;
    }
}
