<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;
use app\common\library\Auth;

/**
 * 会员管理
 *
 * @icon fa fa-user
 */
class User extends Backend
{

    protected $relationSearch = true;
    protected $searchFields = 'id,username,nickname';

    /**
     * @var \app\admin\model\User
     */
    protected $model = null;

    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\User;
    }

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->alias('user')
                ->withJoin('group')
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $k => $v) {
                $v->avatar = $v->avatar ? cdnurl($v->avatar, true) : letter_avatar($v->nickname);
                $v->hidden(['password', 'salt']);
            }
            $result = ["total" => $list->total(), "rows" => $list->items()];

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {
        $groups = \app\admin\model\UserGroup::where('status', 'normal')->column('name', 'id');
        if ($this->request->isPost()) {
            $this->token();
            $post = $this->request->post();
            $params = $this->request->post('row/a', []);
            $groupId = $params['group_id'] ?? null;
            if (!is_scalar($groupId) || !isset($groups[$groupId])) {
                $this->error(__('Invalid parameters'));
            }
            // 新增仅接收表单字段，登录状态和账户余额使用系统默认值。
            $post['row'] = array_intersect_key($params, array_flip(['group_id', 'username', 'nickname', 'password', 'email', 'mobile', 'status']));
            $post['row']['jointime'] = time();
            $post['row']['joinip'] = $this->request->ip();
            $post['row']['level'] = 1;
            $this->request->withPost($post);
            $this->modelValidate = true;
            $this->modelSceneValidate = true;
        }
        $this->view->assign('groupList', build_select('row[group_id]', $groups, null, ['id' => 'c-group_id', 'class' => 'form-control selectpicker', 'data-rule' => 'required']));
        return parent::add();
    }

    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $this->token();
        }
        $row = $this->model->find($ids);
        $this->modelValidate = true;
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('groupList', build_select('row[group_id]', \app\admin\model\UserGroup::column('name', 'id'), $row['group_id'], ['class' => 'form-control selectpicker']));
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
        $ids = array_filter(array_unique(explode(',', $ids)), function ($id) {
            return preg_match('/^\d+$/', (string)$id);
        });
        $row = $ids ? $this->model->where('id', 'in', $ids)->select() : [];
        $this->modelValidate = true;
        if (!$row || count($row) === 0) {
            $this->error(__('No Results were found'));
        }
        foreach ($row as $item) {
            Auth::instance()->delete($item['id']);
        }
        $this->success();
    }

}
