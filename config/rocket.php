<?php


return [
    //是否开启前台会员中心
    'usercenter'            => true,
    //会员注册验证码类型email/mobile/wechat/text/false
    'user_register_captcha' => 'text',
    //登录验证码
    'login_captcha'         => false,
    //短信/邮箱验证码位数
    'sms_captcha_length'    => 6,
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
    //是否开启后台页面水印
    'watermark'             => false,
    //水印内容,支持占位符 {nickname} {username} {date} {time}
    'watermark_text'        => 'RocketAdmin {nickname} {date}',
    //无操作自动登出秒数,0 表示不启用(1800 = 30 分钟)
    'auto_logout'           => 0,
    //后台是否启用面包屑
    'breadcrumb'            => true,
    //后台是否显示多语言切换开关
    'lang_switch' => true,
    //是否开启后台自动日志记录
    'auto_record_log'       => true,
    //允许跨域的域名,多个以,分隔
    'cors_request_domain'   => 'localhost,127.0.0.1',
    //版本号
    'version'               => '1.6.1.20250430.0',
    //系统升级配置
    'upgrade'               => [
        //是否开放后台升级入口
        'enabled'          => true,
        //版本信息 JSON 地址
        'version_info_url' => '/upgrade.json',
        //保留最近 N 个批次,0 表示不清理
        'keep_batches'     => 5,
        //MySQL 备份客户端,不在 PATH 时填写完整路径
        'mysqldump_binary' => 'mysqldump',
        //MySQL 恢复客户端,不在 PATH 时填写完整路径
        'mysql_binary'     => 'mysql',
    ],
    //插件市场接口地址（插件包读取此配置）
    'addon_market_api_url'  => 'https://api.fastadmin.net',
];
