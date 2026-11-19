<?php
// +----------------------------------------------------------------------
// | 会话设置
// +----------------------------------------------------------------------

return [
    // session name
    'name'           => 'PHPSESSID',
    // SESSION_ID的提交变量,解决flash上传跨域
    'var_session_id' => '',
    // 驱动方式 支持file cache
    'type'           => 'file',
    //各应用共用同一 Cookie 和存储，重建会话时保留其他应用的登录身份。
    'path'           => root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'session',
    // 存储连接标识 当type使用cache的时候有效
    'store'          => null,
    // 过期时间
    'expire'         => 86400,
    // 前缀
    'prefix'         => '',
];
