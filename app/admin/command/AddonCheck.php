<?php

namespace app\admin\command;

use app\admin\service\AddonCompat;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Exception;

/**
 * 插件兼容性体检
 */
class AddonCheck extends Command
{
    protected function configure()
    {
        $this->setName('addon:check')
            ->addOption('path', 'p', Option::VALUE_REQUIRED, 'addon package (.zip) or addon directory', null)
            ->addOption('json', null, Option::VALUE_NONE, 'output result as json')
            ->setDescription('Check whether an addon is compatible with the current kernel');
    }

    protected function execute(Input $input, Output $output)
    {
        $path = (string)$input->getOption('path');
        if ($path === '') {
            throw new Exception('Please specify the addon path by --path');
        }
        if (!file_exists($path)) {
            throw new Exception('Path not found: ' . $path);
        }

        $result = is_dir($path) ? AddonCompat::checkDir($path) : AddonCompat::checkZip($path);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $result['errors'] > 0 ? 1 : 0;
        }

        $output->info(sprintf('Scanned %d file(s): %d error(s), %d warning(s)', $result['files'], $result['errors'], $result['warnings']));
        foreach ($result['issues'] as $issue) {
            $output->writeln(sprintf('%s %s:%d %s', $issue['level'] === 'error' ? '[ERROR]' : '[WARN ]', $issue['file'], $issue['line'], $issue['message']));
        }
        if (!$result['issues']) {
            $output->info('No compatibility issue found.');
        }

        return $result['errors'] > 0 ? 1 : 0;
    }
}
