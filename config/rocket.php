<?php


return [
    //是否开启前台会员中心
    'usercenter'            => true,
    //会员注册验证码类型email/mobile/wechat/text/false
    'user_register_captcha' => 'text',
    //登录验证码
    'login_captcha'         => false,
    //登录失败超过10次则1天后重试
    'login_failure_retry'   => true,
    //是否同一账号同一时间只能在一个地方登录
    'login_unique'          => false,
    //是否开启IP变动检测
    'loginip_check'         => false,
    //登录页默认背景图
    'login_background'      => "",
    //是否启用多级菜单导航
    'multiplenav'           => false,
    //是否开启多选项卡(仅在开启多级菜单时起作用)
    'multipletab'           => true,
    //是否默认展示子菜单
    'show_submenu'          => false,
    //后台皮肤,为空时表示使用skin-white
    'adminskin'             => '',
    //后台是否启用面包屑
    'breadcrumb'            => true,
    //后台是否显示多语言切换开关
    'lang_switch' => true,
    //是否开启后台自动日志记录
    'auto_record_log'       => true,
    //允许跨域的域名,多个以,分隔
    'cors_request_domain'   => 'localhost,127.0.0.1',
    //版本号
    'version'               => '1.6.1.20250430',
    //插件市场接口地址（插件包读取此配置）
    'addon_market_api_url'  => 'https://api.fastadmin.net',
];
