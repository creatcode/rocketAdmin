<?php

declare(strict_types=1);

namespace kernel\services\excel;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;
use think\facade\Config;
use think\facade\Db;
use think\Response;

/**
 * Excel 通用服务
 */
class ExcelService
{
    /**
     * 导出为 Excel 对象
     *
     * @param array $rows 数据列表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return Spreadsheet
     */
    public static function export(array $rows, array $columns, array $options = []): Spreadsheet
    {
        return (new ExcelWriter())->make($rows, self::columns($columns), self::exportOptions($options));
    }

    /**
     * 保存到指定文件
     *
     * @param string $path 保存路径
     * @param array $rows 数据列表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return string
     */
    public static function save(string $path, array $rows, array $columns, array $options = []): string
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Excel 保存目录创建失败：' . $directory);
        }

        return (new ExcelWriter())->save($path, $rows, self::columns($columns), self::exportOptions($options));
    }

    /**
     * 按日期目录保存
     *
     * @param array $rows 数据列表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @param string|null $basePath 基础目录
     * @return string
     */
    public static function saveByDate(array $rows, array $columns, array $options = [], ?string $basePath = null): string
    {
        $options = self::exportOptions($options);
        $filename = self::filename((string) ($options['filename'] ?? date('YmdHis') . '.xlsx'));
        $basePath = $basePath ?: self::defaultSaveBasePath();
        $directory = rtrim($basePath, DIRECTORY_SEPARATOR . '/\\')
            . DIRECTORY_SEPARATOR . date('Ym')
            . DIRECTORY_SEPARATOR . date('d');

        return self::save($directory . DIRECTORY_SEPARATOR . $filename, $rows, $columns, $options);
    }

    /**
     * 导出并下载
     *
     * @param array $rows 数据列表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return Response
     */
    public static function download(array $rows, array $columns, array $options = []): Response
    {
        $options = self::exportOptions($options);
        $filename = self::filename((string) ($options['filename'] ?? '导出数据.xlsx'));
        $tempFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('excel_', true) . '.xlsx';

        self::save($tempFile, $rows, $columns, $options);

        // ThinkPHP File 响应没有 deleteFileAfterSend，请求结束后清理临时文件。
        register_shutdown_function(static function () use ($tempFile): void {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        });

        return download($tempFile, $filename);
    }

    /**
     * 按固定列顺序读取
     *
     * @param string $path 文件路径
     * @param array $columns 列配置
     * @param array $options 导入配置
     * @return array
     */
    public static function import(string $path, array $columns = [], array $options = []): array
    {
        return (new ExcelReader())->read($path, self::columns($columns), self::importOptions($options));
    }

    /**
     * 原样读取二维数组
     *
     * @param string $path 文件路径
     * @param array $options 导入配置
     * @return array
     */
    public static function raw(string $path, array $options = []): array
    {
        return (new ExcelReader())->readRaw($path, self::importOptions($options));
    }

    /**
     * 兼容旧命名：原样读取二维数组
     *
     * @param string $path 文件路径
     * @param array $options 导入配置
     * @return array
     */
    public static function importRaw(string $path, array $options = []): array
    {
        return self::raw($path, $options);
    }

    /**
     * 按 Excel 表头映射读取
     *
     * @param string $path 文件路径
     * @param array $map 表头映射，例如 ['姓名' => 'name']
     * @param array $options 导入配置
     * @return array
     */
    public static function importMap(string $path, array $map, array $options = []): array
    {
        return (new ExcelReader())->readByHeaderMap($path, $map, self::importOptions($options));
    }

    /**
     * 兼容旧命名：按 Excel 表头映射读取
     *
     * @param string $path 文件路径
     * @param array $map 表头映射
     * @param array $options 导入配置
     * @return array
     */
    public static function importByHeaderMap(string $path, array $map, array $options = []): array
    {
        return self::importMap($path, $map, $options);
    }

    /**
     * 读取表头
     *
     * @param string $path 文件路径
     * @param array $options 导入配置
     * @return array
     */
    public static function readHeader(string $path, array $options = []): array
    {
        return (new ExcelReader())->readHeader($path, self::importOptions($options));
    }

    /**
     * 导入数据库
     *
     * @param string $path 文件路径
     * @param string $table 表名，不需要表前缀
     * @param array|null $config 映射配置，例如 ['map' => ['姓名' => 'name'], 'required' => ['name']]
     * @param array $options 导入配置
     * @return array
     */
    public static function importToDb(string $path, string $table, ?array $config = null, array $options = []): array
    {
        $options = self::dbOptions($options);
        $transactionStarted = false;

        try {
            $columns = self::tableColumns($table);
            if (empty($columns)) {
                return self::fail("无法获取数据表 {$table} 的字段结构");
            }

            $mapConfig = self::dbMap($columns, $config, $options);
            if (empty($mapConfig['map'])) {
                return self::fail('Excel 表头映射配置不能为空');
            }

            $rows = self::importMap($path, $mapConfig['map'], [
                'header' => $options['header'],
                'start' => $options['start'],
                'required' => $mapConfig['required'],
                'skip_empty' => $options['skip_empty'],
                'sheet' => $options['sheet'],
            ]);

            $rows = self::prepareDbRows($rows, $columns, $options);
            if (empty($rows)) {
                return self::fail('Excel 中没有找到可导入的有效数据');
            }

            if ($options['transaction']) {
                Db::startTrans();
                $transactionStarted = true;
            }

            $count = 0;
            foreach (array_chunk($rows, $options['chunk']) as $chunk) {
                $result = Db::name($table)->insertAll($chunk);
                if ($result === false) {
                    throw new \RuntimeException('数据入库失败');
                }

                $count += (int) $result;
            }

            if ($transactionStarted) {
                Db::commit();
                $transactionStarted = false;
            }

            return [
                'success' => true,
                'message' => "成功导入 {$count} 条记录到 {$table}",
                'data_count' => $count,
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                Db::rollback();
            }

            return self::fail(self::exceptionMessage($e));
        }
    }

    /**
     * 兼容旧命名：导入数据库
     *
     * @param string $path 文件路径
     * @param string $table 表名
     * @param array|null $config 映射配置
     * @param array $options 导入配置
     * @return array
     */
    public static function importExcelToDb(string $path, string $table, ?array $config = null, array $options = []): array
    {
        return self::importToDb($path, $table, $config, $options);
    }

    /**
     * 统一列配置
     *
     * @param array $columns 原始列配置
     * @return array
     */
    public static function columns(array $columns): array
    {
        $items = [];
        foreach ($columns as $key => $column) {
            if (is_string($column)) {
                $field = is_string($key) ? $key : (string) $key;
                $items[] = self::column($field, $column);
                continue;
            }

            if (!is_array($column)) {
                continue;
            }

            $field = (string) ($column['field'] ?? $key);
            $title = (string) ($column['title'] ?? $column['name'] ?? $field);
            $items[] = self::column($field, $title, $column);
        }

        return $items;
    }

    /**
     * 统一单列配置
     *
     * @param string $field 字段
     * @param string $title 标题
     * @param array $options 配置
     * @return array
     */
    protected static function column(string $field, string $title, array $options = []): array
    {
        return [
            'field' => $field,
            'title' => $title,
            'width' => isset($options['width']) ? (float) $options['width'] : null,
            'type' => strtolower((string) ($options['type'] ?? 'auto')),
            'format' => isset($options['format']) ? (string) $options['format'] : null,
            'default' => $options['default'] ?? '',
            'callback' => $options['callback'] ?? null,
            'column' => isset($options['column']) ? strtoupper((string) $options['column']) : null,
        ];
    }

    /**
     * 统一导出配置
     *
     * @param array $options 原始配置
     * @return array
     */
    protected static function exportOptions(array $options): array
    {
        $style = is_array($options['style'] ?? null) ? $options['style'] : [];

        return array_merge($options, [
            'filename' => $options['filename'] ?? $options['name'] ?? $options['file_name'] ?? null,
            'sheet_name' => $options['sheet_name'] ?? $options['sheet'] ?? 'Sheet1',
            'operator' => $options['operator'] ?? $options['user'] ?? null,
            'zebra' => $options['zebra'] ?? $style['zebra'] ?? false,
            'writer_type' => $options['writer_type'] ?? $options['writer'] ?? 'Xlsx',
        ]);
    }

    /**
     * 统一导入配置
     *
     * @param array $options 原始配置
     * @return array
     */
    protected static function importOptions(array $options): array
    {
        return array_merge($options, [
            'header_row' => (int) ($options['header_row'] ?? $options['header'] ?? $options['head'] ?? 1),
            'start_row' => isset($options['start_row']) || isset($options['start'])
                ? (int) ($options['start_row'] ?? $options['start'])
                : null,
            'required_fields' => $options['required_fields'] ?? $options['required'] ?? [],
            'skip_empty' => $options['skip_empty'] ?? true,
            'sheet' => $options['sheet'] ?? 0,
        ]);
    }

    /**
     * 统一数据库导入配置
     *
     * @param array $options 原始配置
     * @return array
     */
    protected static function dbOptions(array $options): array
    {
        $header = (int) ($options['header_row'] ?? $options['header'] ?? 1);

        return array_merge($options, [
            'header' => $header,
            'start' => (int) ($options['start_row'] ?? $options['start'] ?? ($header + 1)),
            'chunk' => max(1, (int) ($options['chunk_size'] ?? $options['chunk'] ?? 1000)),
            'transaction' => (bool) ($options['transaction'] ?? true),
            'mode' => (string) ($options['mode'] ?? $options['import_head_type'] ?? 'comment'),
            'required' => $options['required'] ?? $options['required_fields'] ?? [],
            'skip_empty' => (bool) ($options['skip_empty'] ?? true),
            'sheet' => $options['sheet'] ?? 0,
            'extra' => $options['extra'] ?? $options['extra_fields'] ?? [],
            'admin_id' => $options['admin_id'] ?? $options['admin_id_value'] ?? 1,
            'fill_admin_id' => (bool) ($options['fill_admin_id'] ?? true),
            'callback' => $options['callback'] ?? null,
        ]);
    }

    /**
     * 数据库字段映射
     *
     * @param array $columns 数据表字段
     * @param array|null $config 手动配置
     * @param array $options 导入配置
     * @return array
     */
    protected static function dbMap(array $columns, ?array $config, array $options): array
    {
        if ($config !== null) {
            return [
                'map' => $config['map'] ?? [],
                'required' => $config['required'] ?? $config['required_fields'] ?? $config['fields'] ?? [],
            ];
        }

        $map = [];
        foreach ($columns as $field => $column) {
            $comment = self::cleanComment((string) $column['comment']);
            $header = $options['mode'] === 'comment' && $comment !== '' ? $comment : $field;
            $map[$header] = $field;
        }

        return [
            'map' => $map,
            'required' => $options['required'],
        ];
    }

    /**
     * 获取数据表字段
     *
     * @param string $table 表名
     * @return array
     */
    protected static function tableColumns(string $table): array
    {
        $default = (string) Config::get('database.default', 'mysql');
        $database = (string) Config::get('database.connections.' . $default . '.database');
        $realTable = str_replace('`', '', (string) Db::name($table)->getTable());

        if (str_contains($realTable, '.')) {
            $parts = explode('.', $realTable);
            $realTable = end($parts) ?: $table;
        }

        $list = Db::query(
            'SELECT COLUMN_NAME, COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?',
            [$realTable, $database]
        );

        $columns = [];
        foreach ($list as $item) {
            $name = (string) ($item['COLUMN_NAME'] ?? '');
            if ($name !== '') {
                $columns[$name] = [
                    'name' => $name,
                    'comment' => (string) ($item['COLUMN_COMMENT'] ?? ''),
                ];
            }
        }

        return $columns;
    }

    /**
     * 整理入库数据
     *
     * @param array $rows Excel 数据
     * @param array $columns 表字段
     * @param array $options 导入配置
     * @return array
     */
    protected static function prepareDbRows(array $rows, array $columns, array $options): array
    {
        $fields = array_keys($columns);
        $fieldKeys = array_flip($fields);
        $prepared = [];

        foreach ($rows as $index => $row) {
            $item = array_intersect_key($row, $fieldKeys);

            if (!empty($options['extra'])) {
                $item = array_merge($item, array_intersect_key($options['extra'], $fieldKeys));
            }

            if ($options['fill_admin_id'] && in_array('admin_id', $fields, true) && empty($item['admin_id'])) {
                $item['admin_id'] = $options['admin_id'];
            }

            if (is_callable($options['callback'])) {
                $item = call_user_func($options['callback'], $item, $row, $index + 1);
            }

            if (is_array($item) && !self::emptyRow($item)) {
                $prepared[] = array_intersect_key($item, $fieldKeys);
            }
        }

        return $prepared;
    }

    /**
     * 清理文件名
     *
     * @param string $filename 文件名
     * @return string
     */
    protected static function filename(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            $filename = '导出数据.xlsx';
        }

        if (!preg_match('/\.(xlsx|xls|csv)$/i', $filename)) {
            $filename .= '.xlsx';
        }

        return $filename;
    }

    /**
     * 默认保存目录
     *
     * @return string
     */
    protected static function defaultSaveBasePath(): string
    {
        // 优先使用 ThinkPHP 提供的公共目录方法，避免手动拼接路径导致目录不准确
        if (function_exists('public_path')) {
            return rtrim(public_path(), DIRECTORY_SEPARATOR . '/\\')
                . DIRECTORY_SEPARATOR . 'excelPort';
        }

        // 兼容旧环境
        if (function_exists('root_path')) {
            return rtrim(root_path('public'), DIRECTORY_SEPARATOR . '/\\')
                . DIRECTORY_SEPARATOR . 'excelPort';
        }

        if (defined('ROOT_PATH')) {
            return rtrim((string) ROOT_PATH, DIRECTORY_SEPARATOR . '/\\')
                . DIRECTORY_SEPARATOR . 'public'
                . DIRECTORY_SEPARATOR . 'excelPort';
        }

        return rtrim(getcwd(), DIRECTORY_SEPARATOR . '/\\')
            . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'excelPort';
    }

    /**
     * 清理字段注释
     *
     * @param string $comment 字段注释
     * @return string
     */
    protected static function cleanComment(string $comment): string
    {
        $comment = trim($comment);
        if ($comment === '') {
            return '';
        }

        return trim((string) preg_split('/[:：]/u', $comment)[0]);
    }

    /**
     * 判断空行
     *
     * @param array $row 行数据
     * @return bool
     */
    protected static function emptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 异常信息
     *
     * @param Throwable $e 异常对象
     * @return string
     */
    protected static function exceptionMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        if (preg_match("/Duplicate entry '(.+)' for key '(.+)'/i", $message, $matches)) {
            return "导入失败，包含【{$matches[1]}】的重复记录";
        }

        return '导入过程发生异常：' . $message;
    }

    /**
     * 失败返回
     *
     * @param string $message 错误信息
     * @return array
     */
    protected static function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data_count' => 0,
        ];
    }
}
