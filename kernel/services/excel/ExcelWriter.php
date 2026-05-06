<?php

declare(strict_types=1);

namespace kernel\services\excel;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel 导出器
 */
class ExcelWriter
{
    /**
     * 构建 Excel
     *
     * @param array $rows 数据列表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return Spreadsheet
     */
    public function make(array $rows, array $columns, array $options = []): Spreadsheet
    {
        if (empty($columns)) {
            throw new InvalidArgumentException('Excel 表头不能为空');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle((string) ($options['sheet_name'] ?? 'Sheet1')));

        $this->writeTitle($sheet, $columns, $options);
        $this->writeInfo($sheet, $columns, $options);
        $this->writeHeader($sheet, $columns, $options);
        $this->writeRows($sheet, $rows, $columns, $options);
        $this->applyStyle($sheet, $columns, count($rows), $options);

        $creator = (string) ($options['creator'] ?? 'FastAdmin');
        $spreadsheet->getProperties()
            ->setCreator($creator)
            ->setLastModifiedBy($creator)
            ->setTitle((string) ($options['title'] ?? '导出数据'));

        return $spreadsheet;
    }

    /**
     * 保存 Excel
     *
     * @param string $path 保存路径
     * @param array $rows 数据列表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return string
     */
    public function save(string $path, array $rows, array $columns, array $options = []): string
    {
        $spreadsheet = $this->make($rows, $columns, $options);
        $writer = IOFactory::createWriter($spreadsheet, (string) ($options['writer_type'] ?? 'Xlsx'));
        $writer->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $path;
    }

