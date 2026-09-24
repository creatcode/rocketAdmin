<?php

/**
 * 检查版本号格式。
 *
 * @param string $version 待检查版本号
 * @return bool
 */
function rocket_upgrade_valid_version(string $version): bool
{
    return strlen($version) <= 64
        && preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+){1,4}(?:\+[A-Za-z0-9.-]+)?$/D', $version) === 1;
}

/**
 * 判断 JSON 清单字段是否为顺序数组。
 *
 * @param array $items 待检查数组
 * @return bool
 */
function rocket_upgrade_release_is_list(array $items): bool
{
    return $items === [] || array_keys($items) === range(0, count($items) - 1);
}

/**
 * 检查并返回安全的包内相对路径。
 *
 * @param string $path 相对路径
 * @return string
 */
function rocket_upgrade_release_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)
        || preg_match('/[\x00-\x1F\x7F:*?"<>|]/', $path)) {
        throw new RuntimeException('升级清单包含无效路径。');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || trim($segment) !== $segment
            || preg_match('/[. ]$/', $segment)
            || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)) {
            throw new RuntimeException('升级清单包含越界路径或系统保留名。');
        }
    }
    return $path;
}

/**
 * 判断文件是否属于环境、用户数据或构建缓存。
 *
 * @param string $path 项目相对路径
 * @return bool
 */
function rocket_upgrade_release_excluded(string $path): bool
{
    $path = strtolower(str_replace('\\', '/', $path));
    foreach (['.git', 'runtime', 'node_modules', 'public/uploads', 'public/storage', 'public/assets/addons'] as $prefix) {
        if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
            return true;
        }
    }
    if (preg_match('/^\.env(?:\..*)?$/', basename($path)) === 1 || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql') {
        return true;
    }
    return in_array($path, [
        'config/database.php',
        'config/rocket.php',
        'config/site.php',
        'config/token.php',
    ], true);
}

/**
 * 把允许发布的目录树加入 ZIP，不跟随符号链接。
 *
 * @param ZipArchive $zip ZIP 对象
 * @param string $projectRoot 项目根目录
 * @param string $relative 项目相对目录
 * @param array $payloadFiles 已加入的相对文件路径
 * @return void
 */
function rocket_upgrade_release_add_tree(ZipArchive $zip, string $projectRoot, string $relative, array &$payloadFiles): void
{
    $directory = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $entries = scandir($directory);
    if ($entries === false) {
        throw new RuntimeException('无法读取发布目录：' . $relative);
    }
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $child = $relative . '/' . $name;
        if (rocket_upgrade_release_excluded($child)) {
            continue;
        }
        $source = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_link($source)) {
            throw new RuntimeException('发布目录包含符号链接：' . $child);
        }
        if (is_dir($source)) {
            rocket_upgrade_release_add_tree($zip, $projectRoot, $child, $payloadFiles);
            continue;
        }
        if (!is_file($source)) {
            throw new RuntimeException('发布目录包含特殊文件：' . $child);
        }

        $path = rocket_upgrade_release_path($child);
        if (!$zip->addFile($source, 'payload/' . $path)) {
            throw new RuntimeException('无法加入发布文件：' . $path);
        }
        $payloadFiles[strtolower($path)] = true;
        if (method_exists($zip, 'setExternalAttributesName')) {
            $mode = fileperms($source);
            if ($mode !== false) {
                $zip->setExternalAttributesName('payload/' . $path, ZipArchive::OPSYS_UNIX, ($mode & 0777) << 16);
            }
        }
    }
}

/**
 * 构建固定布局的升级 ZIP 和普通版本信息 JSON。
 *
 * @param string $projectRoot 项目根目录
 * @param string $outputDirectory 输出目录
 * @param string $packageUrl 可直接下载 ZIP 的 HTTP/HTTPS 地址
 * @param string $notes 更新说明
 * @return array
 */
