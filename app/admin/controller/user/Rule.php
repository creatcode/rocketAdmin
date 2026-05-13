<?php

namespace app\admin\controller\user;

use app\admin\model\UserRule;
use app\common\controller\Backend;
use util\Tree;

/**
 * 会员规则管理
 *
 * @icon fa fa-circle-o
 */
class Rule extends Backend
{

    /**
     * @var \app\admin\model\UserRule
     */
    protected $model = null;
    protected $rulelist = [];
    protected $multiFields = 'ismenu,status';

    public function initialize()
    {
        parent::initialize();
        $this->model = new UserRule();
        $this->view->assign("statusList", $this->model->getStatusList());
        // 必须将结果集转换为数组
        $ruleList = collect($this->model->order('weigh', 'desc')->select())->toArray();
        foreach ($ruleList as $k => &$v) {
            $v['title'] = __($v['title']);
            $v['remark'] = __($v['remark']);
        }
        unset($v);
        Tree::instance()->init($ruleList)->icon = ['&nbsp;&nbsp;&nbsp;&nbsp;', '&nbsp;&nbsp;&nbsp;&nbsp;', '&nbsp;&nbsp;&nbsp;&nbsp;'];
        $this->rulelist = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(0), 'title');
        $ruledata = [0 => __('None')];
        foreach ($this->rulelist as $k => &$v) {
            if (!$v['ismenu']) {
                continue;
            }
            $ruledata[$v['id']] = $v['title'];
        }
        $this->view->assign('ruledata', $ruledata);
    }

    /**
     * 查看
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $list = $this->rulelist;
            $total = count($this->rulelist);

            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $this->token();
            $params = $this->request->post("row/a", [], 'strip_tags');
            if ($params) {
                if (empty($params['ismenu']) && empty($params['pid'])) {
                    $this->error(__('The non-menu rule must have parent'));
                }
                if (empty($params['name']) || !preg_match('/^[a-zA-Z0-9_\/]+$/', $params['name'])) {
                    $this->error(__('Invalid parameters'));
                }
                if (empty($params['title'])) {
                    $this->error(__('Parameter %s can not be empty', __('Title')));
                }
                if ($this->model->where('name', $params['name'])->find()) {
                    $this->error(__('Name already exist'));
                }
            }
        }
        return parent::add();
    }

    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $this->token();
            $row = $this->model->find($ids);
            if (!$row) {
                $this->error(__('No Results were found'));
            }
            $params = $this->request->post("row/a", [], 'strip_tags');
            if ($params) {
                if (empty($params['ismenu']) && empty($params['pid'])) {
                    $this->error(__('The non-menu rule must have parent'));
                }
                $pid = $params['pid'] ?? 0;
                if ($pid == $row['id']) {
                    $this->error(__('Can not change the parent to self'));
                }
                if ($pid != $row['pid']) {
                    $childrenIds = Tree::instance()->init(collect(UserRule::select())->toArray())->getChildrenIds($row['id']);
                    if (in_array($pid, $childrenIds)) {
                        $this->error(__('Can not change the parent to child'));
                    }
                }
                if (empty($params['name']) || !preg_match('/^[a-zA-Z0-9_\/]+$/', $params['name'])) {
                    $this->error(__('Invalid parameters'));
                }
                if (empty($params['title'])) {
                    $this->error(__('Parameter %s can not be empty', __('Title')));
                }
                if ($this->model->where('name', $params['name'])->where('id', '<>', $row['id'])->find()) {
                    $this->error(__('Name already exist'));
                }
            }
        }
        return parent::edit($ids);
    }

    /**
     * 删除
     */
    public function del($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $ids = $ids ?: $this->request->post("ids");
        if ($ids) {
            $delIds = [];
            $ids = array_filter(array_unique(explode(',', $ids)), function ($id) {
                return preg_match('/^\d+$/', (string)$id);
            });
            foreach ($ids as $k => $v) {
                $delIds = array_merge($delIds, Tree::instance()->getChildrenIds($v, true));
            }
            $delIds = array_unique($delIds);
            if (!$delIds) {
                $this->error(__('Operation failed'));
            }
            $count = $this->model->where('id', 'in', $delIds)->delete();
            if ($count) {
                $this->success();
            }
        }
        $this->error(__('Operation failed'));
    }
}
