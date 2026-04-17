<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use Exception;
use think\facade\Config;
use ZipArchive;

/**
 * 项目升级
 */
class Upgrade extends Backend
{
    protected $noNeedRight = ['index', 'run'];

    protected array $blockedPaths = [
        '.env',
        '.git/',
        'runtime/',
        'public/uploads/',
    ];

    public function initialize()
    {
        parent::initialize();
        if (!$this->auth->isSuperAdmin()) {
            $this->error(__('Access is allowed only to the super management group'));
        }
    }

    public function index()
    {
        $this->view->assign([
            'currentVersion' => Config::get('rocket.version'),
            'upgradeUrl'     => Config::get('rocket.upgrade_url', ''),
        ]);
        return $this->view->fetch();
    }

    public function run()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }

        $url = trim($this->request->post('url', ''));
        if (!$this->isValidRemoteUrl($url)) {
            $this->error('请输入有效的远程升级包地址，仅支持 http/https');
        }

        $rootPath = rtrim(root_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $upgradePath = runtime_path() . 'upgrade' . DIRECTORY_SEPARATOR . date('Ymd_His') . DIRECTORY_SEPARATOR;
        $packageFile = $upgradePath . 'package.zip';
        $extractPath = $upgradePath . 'extract' . DIRECTORY_SEPARATOR;
        $backupPath = $upgradePath . 'backup' . DIRECTORY_SEPARATOR;

        try {
            $this->ensureDirectory($upgradePath);
            $this->downloadPackage($url, $packageFile);
            $this->extractPackage($packageFile, $extractPath);

            $manifest = $this->readManifest($extractPath);
            $basePath = $this->resolveBasePath($extractPath, $manifest);
            $sqlFiles = $this->collectSqlFiles($extractPath, $basePath, $manifest);
            $sqlOutput = $this->writeSqlFile($rootPath, $sqlFiles);
            $result = $this->copyUpgradeFiles($rootPath, $basePath, $backupPath, $sqlFiles);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }

        $message = '升级文件处理完成';
        if ($sqlOutput) {
            $message .= '，SQL 已生成到根目录，请确认后手动执行';
        }

        $this->success($message, null, [
            'updated_files' => $result['updated'],
            'skipped_files' => $result['skipped'],
            'backup_path'   => $this->relativePath($rootPath, $backupPath),
            'sql_file'      => $sqlOutput ? $this->relativePath($rootPath, $sqlOutput) : '',
        ]);
    }

    protected function isValidRemoteUrl(string $url): bool
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    protected function downloadPackage(string $url, string $target): void
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'header'  => "User-Agent: RocketAdmin-Upgrader\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false || $content === '') {
            throw new Exception('远程升级包下载失败');
        }
        if (file_put_contents($target, $content, LOCK_EX) === false) {
            throw new Exception('升级包写入失败');
        }
    }

    protected function extractPackage(string $packageFile, string $extractPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($packageFile) !== true) {
            throw new Exception('升级包不是有效的 ZIP 文件');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!$this->isSafeZipName($name)) {
                $zip->close();
                throw new Exception('升级包包含非法路径: ' . $name);
            }
        }
        $this->ensureDirectory($extractPath);
        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new Exception('升级包解压失败');
        }
        $zip->close();
    }

    protected function readManifest(string $extractPath): array
    {
        $manifestFile = $extractPath . 'manifest.json';
        if (!is_file($manifestFile)) {
            $dirs = glob($extractPath . '*', GLOB_ONLYDIR) ?: [];
            if (count($dirs) === 1 && is_file($dirs[0] . DIRECTORY_SEPARATOR . 'manifest.json')) {
                $manifestFile = $dirs[0] . DIRECTORY_SEPARATOR . 'manifest.json';
            }
        }
        if (!is_file($manifestFile)) {
            return [];
        }
        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            throw new Exception('manifest.json 格式错误');
        }
        return $manifest;
    }

    protected function resolveBasePath(string $extractPath, array $manifest): string
    {
        if (!empty($manifest['files'])) {
            $path = $this->normalizeRelativePath((string)$manifest['files']);
            $basePath = $extractPath . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_dir($basePath)) {
                return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }
        if (is_dir($extractPath . 'files')) {
            return $extractPath . 'files' . DIRECTORY_SEPARATOR;
        }

        $dirs = glob($extractPath . '*', GLOB_ONLYDIR) ?: [];
        if (count($dirs) === 1 && $this->looksLikeProjectPath($dirs[0] . DIRECTORY_SEPARATOR)) {
            return $dirs[0] . DIRECTORY_SEPARATOR;
        }
        return $extractPath;
    }

    protected function looksLikeProjectPath(string $path): bool
    {
        foreach (['app', 'config', 'public', 'extend', 'composer.json'] as $name) {
            if (is_dir($path . $name) || is_file($path . $name)) {
                return true;
            }
        }
        return false;
    }

    protected function collectSqlFiles(string $extractPath, string $basePath, array $manifest): array
    {
        $files = [];
        if (!empty($manifest['sql'])) {
            foreach ((array)$manifest['sql'] as $sql) {
                $path = $extractPath . str_replace('/', DIRECTORY_SEPARATOR, $this->normalizeRelativePath((string)$sql));
                if (is_file($path)) {
                    $files[$path] = $path;
                }
            }
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractPath, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'sql') {
                continue;
            }
            $path = $file->getPathname();
            $relative = $this->relativePath($extractPath, $path);
            $basename = strtolower($file->getBasename('.sql'));
            $inSqlDir = preg_match('#(^|/)sql/#i', $relative);
            $isUpgradeSql = in_array($basename, ['upgrade', 'update', 'migrate', 'migration', 'patch'], true);
            if ($inSqlDir || $isUpgradeSql) {
                $files[$path] = $path;
            }
        }

        return array_values(array_filter($files, function ($path) use ($basePath) {
            return strpos($path, $basePath) !== 0 || preg_match('/\.sql$/i', $path);
        }));
    }

    protected function writeSqlFile(string $rootPath, array $sqlFiles): string
    {
        if (!$sqlFiles) {
            return '';
        }
        $target = $rootPath . 'upgrade_' . date('Ymd_His') . '.sql';
        $content = "-- RocketAdmin remote upgrade SQL\n-- Generated at " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($sqlFiles as $sqlFile) {
            $content .= "-- Source: " . basename($sqlFile) . "\n";
            $content .= trim((string)file_get_contents($sqlFile)) . "\n\n";
        }
        if (file_put_contents($target, $content, LOCK_EX) === false) {
            throw new Exception('SQL 文件生成失败');
        }
        return $target;
    }

    protected function copyUpgradeFiles(string $rootPath, string $basePath, string $backupPath, array $sqlFiles): array
    {
        $updated = 0;
        $skipped = 0;
        $sqlMap = array_flip($sqlFiles);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $source = $file->getPathname();
            if (isset($sqlMap[$source])) {
                $skipped++;
                continue;
            }
            $relative = $this->normalizeRelativePath($this->relativePath($basePath, $source));
            if ($this->shouldSkipFile($relative)) {
                $skipped++;
                continue;
            }

            $target = $rootPath . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($target)) {
                $backupFile = $backupPath . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->ensureDirectory(dirname($backupFile));
                if (!copy($target, $backupFile)) {
                    throw new Exception('备份文件失败: ' . $relative);
                }
            }
            $this->ensureDirectory(dirname($target));
            if (!copy($source, $target)) {
                throw new Exception('覆盖文件失败: ' . $relative);
            }
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    protected function shouldSkipFile(string $relative): bool
    {
        foreach ($this->blockedPaths as $blockedPath) {
            if ($relative === rtrim($blockedPath, '/') || strpos($relative, $blockedPath) === 0) {
                return true;
            }
        }
        return false;
    }

    protected function isSafeZipName(string $name): bool
    {
        $name = str_replace('\\', '/', $name);
        return $name !== '' && $name[0] !== '/' && !preg_match('#(^|/)\.\.(/|$)#', $name) && !preg_match('#^[a-z]:/#i', $name);
    }

    protected function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', $path) || preg_match('#^[a-z]:/#i', $path)) {
            throw new Exception('非法相对路径: ' . $path);
        }
        return $path;
    }

    protected function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new Exception('目录创建失败: ' . $path);
        }
    }

    protected function relativePath(string $base, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        return strpos($path, $base) === 0 ? substr($path, strlen($base)) : $path;
    }
}
