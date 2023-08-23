<?php

namespace app\index\validate;

use think\Validate;

class User extends Validate
{
    public function __construct()
    {
        $this->field = [
            'account' => __('Account'),
            'username' => __('Username'),
            'password' => __('Password'),
            'email'    => __('Email'),
            'mobile'   => __('Mobile'),
            'oldpassword'   => __('Oldpassword'),
            'newpassword'   => __('Newpassword'),
            'renewpassword'   => __('Renewpassword'),
        ];
        parent::__construct();
    }

    /**
     * 验证规则
     */
    protected $rule = [
        'account' => 'require|length:3,50',
        'username' => 'require|length:3,30|unique:user',
        'email'    => 'require|email|unique:user',
        'mobile'   => 'require|mobile|unique:user',
        'password' => 'require|length:6,30|token:',
        'oldpassword'   => 'require|regex:\S{6,30}|token:',
        'newpassword'   => 'require|regex:\S{6,30}',
        'renewpassword'   => 'require|regex:\S{6,30}|confirm:newpassword',
    ];

    /**
     * 字段描述
     */
    protected $field = [];

    /**
     * 提示消息
     */
    protected $message = [
        'account.require'  => 'Account can not be empty',
        'account.length'   => 'Account must be 3 to 50 characters',
        'username.require' => 'Username can not be empty',
        'username.length'  => 'Username must be 3 to 30 characters',
        'password.require' => 'Password can not be empty',
        'password.length'  => 'Password must be 6 to 30 characters',
        'email'            => 'Email is incorrect',
        'mobile'           => 'Mobile is incorrect',
        'renewpassword.confirm' => 'Password and confirm password don\'t match'
    ];
    /**
     * 验证场景
     */
    protected $scene = [
        'login'  => ['account', 'password'],
        'register' => ['username', 'password', 'email', 'mobile'],
        'changepwd' => ['oldpassword', 'newpassword', 'renewpassword'],
    ];
}
