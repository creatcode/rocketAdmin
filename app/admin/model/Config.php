<?php
declare (strict_types = 1);

namespace app\admin\model;

use think\Model;

/**
 * @mixin \think\Model
 */
class Config extends Model
{
    public static $cacheTag = 'sys_config';
}
