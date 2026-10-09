<?php

namespace creatcode\easyaddons\addons\command;

/**
 * 租户应用的插件管理命令。
 */
class TenantAddonCommand extends BaseAddonCommand
{
    /**
     * 获取命令名称。
     * @return string
     */
    protected function getCommandName()
    {
        return 'tenant:addon';
    }

    /**
     * 加载应用配置和公共函数。
     * @return void
     */
    protected function loadContext()
    {
        $this->loadContextFiles(app()->getAppPath() . 'tenant' . DIRECTORY_SEPARATOR);
    }
}
