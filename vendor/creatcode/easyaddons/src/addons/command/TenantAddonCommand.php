<?php

namespace creatcode\easyaddons\addons\command;

class TenantAddonCommand extends BaseAddonCommand
{
    protected function getCommandName()
    {
        return 'tenant:addon';
    }

    protected function loadContext()
    {
        $this->loadContextFiles(app_path() . 'tenant' . DIRECTORY_SEPARATOR);
    }
}
