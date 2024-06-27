<?php

return [
    // 进程名称
    'name' => '',
    //tcp监听地址和端口，不带协议头
    'base_url' => '127.0.0.1:2350',
    //任务锁驱动 mysql，redis,不设置表示不开启
    'driver' => '',
    //安全秘钥，设置安全密钥需要在请求头或者参数附带key，
    'safe_key' => '',
    // 开启任务日志
    'log' => false
];
