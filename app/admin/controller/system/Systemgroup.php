<?php

namespace app\admin\controller\system;

use app\common\controller\Backend;
use app\common\model\SystemGroup as SystemGroupModel;
use app\common\model\SystemGroupData as SystemGroupDataModel;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 组合数据组管理
 *
 * @icon   fa fa-th-large
 * @remark 维护多条记录共用一套字段的数据组,例如友情链接、轮播图,数据读取使用 SystemGroupData::getDataList
 */
class SystemGroup extends Backend
{
    /**
     * @var \app\common\model\SystemGroup
     */
    protected $model = null;

    /**
     * 批量操作只允许修改状态和排序
     */
    protected $multiFields = 'status,weigh';

    /**
     * 排除客户端可提交的字段
     */
    protected $excludeFields = ['id', 'createtime', 'updatetime'];

    /**
     * 初始化组合数据管理模型与页面配置
     *
     * @return void
     */
    public function initialize()
    {
        parent::initialize();
        $this->model = new SystemGroupModel();
        $this->view->assign('fieldTypeList', SystemGroupModel::getFieldTypeList());
    }

    /**
     * 查看
     *
     * @return string|\think\response\Json
     * @throws \think\Exception
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if (false === $this->request->isAjax()) {
            return $this->view->fetch();
        }
        [$where, $sort, $order, $offset, $limit] = $this->buildparams();
        $list = $this->model
            ->where($where)
            //字段定义只在编辑页面读取,列表不返回
            ->field('id,name,title,tip,weigh,status,createtime,updatetime')
            ->order($sort, $order)
            ->order('id', 'desc')
            ->paginate($limit);
        return json(['total' => $list->total(), 'rows' => $list->items()]);
    }

    /**
     * 添加数据组
     *
     * @return string
     * @throws \think\Exception
     */
    public function add()
    {
        if (false === $this->request->isPost()) {
            //新增时字段定义为空,由JS逐个添加字段
            $this->view->assign('fieldsValue', '[]');
            return $this->view->fetch();
        }
        $this->token();
        $params = $this->request->post('row/a', [], 'strip_tags,trim');
        if (!$params) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);
        $params['name'] = isset($params['name']) ? strtolower($params['name']) : '';
        $params['weigh'] = (int)($params['weigh'] ?? 0);
        try {
            validate(\app\admin\validate\SystemGroup::class)->scene('add')->check($params);
            $params['fields'] = SystemGroupModel::normalizeFields($params['fields'] ?? []);
            $this->model->save($params);
        } catch (ValidateException $e) {
            $this->error($e->getMessage());
        }
        $this->success();
    }

    /**
     * 编辑数据组
     *
     * 标识是业务读取依据,创建后不可修改。
     *
     * @param int|null $ids
     * @return string
     * @throws \think\Exception
     */
    public function edit($ids = null)
    {
        $row = $this->model->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if (false === $this->request->isPost()) {
            $this->view->assign('row', $row);
            $this->view->assign('fieldsValue', json_encode(SystemGroupModel::toFormFields($row['fields']), JSON_UNESCAPED_UNICODE));
            return $this->view->fetch();
        }
        $this->token();
        $params = $this->request->post('row/a', [], 'strip_tags,trim');
        if (!$params) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);
        unset($params['name']);
        $params['weigh'] = (int)($params['weigh'] ?? 0);
        try {
            validate(\app\admin\validate\SystemGroup::class)->scene('edit')->check($params);
        } catch (ValidateException $e) {
            $this->error($e->getMessage());
        }
        Db::startTrans();
        try {
            $fields = SystemGroupModel::normalizeFields($params['fields'] ?? []);
            //锁定当前数据组,避免与组内记录写入并发
            $locked = $this->model->where('id', $row['id'])->lock(true)->find();
            if (!$locked) {
                throw new ValidateException(__('No Results were found'));
            }
            $locked->save([
                'title'  => $params['title'],
                'tip'    => $params['tip'] ?? '',
                'fields' => $fields,
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
     * 删除数据组
     *
     * 组内存在记录时禁止删除,避免产生孤立记录。
     *
     * @param string|null $ids
     * @return void
     * @throws \think\Exception
     */
    public function del($ids = null)
    {
        if (false === $this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        $ids = array_filter(array_unique(explode(',', (string)$ids)), function ($id) {
            return preg_match('/^\d+$/', (string)$id);
        });
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        Db::startTrans();
        try {
            $count = 0;
            $list = $this->model->where('id', 'in', $ids)->lock(true)->select();
            foreach ($list as $item) {
                if (SystemGroupDataModel::where('group_id', $item['id'])->count()) {
                    throw new ValidateException(__('Please handle the data of the group %s first', $item['title']));
                }
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
     * 只允许修改状态和排序,字段结构必须通过编辑表单变更。
     *
     * @param string|null $ids
     * @return void
     * @throws \think\Exception
     */
    public function multi($ids = null)
    {
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
                validate(\app\admin\validate\SystemGroup::class)->scene('multi')->check($values);
            } catch (ValidateException $e) {
                $this->error($e->getMessage());
            }
        }
        return parent::multi($ids);
    }
}
