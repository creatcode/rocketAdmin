<?php

return [
    // 驱动方式
    'type'     => 'Mysql',
    // 缓存前缀
    'key'      => env('token.key', 'i3d6o32wo8fvs1fvdpwens'),
    // 加密方式
    'hashalgo' => 'ripemd160',
    // Token有效期，单位秒，0表示永久；默认30天
    'expire'   => env('token.expire', 2592000),
    // 单个Token允许设置的最大有效期，单位秒，0表示不限制；默认30天
    'max_expire' => env('token.max_expire', 2592000),
];
