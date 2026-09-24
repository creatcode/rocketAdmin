<?php

namespace app\admin\service;

use RuntimeException;
use think\facade\Config;
use think\facade\Db;

/**
 * 执行基于普通版本 JSON 和专用 ZIP 的系统升级。
 */
class UpgradeService
{
    private const MAX_VERSION_INFO_SIZE = 1048576;
    private const MAX_PACKAGE_SIZE = 1073741824;
    private const MAX_UNCOMPRESSED_SIZE = 1073741824;
    private const MAX_ARCHIVE_FILES = 20000;
    private const MAX_SQL_FILE_SIZE = 16777216;

    /** 已结束、备份可安全删除的批次状态 */
    private const TERMINAL_STATES = ['done', 'failed', 'failed_rolled_back'];

    private $rootPath;
    private $runtimePath;

    /**
     * 初始化项目和运行目录；目录参数用于隔离离线自检。
     *
     * @param string|null $rootPath 项目根目录
     * @param string|null $runtimePath 运行目录
     * @return void
     */
    public function __construct($rootPath = null, $runtimePath = null)
    {
        $root = realpath($rootPath === null ? root_path() : $rootPath);
        $runtime = realpath($runtimePath === null ? runtime_path() : $runtimePath);
        if ($root === false || $runtime === false || !is_dir($runtime)) {
            throw new RuntimeException('项目根目录或运行目录不可用。');
        }
        $this->rootPath = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $this->runtimePath = rtrim($runtime, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * 读取版本信息并比较版本号，不下载升级包。
     *
     * @param string $currentVersion 当前版本
     * @return array
     */
    public function check(string $currentVersion): array
    {
        $this->validateVersion($currentVersion);
        $latest = $this->fetchVersionInfo();
        return [
            'current_version' => $currentVersion,
            'latest_version' => $latest['version'],
            'notes' => $latest['notes'],
            'download_url' => $latest['url'],
            'available' => version_compare($latest['version'], $currentVersion, '>'),
        ];
    }

    /**
     * 重新读取版本信息，下载并执行一个升级批次。
     *
     * @param string $expectedVersion 管理员确认的版本
     * @return array
     */
    public function run(string $expectedVersion): array
    {
        $latest = $this->fetchVersionInfo();
        if ($expectedVersion === '' || $expectedVersion !== $latest['version']) {
            throw new RuntimeException('版本信息已变化，请重新检查更新。');
        }
        $lock = $this->acquireLock();
        $barrier = null;
        $batch = '';
        try {
            $fromVersion = $this->readVersion();
            if (!version_compare($latest['version'], $fromVersion, '>')) {
                throw new RuntimeException('目标版本必须高于当前版本。');
            }
            if ($this->hasUnfinishedBatch()) {
                throw new RuntimeException('存在未完成的升级批次，请先恢复现场。');
            }

            $batch = $this->newBatchId();
            $this->ensureDirectory($this->batchPath($batch));
            $status = [
                'batch' => $batch,
                'state' => 'preparing',
                'from_version' => $fromVersion,
                'to_version' => $latest['version'],
                'package_url' => $latest['url'],
                'message' => '',
                'has_sql' => false,
                'sql_started' => false,
                'sql_completed' => [],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $this->saveStatus($batch, $status);
            @set_time_limit(0);
            ignore_user_abort(true);

            $status['state'] = 'downloading';
            $this->saveStatus($batch, $status);
            $packageFile = $this->batchPath($batch) . 'package.zip';
            $this->downloadPackage($latest['url'], $packageFile);

            $bundle = $this->inspectPackage($packageFile, $latest['version'], $fromVersion);
            $status['state'] = 'verified';
            $status['has_sql'] = count($bundle['sql']) > 0;
            $status['sql_total'] = count($bundle['sql']);
            $this->saveStatus($batch, $status);

            $stagingPath = $this->batchPath($batch) . 'staging';
            $plan = $this->buildFilePlan($bundle);
            $this->preflight($bundle, $plan);
            $this->extractPayload($packageFile, $stagingPath, $bundle);
            $this->prepareJournal($batch, $plan);

            $this->writeMaintenanceLock($batch);
            $barrier = $this->acquireMaintenanceBarrier();
            if ($bundle['sql']) {
                $status['state'] = 'backing_up';
                $this->saveStatus($batch, $status);
                $backup = $this->createDatabaseBackup($batch);
                $status['database_backup'] = $backup;
                $this->saveStatus($batch, $status);
            }

            $status['state'] = 'applying_files';
            $this->saveStatus($batch, $status);
            $this->applyFileChanges($batch, $stagingPath);

            if ($bundle['sql']) {
                $this->executeSqlFiles($batch, $packageFile, $bundle);
            }

            $status = $this->loadStatus($batch);
            $status['state'] = 'committing';
            $this->saveStatus($batch, $status);
            $this->writeVersion($latest['version']);
            $status['state'] = 'done';
            $status['message'] = '';
            $this->saveStatus($batch, $status);
            $this->clearMaintenanceLock($batch);

            $warning = '';
            try {
                $this->cleanupBatches((int)$this->upgradeConfig()['keep_batches']);
            } catch (\Throwable $cleanupError) {
                $warning = $cleanupError->getMessage();
            }
            return [
                'version' => $latest['version'],
                'batch' => $batch,
                'updated' => count($plan),
                'sql_executed' => count($bundle['sql']),
                'cleanup_warning' => $warning,
            ];
        } catch (\Throwable $e) {
            if ($batch !== '') {
                try {
                    $status = $this->loadStatus($batch);
                    if ($this->maintenanceLockBelongsTo($batch)) {
                        $this->restoreBatch($batch);
                        $status = $this->loadStatus($batch);
                        $status['state'] = 'failed_rolled_back';
                        $restored = !empty($status['database_restored'])
                            ? '数据库和文件均已恢复。'
                            : '文件已恢复，数据库未执行变更。';
                        $status['message'] = '升级失败，' . $restored . '原因：' . $e->getMessage();
                        $this->saveStatus($batch, $status);
                        $this->clearMaintenanceLock($batch);
                    } else {
                        $status['state'] = 'failed';
                        $status['message'] = $e->getMessage();
                        $this->saveStatus($batch, $status);
                    }
                } catch (\Throwable $restoreError) {
                    try {
                        $status = $this->loadStatus($batch);
                        $status['state'] = 'needs_repair';
                        $status['message'] = '升级失败且自动恢复未完成：' . $restoreError->getMessage();
                        $this->saveStatus($batch, $status);
                    } catch (\Throwable $statusError) {
                        // 状态日志无法写入时保留维护锁，避免继续处理请求。
                    }
                    throw new RuntimeException('升级失败，恢复未完成；站点仍处于维护状态。请检查批次 ' . $batch . ' 并人工修复。');
                }
            }
            throw $e;
        } finally {
            $this->releaseMaintenanceBarrier($barrier);
            $this->releaseLock($lock);
        }
    }

    /**
     * 使用已保存的数据库备份和文件日志恢复未完成批次。
     *
     * @param string $batch 批次编号
     * @return array
     */
    public function recover(string $batch): array
    {
        $lock = $this->acquireLock();
        $barrier = null;
        try {
            $status = $this->loadStatus($batch);
            if (!$status || !in_array($status['state'], [
                'preparing', 'downloading', 'verified', 'backing_up', 'applying_files',
                'running_sql', 'committing', 'recovering', 'needs_repair', 'done',
            ], true)) {
                throw new RuntimeException('该批次当前不需要恢复。');
            }
            if ($status['state'] === 'done' && !$this->maintenanceLockBelongsTo($batch)) {
                throw new RuntimeException('已完成的升级批次不需要恢复。');
            }

            $this->writeMaintenanceLock($batch);
            $barrier = $this->acquireMaintenanceBarrier();
            $status['recovery_from'] = $status['state'];
            $status['state'] = 'recovering';
            $this->saveStatus($batch, $status);
            $this->restoreBatch($batch);

            // 文件已按日志回退，版本号必须回到升级前的值，否则会与文件、数据库不一致
            if (!empty($status['from_version'])) {
                $this->writeVersion($status['from_version']);
            }

            $status = $this->loadStatus($batch);
            $status['state'] = 'failed_rolled_back';
            $status['message'] = !empty($status['database_restored'])
                ? '中断的升级批次已从数据库备份恢复，并还原文件。'
                : '中断的升级批次已还原文件；数据库未执行变更。';
            $this->saveStatus($batch, $status);
            $this->clearMaintenanceLock($batch);
            return ['batch' => $batch, 'recovered' => true];
        } catch (\Throwable $e) {
            if ($this->maintenanceLockBelongsTo($batch)) {
                try {
                    $status = $this->loadStatus($batch);
                    $status['state'] = 'needs_repair';
                    $status['message'] = '恢复失败：' . $e->getMessage();
                    $this->saveStatus($batch, $status);
                } catch (\Throwable $statusError) {
                    // 日志不可写时保留维护锁，供人工检查现场。
                }
            }
            throw $e;
        } finally {
            $this->releaseMaintenanceBarrier($barrier);
            $this->releaseLock($lock);
        }
    }

    /**
     * 返回最近批次和需要恢复的任务状态。
     *
     * @return array
     */
    public function dashboard(): array
    {
        $batches = $this->batchStatuses();

        $locked = $this->upgradeLockHeld();
        $interrupted = null;
        foreach ($batches as $status) {
            $state = $status['state'] ?? '';
            $hasMaintenance = $this->maintenanceLockBelongsTo($status['batch']);
            if ($hasMaintenance || !in_array($state, self::TERMINAL_STATES, true)) {
                if ($state === 'done' && !$hasMaintenance) {
                    continue;
                }
                $interrupted = [
                    'batch' => $status['batch'],
                    'state' => $state,
                    'message' => htmlspecialchars((string)($status['message'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'package_url' => htmlspecialchars((string)($status['package_url'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'to_version' => htmlspecialchars((string)($status['to_version'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'sql_total' => max(0, (int)($status['sql_total'] ?? 0)),
                    'sql_completed' => is_array($status['sql_completed'] ?? null) ? count($status['sql_completed']) : 0,
                    'recoverable' => !$locked,
                ];
                break;
            }
        }

        return ['interrupted' => $interrupted, 'history' => $this->buildHistory($batches, 5)];
    }

    /**
     * 返回全部升级批次日志，按批次倒序。
     *
     * @return array
     */
    public function history(): array
    {
        return $this->buildHistory($this->batchStatuses());
    }

    /**
     * 批量删除已结束的升级批次目录。
     *
     * @param array $batches 批次编号列表
     * @return void
     */
    public function deleteBatches(array $batches): void
    {
        $directories = [];
        // 先整体校验再删除：任一不可删就整体拒绝，避免删掉一半再报错
        foreach ($batches as $batch) {
            $batch = (string)$batch;
            if (!$this->isValidBatch($batch)) {
                throw new RuntimeException('批次编号无效。');
            }
            $directory = $this->upgradeRoot() . DIRECTORY_SEPARATOR . $batch;
            if (is_link($directory) || !is_dir($directory)) {
                throw new RuntimeException('批次目录不存在。');
            }
            $state = $this->loadStatus($batch)['state'] ?? '';
            if (!in_array($state, self::TERMINAL_STATES, true)) {
                throw new RuntimeException('仅可删除已完成、失败或已回滚的批次；进行中与待修复的批次必须保留备份。');
            }
            if ($this->maintenanceLockBelongsTo($batch)) {
                throw new RuntimeException('该批次仍处于维护状态，不能删除。');
            }
            $directories[] = $directory;
        }
        foreach ($directories as $directory) {
            $this->removeTree($directory);
        }
    }

    /**
     * 扫描升级批次目录，按批次倒序返回原始状态。
     *
     * @return array
     */
    private function batchStatuses(): array
    {
        $batches = [];
        foreach (glob($this->upgradeRoot() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            $batch = basename($directory);
            if (!$this->isValidBatch($batch) || is_link($directory)) {
                continue;
            }
            $status = $this->loadStatus($batch);
            if ($status) {
                $batches[] = $status;
            }
        }
        usort($batches, function ($left, $right) {
            return strcmp($right['batch'], $left['batch']);
        });
        return $batches;
    }

    /**
     * 把批次状态格式化为日志行。
     *
     * @param array $batches 批次状态列表
     * @param int $limit 返回条数，0 表示不限
     * @return array
     */
    private function buildHistory(array $batches, int $limit = 0): array
    {
        $knownStates = [
            'preparing', 'downloading', 'verified', 'backing_up', 'applying_files',
            'running_sql', 'committing', 'recovering', 'done', 'failed',
            'failed_rolled_back', 'needs_repair',
        ];
        $rows = $limit > 0 ? array_slice($batches, 0, $limit) : $batches;
        $history = [];
        foreach ($rows as $status) {
            $completedSql = $status['sql_completed'] ?? [];
            $history[] = [
                'batch' => htmlspecialchars((string)($status['batch'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'from_version' => htmlspecialchars((string)($status['from_version'] ?? '-'), ENT_QUOTES, 'UTF-8'),
                'to_version' => htmlspecialchars((string)($status['to_version'] ?? '-'), ENT_QUOTES, 'UTF-8'),
                'state' => in_array($status['state'], $knownStates, true) ? $status['state'] : 'needs_repair',
                'created_at' => htmlspecialchars((string)($status['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'),
                'message' => htmlspecialchars((string)($status['message'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'sql_total' => max(0, (int)($status['sql_total'] ?? 0)),
                'sql_completed' => is_array($completedSql) ? count($completedSql) : 0,
            ];
        }
        return $history;
    }

    /**
     * 检查本地升级包布局、版本、路径与 SQL 清单。
     *
     * @param string $packageFile ZIP 文件路径
     * @param string $expectedVersion 预期版本
     * @param string $currentVersion 当前已安装版本
     * @return array
     */
    private function inspectPackage(string $packageFile, string $expectedVersion, string $currentVersion): array
    {
        if (!class_exists(\ZipArchive::class) || !is_file($packageFile)
            || filesize($packageFile) > self::MAX_PACKAGE_SIZE) {
            throw new RuntimeException('升级包不可读或超过大小限制。');
        }

        $zip = new \ZipArchive();
        if ($zip->open($packageFile) !== true) {
            throw new RuntimeException('升级包不是有效的 ZIP 文件。');
        }
        try {
            return $this->inspectArchive($zip, $expectedVersion, $currentVersion);
        } finally {
            $zip->close();
        }
    }

    /**
     * 校验 ZIP 项和 upgrade.json，不解压包内路径。
     *
     * @param \ZipArchive $zip 已打开的 ZIP 文件
     * @param string $expectedVersion 预期版本
     * @param string $currentVersion 当前已安装版本
     * @return array
     */
    private function inspectArchive(\ZipArchive $zip, string $expectedVersion, string $currentVersion): array
    {
        $seen = [];
        $sqlEntries = [];
        $payloadFiles = [];
        $totalSize = 0;
        if ($zip->numFiles > self::MAX_ARCHIVE_FILES) {
            throw new RuntimeException('升级包文件数量超过限制。');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || !$this->isSafeArchiveName($name)) {
                throw new RuntimeException('升级包包含非法路径。');
            }
            $directory = substr($name, -1) === '/';
            $normalized = rtrim($name, '/');
            if ($normalized === 'payload' && !$directory) {
                throw new RuntimeException('payload 根路径必须是目录。');
            }
            $key = strtolower($normalized);
            if (isset($seen[$key])) {
                throw new RuntimeException('升级包包含重复路径。');
            }
            $seen[$key] = true;

            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['size']) || $stat['size'] < 0
                || ($totalSize += (int)$stat['size']) > self::MAX_UNCOMPRESSED_SIZE) {
                throw new RuntimeException('升级包解压后的体积超过限制。');
            }
            $segments = explode('/', $normalized);
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)
                && $opsys === \ZipArchive::OPSYS_UNIX) {
                $type = ($attributes >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    throw new RuntimeException('升级包包含符号链接或特殊文件。');
                }
            }

            if ($segments[0] === 'payload') {
                $relative = implode('/', array_slice($segments, 1));
                if ($relative !== '' && $this->isProtectedPath($relative)) {
                    throw new RuntimeException('升级包试图覆盖受保护路径：' . $relative);
                }
                if ($relative !== '' && !$directory) {
                    $payloadFiles[$relative] = [
                        'name' => $name,
                        'size' => (int)$stat['size'],
                        'mode' => $this->archiveMode($zip, $index),
                    ];
                }
                continue;
            }

            if (($normalized === 'upgrade' && $directory)
                || ($normalized === 'upgrade/sql' && $directory)
                || ($normalized === 'upgrade/upgrade.json' && !$directory)) {
                continue;
            }
            if ($segments[0] !== 'upgrade' || $directory || count($segments) !== 3
                || $segments[1] !== 'sql'
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sql$/iD', $segments[2])) {
                throw new RuntimeException('升级包只允许包含 payload/ 和 upgrade/ 元数据。');
            }
            $sqlEntries[$normalized] = (int)$stat['size'];
        }

        if (!isset($seen['payload'], $seen['upgrade/upgrade.json'])) {
            throw new RuntimeException('升级包必须包含 payload/ 和 upgrade/upgrade.json。');
        }
        $manifestJson = $zip->getFromName('upgrade/upgrade.json');
        if (!is_string($manifestJson) || strlen($manifestJson) > self::MAX_VERSION_INFO_SIZE) {
            throw new RuntimeException('升级包 upgrade.json 缺失或过大。');
        }
        $manifest = $this->decodeJson($manifestJson, '升级包 upgrade.json 格式错误。');
        if (!is_array($manifest) || count($manifest) !== 4
            || !isset($manifest['version'], $manifest['min_version'], $manifest['delete'], $manifest['sql'])
            || !is_string($manifest['version']) || !is_string($manifest['min_version'])
            || !$this->validVersion($manifest['version']) || !$this->validVersion($manifest['min_version'])
            || !version_compare($manifest['version'], $manifest['min_version'], '>')
            || !is_array($manifest['delete']) || !$this->isList($manifest['delete'])
            || !is_array($manifest['sql']) || !$this->isList($manifest['sql'])) {
            throw new RuntimeException('升级包版本或清单格式不匹配。');
        }
        if ($manifest['version'] !== $expectedVersion) {
            throw new RuntimeException('升级包版本与远程版本信息不一致。');
        }
        if (version_compare($currentVersion, $manifest['min_version'], '<')
            || !version_compare($manifest['version'], $currentVersion, '>')) {
            throw new RuntimeException('该升级包支持从 ' . $manifest['min_version']
                . ' 到目标版本之前的版本升级，当前版本为 ' . $currentVersion . '。');
        }

        $delete = [];
        $targetKeys = [];
        foreach ($manifest['delete'] as $path) {
            if (!is_string($path)) {
                throw new RuntimeException('删除清单包含无效路径。');
            }
            $path = $this->normalizeRelativePath($path);
            $key = strtolower($path);
            if ($this->isProtectedPath($path) || isset($targetKeys[$key])) {
                throw new RuntimeException('删除清单包含受保护或重复路径。');
            }
            $targetKeys[$key] = true;
            $delete[] = $path;
        }

        $sql = [];
        $referenced = [];
        $previousSqlVersion = $manifest['min_version'];
        foreach ($manifest['sql'] as $entry) {
            if (!is_array($entry) || count($entry) !== 2
                || !isset($entry['version'], $entry['file'])
                || !is_string($entry['version']) || !is_string($entry['file'])
                || !$this->validVersion($entry['version'])
                || !version_compare($entry['version'], $manifest['min_version'], '>')
                || version_compare($entry['version'], $manifest['version'], '>')
                || version_compare($entry['version'], $previousSqlVersion, '<')) {
                throw new RuntimeException('SQL 清单版本或顺序无效。');
            }
            $previousSqlVersion = $entry['version'];
            $path = $this->normalizeRelativePath($entry['file']);
            if (!preg_match('#^upgrade/sql/[A-Za-z0-9][A-Za-z0-9._-]*\.sql$#iD', $path)) {
                throw new RuntimeException('SQL 文件必须位于 upgrade/sql/。');
            }
            $key = strtolower($path);
            if (isset($referenced[$key]) || !isset($sqlEntries[$path])) {
                throw new RuntimeException('SQL 清单包含重复或缺失文件。');
            }
            if ($sqlEntries[$path] > self::MAX_SQL_FILE_SIZE) {
                throw new RuntimeException('单个 SQL 文件超过大小限制。');
            }
            $contents = $zip->getFromName($path);
            if (!is_string($contents) || trim($contents) === '' || strpos($contents, "\0") !== false) {
                throw new RuntimeException('SQL 文件为空或格式无效：' . basename($path));
            }
            $referenced[$key] = true;
            if (version_compare($entry['version'], $currentVersion, '>')) {
                $sql[] = ['path' => $path, 'sha256' => hash('sha256', $contents)];
            }
        }
        if (count($referenced) !== count($sqlEntries)) {
            throw new RuntimeException('升级包包含未在清单中声明的 SQL 文件。');
        }

        foreach ($payloadFiles as $path => $file) {
            $key = strtolower($path);
            if (isset($targetKeys[$key])) {
                throw new RuntimeException('升级包覆盖和删除了同一路径。');
            }
            $targetKeys[$key] = true;
        }
        ksort($payloadFiles, SORT_STRING);
        return [
            'version' => $manifest['version'],
            'min_version' => $manifest['min_version'],
            'delete' => $delete,
            'sql' => $sql,
            'payload_files' => $payloadFiles,
            'uncompressed_size' => $totalSize,
        ];
    }

    /**
     * 根据 ZIP Unix 属性读取目标文件权限，缺省使用普通文件权限。
     *
     * @param \ZipArchive $zip 已打开的 ZIP 文件
     * @param int $index ZIP 项索引
     * @return int
     */
    private function archiveMode(\ZipArchive $zip, int $index): int
    {
        $opsys = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)
            && $opsys === \ZipArchive::OPSYS_UNIX) {
            $mode = ($attributes >> 16) & 0777;
            return $mode ?: 0644;
        }
        return 0644;
    }


    /**
     * 将 payload 文件流式写入批次暂存目录。
     *
     * @param string $packageFile ZIP 文件路径
     * @param string $stagingPath 暂存目录
     * @param array $bundle 已验证包内容
     * @return void
     */
    private function extractPayload(string $packageFile, string $stagingPath, array $bundle): void
    {
        if (file_exists($stagingPath) || is_link($stagingPath)) {
            throw new RuntimeException('升级暂存目录已存在。');
        }
        $this->ensureDirectory($stagingPath);
        $zip = new \ZipArchive();
        if ($zip->open($packageFile) !== true) {
            throw new RuntimeException('升级包无法重新打开。');
        }
        try {
            foreach ($bundle['payload_files'] as $relative => $metadata) {
                $target = $stagingPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->ensureDirectory(dirname($target));
                $input = $zip->getStream($metadata['name']);
                $output = @fopen($target, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new RuntimeException('无法写入升级暂存文件：' . $relative);
                }
                $copied = stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if ($copied !== $metadata['size']) {
                    throw new RuntimeException('升级暂存文件写入不完整：' . $relative);
                }
                @chmod($target, $metadata['mode']);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * 生成覆盖、新增和删除计划，并检查目标类型。
     *
     * @param array $bundle 已验证包内容
     * @return array
     */
    private function buildFilePlan(array $bundle): array
    {
        $plan = [];
        foreach ($bundle['payload_files'] as $relative => $metadata) {
            $target = $this->projectFile($relative);
            if (is_dir($target) || (file_exists($target) && !is_file($target))) {
                throw new RuntimeException('升级目标不是普通文件：' . $relative);
            }
            $existed = is_file($target);
            $permissions = $existed ? fileperms($target) : false;
            $plan[] = [
                'path' => $relative,
                'action' => 'write',
                'existed' => $existed,
                'mode' => $metadata['mode'],
                'backup_mode' => $permissions === false ? 0644 : ($permissions & 0777),
            ];
        }
        foreach ($bundle['delete'] as $relative) {
            $target = $this->projectFile($relative);
            if (is_dir($target) || (file_exists($target) && !is_file($target))) {
                throw new RuntimeException('删除目标不是普通文件：' . $relative);
            }
            $existed = is_file($target);
            $permissions = $existed ? fileperms($target) : false;
            $plan[] = [
                'path' => $relative,
                'action' => 'delete',
                'existed' => $existed,
                'mode' => 0644,
                'backup_mode' => $permissions === false ? 0644 : ($permissions & 0777),
            ];
        }
        return $plan;
    }

    /**
     * 检查写入权限和运行目录可用空间。
     *
     * @param array $bundle 已验证包内容
     * @param array $plan 文件操作计划
     * @return void
     */
    private function preflight(array $bundle, array $plan): void
    {
        if (!is_writable($this->rootPath) || !is_writable($this->runtimePath)
            || !is_writable($this->projectFile('config/rocket.php'))) {
            throw new RuntimeException('项目文件或运行目录不可写。');
        }

        $backupBytes = (int)filesize($this->projectFile('config/rocket.php'));
        foreach ($plan as $item) {
            $target = $this->projectFile($item['path']);
            $parent = dirname($target);
            while (!is_dir($parent)) {
                $parent = dirname($parent);
            }
            if (!is_writable($parent) || ($item['existed'] && !is_writable($target))) {
                throw new RuntimeException('升级目标目录或文件不可写：' . $item['path']);
            }
            if ($item['existed']) {
                $backupBytes += (int)filesize($target);
            }
        }

        $databaseBytes = $bundle['sql'] ? $this->estimateDatabaseSize() : 0;
        $required = $bundle['uncompressed_size'] + $backupBytes
            + (int)ceil($databaseBytes * 1.2) + 67108864;
        $available = @disk_free_space($this->runtimePath);
        if (!is_numeric($available) || $available < $required) {
            throw new RuntimeException('运行目录可用空间不足，升级前需要约 ' . $this->formatBytes($required) . '。');
        }
    }

    /**
     * 创建文件恢复日志并备份版本配置原件。
     *
     * @param string $batch 批次编号
     * @param array $plan 文件操作计划
     * @return void
     */
    private function prepareJournal(string $batch, array $plan): void
    {
        $backupRoot = $this->batchPath($batch) . 'backup';
        $this->ensureDirectory($backupRoot);
        $versionSource = $this->projectFile('config/rocket.php');
        $versionBackup = $backupRoot . DIRECTORY_SEPARATOR . 'rocket.php';
        $versionMode = fileperms($versionSource);
        if (!copy($versionSource, $versionBackup)) {
            throw new RuntimeException('版本配置备份失败。');
        }
        $versionHash = hash_file('sha256', $versionBackup);
        if (!is_string($versionHash)) {
            throw new RuntimeException('版本配置备份校验失败。');
        }

        $files = [];
        foreach ($plan as $item) {
            $files[] = [
                'path' => $item['path'],
                'action' => $item['action'],
                'existed' => $item['existed'],
                'mode' => $item['mode'],
                'backup_mode' => $item['backup_mode'],
                'backup_ready' => false,
            ];
        }
        $this->saveJournal($batch, [
            'files' => $files,
            'version_backup_ready' => true,
            'version_backup_sha256' => $versionHash,
            'version_backup_mode' => $versionMode === false ? 0644 : ($versionMode & 0777),
        ]);
    }

    /**
     * 逐项先备份并记录，再覆盖、新增或删除文件。
     *
     * @param string $batch 批次编号
     * @param string $stagingPath 暂存目录
     * @return void
     */
    private function applyFileChanges(string $batch, string $stagingPath): void
    {
        $journal = $this->loadJournal($batch);
        foreach ($journal['files'] as $index => $item) {
            $target = $this->projectFile($item['path']);
            if ($item['existed'] !== is_file($target)) {
                throw new RuntimeException('升级期间文件状态发生变化：' . $item['path']);
            }
            if ($item['existed']) {
                $backup = $this->fileBackupPath($batch, $item['path']);
                $this->ensureDirectory(dirname($backup));
                if (!copy($target, $backup)) {
                    throw new RuntimeException('原文件备份失败：' . $item['path']);
                }
                $backupHash = hash_file('sha256', $backup);
                if (!is_string($backupHash)) {
                    throw new RuntimeException('原文件备份校验失败：' . $item['path']);
                }
                $journal['files'][$index]['backup_sha256'] = $backupHash;
            }
            $journal['files'][$index]['backup_ready'] = true;
            $this->saveJournal($batch, $journal);

            if ($item['action'] === 'write') {
                $source = $stagingPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['path']);
                $this->replaceFile($source, $target, (int)$item['mode']);
            } elseif ($item['existed'] && !unlink($target)) {
                throw new RuntimeException('旧文件删除失败：' . $item['path']);
            }
        }
    }

    /**
     * 按日志逆序恢复文件和版本配置。
     *
     * @param string $batch 批次编号
     * @return void
     */
    private function restoreFiles(string $batch): void
    {
        $journal = $this->loadJournal($batch);
        foreach (array_reverse($journal['files'], true) as $item) {
            if (empty($item['backup_ready'])) {
                continue;
            }
            $target = $this->projectFile($item['path']);
            if ($item['existed']) {
                $backup = $this->fileBackupPath($batch, $item['path']);
                if (!is_file($backup) || is_link($backup)
                    || hash_file('sha256', $backup) !== ($item['backup_sha256'] ?? '')) {
                    throw new RuntimeException('文件备份缺失或校验失败：' . $item['path']);
                }
                $this->replaceFile($backup, $target, (int)$item['backup_mode']);
            } elseif (file_exists($target)) {
                if (!is_file($target) || is_link($target) || !unlink($target)) {
                    throw new RuntimeException('新增文件无法移除：' . $item['path']);
                }
            }
        }

        if (!empty($journal['version_backup_ready'])) {
            $backup = $this->batchPath($batch) . 'backup' . DIRECTORY_SEPARATOR . 'rocket.php';
            if (!is_file($backup) || is_link($backup)
                || hash_file('sha256', $backup) !== ($journal['version_backup_sha256'] ?? '')) {
                throw new RuntimeException('版本配置备份缺失或校验失败。');
            }
            $this->replaceFile($backup, $this->projectFile('config/rocket.php'), (int)($journal['version_backup_mode'] ?? 0644));
        }
    }

    /**
     * 按原子替换方式写入文件。
     *
     * @param string $source 源文件
     * @param string $target 目标文件
     * @param int $mode 文件权限
     * @return void
     */
    private function replaceFile(string $source, string $target, int $mode): void
    {
        $this->ensureDirectory(dirname($target));
        if (is_link($target)) {
            throw new RuntimeException('目标文件是符号链接。');
        }
        $temporary = $target . '.upgrade-' . bin2hex(random_bytes(6));
        if (!copy($source, $temporary)) {
            throw new RuntimeException('升级文件复制失败。');
        }
        @chmod($temporary, $mode ?: 0644);
        if (is_file($target) && !unlink($target)) {
            @unlink($temporary);
            throw new RuntimeException('原目标文件无法替换。');
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('升级文件无法写入目标位置。');
        }
    }

    /**
     * 执行已声明 SQL 并记录成功项的文件标识和 SHA-256。
     *
     * @param string $batch 批次编号
     * @param string $packageFile 升级 ZIP
     * @param array $bundle 已验证包内容
     * @return void
     */
    private function executeSqlFiles(string $batch, string $packageFile, array $bundle): void
    {
        $status = $this->loadStatus($batch);
        $status['state'] = 'running_sql';
        $this->saveStatus($batch, $status);

        $zip = new \ZipArchive();
        if ($zip->open($packageFile) !== true) {
            throw new RuntimeException('执行 SQL 时升级包无法打开。');
        }
        $batchPath = $this->batchPath($batch);
        $optionFile = $batchPath . 'client.cnf';
        try {
            $config = $this->databaseConfig();
            $this->writeClientConfig($config, $optionFile);
            $binary = $this->databaseBinary('MYSQL_BINARY', 'mysql');
            $status['sql_started'] = true;
            $this->saveStatus($batch, $status);
            foreach ($bundle['sql'] as $item) {
                if (isset($status['sql_completed'][$item['path']])) {
                    if ($status['sql_completed'][$item['path']] !== $item['sha256']) {
                        throw new RuntimeException('已执行 SQL 的校验值发生变化。');
                    }
                    continue;
                }
                $sql = $zip->getFromName($item['path']);
                if (!is_string($sql) || hash('sha256', $sql) !== $item['sha256']) {
                    throw new RuntimeException('升级包 SQL 文件校验失败：' . basename($item['path']));
                }
                $sqlDirectory = $batchPath . 'sql-execution';
                $this->ensureDirectory($sqlDirectory);
                $sqlFile = $sqlDirectory . DIRECTORY_SEPARATOR . basename($item['path']);
                $this->writeAtomic($sqlFile, $sql);
                try {
                    $result = $this->runProcess([
                        $binary,
                        '--defaults-extra-file=' . $optionFile,
                        '--default-character-set=utf8mb4',
                        '--database=' . $config['database'],
                    ], $sqlFile, $batchPath . 'sql.out', $batchPath . 'sql.err');
                    if ($result['exit_code'] !== 0) {
                        throw new RuntimeException('SQL 执行失败：' . basename($item['path']) . '；' . $result['error']);
                    }
                } finally {
                    if (is_file($sqlFile) && !unlink($sqlFile)) {
                        throw new RuntimeException('SQL 临时文件无法删除：' . basename($item['path']));
                    }
                }
                $status['sql_completed'][$item['path']] = $item['sha256'];
                $this->saveStatus($batch, $status);
            }
        } finally {
            $zip->close();
            if (is_file($optionFile) && !unlink($optionFile)) {
                throw new RuntimeException('数据库客户端临时配置无法删除。');
            }
        }
    }

    /**
     * 估算数据库现有表体积，用于升级前磁盘检查。
     *
     * @return int
     */
    private function estimateDatabaseSize(): int
    {
        $config = $this->databaseConfig();
        $pdo = Db::connect()->getPdo();
        $statement = $pdo->prepare(
            'SELECT COALESCE(SUM(data_length + index_length), 0) '
            . 'FROM information_schema.tables WHERE table_schema = :database'
        );
        $statement->execute(['database' => $config['database']]);
        return (int)$statement->fetchColumn();
    }

    /**
     * 创建整库可恢复备份；失败时停止后续变更。
     *
     * @param string $batch 批次编号
     * @return array
     */
    private function createDatabaseBackup(string $batch): array
    {
        $config = $this->databaseConfig();
        $batchPath = $this->batchPath($batch);
        $optionFile = $batchPath . 'client.cnf';
        $output = $batchPath . 'database.sql';
        $errors = $batchPath . 'database-dump.err';

        try {
            $this->writeClientConfig($config, $optionFile);
            $dumpBinary = $this->databaseBinary('MYSQLDUMP_BINARY', 'mysqldump');
            $mysqlBinary = $this->databaseBinary('MYSQL_BINARY', 'mysql');
            foreach ([$dumpBinary, $mysqlBinary] as $binary) {
                $probe = $this->runProcess(
                    [$binary, '--version'],
                    null,
                    $batchPath . 'client-version.out',
                    $batchPath . 'client-version.err'
                );
                if ($probe['exit_code'] !== 0) {
                    throw new RuntimeException('MySQL 备份和恢复客户端均须可运行。' . $probe['error']);
                }
            }

            $result = $this->runProcess([
                $dumpBinary,
                '--defaults-extra-file=' . $optionFile,
                '--default-character-set=utf8mb4',
                '--lock-all-tables',
                '--routines',
                '--events',
                '--triggers',
                '--hex-blob',
                '--add-drop-database',
                '--databases',
                $config['database'],
            ], null, $output, $errors);
            if ($result['exit_code'] !== 0 || !is_file($output) || filesize($output) < 64) {
                throw new RuntimeException('数据库备份失败：' . $result['error']);
            }
            $backupHash = hash_file('sha256', $output);
            if (!is_string($backupHash)) {
                throw new RuntimeException('数据库备份校验失败。');
            }
            return [
                'file' => 'database.sql',
                'size' => (int)filesize($output),
                'sha256' => $backupHash,
            ];
        } finally {
            if (is_file($optionFile) && !unlink($optionFile)) {
                throw new RuntimeException('数据库客户端临时配置无法删除。');
            }
        }
    }

    /**
     * 使用数据库备份恢复数据并验证 MySQL 连通性。
     *
     * @param string $batch 批次编号
     * @param array $status 批次状态
     * @return void
     */
    private function restoreDatabase(string $batch, array $status): void
    {
        $backup = $status['database_backup'] ?? [];
        $backupFile = $this->batchPath($batch) . 'database.sql';
        if (!is_array($backup) || ($backup['file'] ?? '') !== 'database.sql'
            || !is_file($backupFile) || is_link($backupFile)
            || filesize($backupFile) !== (int)($backup['size'] ?? -1)
            || hash_file('sha256', $backupFile) !== ($backup['sha256'] ?? '')) {
            throw new RuntimeException('数据库备份缺失或校验失败。');
        }

        $config = $this->databaseConfig();
        $batchPath = $this->batchPath($batch);
        $optionFile = $batchPath . 'client.cnf';
        $output = $batchPath . 'database-restore.out';
        $errors = $batchPath . 'database-restore.err';
        try {
            $this->writeClientConfig($config, $optionFile);
            $binary = $this->databaseBinary('MYSQL_BINARY', 'mysql');
            $result = $this->runProcess([
                $binary,
                '--defaults-extra-file=' . $optionFile,
                '--default-character-set=utf8mb4',
                '--binary-mode',
            ], $backupFile, $output, $errors);
            if ($result['exit_code'] !== 0) {
                throw new RuntimeException('数据库恢复失败：' . $result['error']);
            }
            $verify = $this->runProcess([
                $binary,
                '--defaults-extra-file=' . $optionFile,
                '--default-character-set=utf8mb4',
                '--batch',
                '--skip-column-names',
                '--database=' . $config['database'],
                '--execute=SELECT 1',
            ], null, $output, $errors);
            if ($verify['exit_code'] !== 0 || trim((string)file_get_contents($output)) !== '1') {
                throw new RuntimeException('数据库恢复后连通性验证失败：' . $verify['error']);
            }
        } finally {
            if (is_file($optionFile) && !unlink($optionFile)) {
                throw new RuntimeException('数据库客户端临时配置无法删除。');
            }
        }
    }


    /**
     * 运行数据库客户端，避免通过 shell 拼接命令参数。
     *
     * @param array $command 程序及参数
     * @param string|null $inputFile 标准输入文件
     * @param string $outputFile 标准输出文件
     * @param string $errorFile 标准错误文件
     * @return array
     */
    private function runProcess(array $command, ?string $inputFile, string $outputFile, string $errorFile): array
    {
        $descriptors = [
            0 => $inputFile === null ? ['pipe', 'r'] : ['file', $inputFile, 'r'],
            1 => ['file', $outputFile, 'wb'],
            2 => ['file', $errorFile, 'wb'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动数据库备份或恢复程序。');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $exitCode = proc_close($process);
        $error = is_file($errorFile) ? trim((string)file_get_contents($errorFile)) : '';
        return ['exit_code' => $exitCode, 'error' => substr($error, -2000)];
    }

    /**
     * 读取 ThinkPHP 当前 MySQL 连接参数。
     *
     * @return array
     */
    private function databaseConfig(): array
    {
        $config = Config::get('database.connections.mysql', []);
        if (!is_array($config)) {
            throw new RuntimeException('无法读取 MySQL 连接配置。');
        }
        $database = (string)($config['database'] ?? '');
        $host = (string)($config['hostname'] ?? '');
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        $port = (int)($config['hostport'] ?? 3306);
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', $database)
            || $host === '' || $username === '' || $port < 1 || $port > 65535
            || preg_match('/[\r\n\0]/', $host . $username . $password)) {
            throw new RuntimeException('MySQL 配置缺少有效的数据库名、主机或账号。');
        }
        return [
            'database' => $database,
            'hostname' => $host,
            'username' => $username,
            'password' => $password,
            'hostport' => $port,
        ];
    }

    /**
     * 将数据库账号写入运行目录的临时客户端配置。
     *
     * @param array $config 数据库参数
     * @param string $path 临时配置路径
     * @return void
     */
    private function writeClientConfig(array $config, string $path): void
    {
        $escape = function ($value) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$value) . '"';
        };
        $contents = "[client]\n"
            . 'host=' . $escape($config['hostname']) . "\n"
            . 'port=' . (int)$config['hostport'] . "\n"
            . 'user=' . $escape($config['username']) . "\n"
            . 'password=' . $escape($config['password']) . "\n"
            . "protocol=tcp\n";
        $this->writeAtomic($path, $contents);
        if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($path, 0600)) {
            throw new RuntimeException('数据库客户端临时配置权限无法收紧。');
        }
    }

    /**
     * 获取数据库客户端命令，可由环境变量指定完整路径。
     *
     * @param string $environment 环境变量名
     * @param string $default 默认命令名
     * @return string
     */
    private function databaseBinary(string $environment, string $default): string
    {
        $binary = getenv($environment);
        if ($binary === false || trim($binary) === '') {
            $binary = (string)($this->upgradeConfig()[strtolower($environment)] ?? $default);
        }
        $binary = trim($binary) === '' ? $default : trim($binary);
        if ((strpos($binary, '/') !== false || strpos($binary, '\\') !== false) && !is_file($binary)) {
            throw new RuntimeException('数据库客户端不存在，请检查升级配置项 ' . strtolower($environment) . '。');
        }
        return $binary;
    }

    /**
     * 从备份恢复数据库后再恢复文件；数据库恢复失败时保留文件现场。
     *
     * @param string $batch 批次编号
     * @return void
     */
    private function restoreBatch(string $batch): void
    {
        $status = $this->loadStatus($batch);
        if (!empty($status['sql_started'])) {
            $this->restoreDatabase($batch, $status);
            $status['database_restored'] = true;
            $this->saveStatus($batch, $status);
        }

        $journalPath = $this->batchPath($batch) . 'journal.json';
        if (is_file($journalPath)) {
            $this->restoreFiles($batch);
            return;
        }
        if (!in_array($status['recovery_from'] ?? $status['state'], ['preparing', 'downloading', 'verified'], true)) {
            throw new RuntimeException('文件恢复日志缺失，无法确认现场状态。');
        }
    }

    /**
     * 原子更新 config/rocket.php 中唯一的版本号。
     *
     * @param string $version 新版本号
     * @return void
     */
    private function writeVersion(string $version): void
    {
        $this->validateVersion($version);
        $file = $this->projectFile('config/rocket.php');
        $contents = file_get_contents($file);
        if (!is_string($contents)) {
            throw new RuntimeException('版本配置无法读取。');
        }
        $pattern = "/^(\\s*['\"]version['\"]\\s*=>\\s*['\"])[^'\"]*(['\"]\\s*,?\\s*)$/m";
        if (preg_match_all($pattern, $contents) !== 1) {
            throw new RuntimeException('版本配置项缺失或不唯一。');
        }
        $updated = preg_replace_callback($pattern, function ($match) use ($version) {
            return $match[1] . $version . $match[2];
        }, $contents, 1);
        if (!is_string($updated)) {
            throw new RuntimeException('版本配置更新失败。');
        }
        $this->writeAtomic($file, $updated);
    }

    /**
     * 读取并校验当前版本。
     *
     * @return string
     */
    private function readVersion(): string
    {
        $config = include $this->projectFile('config/rocket.php');
        if (!is_array($config) || !isset($config['version']) || !is_string($config['version'])) {
            throw new RuntimeException('当前系统版本无法读取。');
        }
        $this->validateVersion($config['version']);
        return $config['version'];
    }

    /**
     * 检查版本号是否符合项目格式。
     *
     * @param string $version 待检查版本
     * @return void
     */
    private function validateVersion(string $version): void
    {
        if (!$this->validVersion($version)) {
            throw new RuntimeException('版本号格式无效。');
        }
    }

    /**
     * 判断版本号格式。
     *
     * @param string $version 待检查版本
     * @return bool
     */
    private function validVersion(string $version): bool
    {
        return strlen($version) <= 64
            && preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+){1,4}(?:\+[A-Za-z0-9.-]+)?$/D', $version) === 1;
    }

    /**
     * 读取并校验普通版本信息 JSON。
     *
     * @return array
     */
    private function fetchVersionInfo(): array
    {
        $config = $this->upgradeConfig();
        $url = trim((string)$config['version_info_url']);
        $this->validateResourceAddress($url);
        $json = $this->readResource($url, self::MAX_VERSION_INFO_SIZE);
        $data = $this->decodeJson($json, '版本信息不是有效 JSON。');
        if (!is_array($data) || !isset($data['version'], $data['url'], $data['notes'])
            || count($data) !== 3 || !is_string($data['version'])
            || !is_string($data['url']) || !is_string($data['notes'])) {
            throw new RuntimeException('版本信息必须只包含 version、url 和 notes。');
        }
        $this->validateVersion($data['version']);
        $this->validateResourceAddress($data['url']);
        if (strlen($data['notes']) > 20000) {
            throw new RuntimeException('版本说明超过长度限制。');
        }
        return ['version' => $data['version'], 'url' => $data['url'], 'notes' => $data['notes']];
    }

    /**
     * 通过 cURL 读取小型远程 JSON 响应。
     *
     * @param string $url 请求地址
     * @param int $maxBytes 最大响应大小
     * @return string
     */
    private function httpGet(string $url, int $maxBytes): string
    {
        $body = '';
        $curl = $this->newCurl($url, function ($handle, $chunk) use (&$body, $maxBytes) {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        });
        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($result === false) {
            throw new RuntimeException('读取版本信息失败：' . ($error ?: '响应超过大小限制。'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('版本信息服务器返回 HTTP ' . $status . '。');
        }
        return $body;
    }

    /**
     * 读取 HTTP/HTTPS 或项目内的本地资源，并限制最大体积。
     *
     * @param string $address 资源地址
     * @param int $maxBytes 最大响应大小
     * @return string
     */
    private function readResource(string $address, int $maxBytes): string
    {
        if ($this->isHttpAddress($address)) {
            return $this->httpGet($address, $maxBytes);
        }
        $path = $this->localResourcePath($address);
        if (!is_file($path)) {
            throw new RuntimeException('本地资源不存在或不可读取。');
        }
        $content = @file_get_contents($path, false, null, 0, $maxBytes + 1);
        if ($content === false) {
            throw new RuntimeException('本地资源读取失败。');
        }
        if (strlen($content) > $maxBytes) {
            throw new RuntimeException('本地资源超过大小限制。');
        }
        return $content;
    }

    /**
     * 创建强制校验 TLS 的 HTTP/HTTPS 请求。
     *
     * @param string $url 请求地址
     * @param callable $writer 响应写入回调
     * @return resource
     */
    private function newCurl(string $url, callable $writer)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('服务器未启用 cURL 扩展。');
        }
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_USERAGENT => 'RocketAdmin-Upgrader/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => $writer,
        ]);
        return $curl;
    }

    /**
     * 限制体积并流式下载升级 ZIP。
     *
     * @param string $url 下载地址
     * @param string $target 下载目标
     * @return void
     */
    private function downloadPackage(string $url, string $target): void
    {
        $isRemote = $this->isHttpAddress($url);
        $localPath = $isRemote ? null : $this->localResourcePath($url);
        if ($isRemote && !function_exists('curl_init')) {
            throw new RuntimeException('服务器未启用 cURL 扩展。');
        }
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('升级包临时路径已占用。');
        }
        $temporary = $target . '.part';
        $output = @fopen($temporary, 'xb');
        if (!is_resource($output)) {
            throw new RuntimeException('无法创建升级包临时文件。');
        }
        $size = 0;
        $error = '';
        $writer = function ($chunk) use ($output, &$size, &$error) {
            $length = strlen($chunk);
            if ($size + $length > self::MAX_PACKAGE_SIZE) {
                $error = '升级包超过大小限制。';
                return 0;
            }
            $written = fwrite($output, $chunk);
            if ($written !== $length) {
                $error = '升级包写入临时文件失败。';
                return 0;
            }
            $size += $written;
            return $written;
        };
        $success = false;
        if ($isRemote) {
            $curl = $this->newCurl($url, function ($handle, $chunk) use ($writer) {
                return $writer($chunk);
            });
            $result = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);
            $success = $result !== false && $status >= 200 && $status < 300;
            if (!$success && $error === '') {
                $error = $curlError ?: 'HTTP ' . $status;
            }
        } else {
            $input = @fopen($localPath, 'rb');
            if (!is_resource($input)) {
                $error = '本地升级包不存在或不可读取。';
            } else {
                $success = true;
                while (!feof($input)) {
                    $chunk = fread($input, 8192);
                    if ($chunk === false) {
                        $error = '本地升级包读取失败。';
                        $success = false;
                        break;
                    }
                    if ($chunk === '') {
                        if (!feof($input)) {
                            $error = '本地升级包读取失败。';
                            $success = false;
                        }
                        break;
                    }
                    if ($writer($chunk) !== strlen($chunk)) {
                        $success = false;
                        break;
                    }
                }
                fclose($input);
            }
        }
        fflush($output);
        fclose($output);

        if (!$success || $size < 1) {
            @unlink($temporary);
            throw new RuntimeException('升级包读取失败：' . ($error ?: '资源为空。'));
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('升级包无法保存到运行目录。');
        }
    }

    /**
     * 校验 HTTP/HTTPS 地址或项目内的本地资源路径。
     *
     * @param string $address 待检查地址
     * @return void
     */
    private function validateResourceAddress(string $address): void
    {
        $parts = parse_url($address);
        if ($address === '' || !is_array($parts)) {
            throw new RuntimeException('资源地址格式无效。');
        }
        if (isset($parts['scheme'])) {
            if (!filter_var($address, FILTER_VALIDATE_URL) || !isset($parts['host'])
                || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException('资源地址格式无效。');
            }
            return;
        }
        $this->localResourcePath($address);
    }

    /**
     * 判断资源地址是否为 HTTP/HTTPS 地址。
     *
     * @param string $address 资源地址
     * @return bool
     */
    private function isHttpAddress(string $address): bool
    {
        $scheme = parse_url($address, PHP_URL_SCHEME);
        return $scheme !== null && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * 将本地资源地址解析到项目目录；以斜杠开头的地址相对 public 目录。
     *
     * @param string $address 本地资源地址
     * @return string
     */
    private function localResourcePath(string $address): string
    {
        $address = str_replace('\\', '/', $address);
        if ($address === '' || strpos($address, '//') === 0 || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $address)) {
            throw new RuntimeException('本地资源路径无效。');
        }
        while (strpos($address, './') === 0) {
            $address = substr($address, 2);
        }
        if ($address === '') {
            throw new RuntimeException('本地资源路径无效。');
        }
        $relative = $address[0] === '/' ? 'public/' . ltrim($address, '/') : $address;
        return $this->projectFile($relative);
    }

    /**
     * 解码 JSON 并转换解析异常。
     *
     * @param string $json JSON 文本
     * @param string $message 错误提示
     * @return mixed
     */
    private function decodeJson(string $json, string $message)
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException($message);
        }
    }

    /**
     * 判断连续数字索引数组。
     *
     * @param array $value 待检查数组
     * @return bool
     */
    private function isList(array $value): bool
    {
        return !$value || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * 判断 ZIP 项是否为安全的正斜杠相对路径。
     *
     * @param string $name ZIP 项名称
     * @return bool
     */
    private function isSafeArchiveName(string $name): bool
    {
        if ($name === '' || strpos($name, '\\') !== false || strpos($name, '//') !== false
            || strpos($name, "\0") !== false || substr($name, 0, 1) === '/') {
            return false;
        }
        try {
            $this->normalizeRelativePath(rtrim($name, '/'));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 规范化相对路径并拒绝越界片段和 Windows 保留字符。
     *
     * @param string $path 待检查路径
     * @return string
     */
    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)
            || preg_match('/[\x00-\x1F\x7F:*?"<>|]/', $path)) {
            throw new RuntimeException('路径不是安全的相对路径。');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || trim($segment) !== $segment || preg_match('/[. ]$/', $segment)
                || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)) {
                throw new RuntimeException('路径包含越界片段或系统保留名。');
            }
        }
        return $path;
    }

    /**
     * 判断用户配置和数据目录是否受保护。
     *
     * @param string $path 项目相对路径
     * @return bool
     */
    private function isProtectedPath(string $path): bool
    {
        $path = strtolower($path);
        foreach (['.git', 'runtime', 'public/uploads', 'public/storage', 'public/assets/addons'] as $prefix) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return true;
            }
        }
        return preg_match('/^\.env(?:\..*)?$/', basename($path)) === 1
            || in_array($path, [
                'config/database.php',
                'config/rocket.php',
                'config/site.php',
                'config/token.php',
            ], true);
    }

    /**
     * 校验项目相对路径并拒绝路径段符号链接。
     *
     * @param string $relative 项目相对路径
     * @return string
     */
    private function projectFile(string $relative): string
    {
        $relative = $this->normalizeRelativePath($relative);
        $path = rtrim($this->rootPath, '/\\');
        foreach (explode('/', $relative) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path)) {
                throw new RuntimeException('项目路径包含符号链接：' . $relative);
            }
            if (file_exists($path) && realpath($path) === false) {
                throw new RuntimeException('项目路径无法解析：' . $relative);
            }
        }
        return $path;
    }

    /**
     * 获取受校验批次目录。
     *
     * @param string $batch 批次编号
     * @return string
     */
    private function batchPath(string $batch): string
    {
        if (!$this->isValidBatch($batch)) {
            throw new RuntimeException('升级批次编号无效。');
        }
        $path = $this->upgradeRoot() . DIRECTORY_SEPARATOR . $batch;
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new RuntimeException('升级批次目录不安全。');
        }
        return $path . DIRECTORY_SEPARATOR;
    }

