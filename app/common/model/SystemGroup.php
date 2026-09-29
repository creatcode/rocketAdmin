<?php

namespace app\common\model;

use think\exception\ValidateException;
use think\Model;

/**
 * 组合数据组定义模型
 */
class SystemGroup extends Model
{
    protected $name = 'system_group';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $type = [
        'fields' => 'json',
    ];
    protected $jsonAssoc = true;

    // 字段标识以小写字母开头，最长30个字符
    const FIELD_NAME_REGEX = '/^[a-z][a-z0-9_]{0,29}$/';

    // 字段定义允许的属性:标识/标题/类型/参数(仅列表类型使用)
    const FIELD_KEYS = ['name', 'title', 'type', 'param'];

    // 需要填写选项参数的字段类型
    const PARAM_TYPES = ['select', 'radio', 'checkbox'];

    // 禁止用作字段标识的管理字段名
    const RESERVED_FIELD_NAMES = ['id', 'group_id', 'value', 'weigh', 'status', 'createtime', 'updatetime'];

    /**
     * 读取可用的字段类型列表
     *
     * @return array
     */
    public static function getFieldTypeList()
    {
        return [
            'string'   => __('String'),
            'text'     => __('Text'),
            'number'   => __('Number'),
            'switch'   => __('Switch'),
            'image'    => __('Image'),
            'uploads'  => __('Uploads'),
            'select'   => __('Select'),
            'radio'    => __('Radio'),
            'checkbox' => __('Checkbox'),
            'date'     => __('Date'),
            'datetime' => __('Datetime'),
        ];
    }

    /**
     * 校验并规范化字段定义
     *
     * 字段仅保留标识/标题/类型,列表类型附加参数(选项键值)。
     *
     * @param array|string $fields 字段定义(数组或JSON字符串)
     * @return array 规范化后的字段定义
     * @throws ValidateException
     */
    public static function normalizeFields($fields)
    {
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        if (!is_array($fields)) {
            throw new ValidateException(__('Fields must be an array'));
        }
        if (!$fields) {
            throw new ValidateException(__('Fields can not be empty'));
        }
        //字段定义必须是列表,避免对象形式的定义被当成单个字段
        if (array_keys($fields) !== range(0, count($fields) - 1)) {
            throw new ValidateException(__('Fields must be an array'));
        }
        $typeList = array_keys(self::getFieldTypeList());
        $reserved = self::RESERVED_FIELD_NAMES;
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new ValidateException(__('Field definition must be an object'));
            }
            $unknown = array_diff(array_keys($field), self::FIELD_KEYS);
            if ($unknown) {
                throw new ValidateException(__('Unknown field property %s', implode(',', $unknown)));
            }
            $name = isset($field['name']) ? trim((string)$field['name']) : '';
            $title = isset($field['title']) ? trim((string)$field['title']) : '';
            //fieldlist提交的空行直接忽略
            if ($name === '' && $title === '') {
                continue;
            }
            if (!preg_match(self::FIELD_NAME_REGEX, $name)) {
                throw new ValidateException(__('Field name %s is invalid', $name ?: ''));
            }
            if (in_array($name, $reserved, true)) {
                throw new ValidateException(__('Field name %s is reserved', $name));
            }
            if (isset($result[$name])) {
                throw new ValidateException(__('Field name %s is duplicated', $name));
            }
            if ($title === '' || mb_strlen($title) > 50) {
                throw new ValidateException(__('Title of field %s is invalid', $name));
            }
            $type = isset($field['type']) && $field['type'] !== '' ? (string)$field['type'] : 'string';
            if (!in_array($type, $typeList, true)) {
                throw new ValidateException(__('Type of field %s is invalid', $name));
            }
            $item = [
                'name'  => $name,
                'title' => $title,
                'type'  => $type,
            ];
            if (in_array($type, self::PARAM_TYPES, true)) {
                $item['param'] = self::normalizeParam($name, $field['param'] ?? []);
            }
            $result[$name] = $item;
        }
        if (!$result) {
            throw new ValidateException(__('Fields can not be empty'));
        }
        return array_values($result);
    }

    /**
     * 校验并规范化列表字段的参数(选项)
     *
     * @param string $name 字段标识
     * @param array|string $param 选项("键|值"多行文本或键值数组)
     * @return array 选项键值数组
     * @throws ValidateException
     */
    protected static function normalizeParam($name, $param)
    {
        if (is_string($param)) {
            //表单以"键|值"多行文本提交选项
            $param = Config::decode($param);
        }
        if (!is_array($param) || !$param) {
            throw new ValidateException(__('Options of field %s can not be empty', $name));
        }
        $optionList = [];
        foreach ($param as $key => $value) {
            $key = (string)$key;
            if (!preg_match('/^[A-Za-z0-9_\-]{1,50}$/', $key) || !is_scalar($value) || is_bool($value)
                || mb_strlen((string)$value) > 50) {
                throw new ValidateException(__('Options of field %s is invalid', $name));
            }
            $optionList[$key] = (string)$value;
        }
        return $optionList;
    }

    /**
     * 将字段定义转换为 fieldlist 表单值
     *
     * @param array $fields 字段定义
     * @return array
     */
    public static function toFormFields($fields)
    {
        $result = [];
        foreach ((array)$fields as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }
            $result[] = [
                'name'  => $field['name'],
                'title' => $field['title'] ?? '',
                'type'  => $field['type'] ?? 'string',
                'param' => isset($field['param']) && is_array($field['param']) ? Config::encode($field['param']) : '',
            ];
        }
        return $result;
    }
}
