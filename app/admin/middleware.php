<?php
// 这是系统自动生成的middleware定义文件

return [
    // 多级控制器斜杠URL兼容（general/attachment/select → general.attachment/select）
    \app\admin\middleware\MultiLevelControllerUrl::class,
    \think\middleware\AllowCrossDomain::class,
    // Session初始化
    \think\middleware\SessionInit::class,
    // 多语言加载
    \think\middleware\LoadLangPack::class,
    // 系统初始化
    app\common\middleware\CommonInit::class,
    app\admin\middleware\AdminLog::class
];
