# Excel 服务使用说明

基于 `phpoffice/phpspreadsheet` 封装，业务层只需要使用 `ExcelService`。

```php
use kernel\services\excel\ExcelService;
```

当前目录只保留 3 个核心类：

- `ExcelService.php`：统一入口，业务代码只调用它。
- `ExcelWriter.php`：导出实现。
- `ExcelReader.php`：导入实现。

## 一、推荐用法

### 1. 导出下载

```php
$columns = [
    'name' => '姓名',
    ['field' => 'mobile', 'title' => '手机号', 'type' => 'string', 'width' => 20],
    ['field' => 'money', 'title' => '余额', 'type' => 'float', 'format' => '#,##0.00'],
    [
        'field' => 'status',
        'title' => '状态',
        'callback' => fn($value) => $value == 1 ? '启用' : '禁用',
    ],
];

$rows = [
    ['name' => '张三', 'mobile' => '013800000000', 'money' => 123.45, 'status' => 1],
];

return ExcelService::download($rows, $columns, [
    'name' => '用户列表.xlsx',
    'title' => '用户列表',
    'user' => '管理员',
    'zebra' => true,
]);
```

### 2. 保存到服务器

```php
$path = ExcelService::save(root_path() . 'runtime/export/users.xlsx', $rows, $columns, [
    'title' => '用户列表',
    'user' => '管理员',
]);
```

按日期目录保存，默认目录是 `public/excelPort/年月/日`：

```php
$path = ExcelService::saveByDate($rows, $columns, [
    'name' => '用户列表.xlsx',
    'title' => '用户列表',
]);
```

### 3. 按表头导入

推荐这种方式，Excel 列顺序可以变化，只要表头文字匹配即可。

```php
$rows = ExcelService::importMap($filePath, [
    '姓名' => 'name',
    '手机号' => 'mobile',
    '余额' => 'money',
], [
    'header' => 1,
    'start' => 2,
    'required' => ['name', 'mobile'],
]);
```

如果 Excel 是本服务导出的，并且传了 `title` 和 `user`，表头通常在第 3 行，数据从第 4 行开始：

```php
$rows = ExcelService::importMap($filePath, [
    '姓名' => 'name',
    '手机号' => 'mobile',
    '余额' => 'money',
], [
    'header' => 3,
    'start' => 4,
]);
```

### 4. 直接导入数据库

```php
$result = ExcelService::importToDb($filePath, 'user', [
    'map' => [
        '昵称' => 'nickname',
        '手机号' => 'mobile',
        '余额' => 'money',
    ],
    'required' => ['nickname', 'mobile'],
], [
    'header' => 1,
    'start' => 2,
    'chunk' => 500,
    'admin_id' => 1,
    'extra' => [
        'status' => 'normal',
    ],
]);
```

返回格式：

```php
[
    'success' => true,
    'message' => '成功导入 10 条记录到 user',
    'data_count' => 10,
]
```

## 二、列配置

简单写法：

```php
$columns = [
    'name' => '姓名',
    'mobile' => '手机号',
];
```

完整写法：

```php
$columns = [
    ['field' => 'name', 'title' => '姓名', 'width' => 16, 'type' => 'string'],
    ['field' => 'mobile', 'title' => '手机号', 'width' => 20, 'type' => 'string'],
    ['field' => 'money', 'title' => '余额', 'type' => 'float', 'format' => '#,##0.00'],
    ['field' => 'created_at', 'title' => '创建时间', 'type' => 'datetime'],
];
```

常用列参数：

| 参数 | 说明 |
| --- | --- |
| `field` | 数据字段 |
| `title` | Excel 表头 |
| `width` | 列宽 |
| `type` | 类型：`auto`、`string`、`int`、`float`、`date`、`datetime`、`bool`、`formula` |
| `format` | Excel 格式，例如 `#,##0.00` |
| `default` | 默认值 |
| `callback` | 导出回调：`fn($value, $row, $column) => ...` |
| `column` | 导入时指定列字母，例如 `A` |

手机号、订单号、身份证号建议设置 `type => string`，避免前导 0 丢失或长数字被科学计数法显示。

## 三、导出方式

### 下载

```php
return ExcelService::download($rows, $columns, [
    'name' => '订单列表.xlsx',
    'title' => '订单列表',
    'user' => '管理员',
]);
```

### 保存

```php
ExcelService::save($path, $rows, $columns);
```

### 获取 Spreadsheet 对象继续加工

```php
$spreadsheet = ExcelService::export($rows, $columns, [
    'title' => '订单列表',
]);

$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A10', '自定义内容');
```

