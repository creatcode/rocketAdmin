<?php

namespace creatcode\easyaddons\addons\command;

class AddonCommand extends BaseAddonCommand
{
    protected function getCommandName()
    {
        return 'addon';
    }

    protected function loadContext()
    {
        $this->loadContextFiles(app_path() . 'admin' . DIRECTORY_SEPARATOR);
    }
}
