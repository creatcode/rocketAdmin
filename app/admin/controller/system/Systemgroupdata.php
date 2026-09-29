<?php

namespace app\admin\controller\system;

use app\common\controller\Backend;
use app\common\model\SystemGroup as SystemGroupModel;
use app\common\model\SystemGroupData as SystemGroupDataModel;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 组合数据记录管理
 *
 * @icon   fa fa-list-alt
 * @remark 按所属数据组的字段定义维护组内记录,记录归属由数据组的ID确定
 */
class SystemGroupData extends Backend
{
    /**
     * @var \app\common\model\SystemGroupData
     */
    protected $model = null;

    /**
     * 批量操作只允许修改状态和排序
     */
    protected $multiFields = 'status,weigh';

    /**
     * 只允许主键作为快速搜索字段,动态字段保存在JSON中不可查询
     */
    protected $searchFields = 'id';

    /**
     * 排除客户端可提交的字段,记录归属不允许修改
     */
    protected $excludeFields = ['id', 'group_id', 'createtime', 'updatetime'];

    /**
     * 初始化组合数据管理模型与页面配置
     *
     * @return void
     */
    public function initialize()
    {
        parent::initialize();
        $this->model = new SystemGroupDataModel();
    }

    /**
     * 读取当前请求的数据组
     *
     * @return \app\common\model\SystemGroup
     * @throws \think\Exception
     */
    protected function getGroup()
    {
        $group = SystemGroupModel::find((int)$this->request->request('group_id', 0));
        if (!$group) {
            $this->error(__('Data group not found'));
        }
        return $group;
    }