## 四、导入方式

### 固定列顺序导入

```php
$rows = ExcelService::import($filePath, [
    ['field' => 'name', 'title' => '姓名', 'type' => 'string'],
    ['field' => 'mobile', 'title' => '手机号', 'type' => 'string'],
], [
    'start' => 2,
]);
```

### 按表头导入

```php
$rows = ExcelService::importMap($filePath, [
    '姓名' => 'name',
    '手机号' => 'mobile',
], [
    'header' => 1,
    'start' => 2,
]);
```

### 原样读取

```php
$rows = ExcelService::raw($filePath);
```

返回示例：

```php
[
    ['姓名', '手机号'],
    ['张三', '013800000000'],
]
```

### 读取表头

`readHeader()` 只读取表头，不读取数据。它主要用于导入前校验模板，或者给前端做字段映射。

```php
$headers = ExcelService::readHeader($filePath, [
    'header' => 1,
]);
```

返回：

```php
[
    'A' => '姓名',
    'B' => '手机号',
]
```

模板校验示例：

```php
$headers = ExcelService::readHeader($filePath);
$missing = array_diff(['姓名', '手机号'], array_values($headers));

if ($missing) {
    return json([
        'code' => 0,
        'msg' => 'Excel 缺少表头：' . implode('、', $missing),
    ]);
}
```

## 五、数据库导入

### 手动映射

```php
$result = ExcelService::importToDb($filePath, 'user', [
    'map' => [
        '昵称' => 'nickname',
        '手机号' => 'mobile',
    ],
    'required' => ['nickname', 'mobile'],
]);
```

### 自动按字段注释匹配

数据库字段注释：

```sql
nickname COMMENT '昵称'
mobile COMMENT '手机号'
```

Excel 表头：

```text
昵称 | 手机号
```

代码：

```php
$result = ExcelService::importToDb($filePath, 'user', null, [
    'mode' => 'comment',
    'required' => ['nickname', 'mobile'],
]);
```

### 自动按字段名匹配

Excel 表头：

```text
nickname | mobile
```

代码：

```php
$result = ExcelService::importToDb($filePath, 'user', null, [
    'mode' => 'name',
    'required' => ['nickname', 'mobile'],
]);
```

### 入库前处理每一行

```php
$result = ExcelService::importToDb($filePath, 'user', [
    'map' => [
        '昵称' => 'nickname',
        '手机号' => 'mobile',
        '状态' => 'status',
    ],
], [
    'callback' => function (array $item) {
        $item['mobile'] = preg_replace('/\s+/', '', $item['mobile'] ?? '');
        $item['status'] = ($item['status'] ?? '') === '启用' ? 'normal' : 'hidden';

        return $item;
    },
]);
```

## 六、常用参数

### 导出参数

| 推荐参数 | 说明 | 兼容旧参数 |
| --- | --- | --- |
| `name` | 文件名 | `filename`、`file_name` |
| `title` | 大标题 | - |
| `user` | 操作人 | `operator` |
| `sheet` | 工作表名称 | `sheet_name` |
| `zebra` | 斑马纹 | - |
| `info` | 信息行，支持字符串或数组 | - |

### 导入参数

| 推荐参数 | 说明 | 兼容旧参数 |
| --- | --- | --- |
| `header` | 表头行号 | `header_row`、`head` |
| `start` | 数据开始行 | `start_row` |
| `required` | 必填字段 | `required_fields` |
| `sheet` | 工作表序号或名称 | - |
| `skip_empty` | 是否跳过空行 | - |

### 数据库导入参数

| 参数 | 说明 |
| --- | --- |
| `mode` | 自动映射方式：`comment` 按字段注释，`name` 按字段名 |
| `chunk` | 每批入库数量，兼容 `chunk_size` |
| `transaction` | 是否开启事务 |
| `extra` | 每行额外写入字段，兼容 `extra_fields` |
| `admin_id` | 自动填充的管理员 ID |
| `fill_admin_id` | 表存在 `admin_id` 时是否自动填充 |
| `callback` | 入库前逐行处理 |

## 七、怎么选

固定模板导入：

```php
ExcelService::importMap($filePath, $map);
```

需要先检查模板：

```php
ExcelService::readHeader($filePath);
```

后台列表导出：

```php
ExcelService::download($rows, $columns, ['name' => '列表.xlsx']);
```

直接入库：

```php
ExcelService::importToDb($filePath, 'user', $config);
```

生成文件给其他服务使用：

```php
ExcelService::saveByDate($rows, $columns, ['name' => '列表.xlsx']);
```
