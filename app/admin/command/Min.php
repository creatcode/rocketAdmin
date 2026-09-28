<?php

namespace app\admin\command;

use think\Exception;
use think\console\Input;
use think\console\Output;
use think\console\Command;
use think\console\input\Option;

/**
 * 压缩后台及前台静态资源
 */
class Min extends Command
{

    /**
     * 路径和文件名配置
     */
    protected $options = [
        'cssBaseUrl'  => 'public/assets/css/',
        'cssBaseName' => '{module}',
        'jsBaseUrl'   => 'public/assets/js/',
        'jsBaseName'  => 'require-{module}',
    ];

    /**
     * 配置资源压缩命令参数
     * @return void
     */
    protected function configure()
    {
        $this->setName('min')
            ->addOption('module', 'm', Option::VALUE_REQUIRED, 'module name(frontend or backend),use \'all\' when build all modules', null)
            ->addOption('resource', 'r', Option::VALUE_REQUIRED, 'resource name(js or css),use \'all\' when build all resources', null)
            ->addOption('optimize', 'o', Option::VALUE_OPTIONAL, 'optimize type(uglify|closure|none)', 'none')
            ->setDescription('Compress js and css file');
    }

    /**
     * 压缩指定模块资源并检查执行结果
     * @param Input $input 命令输入
     * @param Output $output 命令输出
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $module = $input->getOption('module') ?: '';
        $resource = $input->getOption('resource') ?: '';
        $optimize = $input->getOption('optimize') ?: 'none';

        if (!$module || !in_array($module, ['frontend', 'backend', 'all'])) {
            throw new Exception('Please input correct module name');
        }
        if (!$resource || !in_array($resource, ['js', 'css', 'all'])) {
            throw new Exception('Please input correct resource name');
        }

        if (!in_array($optimize, ['uglify', 'closure', 'none'])) {
            throw new Exception('Please input correct optimize type');
        }

        $moduleArr = $module == 'all' ? ['frontend', 'backend'] : [$module];
        $resourceArr = $resource == 'all' ? ['js', 'css'] : [$resource];

        $minPath = __DIR__ . DIRECTORY_SEPARATOR . 'Min' . DIRECTORY_SEPARATOR;
        $publicPath = app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR;
        $tempFile = $minPath . uniqid('temp_', true) . '.js';

        $nodeExec = '';

        if (strpos(PHP_OS, 'WIN') !== false) {
            // Winsows下请手动配置配置该值,一般将该值配置为 '"C:\Program Files\nodejs\node.exe"'，除非你的Node安装路径有变更
            $nodeExec = 'C:\Program Files\nodejs\node.exe';
            if (file_exists($nodeExec)) {
                $nodeExec = '"' . $nodeExec . '"';
            } else {
                // 如果 '"C:\Program Files\nodejs\node.exe"' 不存在，可能是node安装路径有变更
                // 但安装node会自动配置环境变量，直接执行 '"node.exe"' 提高第一次使用压缩打包的成功率
                $nodeExec = '"node.exe"';
            }
        } else {
            try {
                $node_version = exec('node -v');
                $nodeExec = 'node';
                if (!$node_version) {
                    throw new Exception("node environment not found!please install node first!");
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }

        try {
            foreach ($moduleArr as $mod) {
                foreach ($resourceArr as $res) {
                    $data = [
                        'publicPath'  => $publicPath,
                        'jsBaseName'  => str_replace('{module}', $mod, $this->options['jsBaseName']),
                        'jsBaseUrl'   => $this->options['jsBaseUrl'],
                        'cssBaseName' => str_replace('{module}', $mod, $this->options['cssBaseName']),
                        'cssBaseUrl'  => $this->options['cssBaseUrl'],
                        'jsBasePath'  => str_replace(
                            DIRECTORY_SEPARATOR,
                            '/',
                            app()->getRootPath() . $this->options['jsBaseUrl']
                        ),
                        'cssBasePath' => str_replace(
                            DIRECTORY_SEPARATOR,
                            '/',
                            app()->getRootPath() . $this->options['cssBaseUrl']
                        ),
                        'optimize'    => $optimize,
                        'ds'          => DIRECTORY_SEPARATOR,
                    ];

                    //源文件
                    $from = $data["{$res}BasePath"] . $data["{$res}BaseName"] . '.' . $res;
                    if (!is_file($from)) {
                        throw new Exception("{$res} source file not found!file:{$from}");
                    }
                    if ($res == "js") {
                        $content = file_get_contents($from);
                        preg_match("/require\.config\(\{[\r\n]?[\n]?+(.*?)[\r\n]?[\n]?}\);/is", $content, $matches);
                        if (!isset($matches[1])) {
                            throw new Exception("js config not found!");
                        }
                        $config = preg_replace("/(urlArgs|baseUrl):(.*)\n/", '', $matches[1]);
                        $config = preg_replace("/('tableexport'):(.*)\,\n/", "'tableexport': 'empty:',\n", $config);
                        $data['config'] = $config;
                    }
                    // 生成压缩文件
                    $this->writeToFile($res, $data, $tempFile);

                    $output->info("Compress " . $data["{$res}BaseName"] . ".{$res}");

                    // 执行压缩
                    $command = "{$nodeExec} \"{$minPath}r.js\" -o \"{$tempFile}\" 2>&1";
                    if ($output->isDebug()) {
                        $output->warning($command);
                    }
                    $messages = [];
                    exec($command, $messages, $exitCode);
                    if ($exitCode != 0) {
                        throw new Exception('Compress failed: ' . implode(PHP_EOL, $messages));
                    }
                }
            }
        } finally {
            if (!$output->isDebug() && is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        $output->info("Build Successed!");
    }

    /**
     * 写入到文件
     * @param string $name
     * @param array $data
     * @param string $pathname
     * @return mixed
     */
    protected function writeToFile($name, $data, $pathname)
    {
        $search = $replace = [];
        foreach ($data as $k => $v) {
            $search[] = "{%{$k}%}";
            $replace[] = $v;
        }
        $stub = file_get_contents($this->getStub($name));
        $content = str_replace($search, $replace, $stub);

        if (!is_dir(dirname($pathname))) {
            mkdir(dirname($pathname), 0755, true);
        }
        $result = file_put_contents($pathname, $content);
        if ($result === false) {
            throw new Exception('Cannot write file: ' . $pathname);
        }
        return $result;
    }

    /**
     * 获取基础模板
     * @param string $name
     * @return string
     */
    protected function getStub($name)
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Min' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . $name . '.stub';
    }
}
