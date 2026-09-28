<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use think\console\Input;
use think\facade\Db;
use Throwable;

/**
 * 在线命令管理
 *
 * @icon fa fa-circle-o
 */
class Command extends Backend
{

    /**
     * Command模型对象
     */
    protected $model = null;
    protected $noNeedRight = ['get_controller_list', 'get_field_list'];

    /**
     * 初始化在线命令模型和状态选项
     * @return void
     */
    public function initialize()
    {
        parent::initialize();
        $this->model = new \app\admin\model\Command;
        $this->view->assign("statusList", $this->model->getStatusList());
    }

    /**
     * 添加
     */
    public function add()
    {

        $tableList = [];
        $list = Db::query("SHOW TABLES");
        foreach ($list as $key => $row) {
            $tableList[reset($row)] = reset($row);
        }

        $this->view->assign("tableList", $tableList);
        return $this->view->fetch();
    }

    /**
     * 获取字段列表
     * @internal
     */
    public function get_field_list()
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式不正确'));
        }
        $dbname = Db::connect()->getConfig('database');
        $table = $this->request->request('table');
        //从数据库中获取表字段信息
        $sql = "SELECT * FROM `information_schema`.`columns` "
            . "WHERE TABLE_SCHEMA = ? AND table_name = ? "
            . "ORDER BY ORDINAL_POSITION";
        //加载主表的列
        $columnList = Db::query($sql, [$dbname, $table]);
        $fieldlist = [];
        foreach ($columnList as $index => $item) {
            $fieldlist[] = $item['COLUMN_NAME'];
        }
        $this->success("", null, ['fieldlist' => $fieldlist]);
    }

    /**
     * 获取控制器列表
     * @internal
     */
    public function get_controller_list()
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式不正确'));
        }
        //搜索关键词,客户端输入以空格分开,这里接收为数组
        $word = (array)$this->request->post("q_word/a");
        $word = implode('', $word);

        $adminPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $controllerDir = $adminPath . 'controller' . DIRECTORY_SEPARATOR;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllerDir), \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $list = [];
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $name = str_replace($controllerDir, '', $filePath);
                $name = str_replace(DIRECTORY_SEPARATOR, "/", $name);
                if (!preg_match("/(.*)\.php\$/", $name)) {
                    continue;
                }
                if (!$word || stripos($name, $word) !== false) {
                    $list[] = ['id' => $name, 'name' => $name];
                }
            }
        }
        $pageNumber = $this->request->request("pageNumber");
        $pageSize = $this->request->request("pageSize");
        return json(['list' => array_slice($list, ($pageNumber - 1) * $pageSize, $pageSize), 'total' => count($list)]);
    }

    /**
     * 详情
     */
    public function detail($ids)
    {
        $row = $this->model->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $row['params'] = (array)json_decode($row['params'], true);
        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

    /**
     * 执行
     */
    public function execute($ids)
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式不正确'));
        }
        $row = $this->model->find($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $params = (array)json_decode($row['params'], true);
        if (!isset($params['commandtype'])) {
            $this->error('不支持1.1.3之前版本的旧命令');
        }
        // 重放历史参数时使用本次请求的令牌，避免复用已失效的旧令牌
        $params['__token__'] = $this->request->post('__token__');
        $this->request->withPost($params);
        $this->command('execute');
    }

    /**
     * 生成命令
     */
    public function command($action = '')
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式不正确'));
        }
        $this->token();
        $commandtype = $this->request->post("commandtype");
        $params = $this->request->post();
        $allowfields = [
            'crud' => 'table,controller,model,fields,force,local,delete,menu',
            'menu' => 'delete,force',
            'min'  => 'module,resource,optimize',
        ];
        if (!isset($allowfields[$commandtype])) {
            $this->error(__('不支持的命令类型'));
        }
        $argv = [];
        $allowfields = isset($allowfields[$commandtype]) ? explode(',', $allowfields[$commandtype]) : [];
        $allowfields = array_filter(array_intersect_key($params, array_flip($allowfields)));
        if (isset($params['local']) && !$params['local']) {
            $allowfields['local'] = $params['local'];
        } else {
            unset($allowfields['local']);
        }
        $tableFields = [];
        if (isset($params['table'])) {
            $tableFields = Db::table($params['table'])->getTableFields();
            if (isset($params['fields']) && $params['fields'] !== '') {
                $fields = is_array($params['fields']) ? $params['fields'] : explode(',', $params['fields']);
                $fields = array_filter(array_map('trim', $fields));
                $invalidFields = array_diff($fields, $tableFields);
                if ($invalidFields) {
                    $this->error(__('字段 %s 不存在于数据表中', implode(', ', $invalidFields)));
                }
            }
        }

        foreach ($allowfields as $key => $param) {
            if (in_array($key, ['force', 'delete', 'local'])) {
                if (!in_array($param, [0, 1, '0', '1'], true)) {
                    $this->error(__('参数 %s 的值不正确', $key));
                }
                $param = (int)$param;
            } elseif ($key === 'menu') {
                if (!preg_match('/^\d+$/', (string)$param)) {
                    $this->error(__('参数 %s 的值不正确', $key));
                }
                $param = (int)$param;
            } elseif (in_array($key, ['table', 'model'])) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$param)) {
                    $this->error(__('参数 %s 的值不正确', $key));
                }
            } elseif ($key === 'controller') {
                if (!preg_match('/^[a-zA-Z0-9_\/]+$/', (string)$param)) {
                    $this->error(__('参数 %s 的值不正确', $key));
                }
            } elseif (in_array($key, ['module', 'resource', 'optimize'])) {
                $validValues = [
                    'module'   => ['all', 'backend', 'frontend'],
                    'resource' => ['all', 'js', 'css'],
                    'optimize' => ['uglify', 'closure'],
                ];
                if (!in_array($param, $validValues[$key], true)) {
                    $this->error(__('参数 %s 的值不正确', $key));
                }
            }
            $argv[] = "--{$key}=" . (is_array($param) ? implode(',', $param) : $param);
        }
        if ($commandtype == 'crud') {
            $extend = 'setcheckboxsuffix,enumradiosuffix,imagefield,filefield,intdatesuffix,switchsuffix,citysuffix,selectpagesuffix,selectpagessuffix,ignorefields,sortfield,editorsuffix,headingfilterfield,tagsuffix,jsonsuffix,fixedcolumns';
            $extendArr = explode(',', $extend);
            foreach ($params as $index => $item) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$index)) {
                    $this->error(__('参数 %s 的值不正确', $index));
                }
                if (in_array($index, $extendArr)) {
                    if (!is_string($item)) {
                        $this->error(__('参数 %s 的值不正确', $index));
                    }
                    foreach (explode(',', $item) as $key => $value) {
                        $value = trim($value);
                        if ($value) {
                            if (!preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
                                $this->error(__('参数 %s 的值不正确', $index));
                            }
                            $argv[] = "--{$index}={$value}";
                        }
                    }
                }
            }
            $isrelation = (int)$this->request->request('isrelation');
            if ($isrelation && isset($params['relation'])) {
                foreach ($params['relation'] as $index => $relation) {
                    $relationTableFields = Db::table($relation['relation'])->getTableFields();
                    if ($relation['relationmode'] === 'hasone') {
                        if (!in_array($relation['relationforeignkey'], $relationTableFields)) {
                            $this->error('关联字段不正确(code:2001)');
                        }

                        if (!in_array($relation['relationprimarykey'], $tableFields)) {
                            $this->error('关联字段不正确(code:2002)');
                        }

                    } elseif ($relation['relationmode'] === 'belongsto') {

                        if (!in_array($relation['relationforeignkey'], $tableFields)) {
                            $this->error('关联字段不正确(code:2003)');
                        }

                        if (!in_array($relation['relationprimarykey'], $relationTableFields)) {
                            $this->error('关联字段不正确(code:2004)');
                        }
                    } else {
                        $this->error('参数不正确');
                    }
                    if (isset($relation['relationfields']) && array_diff($relation['relationfields'], $relationTableFields)) {
                        $this->error('关联字段不正确(code:2005)');
                    }
                    foreach ($relation as $key => $value) {
                        if (!in_array($key, ['relation', 'relationmode', 'relationforeignkey', 'relationprimarykey', 'relationfields'])) {
                            $this->error('参数不正确');
                        }
                        $argv[] = "--{$key}=" . (is_array($value) ? implode(',', $value) : $value);
                    }
                }
            }
        } elseif ($commandtype == 'menu') {

            if (isset($params['allcontroller']) && $params['allcontroller']) {
                $argv[] = "--controller=all-controller";
            } else {
                foreach (explode(',', $params['controllerfile']) as $index => $param) {
                    if ($param) {
                        if (!preg_match("/^([a-zA-Z0-9\/]+)\.php$/", $param)) {
                            $this->error("请输入正确的控制器名称");
                        }
                        $argv[] = "--controller=" . substr($param, 0, -4);
                    }
                }
            }
        } elseif ($commandtype == 'min') {
            $module = $params['module'] ?? '';
            if ($module && !in_array($module, ['all', 'backend', 'frontend'])) {
                $this->error("请选择压缩模块");
            }
            $resource = $params['resource'] ?? '';
            if ($resource && !in_array($resource, ['all', 'js', 'css'])) {
                $this->error("请选择压缩资源");
            }
            $optimize = $params['optimize'] ?? '';
            if ($optimize && !in_array($optimize, ['uglify', 'closure'])) {
                $this->error("请选择压缩模式");
            }
        } else {
            $this->error('参数类型错误');
        }
        if ($action == 'execute') {
            if (stripos(implode(' ', $argv), '--controller=all-controller') !== false) {
                $this->error("只允许在命令行执行该命令，执行前请做好菜单规则备份！！！");
            }
            if ($this->app->isDebug()) {
                $result = $this->doexecute($commandtype, $argv);
                $this->success("", null, ['result' => $result]);
            } else {
                $this->error("只允许在开发环境下执行命令");
            }
        } else {
            $this->success("", null, ['command' => "php think {$commandtype} " . implode(' ', $argv)]);
        }
    }

    /**
     * 执行命令并保存输出和执行状态
     * @param string $commandtype 命令类型
     * @param array $argv 命令参数
     * @return string
     */
    protected function doexecute($commandtype, $argv)
    {
        if (!$this->app->isDebug()) {
            $this->error("只允许在开发环境下执行命令");
        }
        if (preg_match("/([;\|&]+)/", implode(' ', $argv))) {
            $this->error("不支持的命令参数");
        }
        if (!$this->auth->isSuperAdmin()) {
            $this->error("仅允许超级管理员执行命令");
        }
        $commandName = "\\app\\admin\\command\\" . ucfirst($commandtype);
        $input = new Input($argv);
        $output = new \addons\command\library\Output();
        $command = new $commandName($commandtype);
        $data = [
            'type'        => $commandtype,
            'params'      => json_encode($this->request->post()),
            'command'     => "php think {$commandtype} " . implode(' ', $argv),
            'executetime' => time(),
        ];
        $this->model->save($data);
        try {
            $command->run($input, $output);
            $result = implode("\n", $output->getMessage());
            $this->model->status = 'successed';
        } catch (Throwable $e) {
            $result = implode("\n", $output->getMessage()) . "\n";
            $result .= $e->getMessage();
            $this->model->status = 'failured';
        }
        $result = trim($result);
        $this->model->content = $result;
        $this->model->save();
        return $result;
    }

    /**
     * 检查字符串是否仅包含允许的字符
     * @param string $string 待检查字符串
     * @return int|false
     */
    protected function validateString($string)
    {
        // 匹配中文、英文、数字、下划线、连字符、空格和感叹号
        $pattern = '/^[a-zA-Z0-9_\-\.\s\x{4e00}-\x{9fa5}!]+$/u';
        return preg_match($pattern, $string);
    }

}
