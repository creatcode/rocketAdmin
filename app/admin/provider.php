<?php

// 容器Provider定义文件

use app\admin\library\AdminExceptionHandle;

return [
    'think\exception\Handle' => AdminExceptionHandle::class,
];
