<?php

namespace app\admin\controller\system;

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
    protected $noNeedRight = ['index', 'check', 'run', 'recover', 'logs', 'status', 'del', 'detail', 'backup'];

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
            'lastBatch' => $data['history'][0]['batch'] ?? '0',
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
        try {
            $result = (new UpgradeService())->progress(
                trim((string)$this->request->get('batch', '')),
                trim((string)$this->request->get('after', ''))
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        return json($result);
    }

    /**
     * 查看指定批次的 SQL 执行详情和备份信息。
     *
     * @return void
     */
    public function detail()
    {
        try {
            $result = (new UpgradeService())->detail(trim((string)$this->request->get('batch', '')));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('', null, $result);
    }

    /**
     * 仅向超级管理员流式导出指定批次的备份，避免整包载入内存。
     *
     * @return \think\Response|null
     */
    public function backup()
    {
        if (!$this->request->isGet()) {
            $this->error(__('Invalid parameters'));
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        try {
            // 发送过程由服务持锁保护，返回后再清理临时 ZIP 和释放锁。
            (new UpgradeService())->exportBackup(trim((string)$this->request->get('batch', '')), function ($file, $name) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Cache-Control: no-store');
                header('Pragma: no-cache');
                header('Content-Length: ' . filesize($file));
                if (readfile($file) === false) {
                    throw new \RuntimeException('备份发送失败。');
                }
            });
            return response('', 200, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            if (headers_sent()) {
                // 已发送 ZIP 时不追加 HTML 或 JSON，以免破坏下载内容。
                \think\facade\Log::error('升级备份导出失败：' . $e->getMessage());
                return response('', 200, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store']);
            }
            $this->error($e->getMessage());
        }
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
            $service = new UpgradeService();
            $previousBatch = $service->history()[0]['batch'] ?? '';
            $result = $service->run(trim((string)$this->request->post('version', '')));
        } catch (\Throwable $e) {
            $batch = '';
            $message = $e->getMessage();
            if (isset($service, $previousBatch)) {
                try {
                    // 即使轮询尚未看到批次，也能返回这次失败的最终现场。
                    $latestBatch = $service->history()[0]['batch'] ?? '';
                    $batch = $latestBatch !== $previousBatch ? $latestBatch : '';
                } catch (\Throwable $statusError) {
                    $message .= '；读取升级状态失败：' . $statusError->getMessage();
                }
            }
            $this->error($message, null, ['batch' => $batch]);
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