function build_upgrade_release(string $projectRoot, string $outputDirectory, string $packageUrl, string $notes = ''): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('构建升级包需要 PHP ZIP 扩展。');
    }
    $root = realpath($projectRoot);
    if ($root === false || !is_file($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'rocket.php')) {
        throw new RuntimeException('项目根目录或版本配置不可用。');
    }
    $config = include $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'rocket.php';
    $version = is_array($config) ? ($config['version'] ?? '') : '';
    if (!is_string($version) || !rocket_upgrade_valid_version($version)) {
        throw new RuntimeException('config/rocket.php 中的版本号无效。');
    }

    $manifestPath = $root . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'upgrade.json';
    if (is_link($root . DIRECTORY_SEPARATOR . 'upgrade')
        || is_link($root . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'sql')
        || !is_file($manifestPath) || is_link($manifestPath)) {
        throw new RuntimeException('缺少 upgrade/upgrade.json 发布清单。');
    }
    try {
        $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException('upgrade/upgrade.json 格式无效。');
    }
    if (!is_array($manifest) || count($manifest) !== 3
        || !isset($manifest['min_version'], $manifest['delete'], $manifest['sql'])
        || !is_string($manifest['min_version'])
        || !rocket_upgrade_valid_version($manifest['min_version'])
        || !version_compare($version, $manifest['min_version'], '>')
        || !is_array($manifest['delete']) || !rocket_upgrade_release_is_list($manifest['delete'])
        || !is_array($manifest['sql']) || !rocket_upgrade_release_is_list($manifest['sql'])) {
        throw new RuntimeException('发布清单版本、支持的起始版本或字段格式无效。');
    }
    $delete = [];
    $deleteKeys = [];
    foreach ($manifest['delete'] as $path) {
        if (!is_string($path)) {
            throw new RuntimeException('删除清单包含无效路径。');
        }
        $path = rocket_upgrade_release_path($path);
        $key = strtolower($path);
        if (rocket_upgrade_release_excluded($path) || isset($deleteKeys[$key])) {
            throw new RuntimeException('删除清单包含受保护或重复路径：' . $path);
        }
        $deleteKeys[$key] = true;
        $delete[] = $path;
    }
    $sql = [];
    $sqlKeys = [];
    $previousSqlVersion = $manifest['min_version'];
    foreach ($manifest['sql'] as $entry) {
        if (!is_array($entry) || count($entry) !== 2
            || !isset($entry['version'], $entry['file'])
            || !is_string($entry['version']) || !is_string($entry['file'])
            || !rocket_upgrade_valid_version($entry['version'])
            || !version_compare($entry['version'], $manifest['min_version'], '>')
            || version_compare($entry['version'], $version, '>')
            || version_compare($entry['version'], $previousSqlVersion, '<')) {
            throw new RuntimeException('SQL 清单版本或顺序无效。');
        }
        $previousSqlVersion = $entry['version'];
        $path = rocket_upgrade_release_path($entry['file']);
        if (!preg_match('#^upgrade/sql/[A-Za-z0-9][A-Za-z0-9._-]*\.sql$#iD', $path)
            || isset($sqlKeys[strtolower($path)])) {
            throw new RuntimeException('SQL 清单路径无效或重复：' . $path);
        }
        $sqlKeys[strtolower($path)] = true;
        $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $resolved = realpath($source);
        if (!is_file($source) || is_link($source) || $resolved === false
            || stripos($resolved, $root . DIRECTORY_SEPARATOR) !== 0
            || filesize($source) > 16777216) {
            throw new RuntimeException('清单 SQL 文件缺失、不安全或过大：' . basename($path));
        }
        $contents = file_get_contents($source);
        if (!is_string($contents) || trim($contents) === '' || strpos($contents, "\0") !== false) {
            throw new RuntimeException('清单 SQL 文件为空或格式无效：' . basename($path));
        }
        $sql[] = ['version' => $entry['version'], 'file' => $path];
    }

    $parts = parse_url($packageUrl);
    if (!filter_var($packageUrl, FILTER_VALIDATE_URL) || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || strlen($notes) > 20000) {
        throw new RuntimeException('ZIP 地址或更新说明无效。');
    }
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('无法创建发布输出目录。');
    }
    $outputRoot = realpath($outputDirectory);
    if ($outputRoot === false) {
        throw new RuntimeException('发布输出目录不可用。');
    }
    if (strcasecmp($outputRoot, $root) === 0
        || stripos($outputRoot, rtrim($root, '/\\') . DIRECTORY_SEPARATOR) === 0) {
        throw new RuntimeException('发布输出目录必须位于项目目录之外。');
    }
    $assetVersion = str_replace('+', '_', $version);
    $packageFile = $outputRoot . DIRECTORY_SEPARATOR . 'rocket-admin-' . $assetVersion . '.zip';
    $versionInfoFile = $outputRoot . DIRECTORY_SEPARATOR . 'latest.json';
    if (file_exists($packageFile) || is_link($packageFile)
        || file_exists($versionInfoFile) || is_link($versionInfoFile)) {
        throw new RuntimeException('发布输出文件已存在，避免覆盖旧产物。');
    }

    $zip = new ZipArchive();
    if ($zip->open($packageFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('无法创建升级 ZIP。');
    }
    $payloadFiles = [];
    try {
        if (!$zip->addEmptyDir('payload') || !$zip->addEmptyDir('upgrade') || !$zip->addEmptyDir('upgrade/sql')) {
            throw new RuntimeException('升级 ZIP 目录结构创建失败。');
        }
        foreach (['addons', 'app', 'config', 'extend', 'kernel', 'public', 'route', 'view', 'vendor'] as $directory) {
            $sourceDirectory = $root . DIRECTORY_SEPARATOR . $directory;
            if (is_link($sourceDirectory)) {
                throw new RuntimeException('发布目录不能是符号链接：' . $directory);
            }
            if (is_dir($sourceDirectory)) {
                rocket_upgrade_release_add_tree($zip, $root, $directory, $payloadFiles);
            }
        }
        foreach (['think', 'index.php'] as $file) {
            $source = $root . DIRECTORY_SEPARATOR . $file;
            if (is_link($source)) {
                throw new RuntimeException('发布文件不能是符号链接：' . $file);
            }
            if (is_file($source)) {
                if (!$zip->addFile($source, 'payload/' . $file)) {
                    throw new RuntimeException('无法加入发布文件：' . $file);
                }
                $payloadFiles[strtolower($file)] = true;
            }
        }
        foreach ($sql as $entry) {
            $path = $entry['file'];
            if (!$zip->addFile($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path), $path)) {
                throw new RuntimeException('无法加入清单 SQL 文件：' . basename($path));
            }
        }
        foreach ($delete as $path) {
            if (isset($payloadFiles[strtolower($path)])) {
                throw new RuntimeException('同一路径同时出现在更新和删除清单：' . $path);
            }
        }
        $packageManifest = [
            'version' => $version,
            'min_version' => $manifest['min_version'],
            'delete' => $delete,
            'sql' => $sql,
        ];
        $manifestJson = json_encode($packageManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (!$zip->addFromString('upgrade/upgrade.json', $manifestJson . "\n") || !$zip->close()) {
            throw new RuntimeException('升级 ZIP 写入失败。');
        }
    } catch (Throwable $e) {
        $zip->close();
        if (is_file($packageFile)) {
            unlink($packageFile);
        }
        throw $e;
    }

    $versionInfo = json_encode([
        'version' => $version,
        'url' => $packageUrl,
        'notes' => $notes,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($versionInfoFile, $versionInfo . "\n", LOCK_EX) === false) {
        unlink($packageFile);
        throw new RuntimeException('版本信息 JSON 写入失败。');
    }
    return ['package' => $packageFile, 'version_info' => $versionInfoFile, 'version' => $version];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if ($argc < 3 || $argc > 4) {
        fwrite(STDERR, "用法: php scripts/build_upgrade_release.php <输出目录> <直链 ZIP URL> [更新说明]\n");
        exit(2);
    }
    try {
        $result = build_upgrade_release(dirname(__DIR__), $argv[1], $argv[2], $argv[3] ?? '');
        fwrite(STDOUT, "已生成升级包 {$result['version']}\n{$result['package']}\n{$result['version_info']}\n");
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
