<?php

namespace app\admin\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Exception;

/**
 * 从插件源目录的 packages 中生成插件清单
 */
class AddonManifest extends Command
{
    protected function configure()
    {
        $this->setName('addon:manifest')
            ->addOption('path', 'p', Option::VALUE_REQUIRED, 'addon source path', null)
            ->setDescription('Generate plugin manifest from the packages directory');
    }

    protected function execute(Input $input, Output $output)
    {
        $path = $input->getOption('path');
        if (!$path) {
            throw new Exception('Please specify the addon source path by --path');
        }
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $packagesDir = $path . 'packages/';
        if (!is_dir($packagesDir)) {
            throw new Exception('Packages directory not found: ' . $packagesDir);
        }

        $grouped = [];
        foreach (glob($packagesDir . '*.zip') ?: [] as $zipFile) {
            $zipInfo = $this->readZipInfo($zipFile);
            $pluginName = (string)($zipInfo['name'] ?? '');
            $version = (string)($zipInfo['version'] ?? '');
            if ($pluginName === '' || $version === '') {
                $output->warning('Skip invalid package: ' . basename($zipFile));
                continue;
            }
            $relative = 'packages/' . basename($zipFile);
            if (!isset($grouped[$pluginName])) {
                $grouped[$pluginName] = ['name' => $pluginName, 'releases' => []];
            }
            //暂存包内信息，排序后按最高版本取顶层字段
            $grouped[$pluginName]['releases'][] = [
                'version'  => $version,
                'download' => $relative,
                'info'     => $zipInfo,
            ];
        }

        if (!$grouped) {
            throw new Exception('No valid package found in ' . $packagesDir);
        }

        //版本降序，顶层字段取最高版本
        $plugins = [];
        foreach ($grouped as $plugin) {
            usort($plugin['releases'], function ($a, $b) {
                return version_compare($b['version'], $a['version']);
            });
            $top = $plugin['releases'][0];
            $info = $top['info'];
            foreach ($plugin['releases'] as &$release) {
                unset($release['info']);
            }
            unset($release);
            $plugins[] = [
                'name'        => $plugin['name'],
                'title'       => (string)($info['title'] ?? $plugin['name']),
                'intro'       => (string)($info['intro'] ?? ''),
                'author'      => (string)($info['author'] ?? ''),
                'version'     => $top['version'],
                'compatible'  => filter_var($info['compatible'] ?? '', FILTER_VALIDATE_BOOLEAN),
                'download'    => $top['download'],
                'homepage'    => (string)($info['website'] ?? ''),
                'screenshots' => [],
                'releases'    => $plugin['releases'],
            ];
        }

        $manifestFile = $path . 'plugins.json';
        $old = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : [];
        $manifest = [
            'source'  => is_array($old) && !empty($old['source']) ? $old['source'] : '插件源',
            'updated' => date('Y-m-d'),
            'plugins' => $plugins,
        ];
        if (file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new Exception('Unable to write manifest: ' . $manifestFile);
        }
        $output->info("Manifest Path:" . $manifestFile);
        $output->info("Manifest Successed! (" . count($grouped) . " plugins)");
    }

    /**
     * 读取插件压缩包内的 info.ini
     *
     * @param string $zipFile 压缩包路径
     * @return array
     */
    protected function readZipInfo($zipFile)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return [];
        }
        $content = $zip->getFromName('info.ini');
        $zip->close();

        return $content === false ? [] : (parse_ini_string($content) ?: []);
    }
}