    /**
     * 构造动态表单需要的字段数据
     *
     * 控件类型判断和转义在服务端完成,模板只负责输出。
     *
     * @param array $fields 已规范化的字段定义
     * @param array $values 已有记录值
     * @return array
     */
    protected function formFields($fields, $values = [])
    {
        $result = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['type'];
            if (array_key_exists($name, (array)$values)) {
                $value = $values[$name];
            } else {
                $value = in_array($type, ['number', 'switch'], true) ? 0 : ($type === 'checkbox' ? [] : '');
            }
            //多选值保持数组供复选框组回填,其余类型转字符串
            $value = $type === 'checkbox' ? (array)$value : (is_scalar($value) ? (string)$value : '');
            $result[] = [
                'name'    => $name,
                'title'   => $field['title'],
                'type'    => $type,
                'value'   => $value,
                'options' => isset($field['param']) && is_array($field['param']) ? $field['param'] : [],
            ];
        }
        return $result;
    }

    /**
     * 解析提交的记录ID
     *
     * @param string|null $ids
     * @return array
     */
    protected function parseIds($ids)
    {
        $ids = array_filter(array_unique(explode(',', (string)$ids)), function ($id) {
            return preg_match('/^\d+$/', (string)$id);
        });
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        return $ids;
    }

    /**
     * 查看
     *
     * @return string|\think\response\Json
     * @throws \think\Exception
     */
    public function index()
    {
        $group = $this->getGroup();
        $this->request->filter(['strip_tags', 'trim']);
        if (false === $this->request->isAjax()) {
            $this->view->assign('group', $group);
            $this->assignconfig('group', ['id' => $group['id'], 'title' => $group['title']]);
            $this->assignconfig('fields', array_values((array)$group['fields']));
            return $this->view->fetch();
        }
        [$where, $sort, $order, $offset, $limit] = $this->buildparams();
        $list = $this->model
            ->where('group_id', $group['id'])
            ->where($where)
            ->order($sort, $order)
            ->order('id', 'desc')
            ->paginate($limit);
        return json(['total' => $list->total(), 'rows' => $list->items()]);
    }

    /**
     * 添加记录
     *
     * @return string
     * @throws \think\Exception
     */
    public function add()
    {
        $group = $this->getGroup();
        $fields = SystemGroupModel::normalizeFields($group['fields']);
        if (false === $this->request->isPost()) {
            $this->view->assign('group', $group);
            $this->view->assign('fields', $this->formFields($fields));
            return $this->view->fetch();
        }
        $this->token();
        $params = $this->request->post('row/a', [], 'strip_tags,trim');
        if (!$params) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);
        //归属取自已确认的数据组,不接受客户端提交
        $params['group_id'] = $group['id'];
        $params['weigh'] = (int)($params['weigh'] ?? 0);
        try {
            validate(\app\admin\validate\SystemGroupData::class)->scene('add')->check($params);
        } catch (ValidateException $e) {
            $this->error($e->getMessage());
        }
        Db::startTrans();
        try {
            //锁定数据组,避免与字段结构修改并发
            $locked = SystemGroupModel::where('id', $group['id'])->lock(true)->find();
            if (!$locked) {
                throw new ValidateException(__('Data group not found'));
            }
            $params['value'] = SystemGroupDataModel::normalizeValue(
                SystemGroupModel::normalizeFields($locked['fields']),
                $params['value'] ?? []
            );
            $this->model->save($params);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success();
    }

    /**
     * 编辑记录
     *
     * @param int|null $ids
     * @return string
     * @throws \think\Exception
     */
    public function edit($ids = null)
    {
        $group = $this->getGroup();
        $row = $this->model->where('group_id', $group['id'])->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $fields = SystemGroupModel::normalizeFields($group['fields']);
        if (false === $this->request->isPost()) {
            $this->view->assign('row', $row);
            $this->view->assign('group', $group);
            $this->view->assign('fields', $this->formFields($fields, (array)$row['value']));
            return $this->view->fetch();
        }
        $this->token();
        $params = $this->request->post('row/a', [], 'strip_tags,trim');
        if (!$params) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);
        $params['weigh'] = (int)($params['weigh'] ?? 0);
        try {
            validate(\app\admin\validate\SystemGroupData::class)->scene('edit')->check($params);
        } catch (ValidateException $e) {
            $this->error($e->getMessage());
        }
        Db::startTrans();
        try {
            //锁定数据组,避免读取到正在修改的字段结构
            $locked = SystemGroupModel::where('id', $group['id'])->lock(true)->find();
            if (!$locked) {
                throw new ValidateException(__('Data group not found'));
            }
            $row->save([
                'value'  => SystemGroupDataModel::normalizeValue(
                    SystemGroupModel::normalizeFields($locked['fields']),
                    $params['value'] ?? []
                ),
                'weigh'  => $params['weigh'],
                'status' => $params['status'],
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success();
    }

    /**
     * 删除记录
     *
     * @param string|null $ids
     * @return void
     * @throws \think\Exception
     */
    public function del($ids = null)
    {
        $group = $this->getGroup();
        if (false === $this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        $ids = $this->parseIds($ids);
        Db::startTrans();
        try {
            $count = 0;
            //只有当前数据组内的记录允许删除
            $list = $this->model
                ->where('group_id', $group['id'])
                ->where('id', 'in', $ids)
                ->lock(true)
                ->select();
            if (count($list) !== count($ids)) {
                throw new ValidateException(__('You have no permission'));
            }
            foreach ($list as $item) {
                $count += $item->delete();
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        if ($count > 0) {
            $this->success();
        }
        $this->error(__('No rows were deleted'));
    }

    /**
     * 批量更新
     *
     * 记录必须全部属于当前数据组,只允许修改状态和排序。
     *
     * @param string|null $ids
     * @return void
     * @throws \think\Exception
     */
    public function multi($ids = null)
    {
        $group = $this->getGroup();
        if ($this->request->isPost()) {
            $params = $this->request->post('params', '');
            if (!is_string($params)) {
                $this->error(__('Invalid parameters'));
            }
            parse_str($params, $values);
            if (array_diff(array_keys($values), ['status', 'weigh'])) {
                $this->error(__('Invalid parameters'));
            }
            try {
                validate(\app\admin\validate\SystemGroupData::class)->scene('multi')->check($values);
            } catch (ValidateException $e) {
                $this->error($e->getMessage());
            }
            $ids = $this->parseIds($ids ?: $this->request->post('ids'));
            if ($this->model->where('group_id', $group['id'])->where('id', 'in', $ids)->count() !== count($ids)) {
                $this->error(__('You have no permission'));
            }
        }
        return parent::multi($ids);
    }
}
