<?php

namespace app\admin\model;

use app\common\library\Token;
use app\common\model\MoneyLog;
use app\common\model\ScoreLog;
use think\Model;
use util\Random;

class User extends Model
{
    // 表名
    protected $name = 'user';
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    // 追加属性
    protected $append = [
        'prevtime_text',
        'logintime_text',
        'jointime_text',
    ];

    public function getOriginData()
    {
        return $this->origin;
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

    public function getGenderList()
    {
        return ['1' => __('Male'), '0' => __('Female')];
    }

    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden')];
    }

    public function getPrevtimeTextAttr($value, $data)
    {
        return $this->formatDateTimeAttr($value, $data['prevtime'] ?? null);
    }

    public function getLogintimeTextAttr($value, $data)
    {
        return $this->formatDateTimeAttr($value, $data['logintime'] ?? null);
    }

    public function getJointimeTextAttr($value, $data)
    {
        return $this->formatDateTimeAttr($value, $data['jointime'] ?? null);
    }

    protected function setPrevtimeAttr($value)
    {
        return $this->parseDateTimeAttr($value);
    }

    protected function setLogintimeAttr($value)
    {
        return $this->parseDateTimeAttr($value);
    }

    protected function setJointimeAttr($value)
    {
        return $this->parseDateTimeAttr($value);
    }

    /**
     * 格式化日期时间获取器
     * @param mixed $value
     * @param mixed $fallback
     * @return string
     */
    protected function formatDateTimeAttr($value, $fallback)
    {
        $value = $value ?: $fallback;

        return is_numeric($value) ? date('Y-m-d H:i:s', $value) : $value;
    }

    /**
     * 解析日期时间修改器
     * @param mixed $value
     * @return int|mixed
     */
    protected function parseDateTimeAttr($value)
    {
        return $value && !is_numeric($value) ? strtotime($value) : $value;
    }

    public function group()
    {
        return $this->belongsTo('UserGroup', 'group_id', 'id')->joinType('LEFT');
    }
}
