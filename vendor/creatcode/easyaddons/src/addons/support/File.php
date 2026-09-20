<?php

namespace creatcode\easyaddons\addons\support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 文件操作支持类
 *
 * 宿主已提供同名全局函数时委托给宿主，否则使用内置实现。
 */
class File
{
    /**
     * 删除文件夹
     *
     * @param string $dirname  目录
     * @param bool   $withself 是否删除自身
     * @return bool
     */
    public static function rmdirs($dirname, $withself = true)
    {
        if (function_exists('rmdirs')) {
            return rmdirs($dirname, $withself);
        }

        if (!is_dir($dirname)) {
            return false;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        if ($withself) {
            @rmdir($dirname);
        }

        return true;
    }

    /**
     * 复制文件夹
     *
     * @param string $source 源文件夹
     * @param string $dest   目标文件夹
     * @return bool
     */
    public static function copydirs($source, $dest)
    {
        if (function_exists('copydirs')) {
            return copydirs($source, $dest);
        }

        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($dest) && !mkdir($dest, 0755, true)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true)) {
                    return false;
                }
                continue;
            }

            // 兜底：避免异常路径导致复制失败
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
                return false;
            }

            if (!copy($item->getPathname(), $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断文件或目录是否可写
     *
     * @param string $file 文件或目录
     * @return bool
     */
    public static function isReallyWritable($file)
    {
        if (function_exists('is_really_writable')) {
            return is_really_writable($file);
        }

        if (DIRECTORY_SEPARATOR === '/') {
            return is_writable($file);
        }

        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/' . md5(mt_rand());
            if (($fp = @fopen($file, 'ab')) === false) {
                return false;
            }
            fclose($fp);
            @chmod($file, 0777);
            @unlink($file);
            return true;
        }

        if (!is_file($file) || ($fp = @fopen($file, 'ab')) === false) {
            return false;
        }

        fclose($fp);
        return true;
    }

    /**
     * 导出可写入 PHP 文件的数据结构
     *
     * @param mixed $data   数据
     * @param bool  $return 是否返回字符串
     * @return string
     */
    public static function export($data, $return = true)
    {
        // 不委托宿主的 var_export_short()：其 array_walk_recursive 回调在 PHP 8
        // 下会因 $key 声明为引用而抛 "must be passed by reference"。
        // 使用 PHP 原生导出以保持 PHP 7.2+ 兼容，避免引入提高运行基线的依赖。
        $code = var_export($data, true);

        if (!$return) {
            echo $code;
            return null;
        }

        return $code;
    }
}
