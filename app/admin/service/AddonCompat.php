<?php

namespace app\admin\service;

use ZipArchive;

/**
 * 插件兼容性体检
 *
 * 扫描插件包或插件目录，报告在 TP6/TP8 内核下会失效的写法，只报告不改写。
 */
class AddonCompat
{
    /**
     * 静态门面类：TP5 可直接 use think\X，TP6/TP8 必须改为 think\facade\X
     *
     * @var array
     */
    protected static $facadeClasses = [
        'App', 'Cache', 'Config', 'Cookie', 'Db', 'Env', 'Event', 'Filesystem',
        'Lang', 'Log', 'Middleware', 'Request', 'Response', 'Route', 'Session', 'Validate', 'View',
    ];

    /**
     * 已移除的类及替代建议
     *
     * @var array
     */
    protected static $removedClasses = [
        'Controller' => '类已移除，控制器应继承项目基类（如 app\common\controller\Backend）',
        'Loader'     => '该类已移除，改用命名空间自动加载',
        'Hook'       => '改用 think\facade\Event 或 event() 助手函数',
        'Url'        => '改用 url() 助手函数',
    ];

    /**
     * 疑似加密/混淆的函数
     *
     * @var array
     */
    protected static $obfuscationFunctions = ['eval', 'gzinflate', 'gzuncompress', 'str_rot13', 'base64_decode'];

    /**
     * 扫描的文件后缀
     *
     * @var array
     */
    protected static $scanExtensions = ['php', 'html', 'htm', 'tpl'];

    /**
     * 检测插件压缩包
     *
     * @param string $zipFile 压缩包路径
     * @return array
     */
    public static function checkZip($zipFile)
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return self::buildResult([[
                'file'    => basename($zipFile),
                'line'    => 0,
                'level'   => 'error',
                'message' => '无法打开压缩包',
            ]], 0);
        }

        $issues = [];
        $files = 0;
        $infoContent = $zip->getFromName('info.ini');
        if ($infoContent === false) {
            $issues[] = ['file' => 'info.ini', 'line' => 0, 'level' => 'error', 'message' => '缺少 info.ini（必须位于压缩包根目录）'];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || substr($entry, -1) === '/') {
                continue;
            }
            if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::$scanExtensions, true)) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            $files++;
            $issues = array_merge($issues, self::scanContent($entry, $content));
        }
        //入口类必须存在且与插件标识同名
        if ($infoContent !== false) {
            $ini = parse_ini_string($infoContent) ?: [];
            $name = (string)($ini['name'] ?? '');
            if ($name !== '' && $zip->getFromName(ucfirst($name) . '.php') === false) {
                $issues[] = ['file' => ucfirst($name) . '.php', 'line' => 0, 'level' => 'error', 'message' => '缺少插件入口类文件'];
            }
        }
        $zip->close();

        return self::buildResult($issues, $files);
    }

    /**
     * 检测插件目录
     *
     * @param string $dir 插件目录
     * @return array
     */
    public static function checkDir($dir)
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        if (!is_dir($dir)) {
            return self::buildResult([[
                'file'    => $dir,
                'line'    => 0,
                'level'   => 'error',
                'message' => '目录不存在',
            ]], 0);
        }

        $issues = [];
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!in_array(strtolower($file->getExtension()), self::$scanExtensions, true)) {
                continue;
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $files++;
            $issues = array_merge($issues, self::scanContent(substr($path, strlen($dir)), $content));
        }
        if (!is_file($dir . 'info.ini')) {
            array_unshift($issues, ['file' => 'info.ini', 'line' => 0, 'level' => 'error', 'message' => '缺少 info.ini']);
        }

        return self::buildResult($issues, $files);
    }

    /**
     * 扫描单个文件内容
     *
     * @param string $file    相对路径
     * @param string $content 文件内容
     * @return array
     */
    protected static function scanContent($file, $content)
    {
        $issues = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);

        if (preg_match('/\.(html|htm|tpl)$/i', $file)) {
            foreach ($lines as $index => $line) {
                if (preg_match('/\{[^{}]*\$Think\.config\./', $line)) {
                    $issues[] = ['file' => $file, 'line' => $index + 1, 'level' => 'error', 'message' => '模板 $Think.config.* 在本项目内核下不解析，改用 config(\'...\')'];
                }
            }
            return $issues;
        }

        //先收集 import：只有真正被用到的 TP5 写法才算问题，冗余 import 不影响运行
        $tp5Imports = [];
        $removedImports = [];
        $facadeImports = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*use\s+think\\\\facade\\\\(\w+)\s*(?:as\s+(\w+))?\s*;/i', $line, $m)) {
                $facadeImports[$m[1]] = true;
            } elseif (preg_match('/^\s*use\s+think\\\\(\w+)\s*(?:as\s+(\w+))?\s*;/i', $line, $m)) {
                $alias = $m[2] ?? $m[1];
                if (in_array($m[1], self::$facadeClasses, true)) {
                    $tp5Imports[$m[1]] = $alias;
                } elseif (isset(self::$removedClasses[$m[1]])) {
                    $removedImports[$m[1]] = $alias;
                }
            }
        }

        foreach ($lines as $index => $line) {
            $lineNo = $index + 1;
            //TP5 静态门面写法：该类确实被静态调用
            foreach ($tp5Imports as $class => $alias) {
                if (empty($facadeImports[$class]) && preg_match('/(?<![\w\\\\$])' . preg_quote($alias, '/') . '::/', $line)) {
                    $issues[] = ['file' => $file, 'line' => $lineNo, 'level' => 'error', 'message' => 'use think\\' . $class . '; 需改为 use think\\facade\\' . $class . ';（存在静态调用）'];
                }
            }
            //已移除的类：被继承、实例化或静态调用
            foreach ($removedImports as $class => $alias) {
                $name = preg_quote($alias, '/');
                if (preg_match('/(?<![\w\\\\$])(?:new\s+' . $name . '\b|extends\s+' . $name . '\b|' . $name . '::)/', $line)) {
                    $issues[] = ['file' => $file, 'line' => $lineNo, 'level' => 'error', 'message' => 'think\\' . $class . ' ' . self::$removedClasses[$class]];
                }
            }
            //未正确 import 的静态调用
            foreach (self::$facadeClasses as $class) {
                if (empty($facadeImports[$class]) && empty($tp5Imports[$class]) && preg_match('/(?<![\w\\\\$])' . $class . '::/', $line)) {
                    $issues[] = ['file' => $file, 'line' => $lineNo, 'level' => 'warning', 'message' => $class . ':: 静态调用，需确保已 use think\\facade\\' . $class . ';'];
                    break;
                }
            }
            foreach (self::$obfuscationFunctions as $func) {
                if (preg_match('/(?<![\w\\\\$])' . preg_quote($func, '/') . '\s*\(/i', $line)) {
                    $issues[] = ['file' => $file, 'line' => $lineNo, 'level' => 'warning', 'message' => '疑似加密或混淆（' . $func . '），可能无法适配'];
                    break;
                }
            }
        }

        return $issues;
    }

    /**
     * 汇总检测结果
     *
     * @param array $issues 问题列表
     * @param int   $files  扫描文件数
     * @return array
     */
    protected static function buildResult($issues, $files)
    {
        $errors = 0;
        $warnings = 0;
        foreach ($issues as $issue) {
            $issue['level'] === 'error' ? $errors++ : $warnings++;
        }

        return ['files' => $files, 'errors' => $errors, 'warnings' => $warnings, 'issues' => $issues];
    }
}
