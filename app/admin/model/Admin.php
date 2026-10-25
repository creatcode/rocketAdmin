<?php

namespace app\admin\model;

use think\Model;

class Admin extends Model
{

    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $type = ['createtime' => 'integer', 'updatetime' => 'integer'];

    public static function onBeforeWrite(Model $row)
    {
        $changed = $row->getChangedData();
        // 账号凭据变更或禁用时撤销自动登录凭据。
        if (isset($changed['username']) || isset($changed['password']) || isset($changed['salt'])
            || (isset($changed['status']) && $changed['status'] !== 'normal')) {
            $row->token = '';
        }
    }
}
