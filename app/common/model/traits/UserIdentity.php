<?php

namespace app\common\model\traits;

/**
 * 未绑定联系方式使用 NULL 参与唯一约束，对外仍保持空字符串。
 */
trait UserIdentity
{
    public function setEmailAttr($value)
    {
        return $value === '' ? null : $value;
    }

    public function setMobileAttr($value)
    {
        return $value === '' ? null : $value;
    }

    public function getEmailAttr($value)
    {
        return $value ?? '';
    }

    public function getMobileAttr($value)
    {
        return $value ?? '';
    }
}
