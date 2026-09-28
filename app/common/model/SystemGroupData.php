<?php

namespace app\common\model;

use think\Exception;
use think\exception\ValidateException;
use think\Model;

/**
 * 组合数据记录模型
 */
class SystemGroupData extends Model
{
    protected $name = 'system_group_data';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $type = [
        'value' => 'json',
    ];
    protected $jsonAssoc = true;

    /**
     * 按字段定义校验记录值并补全缺省值
     *
     * @param array $fields 已规范化的字段定义
     * @param array $values 提交的字段值
     * @return array 与字段定义一致的记录值
     * @throws ValidateException
     */
    public static function normalizeValue($fields, $values)
    {
        if (!is_array($fields) || !$fields) {
            throw new ValidateException(__('Fields can not be empty'));
        }
        if (!is_array($values)) {
            throw new ValidateException(__('Data can not be empty'));
        }
        $result = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            $title = $field['title'];
            $type = $field['type'];
            $required = !empty($field['required']);
            $value = array_key_exists($name, $values) ? $values[$name] : null;
            if (is_array($value)) {
                throw new ValidateException(__('Value of field %s must be a scalar', $name));
            }
            $value = is_string($value) ? trim($value) : $value;
            // 0 和 false 是有效值，不能作为空值处理
            $empty = $value === null || $value === '';
            if ($required && $empty) {
                throw new ValidateException(__('%s can not be empty', $title));
            }
            if ($empty) {
                $result[$name] = in_array($type, ['number', 'switch'], true) ? 0 : '';
                continue;
            }
            switch ($type) {
                case 'number':
                    if (!is_numeric($value)) {
                        throw new ValidateException(__('%s must be numeric', $title));
                    }
                    $value = $value + 0;
                    if (isset($field['min']) && $value < $field['min']) {
                        throw new ValidateException(__('%s can not be less than %s', $title, $field['min']));
                    }
                    if (isset($field['max']) && $value > $field['max']) {
                        throw new ValidateException(__('%s can not be greater than %s', $title, $field['max']));
                    }
                    $result[$name] = $value;
                    break;
                case 'switch':
                    if (!in_array((string)$value, ['0', '1'], true)) {
                        throw new ValidateException(__('%s must be 0 or 1', $title));
                    }
                    $result[$name] = (int)$value;
                    break;
                case 'select':
                    $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
                    if (!array_key_exists((string)$value, $options)) {
                        throw new ValidateException(__('%s is not in the options', $title));
                    }
                    $result[$name] = (string)$value;
                    break;
                case 'image':
                    //图片只保存原始路径,拒绝可执行协议
                    if (preg_match('/^\s*(javascript|vbscript|data)\s*:/i', (string)$value)) {
                        throw new ValidateException(__('%s is not a valid path', $title));
                    }
                    if (mb_strlen((string)$value) > 500) {
                        throw new ValidateException(__('%s can not exceed %s characters', $title, 500));
                    }
                    $result[$name] = (string)$value;
                    break;
                default:
                    $maxlength = isset($field['maxlength']) ? (int)$field['maxlength'] : ($type === 'string' ? 255 : 65535);
                    if (mb_strlen((string)$value) > $maxlength) {
                        throw new ValidateException(__('%s can not exceed %s characters', $title, $maxlength));
                    }
                    $result[$name] = (string)$value;
                    break;
            }
        }
        $unknown = array_diff(array_keys($values), array_keys($result));
        if ($unknown) {
            throw new ValidateException(__('Unknown field %s', implode(',', $unknown)));
        }
        return $result;
    }

    /**
     * 按数据组标识读取有效记录
     *
     * @param string $name 数据组标识
     * @return array 有效记录列表，保留 id 和 value；数据组不存在或隐藏时为空
     * @throws Exception
     */
    public static function getDataList($name)
    {
        $group = SystemGroup::where('name', $name)->where('status', 'normal')->find();
        if (!$group) {
            return [];
        }
        $list = self::where('group_id', $group['id'])
            ->where('status', 'normal')
            ->field('id,value')
            ->order('weigh', 'desc')
            ->order('id', 'desc')
            ->select();
        $result = [];
        foreach ($list as $item) {
            $value = $item['value'];
            if (!is_array($value)) {
                //损坏的记录值不能伪装成空数据
                throw new Exception(__('Data of group %s is corrupted', $name));
            }
            $result[] = [
                'id'    => (int)$item['id'],
                'value' => $value,
            ];
        }
        return $result;
    }
}
