<?php

namespace creatcode\easyaddons\addons\command;

/**
 * 后台应用的插件管理命令。
 */
class AddonCommand extends BaseAddonCommand
{
    /**
     * 获取命令名称。
     * @return string
     */
    protected function getCommandName()
    {
        return 'addon';
    }

    /**
     * 加载应用配置和公共函数。
     * @return void
     */
    protected function loadContext()
    {
        $this->loadContextFiles(app()->getAppPath() . 'admin' . DIRECTORY_SEPARATOR);
    }
}
