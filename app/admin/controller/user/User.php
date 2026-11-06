<?php

namespace app\admin\controller\user;

use app\common\controller\Backend;
use app\common\library\Auth;
use think\facade\Db;

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
            list($where, $sort, $order, $offset, $limit, $page) = $this->buildparams();
            $list = $this->model
                ->alias('user')
                ->withJoin('group')
                ->where($where)
                ->order($sort, $order)
                ->paginate(['list_rows' => $limit, 'page' => $page]);
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
        $groups = \app\common\model\UserGroup::where('status', 'normal')->column('name', 'id');
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
        if (!$this->request->isPost()) {
            $row = $this->model->find($ids);
            if (!$row) {
                $this->error(__('No Results were found'));
            }
            $this->view->assign('groupList', build_select('row[group_id]', \app\common\model\UserGroup::column('name', 'id'), $row['group_id'], ['class' => 'form-control selectpicker']));
            return parent::edit($ids);
        }

        $this->token();
        if (!is_scalar($ids) || !ctype_digit((string)$ids) || $ids <= 0) {
            $this->error(__('Invalid parameters'));
        }
        $params = $this->preExcludeFields($this->request->post('row/a', []));
        if (!$params) {
            $this->error(__('Invalid parameters'));
        }
        $original = $this->request->post('original/a', []);
        $where = [];
        foreach (['money' => '/^-?\d+(?:\.\d{1,2})?$/D', 'score' => '/^-?\d+$/D'] as $field => $pattern) {
            if (array_key_exists($field, $params)) {
                if (!isset($original[$field]) || !is_scalar($original[$field]) || !is_scalar($params[$field])
                    || !preg_match($pattern, (string)$original[$field]) || !preg_match($pattern, (string)$params[$field])) {
                    $this->error(__('Invalid parameters'));
                }
                $where[] = [$field, '=', $original[$field]];
            }
        }
        unset($params['id']);
        try {
            Db::transaction(function () use ($ids, $params, $where) {
                // 原值参与条件并锁行，旧表单及并发写入都不得覆盖新余额。
                $row = $this->model->where($where)->lock(true)->find($ids);
                if (!$row) {
                    throw new \think\Exception(__('Account data changed, please refresh and try again'));
                }
                $adminIds = $this->getDataLimitAdminIds();
                if (is_array($adminIds) && !in_array($row[$this->dataLimitField], $adminIds)) {
                    throw new \think\Exception(__('You have no permission'));
                }
                validate(\app\admin\validate\User::class)->failException(true)->check(array_merge($params, ['id' => $row->id]));
                if ($row->save($params) === false) {
                    throw new \think\Exception(__('No rows were updated'));
                }
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success();
    }

    /**
     * 删除
     */
    public function del($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $ids = $ids ?: $this->request->post('ids');
        $ids = is_array($ids) ? $ids : explode(',', (string)$ids);
        if (!$ids) {
            $this->error(__('Invalid parameters'));
        }
        foreach ($ids as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id) || $id <= 0) {
                $this->error(__('Invalid parameters'));
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        Db::startTrans();
        try {
            $list = $this->model->where('id', 'in', $ids)->select();
            if (count($list) !== count($ids)) {
                throw new \think\Exception(__('No Results were found'));
            }
            foreach ($list as $row) {
                if (!Auth::instance()->delete($row['id'])) {
                    throw new \think\Exception(Auth::instance()->getError() ?: __('No rows were deleted'));
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success();
    }

}
