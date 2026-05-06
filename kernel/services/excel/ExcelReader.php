<?php

declare(strict_types=1);

namespace kernel\services\excel;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel 导入器
 */
class ExcelReader
{
    /**
     * 按固定列顺序读取
     *
     * @param string $path 文件路径
     * @param array $columns 列配置
     * @param array $options 导入配置
     * @return array
     */
    public function read(string $path, array $columns = [], array $options = []): array
    {
        $this->assertFile($path);

        $reader = $this->reader($path, $options);
        $spreadsheet = $reader->load($path);
        $sheet = $this->sheet($spreadsheet, $options['sheet'] ?? 0);
        $startRow = (int) ($options['start_row'] ?? (empty($columns) ? 1 : 2));
        $highestRow = $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;
        $skipEmpty = (bool) ($options['skip_empty'] ?? true);
        $dateFormat = (string) ($options['date_format'] ?? 'Y-m-d H:i:s');
        $data = [];

        for ($row = $startRow; $row <= $highestRow; $row++) {
            $item = empty($columns)
                ? $this->rowByColumn($sheet, $row, $highestColumnIndex, $dateFormat)
                : $this->rowByConfig($sheet, $row, $columns, $dateFormat);

            if ($skipEmpty && $this->emptyRow($item)) {
                continue;
            }

            $data[] = $item;
            if ($limit > 0 && count($data) >= $limit) {
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $data;
    }

    /**
     * 原样读取二维数组
     *
     * @param string $path 文件路径
     * @param array $options 导入配置
     * @return array
     */
    public function readRaw(string $path, array $options = []): array
    {
        $this->assertFile($path);

        $reader = $this->reader($path, $options);
        $spreadsheet = $reader->load($path);
        $sheet = $this->sheet($spreadsheet, $options['sheet'] ?? 0);
        $startRow = (int) ($options['start_row'] ?? 1);
        $range = 'A' . $startRow . ':' . $sheet->getHighestDataColumn() . $sheet->getHighestDataRow();
        $data = $sheet->rangeToArray(
            $range,
            $options['default_value'] ?? null,
            (bool) ($options['calculate_formulas'] ?? true),
            (bool) ($options['format_data'] ?? false)
        );

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $data;
    }

    /**
     * 按表头映射读取
     *
     * @param string $path 文件路径
     * @param array $map 表头映射
     * @param array $options 导入配置
     * @return array
     */
    public function readByHeaderMap(string $path, array $map, array $options = []): array
    {
        $rawOptions = $options;
        unset($rawOptions['start_row']);
        $raw = $this->readRaw($path, $rawOptions);
        if (empty($raw)) {
            return [];
        }

        $headerRow = max(1, (int) ($options['header_row'] ?? 1));
        $startRow = max($headerRow + 1, (int) ($options['start_row'] ?? ($headerRow + 1)));
        $headers = $raw[$headerRow - 1] ?? [];
        $rows = array_slice($raw, $startRow - 1);
        $required = $options['required_fields'] ?? [];
        $skipEmpty = (bool) ($options['skip_empty'] ?? true);
        $data = [];

        foreach ($rows as $offset => $row) {
            $item = [];
            foreach ($headers as $columnIndex => $title) {
                $field = $map[trim((string) $title)] ?? null;
                if ($field === null) {
                    continue;
                }

                $value = $row[$columnIndex] ?? '';
                $item[$field] = is_string($value) ? trim($value) : $value;
            }

            if ($skipEmpty && $this->emptyRow($item)) {
                continue;
            }

            $this->validateRequired($item, $required, $startRow + $offset);
            $data[] = $item;
        }

        return $data;
    }

    /**
     * 读取表头
     *
     * @param string $path 文件路径
     * @param array $options 导入配置
     * @return array
     */
    public function readHeader(string $path, array $options = []): array
    {
        $this->assertFile($path);

        $reader = $this->reader($path, $options);
        $spreadsheet = $reader->load($path);
        $sheet = $this->sheet($spreadsheet, $options['sheet'] ?? 0);
        $row = (int) ($options['header_row'] ?? 1);
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn($row));
        $headers = [];

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $title = trim((string) $sheet->getCell($this->cell($column, $row))->getFormattedValue());
            if ($title !== '') {
                $headers[Coordinate::stringFromColumnIndex($column)] = $title;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $headers;
    }

    /**
     * 按列配置读取行
     *
     * @param Worksheet $sheet 工作表
     * @param int $row 行号
     * @param array $columns 列配置
     * @param string $dateFormat 日期格式
     * @return array
     */
    protected function rowByConfig(Worksheet $sheet, int $row, array $columns, string $dateFormat): array
    {
        $item = [];
        foreach ($columns as $index => $column) {
            $columnName = isset($column['column']) ? (string) $column['column'] : '';
            $columnIndex = $columnName !== ''
                ? Coordinate::columnIndexFromString($columnName)
                : $index + 1;

            $item[(string) $column['field']] = $this->cellValue($sheet, $columnIndex, $row, $column, $dateFormat);
        }

        return $item;
    }

    /**
     * 按列字母读取行
     *
     * @param Worksheet $sheet 工作表
     * @param int $row 行号
     * @param int $highestColumnIndex 最大列
     * @param string $dateFormat 日期格式
     * @return array
     */
    protected function rowByColumn(Worksheet $sheet, int $row, int $highestColumnIndex, string $dateFormat): array
    {
        $item = [];
        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $field = Coordinate::stringFromColumnIndex($column);
            $item[$field] = $this->cellValue($sheet, $column, $row, null, $dateFormat);
        }

        return $item;
    }

    /**
     * 读取单元格值
     *
     * @param Worksheet $sheet 工作表
     * @param int $column 列号
     * @param int $row 行号
     * @param array|null $config 列配置
     * @param string $dateFormat 日期格式
     * @return mixed
     */
    protected function cellValue(Worksheet $sheet, int $column, int $row, ?array $config, string $dateFormat): mixed
    {
        $cell = $sheet->getCell($this->cell($column, $row));
        $type = $config['type'] ?? 'auto';

        if ($type === 'string') {
            return trim((string) $cell->getFormattedValue());
        }

        if ($type === 'date' || $type === 'datetime') {
            $value = $cell->getValue();
            if (is_numeric($value)) {
                return Date::excelToDateTimeObject((float) $value)->format($dateFormat);
            }

            return trim((string) $cell->getFormattedValue());
        }

        $value = $cell->getCalculatedValue();
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * 校验必填字段
     *
     * @param array $row 行数据
     * @param array $required 必填字段
     * @param int $excelRow Excel 行号
     * @return void
     */
    protected function validateRequired(array $row, array $required, int $excelRow): void
    {
        foreach ($required as $field) {
            if (!isset($row[$field]) || trim((string) $row[$field]) === '') {
                throw new InvalidArgumentException("第 {$excelRow} 行数据缺少必填字段：{$field}");
            }
        }
    }

    /**
     * 判断空行
     *
     * @param array $row 行数据
     * @return bool
     */
    protected function emptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 创建读取器
     *
     * @param string $path 文件路径
     * @param array $options 导入配置
     * @return IReader
     */
    protected function reader(string $path, array $options): IReader
    {
        $readerType = $options['reader_type'] ?? IOFactory::identify($path);
        $reader = IOFactory::createReader((string) $readerType);
        $reader->setReadDataOnly((bool) ($options['read_data_only'] ?? true));

        return $reader;
    }

    /**
     * 获取工作表
     *
     * @param Spreadsheet $spreadsheet Excel 对象
     * @param int|string $sheet 工作表序号或名称
     * @return Worksheet
     */
    protected function sheet(Spreadsheet $spreadsheet, int|string $sheet): Worksheet
    {
        $worksheet = is_int($sheet)
            ? $spreadsheet->getSheet($sheet)
            : $spreadsheet->getSheetByName($sheet);

        if (!$worksheet instanceof Worksheet) {
            throw new InvalidArgumentException('指定的 Excel 工作表不存在');
        }

        return $worksheet;
    }

    /**
     * 单元格坐标
     *
     * @param int $column 列号
     * @param int $row 行号
     * @return string
     */
    protected function cell(int $column, int $row): string
    {
        return Coordinate::stringFromColumnIndex($column) . $row;
    }

    /**
     * 检查文件
     *
     * @param string $path 文件路径
     * @return void
     */
    protected function assertFile(string $path): void
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Excel 文件不存在：' . $path);
        }
    }
}