    /**
     * 验证批次编号格式。
     *
     * @param string $batch 待检查编号
     * @return bool
     */
    private function isValidBatch(string $batch): bool
    {
        return preg_match('/^\d{14}-[a-f0-9]{8}$/D', $batch) === 1;
    }

    /**
     * 返回升级批次根目录。
     *
     * @return string
     */
    private function upgradeRoot(): string
    {
        return $this->runtimePath . 'upgrade';
    }

    /**
     * 创建目录并检查所有已有路径段。
     *
     * @param string $directory 目标目录
     * @return void
     */
    private function ensureDirectory(string $directory): void
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            throw new RuntimeException('目录路径无效。');
        }
        $missing = [];
        $cursor = $directory;
        while (!file_exists($cursor) && !is_link($cursor)) {
            $missing[] = $cursor;
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new RuntimeException('目录路径不存在可用的上级目录。');
            }
            $cursor = $parent;
        }
        if (is_link($cursor) || !is_dir($cursor)) {
            throw new RuntimeException('目录路径被文件或符号链接占用。');
        }
        foreach (array_reverse($missing) as $path) {
            if (!@mkdir($path, 0755) && !is_dir($path)) {
                throw new RuntimeException('目录创建失败：' . basename($path));
            }
            if (is_link($path)) {
                throw new RuntimeException('目录创建后检测到符号链接。');
            }
        }
    }

    /**
     * 原子保存升级状态、日志或配置文件。
     *
     * @param string $path 目标文件
     * @param string $contents 写入内容
     * @return void
     */
    private function writeAtomic(string $path, string $contents): void
    {
        $this->ensureDirectory(dirname($path));
        if (is_link($path)) {
            throw new RuntimeException('写入目标是符号链接。');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $written = @file_put_contents($temporary, $contents, LOCK_EX);
        if ($written !== strlen($contents) || !@rename($temporary, $path)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('文件写入失败：' . basename($path));
        }
    }

    /**
     * 取得指定文件在批次备份中的路径。
     *
     * @param string $batch 批次编号
     * @param string $relative 项目相对路径
     * @return string
     */
    private function fileBackupPath(string $batch, string $relative): string
    {
        return $this->batchPath($batch) . 'backup' . DIRECTORY_SEPARATOR . 'files'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->normalizeRelativePath($relative));
    }

    /**
     * 保存文件恢复日志。
     *
     * @param string $batch 批次编号
     * @param array $journal 恢复日志
     * @return void
     */
    private function saveJournal(string $batch, array $journal): void
    {
        $this->writeAtomic(
            $this->batchPath($batch) . 'journal.json',
            json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * 读取日志并检查每个恢复目标路径。
     *
     * @param string $batch 批次编号
     * @return array
     */
    private function loadJournal(string $batch): array
    {
        $path = $this->batchPath($batch) . 'journal.json';
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('文件恢复日志缺失或不安全。');
        }
        $journal = $this->decodeJson((string)file_get_contents($path), '文件恢复日志格式无效。');
        if (!is_array($journal) || !isset(
            $journal['files'],
            $journal['version_backup_ready'],
            $journal['version_backup_sha256'],
            $journal['version_backup_mode']
        ) || !is_array($journal['files']) || !$this->isList($journal['files'])
            || $journal['version_backup_ready'] !== true
            || !is_string($journal['version_backup_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $journal['version_backup_sha256'])
            || !is_int($journal['version_backup_mode'])
            || $journal['version_backup_mode'] < 0 || $journal['version_backup_mode'] > 0777) {
            throw new RuntimeException('文件恢复日志字段无效。');
        }
        $seen = [];
        foreach ($journal['files'] as $item) {
            if (!is_array($item) || !isset($item['path'], $item['action'], $item['existed'], $item['mode'], $item['backup_mode'], $item['backup_ready'])
                || !is_string($item['path']) || !in_array($item['action'], ['write', 'delete'], true)
                || !is_bool($item['existed']) || !is_bool($item['backup_ready'])
                || !is_int($item['mode']) || $item['mode'] < 0 || $item['mode'] > 0777
                || !is_int($item['backup_mode']) || $item['backup_mode'] < 0 || $item['backup_mode'] > 0777) {
                throw new RuntimeException('文件恢复日志项无效。');
            }
            $relative = $this->normalizeRelativePath($item['path']);
            $key = strtolower($relative);
            if ($this->isProtectedPath($relative) || isset($seen[$key])) {
                throw new RuntimeException('文件恢复日志包含受保护或重复路径。');
            }
            $seen[$key] = true;
        }
        return $journal;
    }

    /**
     * 原子保存并读取批次状态。
     *
     * @param string $batch 批次编号
     * @param array $status 状态数据
     * @return void
     */
    private function saveStatus(string $batch, array $status): void
    {
        $status['batch'] = $batch;
        $status['updated_at'] = date('Y-m-d H:i:s');
        $this->writeAtomic(
            $this->batchPath($batch) . 'status.json',
            json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * 读取一个批次状态。
     *
     * @param string $batch 批次编号
     * @return array
     */
    private function loadStatus(string $batch): array
    {
        $path = $this->batchPath($batch) . 'status.json';
        if (!is_file($path) || is_link($path)) {
            return [];
        }
        $status = $this->decodeJson((string)file_get_contents($path), '升级状态日志格式无效。');
        if (!is_array($status) || ($status['batch'] ?? '') !== $batch || !is_string($status['state'] ?? null)) {
            throw new RuntimeException('升级状态日志字段无效。');
        }
        return $status;
    }

    /**
     * 阻止并发批次或未恢复现场启动。
     *
     * @return bool
     */
    private function hasUnfinishedBatch(): bool
    {
        if (is_file($this->maintenanceLockPath())) {
            return true;
        }
        foreach (glob($this->upgradeRoot() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            $batch = basename($directory);
            if (!$this->isValidBatch($batch)) {
                continue;
            }
            $state = $this->loadStatus($batch)['state'] ?? '';
            if (!in_array($state, self::TERMINAL_STATES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 写入站点维护标记。
     *
     * @param string $batch 批次编号
     * @return void
     */
    private function writeMaintenanceLock(string $batch): void
    {
        $path = $this->maintenanceLockPath();
        if (is_link($path)) {
            throw new RuntimeException('维护标记路径不安全。');
        }
        if (is_file($path)) {
            $current = $this->decodeJson((string)file_get_contents($path), '维护标记格式无效。');
            if (!is_array($current) || ($current['batch'] ?? '') !== $batch) {
                throw new RuntimeException('另一个升级批次正在维护站点。');
            }
        }
        $this->writeAtomic($path, json_encode([
            'batch' => $batch,
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * 删除属于当前批次的维护标记。
     *
     * @param string $batch 批次编号
     * @return void
     */
    private function clearMaintenanceLock(string $batch): void
    {
        $path = $this->maintenanceLockPath();
        if (!is_file($path)) {
            return;
        }
        if (is_link($path)) {
            throw new RuntimeException('维护标记路径不安全。');
        }
        $current = $this->decodeJson((string)file_get_contents($path), '维护标记格式无效。');
        if (!is_array($current) || ($current['batch'] ?? '') !== $batch || !unlink($path)) {
            throw new RuntimeException('维护标记无法安全清除。');
        }
    }

    /**
     * 判断维护标记是否属于给定批次。
     *
     * @param string $batch 批次编号
     * @return bool
     */
    private function maintenanceLockBelongsTo(string $batch): bool
    {
        $path = $this->maintenanceLockPath();
        if (!is_file($path) || is_link($path)) {
            return false;
        }
        try {
            $current = $this->decodeJson((string)file_get_contents($path), '');
        } catch (\Throwable $e) {
            return false;
        }
        return is_array($current) && ($current['batch'] ?? '') === $batch;
    }

    /**
     * 获取请求维护屏障的独占锁。
     *
     * @return resource
     */
    private function acquireMaintenanceBarrier()
    {
        $path = $this->upgradeRoot() . DIRECTORY_SEPARATOR . 'requests.lock';
        if (is_link($path)) {
            throw new RuntimeException('请求维护锁路径不安全。');
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('无法暂停站点请求。');
        }
        return $handle;
    }

    /**
     * 释放请求维护屏障。
     *
     * @param resource|null $handle 屏障句柄
     * @return void
     */
    private function releaseMaintenanceBarrier($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 获取升级批次锁。
     *
     * @return resource
     */
    private function acquireLock()
    {
        $this->ensureDirectory($this->upgradeRoot());
        $path = $this->upgradeRoot() . DIRECTORY_SEPARATOR . 'upgrade.lock';
        if (is_link($path)) {
            throw new RuntimeException('升级锁文件路径不安全。');
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('另一个升级操作正在执行。');
        }
        return $handle;
    }

    /**
     * 检查是否有请求正在执行升级。
     *
     * @return bool
     */
    private function upgradeLockHeld(): bool
    {
        $path = $this->upgradeRoot() . DIRECTORY_SEPARATOR . 'upgrade.lock';
        if (is_link($path)) {
            return true;
        }
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            return true;
        }
        $locked = !flock($handle, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return $locked;
    }

    /**
     * 释放升级批次锁。
     *
     * @param resource $handle 文件锁句柄
     * @return void
     */
    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 返回维护标记路径。
     *
     * @return string
     */
    private function maintenanceLockPath(): string
    {
        return $this->upgradeRoot() . DIRECTORY_SEPARATOR . 'maintenance.lock';
    }

    /**
     * 生成唯一批次编号。
     *
     * @return string
     */
    private function newBatchId(): string
    {
        $batch = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        if (file_exists($this->batchPath($batch))) {
            throw new RuntimeException('升级批次编号冲突，请稍后重试。');
        }
        return $batch;
    }

    /**
     * 读取升级配置。
     *
     * @return array
     */
    // ponytail: 直接返回配置，不再兜底默认值；配置缺键会报出来，正好提示补齐
    private function upgradeConfig(): array
    {
        return (array)Config::get('rocket.upgrade', []);
    }

    /**
     * 格式化容量供空间预检提示使用。
     *
     * @param int $bytes 字节数
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = max(0, $bytes);
        $index = 0;
        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }
        return number_format($size, 1) . ' ' . $units[$index];
    }

    /**
     * 删除超出保留数量的终态批次。
     *
     * @param int $keep 保留批次数量
     * @return void
     */
    private function cleanupBatches(int $keep): void
    {
        if ($keep < 1) {
            return;
        }
        $completed = [];
        // batchStatuses() 已按批次倒序，无需再排
        foreach ($this->batchStatuses() as $status) {
            if (in_array($status['state'] ?? '', self::TERMINAL_STATES, true)) {
                $completed[] = $this->upgradeRoot() . DIRECTORY_SEPARATOR . $status['batch'];
            }
        }
        foreach (array_slice($completed, $keep) as $directory) {
            $this->removeTree($directory);
        }
    }

    /**
     * 在升级目录内删除过期批次，不跟随符号链接。
     *
     * @param string $directory 批次目录
     * @return void
     */
    private function removeTree(string $directory): void
    {
        $root = realpath($this->upgradeRoot());
        $parent = realpath(dirname($directory));
        if ($root === false || $parent === false || strcasecmp($root, $parent) !== 0
            || !$this->isValidBatch(basename($directory)) || is_link($directory)) {
            throw new RuntimeException('旧升级批次目录不安全，未清理。');
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeContents($path);
                if (!rmdir($path)) {
                    throw new RuntimeException('旧升级批次目录无法清理。');
                }
            } elseif (!unlink($path)) {
                throw new RuntimeException('旧升级批次文件无法清理。');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('旧升级批次无法删除。');
        }
    }

    /**
     * 递归删除批次目录内容。
     *
     * @param string $directory 已限定的子目录
     * @return void
     */
    private function removeContents(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeContents($path);
                if (!rmdir($path)) {
                    throw new RuntimeException('旧升级暂存目录无法清理。');
                }
            } elseif (!unlink($path)) {
                throw new RuntimeException('旧升级暂存文件无法清理。');
            }
        }
    }
}
