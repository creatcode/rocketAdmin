<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

/**
 * 会员组模型
 *
 * @mixin \think\Model
 */
class UserGroup extends Model
{
    // 表名
    protected $name = 'user_group';
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    // 追加属性
    protected $append = [
        'status_text'
    ];

    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden')];
    }

    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: $data['status'];
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }
}
