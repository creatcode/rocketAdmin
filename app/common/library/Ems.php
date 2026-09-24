<?php

namespace app\common\library;

use think\facade\Db;
use think\facade\Event;
use util\Random;

/**
 * 邮箱验证码类
 */
class Ems
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
     * 获取最后一次邮箱发送的数据
     *
     * @param int    $email 邮箱
     * @param string $event 事件
     * @return  Ems|null
     */
    public static function get($email, $event = 'default')
    {
        $ems = \app\common\model\Ems::where(['email' => $email, 'event' => $event])
            ->order('id', 'DESC')
            ->find();
        Event::trigger('ems_get', $ems, true);
        return $ems ?: null;
    }

    /**
     * 发送验证码
     *
     * @param int    $email 邮箱
     * @param int    $code  验证码,为空时自动生成
     * @param string $event 事件
     * @return  boolean
     */
    public static function send($email, $code = null, $event = 'default')
    {
        $code = is_null($code) ? Random::numeric((int)config('rocket.sms_captcha_length') ?: 6) : $code;
        $time = time();
        $ip = request()->ip();
        $ems = \app\common\model\Ems::create(['event' => $event, 'email' => $email, 'code' => $code, 'ip' => $ip, 'createtime' => $time]);
        if (!Event::hasListener('ems_send')) {
            //采用框架默认的邮件推送
            Event::listen('ems_send', function ($params) {
                $obj = new Email();
                $result = $obj
                    ->to($params->email)
                    ->subject(__('Please check your verification code'))
                    ->message(__('Your verification code is: %s, valid for %d minutes.', $params->code, ceil(self::$expire / 60)))
                    ->send();
                return $result;
            });
        }
        $result = Event::trigger('ems_send', $ems, true);
        if (!$result) {
            $ems->delete();
            return false;
        }
        return true;
    }

    /**
     * 发送通知
     *
     * @param mixed  $email    邮箱,多个以,分隔
     * @param string $msg      消息内容
     * @param string $template 消息模板
     * @return  boolean
     */
    public static function notice($email, $msg = '', $template = null)
    {
        $params = [
            'email'    => $email,
            'msg'      => $msg,
            'template' => $template
        ];
        if (!Event::hasListener('ems_notice')) {
            //采用框架默认的邮件推送
            Event::listen('ems_notice', function ($params) {
                $subject = '你收到一封新的邮件！';
                $content = $params['msg'];
                $email = new Email();
                $result = $email->to($params['email'])
                    ->subject($subject)
                    ->message($content)
                    ->send();
                return $result;
            });
        }
        $result = Event::trigger('ems_notice', $params, true);
        return (bool)$result;
    }

    /**
     * 校验验证码
     *
     * @param int     $email 邮箱
     * @param int     $code  验证码
     * @param string  $event 事件
     * @param boolean $flush 校验成功是否删除验证码
     * @return  boolean
     */
    public static function check($email, $code, $event = 'default', $flush = false)
    {
        if (!$email || !$code) {
            return false;
        }
        $expireTime = time() - self::$expire;
        //事务加行锁，避免同一验证码被并发重复校验
        Db::startTrans();
        try {
            $ems = \app\common\model\Ems::where(['email' => $email, 'event' => $event])
                ->order('id', 'DESC')
                ->lock(true)
                ->find();
            if (!$ems) {
                Db::rollback();
                return false;
            }
            //过期则清空该邮箱验证码
            if ($ems['createtime'] <= $expireTime) {
                self::flush($email, $event);
                Db::commit();
                return false;
            }
            if ($ems['times'] >= self::$maxCheckNums) {
                Db::rollback();
                return false;
            }
            //无论校验成功与否均计数，避免并发绕过次数上限
            \app\common\model\Ems::where('id', $ems['id'])->inc('times')->update();
            if ($code != $ems['code']) {
                Db::commit();
                return false;
            }
            if ($flush) {
                self::flush($email, $event);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
        Event::trigger('ems_check', $ems, true);
        return true;
    }

    /**
     * 清空指定邮箱验证码
     *
     * @param int    $email 邮箱
     * @param string $event 事件
     * @return  boolean
     */
    public static function flush($email, $event = 'default')
    {
        \app\common\model\Ems::where(['email' => $email, 'event' => $event])
            ->delete();
        Event::trigger('ems_flush');
        return true;
    }
}
