<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;

/**
 * 会员组管理
 *
 * @icon fa fa-users
 */
class Group extends Backend
{

    /**
     * @var \app\admin\model\UserGroup
     */
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = model('UserGroup');
        $this->view->assign("statusList", $this->model->getStatusList());
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $this->token();
        }
        $nodeList = \app\admin\model\UserRule::getTreeList();
        $this->view->assign("nodeList", $nodeList);
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $this->token();
        }
        $row = $this->model->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $rules = explode(',', $row['rules']);
        $nodeList = \app\admin\model\UserRule::getTreeList($rules);
        $this->view->assign("nodeList", $nodeList);
        return parent::edit($ids);
    }
}
