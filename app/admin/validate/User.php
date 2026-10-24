<?php

namespace app\admin\validate;

use think\Validate;

class User extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'username' => 'require|regex:^\w{3,32}$|unique:user',
        'nickname' => 'require|unique:user',
        'password' => 'regex:^\S{6,32}$',
        'email'    => 'require|email|unique:user',
        'mobile'   => 'unique:user',
        'status'   => 'in:normal,hidden',
    ];

    /**
     * 字段描述
     */
    protected $field = [
    ];
    /**
     * 提示消息
     */
    protected $message = [
    ];
    /**
     * 验证场景
     */
    protected $scene = [
        'add'  => [],
        'edit' => ['username', 'email', 'nickname', 'password', 'mobile'],
    ];

    /**
     * 新建账户必须设置密码，编辑仍允许留空以保持原密码。
     */
    public function sceneAdd()
    {
        return $this->only(['username', 'nickname', 'password', 'email', 'mobile', 'status'])->append('password', 'require');
    }

    public function __construct()
    {
        $this->field = [
            'username' => __('Username'),
            'nickname' => __('Nickname'),
            'password' => __('Password'),
            'email'    => __('Email'),
            'mobile'   => __('Mobile'),
        ];
        parent::__construct();
    }
}
