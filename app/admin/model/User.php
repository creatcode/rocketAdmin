<?php

namespace app\admin\model;

use app\common\library\Token;
use app\common\model\MoneyLog;
use app\common\model\ScoreLog;
use app\common\model\User as UserModel;
use util\Random;

/**
 * 后台会员模型
 *
 * 共用 common 会员模型的表定义与获取器，仅保留后台写入钩子和列表追加字段。
 */
class User extends UserModel
{
    // 追加属性
    protected $append = [
        'prevtime_text',
        'logintime_text',
        'jointime_text',
    ];

    /**
     * 新增会员使用与登录校验一致的加盐密码格式。
     */
    public static function onBeforeInsert($row)
    {
        $salt = Random::alnum();
        $row->password = \app\common\library\Auth::instance()->getEncryptPassword($row->password, $salt);
        $row->salt = $salt;
    }

    public static function onBeforeUpdate($row)
    {
        $changed = $row->getChangedData();
        $origin = $row->getOrigin();

        //如果有修改密码
        if (isset($changed['password'])) {
            if ($changed['password']) {
                $salt = Random::alnum();
                $row->password = \app\common\library\Auth::instance()->getEncryptPassword($changed['password'], $salt);
                $row->salt = $salt;
                Token::clear($row->id);
            } else {
                unset($row->password);
            }
        }

        // 会员被禁用时立即清理所有登录Token，避免旧Token继续占用有效会话
        if (isset($changed['status']) && $changed['status'] !== 'normal') {
            Token::clear($row->id);
        }

        if (isset($changed['money'])) {
            MoneyLog::create([
                'user_id' => $row['id'],
                'money'   => $changed['money'] - $origin['money'],
                'before'  => $origin['money'],
                'after'   => $changed['money'],
                'memo'    => '管理员变更金额',
            ]);
        }
        if (isset($changed['score'])) {
            ScoreLog::create([
                'user_id' => $row['id'],
                'score'  => $changed['score'] - $origin['score'],
                'before' => $origin['score'],
                'after'  => $changed['score'],
                'memo'   => '管理员变更积分',
            ]);
        }
    }

    // 编辑表单保留存储原值，避免将默认头像数据写回数据库。
    public function getAvatarAttr($value, $data)
    {
        return $value;
    }

    public function group()
    {
        return $this->belongsTo(\app\common\model\UserGroup::class, 'group_id', 'id')->joinType('LEFT');
    }

    /**
     * 后台沿用原始字符串，避免共享获取器改变列表输出结构。
     */
    public function getVerificationAttr($value, $data)
    {
        return $value;
    }
}
