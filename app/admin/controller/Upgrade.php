<?php

namespace app\admin\controller;

use app\admin\service\UpgradeService;
use app\common\controller\Backend;
use think\facade\Config;

/**
 * 项目升级
 */
class Upgrade extends Backend
{
    /**
     * 仅允许超级管理员使用升级页面和接口。
     *
     * @var array
     */
    protected $noNeedRight = ['index', 'check', 'run', 'recover', 'logs', 'status', 'del'];

    /**
     * 初始化并限制为超级管理员。
     *
     * @return void
     */
    public function initialize()
    {
        parent::initialize();
        if (!$this->auth->isSuperAdmin()) {
            $this->error(__('Access is allowed only to the super management group'));
        }
    }

    /**
     * 显示版本检查和未完成批次状态。
     *
     * @return string
     */
    public function index()
    {
        $data = (new UpgradeService())->dashboard();
        $this->view->assign([
            'currentVersion' => Config::get('rocket.version', ''),
            'upgradeEnabled' => (bool)Config::get('rocket.upgrade.enabled', true),
            'interruptedBatch' => $data['interrupted'],
        ]);
        return $this->view->fetch();
    }

    /**
     * 升级日志列表。
     *
     * @return \think\response\Json
     */
    public function logs()
    {
        list(, $sort, $order, $offset, $limit) = $this->buildparams();

        $history = (new UpgradeService())->history();
        $sortable = ['batch', 'created_at', 'from_version', 'to_version', 'state', 'sql_total', 'sql_completed'];
        if (in_array($sort, $sortable, true)) {
            usort($history, function ($left, $right) use ($sort, $order) {
                $result = $left[$sort] <=> $right[$sort];
                return $order === 'ASC' ? $result : -$result;
            });
        }

        return json([
            'total' => count($history),
            'rows' => array_slice($history, $offset, $limit),
        ]);
    }

    /**
     * 当前升级批次进度，供前端轮询。无进行中批次时返回 null。
     *
     * @return \think\response\Json
     */
    public function status()
    {
        return json((new UpgradeService())->dashboard()['interrupted']);
    }

    /**
     * 删除指定升级批次。
     *
     * @param string $ids 批次编号，多个用逗号分隔
     * @return void
     */
    public function del($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }

        $ids = trim((string)($ids ?: $this->request->post('ids')));
        if ($ids === '') {
            $this->error(__('Invalid parameters'));
        }

        try {
            (new UpgradeService())->deleteBatches(array_filter(array_map('trim', explode(',', $ids))));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success(__('Delete completed'));
    }

    /**
     * 读取远程版本信息，不下载升级包。
     *
     * @return void
     */
    public function check()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        if (!Config::get('rocket.upgrade.enabled', true)) {
            $this->error(__('The upgrade feature is disabled'));
        }

        try {
            $result = (new UpgradeService())->check((string)Config::get('rocket.version', ''));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success(__('Check update success'), null, $result);
    }

    /**
     * 重新读取版本信息并执行文件与数据库升级。
     *
     * @return void
     */
    public function run()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $this->token();
        if (!Config::get('rocket.upgrade.enabled', true)) {
            $this->error(__('The upgrade feature is disabled'));
        }

        try {
            $result = (new UpgradeService())->run(trim((string)$this->request->post('version', '')));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success(__('Upgrade completed'), null, $result);
    }

    /**
     * 根据本地日志恢复未完成批次。
     *
     * @return void
     */
    public function recover()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $this->token();

        try {
            $result = (new UpgradeService())->recover(trim((string)$this->request->post('batch', '')));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success(__('Recovery completed'), null, $result);
    }
}
