<?php

namespace app\admin\library\traits;

use app\admin\library\AdminAuth;
use Exception;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use think\db\exception\BindParamException;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\db\exception\PDOException;
use think\exception\ValidateException;
use think\facade\Config;
use think\facade\Db;
use think\response\Json;

trait Backend
{

    /**
     * 排除前台提交过来的字段
     * @param array $params
     * @return array
     */
    protected function preExcludeFields($params)
    {
        if (is_array($this->excludeFields)) {
            foreach ($this->excludeFields as $field) {
                unset($params[$field]);
            }
        } elseif (array_key_exists($this->excludeFields, $params)) {
            unset($params[$this->excludeFields]);
        }
        return $params;
    }

    /**
     * 事务包装器：统一事务+异常处理
     * @param callable $callback 业务逻辑
     * @param string   $failMsg  失败时的提示信息
     */
    private function runInTransaction(callable $callback, string $failMsg = '')
    {
        Db::startTrans();
        try {
            $result = $callback();
            Db::commit();
            return $result;
        } catch (ValidateException | PDOException | Exception $e) {
            Db::rollback();
            $this->error($failMsg ?: $e->getMessage());
        }
    }

    /**
     * 查看
     *
     * @return string|Json
     * @throws \think\Exception
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if (false === $this->request->isAjax()) {
            return $this->view->fetch();
        }
        //如果发送的来源是 Selectpage，则转发到 Selectpage
        if ($this->request->request('keyField')) {
            return $this->selectpage();
        }
        [$where, $sort, $order, $offset, $limit] = $this->buildparams();
        $list = $this->model
            ->where($where)
            ->order($sort, $order)
            ->paginate($limit);
        $result = ['total' => $list->total(), 'rows' => $list->items()];
        return json($result);
    }

    /**
     * 回收站
     *
     * @return string|Json
     * @throws \think\Exception
     */
    public function recyclebin()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if (false === $this->request->isAjax()) {
            return $this->view->fetch();
        }
        [$where, $sort, $order, $offset, $limit] = $this->buildparams();
        $list = $this->model
            ->onlyTrashed()
            ->where($where)
            ->order($sort, $order)
            ->paginate($limit);
        $result = ['total' => $list->total(), 'rows' => $list->items()];
        return json($result);
    }

    /**
     * 添加
     *
     * @return string
     * @throws \think\Exception
     */
    public function add()
    {
        if (false === $this->request->isPost()) {
            return $this->view->fetch();
        }
        $params = $this->request->post('row/a');
        if (empty($params)) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);

        if ($this->dataLimit && $this->dataLimitFieldAutoFill) {
            $params[$this->dataLimitField] = $this->auth->id;
        }

        $this->runInTransaction(function () use ($params) {
            //是否采用模型验证
            if ($this->modelValidate) {
                $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.add' : $name) : $this->modelValidate;
                $this->model->validate($validate);
            }
            $result = $this->model->allowField(true)->save($params);
            if ($result === false) {
                throw new \think\Exception(__('No rows were inserted'));
            }
            return $result;
        });

        $this->success();
    }

    /**
     * 编辑
     *
     * @param $ids
     * @return string
     * @throws DbException
     * @throws \think\Exception
     */
    public function edit($ids = null)
    {
        $row = $this->model->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds) && !in_array($row[$this->dataLimitField], $adminIds)) {
            $this->error(__('You have no permission'));
        }
        if (false === $this->request->isPost()) {
            $this->view->assign('row', $row);
            return $this->view->fetch();
        }
        $params = $this->request->post('row/a');
        if (empty($params)) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);

        $this->runInTransaction(function () use ($row, $params) {
            //是否采用模型验证
            if ($this->modelValidate) {
                $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
                validate($validate)->check($params);
            }
            $result = $row->save($params);
            if (false === $result) {
                throw new \think\Exception(__('No rows were updated'));
            }
            return $result;
        });

        $this->success();
    }

    /**
     * 删除
     *
     * @param $ids
     * @return void
     * @throws DbException
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     */
    public function del($ids = null)
    {
        if (false === $this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $ids = $ids ?: $this->request->post("ids");
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $pk = $this->model->getPk();
        $adminIds = $this->getDataLimitAdminIds();
        $where[] = [$pk, 'in', $ids];
        if (is_array($adminIds)) {
            $where[] = [$this->dataLimitField, 'in', $adminIds];
        }

        $count = $this->runInTransaction(function () use ($where) {
            $count = 0;
            $list = $this->model->where($where)->select();
            foreach ($list as $item) {
                if ($item->delete()) {
                    $count++;
                }
            }
            return $count;
        });

        if ($count > 0) {
            $this->success();
        }
        $this->error(__('No rows were deleted'));
    }

    /**
     * 真实删除
     *
     * @param $ids
     * @return void
     */
    public function destroy($ids = null)
    {
        if (false === $this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $ids = $ids ?: $this->request->post('ids');
        $pk = $this->model->getPk();
        $adminIds = $this->getDataLimitAdminIds();
        $where = [];
        if (is_array($adminIds)) {
            $where[] = [$this->dataLimitField, 'in', $adminIds];
        }
        if ($ids) {
            $where[] = [$pk, 'in', $ids];
        }

        $count = $this->runInTransaction(function () use ($where) {
            $count = 0;
            $list = $this->model->onlyTrashed()->where($where)->select();
            foreach ($list as $item) {
                $count += $item->force()->delete();
            }
            return $count;
        });

        if ($count) {
            $this->success();
        }
        $this->error(__('No rows were deleted'));
    }

    /**
     * 还原
     *
     * @param $ids
     * @return void
     */
    public function restore($ids = null)
    {
        if (false === $this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $pk = $this->model->getPk();
        $adminIds = $this->getDataLimitAdminIds();
        $where = [];
        if (is_array($adminIds)) {
            $where[] = [$this->dataLimitField, 'in', $adminIds];
        }
        if ($ids) {
            $where[] = [$pk, 'in', $ids];
        }

        $count = $this->runInTransaction(function () use ($where) {
            $count = 0;
            $list = $this->model->onlyTrashed()->where($where)->select();
            foreach ($list as $item) {
                if ($item->restore()) {
                    $count++;
                }
            }
            return $count;
        });

        if ($count > 0) {
            $this->success();
        }
        $this->error(__('No rows were updated'));
    }

    /**
     * 批量更新
     *
     * @param $ids
     * @return void
     */
    public function multi($ids = null)
    {
        if (false === $this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }

        if (false === $this->request->has('params')) {
            $this->error(__('No rows were updated'));
        }
        parse_str($this->request->post('params'), $values);
        $values = $this->auth->isSuperAdmin() ? $values : array_intersect_key($values, array_flip(is_array($this->multiFields) ? $this->multiFields : explode(',', $this->multiFields)));
        if (empty($values)) {
            $this->error(__('You have no permission'));
        }
        $adminIds = $this->getDataLimitAdminIds();
        $pk = $this->model->getPk();
        $where[] = [$pk, 'in', $ids];
        if (is_array($adminIds)) {
            $where[] = [$this->dataLimitField, 'in', $adminIds];
        }

        $count = $this->runInTransaction(function () use ($where, $values) {
            $count = 0;
            $list = $this->model->where($where)->select();
            foreach ($list as $item) {
                if ($item->save($values)) {
                    $count++;
                }
            }
            return $count;
        });

        if ($count > 0) {
            $this->success();
        }
        $this->error(__('No rows were updated'));
    }

    /**
     * 导入
     *
     * @return void
     * @throws PDOException
     * @throws BindParamException
     */
    protected function import()
    {
        $file = $this->request->request('file');
        if (!$file) {
            $this->error(__('Parameter %s can not be empty', 'file'));
        }
        $filePath = public_path() . $file;
        if (!is_file($filePath)) {
            $this->error(__('No results were found'));
        }
        //实例化reader
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xls', 'xlsx'])) {
            $this->error(__('Unknown data format'));
        }

        $tempFilePath = null;
        // CSV文件需要转换为UTF-8编码
        if ($ext === 'csv') {
            $tempPath = tempnam(sys_get_temp_dir(), 'import_csv');
            $fp = fopen($tempPath, 'w');
            $fp2 = fopen($filePath, 'r');
            $n = 0;
            while ($line = fgets($fp2)) {
                $line = rtrim($line, "\n\r\0");
                $encoding = mb_detect_encoding($line, ['utf-8', 'gbk', 'latin1', 'big5']);
                if ($encoding !== 'utf-8') {
                    $line = mb_convert_encoding($line, 'utf-8', $encoding);
                }
                if ($n == 0 || preg_match('/^".*"$/', $line)) {
                    fwrite($fp, $line . "\n");
                } else {
                    fwrite($fp, '"' . str_replace(['"', ','], ['""', '","'], $line) . "\"\n");
                }
                $n++;
            }
            fclose($fp2);
            fclose($fp);
            $tempFilePath = $tempPath;
            $filePath = $tempFilePath;
        }

        //导入文件首行类型,默认是注释,如果需要使用字段名称请使用name
        $importHeadType = !empty($this->importHeadType) ? $this->importHeadType : 'comment';

        $table = $this->model->getQuery()->getTable();
        $default = Config::get('database.default');
        $database = Config::get('database.connections.' . $default . '.database');
        $fieldArr = [];
        try {
            $list = Db::query("SELECT COLUMN_NAME,COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?", [$table, $database]);
            foreach ($list as $v) {
                if ($importHeadType == 'comment') {
                    $comment = explode(':', $v['COLUMN_COMMENT'])[0];
                    $fieldArr[$comment] = $v['COLUMN_NAME'];
                } else {
                    $fieldArr[$v['COLUMN_NAME']] = $v['COLUMN_NAME'];
                }
            }
        } catch (Exception $e) {
            if ($tempFilePath) {
                @unlink($tempFilePath);
            }
            $this->error($e->getMessage());
        }

        //加载文件
        $insert = [];
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);

            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            // 读取表头
            $fields = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $columnLetter = Coordinate::stringFromColumnIndex($col);
                $fields[] = $sheet->getCell($columnLetter . '1')->getValue();
            }

            // 读取数据行
            for ($row = 2; $row <= $highestRow; $row++) {
                $values = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $columnLetter = Coordinate::stringFromColumnIndex($col);
                    $val = $sheet->getCell($columnLetter . $row)->getValue();
                    $values[] = is_null($val) ? '' : $val;
                }
                $rowData = array_combine($fields, $values);
                $rowData = array_filter($rowData, fn($k) => isset($fieldArr[$k]) && $k !== '', ARRAY_FILTER_USE_KEY);
                $rowData = array_combine(
                    array_map(fn($k) => $fieldArr[$k], array_keys($rowData)),
                    $rowData
                );
                if ($rowData) {
                    $insert[] = $rowData;
                }
            }
            unset($spreadsheet, $sheet);
        } catch (Exception $exception) {
            if ($tempFilePath) {
                @unlink($tempFilePath);
            }
            $this->error($exception->getMessage());
        } finally {
            if ($tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }

        if (!$insert) {
            $this->error(__('No rows were updated'));
        }

        try {
            $hasAdminId = in_array('admin_id', $fieldArr);
            if ($hasAdminId) {
                $auth = AdminAuth::instance();
                foreach ($insert as &$val) {
                    if (empty($val['admin_id'])) {
                        $val['admin_id'] = $auth->isLogin() ? $auth->id : 0;
                    }
                }
                unset($val);
            }
            $this->model->saveAll($insert);
        } catch (PDOException $exception) {
            $msg = $exception->getMessage();
            if (preg_match("/.+Integrity constraint violation: 1062 Duplicate entry '(.+)' for key '(.+)'/is", $msg, $matches)) {
                $msg = "导入失败，包含【{$matches[1]}】的记录已存在";
            }
            $this->error($msg);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }

        $this->success();
    }

    /**
     * 基于原生PHP的高速导出（CSV/XLSX，无需任何扩展）
     * CSV 模式直接流式写入，适合百万级数据
     *
     * @param array $headers 表头名称数组，如 ['ID', '名称', '创建时间']
     * @param array $fieldNames 字段名数组，如 ['id', 'name', 'createtime']，与headers一一对应
     * @param array $data 导出的数据数组，如果为空则根据fieldNames从数据库查询
     * @param string $fileName 导出文件名，不含扩展名
     * @throws Exception
     */
    protected function fastExport($headers = [], $fieldNames = [], $data = [], $fileName = '', $exportLimit = 100000)
    {
        $this->request->filter(['strip_tags', 'trim']);

        $format = $this->request->request('format', 'csv');
        if ($exportLimit <= 0) {
            $exportLimit = (int) $this->request->request('limit', 100000);
            $exportLimit = $exportLimit > 0 ? min($exportLimit, 1000000) : 100000;
        } else {
            $exportLimit = min($exportLimit, 1000000);
        }

        if (empty($headers) || empty($fieldNames)) {
            [$where, $sort, $order] = $this->buildparams();

            $table = $this->model->getQuery()->getTable();
            $default = Config::get('database.default');
            $database = Config::get('database.connections.' . $default . '.database');

            $fieldMap = [];
            $exportFields = $this->exportFields ?? [];
            try {
                $columns = Db::query(
                    "SELECT COLUMN_NAME, COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?",
                    [$table, $database]
                );
                foreach ($columns as $col) {
                    $comment = explode(':', $col['COLUMN_COMMENT'])[0];
                    $fieldName = $col['COLUMN_NAME'];
                    if ($exportFields && !in_array($fieldName, $exportFields)) {
                        continue;
                    }
                    $fieldMap[$fieldName] = $comment ?: $fieldName;
                }
            } catch (Exception $e) {
                $this->error($e->getMessage());
            }

            if (empty($fieldMap)) {
                $this->error(__('No fields available for export'));
            }

            $fieldNames = array_keys($fieldMap);
            $headers = array_values($fieldMap);
        }

        $fileName = $fileName ?: ($this->exportFileName ?? 'export') . '_' . date('YmdHis');

        if ($format === 'csv') {
            $this->fastExportCsv($headers, $fieldNames, $data, $fileName, $exportLimit);
        } else {
            $this->fastExportXlsx($headers, $fieldNames, $data, $fileName, $exportLimit);
        }
    }

    /**
     * CSV 高速导出（流式写入，内存占用极低）
     *
     * @param array $headers 表头名称数组
     * @param array $fieldNames 字段名数组
     * @param array $data 数据数组，如果为空则使用默认查询
     * @param string $fileName 文件名
     * @param int $exportLimit 导出数量限制
     * @throws Exception
     */
    protected function fastExportCsv($headers, $fieldNames, $data = [], $fileName = '', $exportLimit = 100000)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'export_') . '.csv';

        try {
            $fp = fopen($tempFile, 'w');
            if ($fp === false) {
                $this->error('无法创建临时文件');
            }

            // BOM + 表头
            fprintf($fp, "\xEF\xBB\xBF");
            fputcsv($fp, $headers);

            $totalWritten = 0;
            $batchSize = 2000;

            $writeRow = function ($row) use ($fp, &$totalWritten, $fieldNames, $exportLimit) {
                if ($totalWritten >= $exportLimit) {
                    return false;
                }

                $rowData = is_object($row) ? $row->toArray() : $row;
                $line = [];
                foreach ($fieldNames as $fieldName) {
                    $value = $rowData[$fieldName] ?? '';

                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    } elseif (is_null($value)) {
                        $value = '';
                    } elseif (in_array($fieldName, ['createtime', 'updatetime', 'deletetime'])) {
                        $value = $value ? date('Y-m-d H:i:s', $value) : '';
                    }

                    $line[] = $value;
                }

                fputcsv($fp, $line);
                $totalWritten++;

                if ($totalWritten % 20000 === 0) {
                    fflush($fp);
                    gc_collect_cycles();
                }
            };

            if (!empty($data)) {
                foreach ($data as $row) {
                    if ($writeRow($row) === false) break;
                }
            } else {
                [$where, $sort, $order] = $this->buildparams();
                $query = $this->model->where($where)->order($sort, $order)->limit($exportLimit);
                $query->chunk($batchSize, function ($list) use ($writeRow) {
                    foreach ($list as $row) {
                        if ($writeRow($row) === false) {
                            return false;
                        }
                    }
                });
            }

            fclose($fp);
            $fileSize = filesize($tempFile);
            $downloadFileName = $fileName . '.csv';

            header('Content-Description: File Transfer');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . rawurlencode($downloadFileName) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . $fileSize);

            readfile($tempFile);
            @unlink($tempFile);
            return;
        } catch (Exception $e) {
            if (isset($fp) && is_resource($fp)) {
                fclose($fp);
            }
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            $this->error($e->getMessage());
        }
    }

    /**
     * XLSX 高速导出（基于原生 XML 打包，内存效率高）
     *
     * @param array $headers 表头名称数组
     * @param array $fieldNames 字段名数组
     * @param array $data 数据数组，如果为空则使用默认查询
     * @param string $fileName 文件名
     * @param int $exportLimit 导出数量限制
     * @throws Exception
     */
    protected function fastExportXlsx($headers, $fieldNames, $data = [], $fileName = '', $exportLimit = 100000)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headers as $colIndex => $title) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter . '1', $title);
        }

        $currentRow = 2;
        $totalWritten = 0;
        $batchSize = 2000;

        $writeRow = function ($row) use (&$currentRow, &$totalWritten, $sheet, $fieldNames, $exportLimit) {
            if ($totalWritten >= $exportLimit) {
                return false;
            }

            $rowData = is_object($row) ? $row->toArray() : $row;
            foreach ($fieldNames as $colIndex => $fieldName) {
                $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                $value = $rowData[$fieldName] ?? '';

                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_null($value)) {
                    $value = '';
                } elseif (in_array($fieldName, ['createtime', 'updatetime', 'deletetime'])) {
                    $value = $value ? date('Y-m-d H:i:s', $value) : '';
                }

                $sheet->setCellValue($colLetter . $currentRow, $value);
            }
            $currentRow++;
            $totalWritten++;

            if ($totalWritten % 10000 === 0) {
                gc_collect_cycles();
            }
        };

        try {
            if (!empty($data)) {
                foreach ($data as $row) {
                    if ($writeRow($row) === false) break;
                }
            } else {
                [$where, $sort, $order] = $this->buildparams();
                $query = $this->model->where($where)->order($sort, $order)->limit($exportLimit);
                $query->chunk($batchSize, function ($list) use ($writeRow) {
                    foreach ($list as $row) {
                        if ($writeRow($row) === false) {
                            return false;
                        }
                    }
                });
            }
        } catch (Exception $e) {
            unset($spreadsheet);
            $this->error($e->getMessage());
        }

        try {
            ob_end_clean();
            ob_start();

            $writer = new Xlsx($spreadsheet);
            $tempFile = tempnam(sys_get_temp_dir(), 'export_');
            $writer->save($tempFile);

            $fileSize = filesize($tempFile);
            $downloadFileName = $fileName . '.xlsx';

            header('Content-Description: File Transfer');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . rawurlencode($downloadFileName) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . $fileSize);

            readfile($tempFile);
            @unlink($tempFile);
            unset($spreadsheet, $writer);
            return;
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 导出
     *
     * @param array $headers 表头名称数组，如 ['ID', '名称', '创建时间']
     * @param array $fieldNames 字段名数组，如 ['id', 'name', 'createtime']，与headers一一对应
     * @param array $data 导出的数据数组，如果为空则根据fieldNames从数据库查询
     * @param string $fileName 导出文件名，不含扩展名
     * @param int $exportLimit 导出数量限制，默认100000
     * @throws Exception
     */
    protected function export($headers = [], $fieldNames = [], $data = [], $fileName = '', $exportLimit = 100000)
    {
        $this->request->filter(['strip_tags', 'trim']);

        $format = $this->request->request('format', 'xlsx');
        if ($exportLimit <= 0) {
            $exportLimit = (int) $this->request->request('limit', 100000);
            $exportLimit = $exportLimit > 0 ? min($exportLimit, 100000) : 100000;
        } else {
            $exportLimit = min($exportLimit, 100000);
        }

        if (empty($headers) || empty($fieldNames)) {
            [$where, $sort, $order] = $this->buildparams();

            $table = $this->model->getQuery()->getTable();
            $default = Config::get('database.default');
            $database = Config::get('database.connections.' . $default . '.database');

            $fieldMap = [];
            $exportFields = $this->exportFields ?? [];
            try {
                $columns = Db::query(
                    "SELECT COLUMN_NAME, COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?",
                    [$table, $database]
                );
                foreach ($columns as $col) {
                    $comment = explode(':', $col['COLUMN_COMMENT'])[0];
                    $fieldName = $col['COLUMN_NAME'];
                    if ($exportFields && !in_array($fieldName, $exportFields)) {
                        continue;
                    }
                    $fieldMap[$fieldName] = [
                        'comment' => $comment ?: $fieldName,
                        'name' => $fieldName,
                    ];
                }
            } catch (Exception $e) {
                $this->error($e->getMessage());
            }

            if (empty($fieldMap)) {
                $this->error(__('No fields available for export'));
            }

            $fileName = ($this->exportFileName ?? $table) . '_' . date('YmdHis');
            $headers = array_column($fieldMap, 'comment');
            $fieldNames = array_keys($fieldMap);
        } else {
            $fileName = $fileName ?: 'export_' . date('YmdHis');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headers as $colIndex => $title) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter . '1', $title);
        }

        $currentRow = 2;
        $batchSize = 1000;
        $totalWritten = 0;

        $writeRow = function ($row) use (&$currentRow, &$totalWritten, $sheet, $fieldNames, $exportLimit) {
            if ($totalWritten >= $exportLimit) {
                return false;
            }

            $rowData = is_object($row) ? $row->toArray() : $row;
            foreach ($fieldNames as $colIndex => $fieldName) {
                $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                $value = $rowData[$fieldName] ?? '';

                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_null($value)) {
                    $value = '';
                } elseif (in_array($fieldName, ['createtime', 'updatetime', 'deletetime'])) {
                    $value = $value ? date('Y-m-d H:i:s', $value) : '';
                }

                $sheet->setCellValue($colLetter . $currentRow, $value);
            }
            $currentRow++;
            $totalWritten++;

            if ($totalWritten % 10000 === 0) {
                gc_collect_cycles();
            }
        };

        try {
            if (!empty($data)) {
                foreach ($data as $row) {
                    if ($writeRow($row) === false) break;
                }
            } else {
                [$where, $sort, $order] = $this->buildparams();
                $query = $this->model->where($where)->order($sort, $order)->limit($exportLimit);
                $query->chunk($batchSize, function ($list) use ($writeRow) {
                    foreach ($list as $row) {
                        if ($writeRow($row) === false) {
                            return false;
                        }
                    }
                });
            }
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }

        try {
            ob_end_clean();
            ob_start();

            if ($format === 'csv') {
                $writer = new Csv($spreadsheet);
                $writer->setDelimiter(',');
                $writer->setEnclosure('"');
                $writer->setLineEnding("\r\n");
                $writer->setUseBOM(true);
                $contentType = 'text/csv';
            } else {
                $writer = new Xlsx($spreadsheet);
                $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'export_');
            $writer->save($tempFile);

            $fileSize = filesize($tempFile);
            $downloadFileName = $fileName . '.' . $format;

            header('Content-Description: File Transfer');
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . rawurlencode($downloadFileName) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . $fileSize);

            readfile($tempFile);
            @unlink($tempFile);
            unset($spreadsheet, $writer);
            return;
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Excel导出
     *
     * @param string $filename 完整文件名
     * @param array $header 首行
     * @param array $export_data 数据
     */
    protected function exportData(string $filename, array $header, array $export_data)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // 首行
        $h_column = 'A';
        $row = 1;
        foreach ($header as $value) {
            $sheet->getColumnDimension($h_column)->setWidth(25);
            $sheet->setCellValue($h_column . $row, $value);
            $h_column++;
        }
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A1:' . $h_column . $row)->getFont()->setBold(true);

        // 写入数据
        foreach ($export_data as $item) {
            $column = 'A';
            $row++;
            foreach ($item as $kk => $vv) {
                $sheet->setCellValue($column . $row, $vv);
                $column++;
            }
        }

        // 设置内容样式
        $sheet->getStyle('A1:' . $column . $row)->applyFromArray([
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ]
        ]);
        $sheet->getStyle('A1:' . $column . $row)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

        // 下载
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
    }
}
