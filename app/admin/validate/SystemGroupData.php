<?php

// 原有注释中的 ConfigData、ConfigDataItem 为历史名称,现分别对应 SystemGroup、SystemGroupData。

namespace app\admin\validate;

use think\Validate;

/**
 * 组合数据记录验证器
 *
 * 只校验记录的管理字段,动态字段值由 \app\common\model\ConfigDataItem::normalizeValue 校验。
 */
class SystemGroupData extends Validate
{
    protected $rule = [
        'group_id' => 'require|integer|gt:0',
        'weigh'    => 'integer',
        'status'   => 'require|in:normal,hidden',
    ];

    protected $message = [
        'group_id.require' => '数据组不能为空',
        'group_id.integer' => '数据组不正确',
        'group_id.gt'      => '数据组不正确',
        'weigh.integer'    => '排序必须是整数',
        'status.require'   => '状态不能为空',
        'status.in'        => '状态值不正确',
    ];

    protected $scene = [
        'add'   => ['group_id', 'weigh', 'status'],
        'edit'  => ['weigh', 'status'],
        'multi' => ['status' => 'in:normal,hidden', 'weigh' => 'integer'],
    ];
}
