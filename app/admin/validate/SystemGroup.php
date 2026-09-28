<?php

namespace app\admin\validate;

use think\Validate;

/**
 * 组合数据组验证器
 *
 * 只校验数据组的静态字段,字段定义与记录值分别由对应模型校验。
 */
class SystemGroup extends Validate
{
    protected $rule = [
        'name'   => 'require|regex:/^[a-z][a-z0-9_]{0,29}$/|unique:system_group',
        'title'  => 'require|max:100',
        'tip'    => 'max:255',
        'fields' => 'require',
        'weigh'  => 'integer',
        'status' => 'require|in:normal,hidden',
    ];

    protected $message = [
        'name.require'   => '标识不能为空',
        'name.regex'     => '标识必须以小写字母开头,只能包含小写字母、数字和下划线',
        'name.unique'    => '标识已存在',
        'title.require'  => '名称不能为空',
        'title.max'      => '名称最多100个字符',
        'tip.max'        => '说明最多255个字符',
        'fields.require' => '字段定义不能为空',
        'weigh.integer'  => '排序必须是整数',
        'status.require' => '状态不能为空',
        'status.in'      => '状态值不正确',
    ];

    protected $scene = [
        'add'   => ['name', 'title', 'tip', 'fields', 'weigh', 'status'],
        'edit'  => ['title', 'tip', 'fields', 'weigh', 'status'],
        'multi' => ['status' => 'in:normal,hidden', 'weigh' => 'integer'],
    ];
}
