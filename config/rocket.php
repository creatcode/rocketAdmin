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
    //后台皮肤,为空时表示使用skin-black-blue
    'adminskin'             => '',
    //后台是否启用面包屑
    'breadcrumb'            => true,
    //是否允许未知来源的插件压缩包
    'unknownsources'        => true,
    //插件启用禁用时是否备份对应的全局文件
    'backup_global_files'   => true,
    //后台是否显示多语言切换开关
    'lang_switch' => true,
    //是否开启后台自动日志记录
    'auto_record_log'       => true,
    //插件纯净模式，插件启用后是否删除插件目录的application、public和assets文件夹
    'addon_pure_mode'       => true,
    //插件压缩包授权验证
    'addon_auth_check'       => false,
    //允许跨域的域名,多个以,分隔
    'cors_request_domain'   => 'localhost,127.0.0.1',
    //版本号
    'version'               => '1.6.1.20250430',
    //API接口地址
    'api_url'               => 'https://api.fastadmin.net',
];
