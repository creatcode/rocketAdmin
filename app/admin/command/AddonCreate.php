<?php

namespace app\admin\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\Exception;

/**
 * 生成规范插件骨架
 */
class AddonCreate extends Command
{
    /**
     * 骨架文件清单，值为文件内容模板
     *
     * @var array
     */
    protected $files = [];

    /**
     * 打包时排除的目录与文件
     *
     * @var array
     */
    protected $excludes = ['.git', '.svn', '.vscode', '.idea', '.addonrc', 'node_modules'];

    protected function configure()
    {
        $this->setName('addon:create')
            ->addArgument('name', Argument::REQUIRED, 'addon name, letters and numbers only')
            ->addOption('title', null, Option::VALUE_REQUIRED, 'addon title', null)
            ->addOption('author', null, Option::VALUE_REQUIRED, 'addon author', 'RocketAdmin')
            ->addOption('ver', null, Option::VALUE_REQUIRED, 'addon version', '1.0.0')
            ->addOption('dir', null, Option::VALUE_REQUIRED, 'output directory', null)
            ->addOption('package', null, Option::VALUE_REQUIRED, 'plugin source directory, pack the skeleton into <package>/packages', null)
            ->setDescription('Create a standard addon skeleton');
    }

    protected function execute(Input $input, Output $output)
    {
        $name = (string)$input->getArgument('name');
        if (!preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            throw new Exception('Addon name can only contain letters and numbers');
        }
        $name = strtolower($name);
        $title = (string)($input->getOption('title') ?: $name);
        $author = (string)$input->getOption('author');
        $version = (string)$input->getOption('ver');
        $dir = (string)($input->getOption('dir') ?: runtime_path() . 'addon-build/' . $name);
        $dir = rtrim(str_replace('\\', '/', $dir), '/');

        if (is_dir($dir)) {
            throw new Exception('Directory already exists: ' . $dir);
        }
        $this->buildFiles($name, $title, $author, $version);

        foreach ($this->files as $relative => $content) {
            $file = $dir . '/' . $relative;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            if (file_put_contents($file, $content) === false) {
                throw new Exception('Unable to write file: ' . $file);
            }
        }
        $output->info('Skeleton created: ' . $dir);
        foreach (array_keys($this->files) as $relative) {
            $output->writeln('  ' . $relative);
        }

        $package = (string)$input->getOption('package');
        if ($package !== '') {
            $packageDir = rtrim(str_replace('\\', '/', $package), '/');
            if (!is_dir($packageDir . '/packages') && !mkdir($packageDir . '/packages', 0755, true)) {
                throw new Exception('Unable to create packages directory: ' . $packageDir . '/packages');
            }
            $zipFile = $packageDir . '/packages/' . $name . '-' . $version . '.zip';
            $this->pack($dir, $zipFile);
            $output->info('Package created: ' . $zipFile);
            $output->writeln('Run "php think addon:manifest -p ' . $packageDir . '" to refresh the manifest.');
        } else {
            $output->writeln('Package it later with --package <plugin source directory>.');
        }
    }

    /**
     * 组装骨架文件内容
     *
     * @param string $name    插件标识
     * @param string $title   插件名称
     * @param string $author  作者
     * @param string $version 版本号
     * @return void
     */
    protected function buildFiles($name, $title, $author, $version)
    {
        $class = ucfirst($name);
        $replace = ['{{name}}' => $name, '{{class}}' => $class, '{{title}}' => $title, '{{author}}' => $author, '{{version}}' => $version];

        $this->files = [
            'info.ini' => strtr(<<<'EOT'
name = "{{name}}"
title = "{{title}}"
intro = "{{title}}"
author = "{{author}}"
website = ""
version = "{{version}}"
state = 0
compatible = true
url = "/addons/{{name}}.html"

EOT
                , $replace),
            $class . '.php' => strtr(<<<'EOT'
<?php

namespace addons\{{name}};

use app\common\library\Menu;
use think\Addons;
use think\facade\Db;

/**
 * {{title}}
 */
class {{class}} extends Addons
{
    /**
     * 插件安装方法
     *
     * @return bool
     */
    public function install()
    {
        Menu::create([
            [
                'name'  => '{{name}}',
                'title' => '{{title}}',
                'icon'  => 'fa fa-cube',
            ],
        ]);

        return true;
    }

    /**
     * 插件卸载方法
     *
     * @return bool
     */
    public function uninstall()
    {
        Menu::delete('{{name}}');
        $prefix = config('database.connections.' . config('database.default') . '.prefix');
        Db::execute('DROP TABLE IF EXISTS `' . $prefix . '{{name}}`');

        return true;
    }

    /**
     * 插件启用方法
     *
     * @return bool
     */
    public function enable()
    {
        Menu::enable('{{name}}');

        return true;
    }

    /**
     * 插件禁用方法
     *
     * @return bool
     */
    public function disable()
    {
        Menu::disable('{{name}}');

        return true;
    }
}

EOT
                , $replace),
            'config.php' => strtr(<<<'EOT'
<?php

return [
    [
        'name'    => 'example',
        'title'   => '示例配置',
        'type'    => 'string',
        'value'   => '',
        'content' => [],
        'rule'    => '',
        'msg'     => '',
        'tip'     => '插件配置项示例',
        'ok'      => '',
        'extend'  => '',
    ],
];

EOT
                , $replace),
            'install.sql' => strtr(<<<'EOT'
CREATE TABLE IF NOT EXISTS `__PREFIX__{{name}}`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '标题',
  `createtime` bigint(16) UNSIGNED DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '{{title}}表';

EOT
                , $replace),
            'application/admin/controller/' . $class . '.php' => strtr(<<<'EOT'
<?php

namespace app\admin\controller;

use app\common\controller\Backend;

/**
 * {{title}}
 *
 * @icon fa fa-cube
 */
class {{class}} extends Backend
{
    public function index()
    {
        $this->view->assign('addon', get_addon_info('{{name}}'));

        return $this->view->fetch();
    }
}

EOT
                , $replace),
            'application/admin/view/' . $name . '/index.html' => strtr(<<<'EOT'
<div class="panel panel-default panel-intro">
    <div class="panel-heading">
        {:build_heading(null, false)}
        <div class="panel-lead">
            <em>{$addon.title}</em> v{$addon.version}
        </div>
    </div>
    <div class="panel-body">
        <div class="alert alert-success">
            <strong>{{title}} 已正常运行。</strong>
        </div>
        <p class="text-muted">模板中读取配置请使用 <code>{:config('rocket.xxx')}</code>，不要用 <code>$Think.config.rocket.xxx</code> —— 后者在 TP6/TP8 下会输出空且不报错。</p>
    </div>
</div>

EOT
                , $replace),
            'public/assets/js/backend/' . $name . '.js' => strtr(<<<'EOT'
define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});

EOT
                , $replace),
        ];
    }

    /**
     * 打包骨架为插件压缩包
     *
     * @param string $dir     骨架目录
     * @param string $zipFile 目标压缩包
     * @return void
     */
    protected function pack($dir, $zipFile)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Unable to create package: ' . $zipFile);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) {
                    return !in_array($current->getFilename(), $this->excludes, true);
                }
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $local = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $zip->addFile($file->getPathname(), $local);
            }
        }
        $zip->close();
    }
}