    /**
     * 写入标题
     *
     * @param Worksheet $sheet 工作表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return void
     */
    protected function writeTitle(Worksheet $sheet, array $columns, array $options): void
    {
        if (empty($options['title'])) {
            return;
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', (string) $options['title']);
        $sheet->getRowDimension(1)->setRowHeight((float) ($options['title_height'] ?? 30));
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => (int) ($options['title_font_size'] ?? 16),
                'color' => ['rgb' => $this->color($options['title_font_color'] ?? '1F2937')],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    /**
     * 写入信息行
     *
     * @param Worksheet $sheet 工作表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return void
     */
    protected function writeInfo(Worksheet $sheet, array $columns, array $options): void
    {
        $info = $this->infoText($options);
        if ($info === '') {
            return;
        }

        $row = empty($options['title']) ? 1 : 2;
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->setCellValue("A{$row}", $info);
        $sheet->getRowDimension($row)->setRowHeight((float) ($options['info_height'] ?? 22));
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => [
                'size' => (int) ($options['info_font_size'] ?? 11),
                'color' => ['rgb' => $this->color($options['info_font_color'] ?? '4B5563')],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    /**
     * 写入表头
     *
     * @param Worksheet $sheet 工作表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return void
     */
    protected function writeHeader(Worksheet $sheet, array $columns, array $options): void
    {
        $row = $this->headerRow($options);
        foreach ($columns as $index => $column) {
            $columnIndex = $index + 1;
            $sheet->setCellValue($this->cell($columnIndex, $row), (string) $column['title']);

            if ($column['width'] !== null) {
                $sheet->getColumnDimensionByColumn($columnIndex)->setWidth((float) $column['width']);
            } elseif (!empty($options['auto_size'])) {
                $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
            } else {
                $sheet->getColumnDimensionByColumn($columnIndex)->setWidth((float) ($options['default_width'] ?? 16));
            }
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $range = "A{$row}:{$lastColumn}{$row}";
        $sheet->getRowDimension($row)->setRowHeight((float) ($options['header_height'] ?? 24));
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => $this->color($options['header_font_color'] ?? 'FFFFFF')],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $this->color($options['header_bg_color'] ?? '2563EB')],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => $this->color($options['border_color'] ?? 'D1D5DB')],
                ],
            ],
        ]);
    }

    /**
     * 写入数据
     *
     * @param Worksheet $sheet 工作表
     * @param array $rows 数据列表
     * @param array $columns 列配置
     * @param array $options 导出配置
     * @return void
     */
    protected function writeRows(Worksheet $sheet, array $rows, array $columns, array $options): void
    {
        $startRow = $this->headerRow($options) + 1;
        foreach ($rows as $rowOffset => $row) {
            foreach ($columns as $columnOffset => $column) {
                $value = $this->rowValue($row, $column);
                $this->setValue($sheet, $columnOffset + 1, $startRow + $rowOffset, $value, $column);
            }
        }
    }

    /**
     * 应用样式
     *
     * @param Worksheet $sheet 工作表
     * @param array $columns 列配置
     * @param int $rowCount 数据行数
     * @param array $options 导出配置
     * @return void
     */
    protected function applyStyle(Worksheet $sheet, array $columns, int $rowCount, array $options): void
    {
        $headerRow = $this->headerRow($options);
        $firstDataRow = $headerRow + 1;
        $lastRow = max($headerRow, $headerRow + $rowCount);
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $firstStyledRow = empty($options['title']) && !$this->hasInfo($options) ? $headerRow : 1;
        $fullRange = "A{$firstStyledRow}:{$lastColumn}{$lastRow}";

        $sheet->getDefaultRowDimension()->setRowHeight((float) ($options['row_height'] ?? 22));
        $sheet->getStyle($fullRange)->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => (bool) ($options['wrap_text'] ?? true),
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => $this->color($options['border_color'] ?? 'E5E7EB')],
                ],
            ],
        ]);

        if ($rowCount > 0) {
            $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastRow}")
                ->getAlignment()
                ->setHorizontal((string) ($options['body_align'] ?? Alignment::HORIZONTAL_LEFT));
        }

        if (!empty($options['zebra']) && $rowCount > 0) {
            $this->zebra($sheet, $firstDataRow, $lastRow, $lastColumn, $options);
        }

        if (($options['freeze'] ?? true) !== false) {
            $sheet->freezePane('A' . ($headerRow + 1));
        }

        if (($options['auto_filter'] ?? true) !== false) {
            $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");
        }
    }

    /**
     * 设置斑马纹
     *
     * @param Worksheet $sheet 工作表
     * @param int $firstDataRow 首行
     * @param int $lastRow 末行
     * @param string $lastColumn 末列
     * @param array $options 导出配置
     * @return void
     */
    protected function zebra(Worksheet $sheet, int $firstDataRow, int $lastRow, string $lastColumn, array $options): void
    {
        $color = $this->color($options['zebra_color'] ?? 'F9FAFB');
        for ($row = $firstDataRow; $row <= $lastRow; $row++) {
            if (($row - $firstDataRow) % 2 !== 1) {
                continue;
            }

            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($color);
        }
    }

    /**
     * 获取字段值
     *
     * @param mixed $row 行数据
     * @param array $column 列配置
     * @return mixed
     */
    protected function rowValue(mixed $row, array $column): mixed
    {
        $field = (string) $column['field'];
        $default = $column['default'] ?? '';

        if (is_array($row)) {
            $value = $row[$field] ?? $default;
        } elseif (is_object($row) && isset($row->{$field})) {
            $value = $row->{$field};
        } elseif (is_object($row) && method_exists($row, 'getAttr')) {
            $value = $row->getAttr($field);
        } elseif (is_object($row) && method_exists($row, 'toArray')) {
            $array = $row->toArray();
            $value = $array[$field] ?? $default;
        } else {
            $value = $default;
        }

        if (is_callable($column['callback'] ?? null)) {
            return call_user_func($column['callback'], $value, $row, $column);
        }

        return $value;
    }

    /**
     * 写入单元格值
     *
     * @param Worksheet $sheet 工作表
     * @param int $columnIndex 列号
     * @param int $rowIndex 行号
     * @param mixed $value 值
     * @param array $column 列配置
     * @return void
     */
    protected function setValue(Worksheet $sheet, int $columnIndex, int $rowIndex, mixed $value, array $column): void
    {
        $cell = $sheet->getCell($this->cell($columnIndex, $rowIndex));
        $type = (string) ($column['type'] ?? 'auto');
        $format = $column['format'] ?? null;

        if ($type === 'formula') {
            $cell->setValue((string) $value);
        } elseif ($type === 'string') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
        } elseif ($type === 'int' || $type === 'integer') {
            $cell->setValue((int) $value);
            $cell->getStyle()->getNumberFormat()->setFormatCode($format ?? NumberFormat::FORMAT_NUMBER);
        } elseif ($type === 'float' || $type === 'decimal') {
            $cell->setValue((float) $value);
            $cell->getStyle()->getNumberFormat()->setFormatCode($format ?? NumberFormat::FORMAT_NUMBER_00);
        } elseif ($type === 'date' || $type === 'datetime') {
            $this->setDate($cell, $value, $format);
        } elseif ($type === 'bool' || $type === 'boolean') {
            $cell->setValue((bool) $value);
        } else {
            $this->setAuto($cell, $value);
        }

        if ($format !== null && !in_array($type, ['int', 'integer', 'float', 'decimal', 'date', 'datetime'], true)) {
            $cell->getStyle()->getNumberFormat()->setFormatCode((string) $format);
        }
    }

    /**
     * 自动设置值
     *
     * @param mixed $cell 单元格
     * @param mixed $value 值
     * @return void
     */
    protected function setAuto(mixed $cell, mixed $value): void
    {
        if ($value instanceof DateTimeInterface) {
            $cell->setValue(Date::PHPToExcel($value));
            $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm:ss');
            return;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            $cell->setValue($value);
            return;
        }

        $string = (string) $value;
        if (preg_match('/^0\d+$/', $string)) {
            $cell->setValueExplicit($string, DataType::TYPE_STRING);
            return;
        }

        $cell->setValue($value);
    }

    /**
     * 设置日期值
     *
     * @param mixed $cell 单元格
     * @param mixed $value 值
     * @param string|null $format 格式
     * @return void
     */
    protected function setDate(mixed $cell, mixed $value, ?string $format): void
    {
        if ($value instanceof DateTimeInterface) {
            $cell->setValue(Date::PHPToExcel($value));
        } elseif (is_numeric($value) && (int) $value > 0) {
            $cell->setValue(Date::PHPToExcel((new DateTimeImmutable())->setTimestamp((int) $value)));
        } elseif (!empty($value) && strtotime((string) $value) !== false) {
            $cell->setValue(Date::PHPToExcel((new DateTimeImmutable())->setTimestamp(strtotime((string) $value))));
        } else {
            $cell->setValue('');
        }

        $cell->getStyle()->getNumberFormat()->setFormatCode($format ?? 'yyyy-mm-dd hh:mm:ss');
    }

    /**
     * 表头行号
     *
     * @param array $options 导出配置
     * @return int
     */
    protected function headerRow(array $options): int
    {
        $row = 1;
        if (!empty($options['title'])) {
            $row++;
        }

        if ($this->hasInfo($options)) {
            $row++;
        }

        return $row;
    }

    /**
     * 是否有信息行
     *
     * @param array $options 导出配置
     * @return bool
     */
    protected function hasInfo(array $options): bool
    {
        return array_key_exists('info', $options)
            || !empty($options['operator'])
            || !empty($options['show_export_time']);
    }

    /**
     * 信息行文字
     *
     * @param array $options 导出配置
     * @return string
     */
    protected function infoText(array $options): string
    {
        if (isset($options['info']) && is_string($options['info'])) {
            return trim($options['info']);
        }

        $items = [];
        if (!empty($options['operator'])) {
            $items[] = '操作人：' . $options['operator'];
        }

        if (!empty($options['operator']) || !empty($options['show_export_time'])) {
            $items[] = '导出时间：' . date('Y-m-d H:i:s');
        }

        if (isset($options['info']) && is_array($options['info'])) {
            foreach ($options['info'] as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $items[] = is_string($key) ? $key . '：' . $value : (string) $value;
            }
        }

        return implode('  ', $items);
    }

    /**
     * 单元格坐标
     *
     * @param int $columnIndex 列号
     * @param int $rowIndex 行号
     * @return string
     */
    protected function cell(int $columnIndex, int $rowIndex): string
    {
        return Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
    }

    /**
     * 工作表名称
     *
     * @param string $title 名称
     * @return string
     */
    protected function sheetTitle(string $title): string
    {
        $title = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $title));
        return $title === '' ? 'Sheet1' : mb_substr($title, 0, 31, 'UTF-8');
    }

    /**
     * 颜色值
     *
     * @param mixed $color 颜色
     * @return string
     */
    protected function color(mixed $color): string
    {
        $color = strtoupper(ltrim(trim((string) $color), '#'));
        return preg_match('/^[0-9A-F]{6}$/', $color) ? $color : '000000';
    }
}
