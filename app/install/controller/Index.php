<?php

namespace app\install\controller;

use app\BaseController;
use app\common\service\InstallService;
use Exception;

class Index extends BaseController
{
    protected function initialize()
    {
        $this->request->filter(['trim']);
    }

    public function index()
    {
        /** @var InstallService $installService */
        $installService = app()->make(InstallService::class);

        if ($this->request->isPost()) {
            if ($installService->isInstalled()) {
                return json([
                    'code' => 0,
                    'msg'  => __('The system has been installed. If you need to reinstall, please remove %s first', '.install.lock'),
                ]);
            }

            $mysqlHostname = (string)$this->request->post('mysqlHostname', '127.0.0.1');
            $mysqlHostport = (string)$this->request->post('mysqlHostport', '3306');
            $hostArr = explode(':', $mysqlHostname);
            if (count($hostArr) > 1) {
                $mysqlHostname = $hostArr[0];
                $mysqlHostport = $hostArr[1];
            }

            $payload = [
                'mysqlHostname' => $mysqlHostname,
                'mysqlHostport' => $mysqlHostport,
                'mysqlUsername' => (string)$this->request->post('mysqlUsername', 'root'),
                'mysqlPassword' => (string)$this->request->post('mysqlPassword', ''),
                'mysqlDatabase' => (string)$this->request->post('mysqlDatabase', ''),
                'mysqlPrefix'   => (string)$this->request->post('mysqlPrefix', 'fa_'),
                'adminUsername' => (string)$this->request->post('adminUsername', 'admin'),
                'adminPassword' => (string)$this->request->post('adminPassword', ''),
                'adminEmail'    => (string)$this->request->post('adminEmail', 'admin@admin.com'),
                'siteName'      => (string)$this->request->post('siteName', __('My Website')),
            ];

            if ($payload['adminPassword'] !== (string)$this->request->post('adminPasswordConfirmation', '')) {
                return json(['code' => 0, 'msg' => __('The two passwords you entered did not match')]);
            }

            try {
                $result = $installService->install($payload);
                $adminUrl = $this->request->domain() . $result['adminPath'];

                return json([
                    'code' => 1,
                    'msg'  => __('Install Successed'),
                    'data' => array_merge($result, ['adminUrl' => $adminUrl]),
                ]);
            } catch (Exception $e) {
                return json(['code' => 0, 'msg' => $e->getMessage()]);
            }
        }

        $installInfo = $installService->getInstallInfo();

        $this->view->assign([
            'installed'       => $installService->isInstalled(),
            'installInfo'     => $installInfo,
            'envStatus'       => $installService->getEnvironmentStatus(),
            'adminUrl'        => $this->request->domain() . '/admin/index/login',
            'lockFileDisplay' => $installInfo['lockFile'] ?? '.install.lock',
            'installedSite'   => $installInfo['site'] ?? '-',
            'installedAdmin'  => $installInfo['admin_user'] ?? '-',
            'installedAt'     => $installInfo['installedAt'] ?? '-',
        ]);

        return $this->view->fetch();
    }
}
