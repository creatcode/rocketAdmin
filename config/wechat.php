<?php

return [
    // 公众号配置，支持按名称配置多个账号
    'official_account' => [
        'default' => [
            'app_id' => env('WECHAT_OFFICIAL_APP_ID', ''),
            'secret' => env('WECHAT_OFFICIAL_SECRET', ''),
            'token' => env('WECHAT_OFFICIAL_TOKEN', ''),
            'aes_key' => env('WECHAT_OFFICIAL_AES_KEY', ''),
        ],
    ],

    // 小程序配置，支持按名称配置多个小程序
    'mini_app' => [
        'default' => [
            'app_id' => env('WECHAT_MINI_APP_ID', ''),
            'secret' => env('WECHAT_MINI_SECRET', ''),
            'token' => env('WECHAT_MINI_TOKEN', ''),
            'aes_key' => env('WECHAT_MINI_AES_KEY', ''),
        ],
    ],

    // 微信支付配置，支持按名称配置多个商户
    'pay' => [
        'default' => [
            'mch_id' => env('WECHAT_PAY_MCH_ID', ''),
            'secret_key' => env('WECHAT_PAY_SECRET_KEY', ''), // API v3 密钥
            'v2_secret_key' => env('WECHAT_PAY_V2_SECRET_KEY', ''), // API v2 密钥，兼容旧接口或旧回调
            'private_key' => env('WECHAT_PAY_PRIVATE_KEY', ''), // 商户私钥内容，或 file:// 开头的证书路径
            'certificate' => env('WECHAT_PAY_CERTIFICATE', ''), // 商户证书内容，或 file:// 开头的证书路径
            'platform_certs' => [
                // 'PUB_KEY_ID_xxx' => env('WECHAT_PAY_PLATFORM_CERT', ''),
            ],
            'notify_url' => env('WECHAT_PAY_NOTIFY_URL', ''),
            'refund_notify_url' => env('WECHAT_PAY_REFUND_NOTIFY_URL', ''),
            'app_id' => env('WECHAT_PAY_APP_ID', env('WECHAT_OFFICIAL_APP_ID', '')),
            'mini_app_id' => env('WECHAT_PAY_MINI_APP_ID', env('WECHAT_MINI_APP_ID', '')),
        ],
    ],

    // EasyWeChat 公共配置
    'common' => [
        'response_type' => 'array',
        'http' => [
            'timeout' => 10.0, // 请求超时时间，单位：秒
            'verify' => (bool) env('WECHAT_HTTP_VERIFY', false),
            'throw' => true,
            'retry' => true,
            'max_retries' => 2,
        ],
        'log' => [
            'default' => env('APP_DEBUG') ? 'dev' : 'prod',
            'channels' => [
                'dev' => [
                    'driver' => 'single',
                    'path' => root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'easywechat.log',
                    'level' => 'debug',
                ],
                'prod' => [
                    'driver' => 'daily',
                    'path' => root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'easywechat.log',
                    'level' => 'info',
                ],
            ],
        ],
    ],
];
