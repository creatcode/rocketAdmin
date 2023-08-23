<?php

return [
    'protocol'       => 'websocket', // 协议 支持 tcp udp unix http websocket text
    'host'           => '0.0.0.0', // 监听地址
    'port'           => 2347, // 监听端口
    'context'        => [], // socket 上下文选项 可配置wss
    'worker_class'   => 'app\admin\library\Event', // 自定义Workerman服务类名 支持数组定义多个服务

    // 支持workerman的所有配置参数
    'name'           => 'thinkphp',
    'count'          => 4,
    'daemonize'      => false,
    'pidFile'        => '',
    'logFile'        => root_path() . 'runtime/workerman.log',

    // channel
    'channel' => [
        'enable' => true,
        'host' => '0.0.0.0',
        'port' => 2348,
    ],
];
