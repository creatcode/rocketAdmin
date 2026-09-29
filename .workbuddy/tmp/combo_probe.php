<?php
// TEMP-PROBE: 组合数据重构前后行为对比，验证完删除
require __DIR__ . '/../../vendor/autoload.php';

use app\common\model\SystemGroup;
use app\common\model\SystemGroupData;

$app = new \think\App();
$app->initialize();

$out = [];

// 1. 字段定义规范化
$defs = [
    ['name' => 'title', 'title' => '标题', 'type' => 'string'],
    ['name' => 'body', 'title' => '正文', 'type' => 'text'],
    ['name' => 'num', 'title' => '数字', 'type' => 'number'],
    ['name' => 'on', 'title' => '开关', 'type' => 'switch'],
    ['name' => 'pic', 'title' => '单图', 'type' => 'image'],
    ['name' => 'pics', 'title' => '多图', 'type' => 'uploads'],
    ['name' => 'day', 'title' => '日期', 'type' => 'date'],
    ['name' => 'time', 'title' => '时间', 'type' => 'datetime'],
    ['name' => 'sel', 'title' => '列表', 'type' => 'select', 'param' => "a|甲\nb|乙"],
    ['name' => 'rad', 'title' => '单选', 'type' => 'radio', 'param' => ['x' => 'X', 'y' => 'Y']],
    ['name' => 'chk', 'title' => '多选', 'type' => 'checkbox', 'param' => ['p' => 'P', 'q' => 'Q']],
];
try {
    $out['fields'] = SystemGroup::normalizeFields($defs);
} catch (\Throwable $e) {
    $out['fields'] = 'ERR: ' . $e->getMessage();
}

// 2. 字段定义异常分支
$bad = [
    'unknownProp' => [['name' => 'a', 'title' => 'A', 'type' => 'string', 'min' => 1]],
    'badName'     => [['name' => 'A1', 'title' => 'A', 'type' => 'string']],
    'reserved'    => [['name' => 'value', 'title' => 'A', 'type' => 'string']],
    'dup'         => [['name' => 'a', 'title' => 'A', 'type' => 'string'], ['name' => 'a', 'title' => 'B', 'type' => 'string']],
    'badType'     => [['name' => 'a', 'title' => 'A', 'type' => 'nosuch']],
    'emptyTitle'  => [['name' => 'a', 'title' => ' ', 'type' => 'string']],
    'noParam'     => [['name' => 'a', 'title' => 'A', 'type' => 'select']],
    'badParamKey' => [['name' => 'a', 'title' => 'A', 'type' => 'select', 'param' => ['a b' => 'X']]],
    'emptyParam'  => [['name' => 'a', 'title' => 'A', 'type' => 'radio', 'param' => []]],
    'objectForm'  => ['a' => ['name' => 'a', 'title' => 'A', 'type' => 'string']],
    'empty'       => [],
    'blankRow'    => [['name' => '', 'title' => '', 'type' => 'string']],
    'onlyName'    => [['name' => 'a', 'title' => '']],
];
foreach ($bad as $k => $v) {
    try {
        $out['bad_' . $k] = SystemGroup::normalizeFields($v);
    } catch (\Throwable $e) {
        $out['bad_' . $k] = 'ERR: ' . $e->getMessage();
    }
}

// 3. 记录值校验
$fields = is_array($out['fields']) ? $out['fields'] : [];
$cases = [
    'full'      => ['title' => ' T ', 'body' => 'b', 'num' => '3', 'on' => '1', 'pic' => '/a.png',
                    'pics' => '/a.png,/b.png', 'day' => '2026-09-29', 'time' => '2026-09-29 10:00:00',
                    'sel' => 'a', 'rad' => 'x', 'chk' => ['p', 'q']],
    'empty'     => [],
    'blankVals' => ['title' => '', 'num' => '', 'on' => '', 'pic' => '', 'pics' => '', 'day' => '', 'time' => '', 'sel' => '', 'rad' => '', 'chk' => []],
    'chkScalar' => ['title' => 'a', 'num' => 1, 'on' => 0, 'pic' => '/a.png', 'pics' => '/a.png',
                    'day' => '2026-09-29', 'time' => '2026-09-29 10:00:00', 'sel' => 'a', 'rad' => 'x', 'chk' => 'p'],
    'chkComma'  => ['title' => 'a', 'num' => 1, 'on' => 0, 'pic' => '/a.png', 'pics' => '/a.png',
                    'day' => '2026-09-29', 'time' => '2026-09-29 10:00:00', 'sel' => 'a', 'rad' => 'x', 'chk' => 'p,q'],
    'chkDup'    => ['title' => 'a', 'chk' => ['p', 'p']],
    'badNum'    => ['title' => 'a', 'num' => 'x'],
    'badSwitch' => ['title' => 'a', 'on' => '2'],
    'badSel'    => ['title' => 'a', 'sel' => 'zzz'],
    'badBadChk' => ['title' => 'a', 'chk' => ['p', 'zzz']],
    'badPath'   => ['title' => 'a', 'pic' => 'javascript:alert(1)'],
    'badPics'   => ['title' => 'a', 'pics' => '/a.png,javascript:alert(1)'],
    'longPath'  => ['title' => 'a', 'pic' => '/' . str_repeat('a', 520) . '.png'],
    'picsEmpty' => ['title' => 'a', 'pics' => ',,'],
    'textLong'  => ['title' => 'a', 'body' => str_repeat('x', 70000)],
    'textOk'    => ['title' => 'a', 'body' => str_repeat('x', 65535)],
    'str256'    => ['title' => str_repeat('x', 256)],
    'str255'    => ['title' => str_repeat('x', 255)],
    'badDate'   => ['title' => 'a', 'day' => 'not-a-date'],
    'arrScalar' => ['title' => ['x']],
    'unknown'   => ['title' => 'a', 'nosuch' => '1'],
];
foreach ($cases as $k => $v) {
    try {
        $out['val_' . $k] = SystemGroupData::normalizeValue($fields, $v);
    } catch (\Throwable $e) {
        $out['val_' . $k] = 'ERR: ' . $e->getMessage();
    }
}

// 4. 表单字段构造（控制器 formFields 逻辑）
$formFields = function ($fields, $values = []) {
    $result = [];
    foreach ($fields as $field) {
        $name = $field['name'];
        $type = $field['type'];
        if (array_key_exists($name, (array)$values)) {
            $value = $values[$name];
        } else {
            $value = in_array($type, ['number', 'switch'], true) ? 0 : ($type === 'checkbox' ? [] : '');
        }
        if ($type === 'checkbox') {
            $value = (array)$value;
        } else {
            $value = is_scalar($value) ? (string)$value : '';
        }
        $result[] = ['name' => $name, 'title' => $field['title'], 'type' => $type, 'value' => $value,
                     'options' => isset($field['param']) && is_array($field['param']) ? $field['param'] : []];
    }
    return $result;
};
$out['form_empty'] = $formFields($fields);
$out['form_stored'] = $formFields($fields, ['chk' => ['p'], 'num' => 5, 'on' => 1]);
$out['form_legacy'] = $formFields($fields, ['chk' => 'p,q', 'sel' => 'a']);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
