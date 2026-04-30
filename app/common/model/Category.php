<?php

namespace app\common\model;

use think\Model;

/**
 * 分类模型.
 */
class Category extends Model
{
    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    // 追加属性
    protected $append = [
        'type_text',
        'flag_text',
    ];

    protected static function onAfterInsert($row)
    {
        if (!$row['weigh']) {
            $row->save(['weigh' => $row['id']]);
        }
    }

    public function setFlagAttr($value, $data)
    {
        return is_array($value) ? implode(',', $value) : $value;
    }

    /**
     * 读取分类类型.
     *
     * @return array
     */
    public static function getTypeList()
    {
        $typeList = (array)config('site.categorytype');
        foreach ($typeList as $k => &$v) {
            $v = __($v);
        }
        unset($v); // 解除引用，防止后续代码意外修改数组

        return $typeList;
    }

    public function getTypeTextAttr($value, $data)
    {
        $value = $value ?: $data['type'];
        $list = $this->getTypeList();
        return $list[$value] ?? '';
    }

    public function getFlagList()
    {
        return ['hot' => __('Hot'), 'index' => __('Index'), 'recommend' => __('Recommend')];
    }

    public function getFlagTextAttr($value, $data)
    {
        $value = $value ?: ($data['flag'] ?? '');
        $valueArr = explode(',', $value);
        $list = $this->getFlagList();

        return implode(',', array_intersect_key($list, array_flip($valueArr)));
    }

    /**
     * 读取分类列表.
     *
     * @param string $type   指定类型
     * @param string $status 指定状态
     *
     * @return array
     */
    public static function getCategoryArray($type = null, $status = null)
    {
        $list = self::where(function ($query) use ($type, $status) {
            if ($type !== null) {
                $query->where('type', '=', $type);
            }
            if ($status !== null) {
                $query->where('status', '=', $status);
            }
        })->order('weigh', 'desc')->select()->toArray();

        return $list;
    }
}
