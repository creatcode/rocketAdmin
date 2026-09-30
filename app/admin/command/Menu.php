<?php

namespace app\admin\command;

use ReflectionClass;
use think\helper\Str;
use think\Exception;
use ReflectionMethod;
use think\facade\Cache;
use think\facade\Db;
use think\console\Input;
use think\facade\Config;
use think\console\Output;
use think\console\Command;
use app\admin\model\AuthRule;
use think\console\input\Option;

/**
 * 从控制器管理后台权限菜单
 */
class Menu extends Command
{
    protected $model = null;

    /**
     * 配置菜单命令参数
     * @return void
     */
    protected function configure()
    {
        $this->setName('menu')
            ->addOption('controller', 'c', Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY, 'controller name,use \'all-controller\' when build all menu', null)
            ->addOption('delete', 'd', Option::VALUE_OPTIONAL, 'delete the specified menu', '')
            ->addOption('force', 'f', Option::VALUE_OPTIONAL, 'force delete menu,without tips', null)
            ->addOption('equal', 'e', Option::VALUE_OPTIONAL, 'the controller must be equal', null)
            ->setDescription('Build auth menu from controller');
        //要执行的controller必须一样，不适用模糊查询
    }

    /**
     * 生成或删除控制器权限菜单
     * @param Input $input 命令输入
     * @param Output $output 命令输出
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        $this->model = new AuthRule();
        $adminPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        //控制器名
        $controller = $input->getOption('controller') ?: '';
        if (!$controller) {
            throw new Exception("please input controller name");
        }
        $force = $input->getOption('force');
        //是否为删除模式
        $delete = $input->getOption('delete');
        //是否控制器完全匹配
        $equal = $input->getOption('equal');


        if ($delete) {
            if (in_array('all-controller', $controller)) {
                throw new Exception("could not delete all menu");
            }
            $ids = [];
            $list = $this->model->where(function ($query) use ($controller, $equal) {
                foreach ($controller as $index => $item) {
                    $controllerArr = explode('/', str_replace(['.', '\\'], '/', $item));
                    $item = implode('.', array_map([Str::class, 'snake'], $controllerArr));
                    if ($equal) {
                        $query->whereOr('name', 'eq', $item);
                    } else {
                        $query->whereOr('name', 'like', str_replace('_', '\_', $item) . "%");
                    }
                }
            })->select();
            foreach ($list as $k => $v) {
                $output->warning($v->name);
                $ids[] = $v->id;
            }
            if (!$ids) {
                throw new Exception("There is no menu to delete");
            }
            if (!$force) {
                $output->info("Are you sure you want to delete all those menu?  Type 'yes' to continue: ");
                $line = fgets(defined('STDIN') ? STDIN : fopen('php://stdin', 'r'));
                if (trim($line) != 'yes') {
                    throw new Exception("Operation is aborted!");
                }
            }
            AuthRule::destroy($ids);

            Cache::delete('__menu__');
            $output->info("Delete Successed");
            return;
        }

        if (!in_array('all-controller', $controller)) {
            foreach ($controller as $index => $item) {
                Db::transaction(function () use ($item) {
                    $this->importRule($item);
                });
            }
        } else {
            $authRuleList = AuthRule::select();
            //生成权限规则备份文件
            $backup = json_encode($authRuleList->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents(app()->getRuntimePath() . 'authrule.json', $backup) === false) {
                throw new Exception('Cannot save auth rule backup');
            }

            // 按名称更新并保留规则主键，避免角色授权失效或误删自定义菜单。
            $controllerDir = $adminPath . 'controller' . DIRECTORY_SEPARATOR;
            // 扫描新的节点信息并导入
            $controllers = $this->scandir($controllerDir);
            Db::transaction(function () use ($controllers) {
                $this->import($controllers);
            });
        }
        Cache::delete("__menu__");
        $output->info("Build Successed!");
    }

    /**
     * 递归扫描文件夹
     * @param string $dir
     * @return array
     */
    public function scandir($dir)
    {
        $result = [];
        $cdir = scandir($dir);
        foreach ($cdir as $value) {
            if (!in_array($value, array(".", ".."))) {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $value)) {
                    $result[$value] = $this->scandir($dir . DIRECTORY_SEPARATOR . $value);
                } else {
                    $result[] = $value;
                }
            }
        }
        return $result;
    }

    /**
     * 导入规则节点
     * @param array $dirarr
     * @param array $parentdir
     * @return array
     */
    public function import($dirarr, $parentdir = [])
    {
        $menuarr = [];
        foreach ($dirarr as $k => $v) {
            if (is_array($v)) {
                //当前是文件夹
                $nowparentdir = array_merge($parentdir, [$k]);
                $this->import($v, $nowparentdir);
            } else {
                //只匹配PHP文件
                if (!preg_match('/^(\w+)\.php$/', $v, $matchone)) {
                    continue;
                }
                //导入文件
                $controller = ($parentdir ? implode('/', $parentdir) . '/' : '') . $matchone[1];
                $this->importRule($controller);
            }
        }

        return $menuarr;
    }

    /**
     * 读取控制器并更新权限规则
     * @param string $controller 控制器路径
     * @return void
     */
    protected function importRule($controller)
    {
        $controllerArr = explode('/', str_replace(['.', '\\'], '/', $controller));
        $key = count($controllerArr) - 1;
        $controllerArr[$key] = Str::studly($controllerArr[$key]);
        $classSuffix = Config::get('route.controller_suffix') ? ucfirst(Config::get('route.controller_layer')) : '';
        $className = "\\app\\admin\\controller\\" . implode("\\", $controllerArr) . $classSuffix;

        $pathArr = $controllerArr;
        array_unshift($pathArr, '', 'app', 'admin', 'controller');
        $classFile = app()->getRootPath() . implode(DIRECTORY_SEPARATOR, $pathArr) . $classSuffix . ".php";
        // 优先读取现有目录，未找到时按 CRUD 的下划线目录规则定位。
        if (!is_file($classFile)) {
            $controllerArr = array_merge(array_map([Str::class, 'snake'], array_slice($controllerArr, 0, $key)), [$controllerArr[$key]]);
            $classFile = app()->getBasePath() . 'admin' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $controllerArr) . $classSuffix . '.php';
        }
        if (!is_file($classFile)) {
            throw new Exception('controller not found: ' . $controller);
        }
        $classContent = file_get_contents($classFile);
        $uniqueName = uniqid("FastAdmin") . $classSuffix;
        $classContent = str_replace("class " . $controllerArr[$key] . $classSuffix . " ", 'class ' . $uniqueName . ' ', $classContent);
        $classContent = preg_replace("/namespace\s(.*);/", 'namespace ' . __NAMESPACE__ . ";", $classContent);

        //临时的类文件
        $tempClassFile = __DIR__ . DIRECTORY_SEPARATOR . $uniqueName . ".php";
        if (file_put_contents($tempClassFile, $classContent) === false) {
            throw new Exception('Cannot write temporary controller file');
        }
        $className = "\\app\\admin\\command\\" . $uniqueName;

        //删除临时文件
        register_shutdown_function(function () use ($tempClassFile) {
            if ($tempClassFile) {
                //删除临时文件
                @unlink($tempClassFile);
            }
        });

        //反射机制调用类的注释和方法名
        $reflector = new ReflectionClass($className);

        //只匹配公共的方法
        $methods = $reflector->getMethods(ReflectionMethod::IS_PUBLIC);
        $classComment = $reflector->getDocComment();
        //判断是否有启用软删除
        $softDeleteMethods = ['destroy', 'restore', 'recyclebin'];
        $withSofeDelete = false;
        $modelRegexArr = ["/\\\$this\->model\s*=\s*model\(['|\"](\w+)['|\"]\);/", "/\\\$this\->model\s*=\s*new\s+([a-zA-Z0-9_\\\]+)/"];
        $modelRegex = preg_match($modelRegexArr[0], $classContent) ? $modelRegexArr[0] : $modelRegexArr[1];
        preg_match_all($modelRegex, $classContent, $matches);
        if (isset($matches[1]) && isset($matches[1][0]) && $matches[1][0]) {
            $modelClass = $this->resolveModelClass($matches[1][0], $classContent);
            if ($modelClass && in_array('trashed', get_class_methods($modelClass))) {
                $withSofeDelete = true;
            }
        }
        //忽略的类
        if (stripos($classComment, "@internal") !== false) {
            return;
        }
        preg_match_all('#(@.*?)\n#s', $classComment, $annotations);
        $controllerIcon = 'fa fa-circle-o';
        $controllerRemark = '';
        //判断注释中是否设置了icon值
        if (isset($annotations[1])) {
            foreach ($annotations[1] as $tag) {
                if (stripos($tag, '@icon') !== false) {
                    $controllerIcon = substr($tag, stripos($tag, ' ') + 1);
                }
                if (stripos($tag, '@remark') !== false) {
                    $controllerRemark = substr($tag, stripos($tag, ' ') + 1);
                }
            }
        }
        //过滤掉其它字符
        $controllerTitle = trim(preg_replace(array('/^\/\*\*(.*)[\n\r\t]/u', '/[\s]+\*\//u', '/\*\s@(.*)/u', '/[\s|\*]+/u'), '', $classComment));

        //导入中文语言包
        \think\facade\Lang::load(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang/zh-cn.php');

        //先导入菜单的数据
        $pid = 0;
        foreach ($controllerArr as $k => $v) {
            $key = $k + 1;
            //驼峰转下划线
            $controllerNameArr = array_slice($controllerArr, 0, $key);
            $controllerNameArr = array_map([Str::class, 'snake'], $controllerNameArr);
            //节点名统一使用点号形态：system.system_group
            $name = implode('.', $controllerNameArr);
            $title = (!isset($controllerArr[$key]) ? $controllerTitle : '');
            $icon = (!isset($controllerArr[$key]) ? $controllerIcon : 'fa fa-list');
            $remark = (!isset($controllerArr[$key]) ? $controllerRemark : '');
            $title = $title ?: $v;
            $rulemodel = $this->model->where(['name' => $name])->find();
            if (!$rulemodel) {
                $this->model
                    ->insert([
                        'pid'    => $pid,
                        'name'   => $name,
                        'title'  => $title,
                        'icon'   => $icon,
                        'remark' => $remark,
                        'ismenu' => 1,
                        'status' => 'normal',
                    ]);
                $pid = $this->model->getLastInsID();
            } else {
                $pid = $rulemodel->id;
            }
        }
        $ruleArr = [];
        foreach ($methods as $m => $n) {
            //过滤特殊的类
            if (substr($n->name, 0, 2) == '__' || $n->name == 'initialize') {
                continue;
            }
            //未启用软删除时过滤相关方法
            if (!$withSofeDelete && in_array($n->name, $softDeleteMethods)) {
                continue;
            }
            //只匹配符合的方法
            if (!preg_match('/^(\w+)' . Config::get('route.action_suffix') . '/', $n->name, $matchtwo)) {
                unset($methods[$m]);
                continue;
            }
            $comment = $reflector->getMethod($n->name)->getDocComment();
            //忽略的方法
            if (stripos($comment, "@internal") !== false) {
                continue;
            }
            //过滤掉其它字符
            $comment = preg_replace(array('/^\/\*\*(.*)[\n\r\t]/u', '/[\s]+\*\//u', '/\*\s@(.*)/u', '/[\s|\*]+/u'), '', $comment);

            $title = $comment ?: ucfirst($n->name);

            //获取主键，作为AuthRule更新依据
            $id = $this->getAuthRulePK($name . "/" . strtolower($n->name));

            $ruleArr[] = array('id' => $id, 'pid' => $pid, 'name' => $name . "/" . strtolower($n->name), 'icon' => 'fa fa-circle-o', 'title' => $title, 'ismenu' => 0, 'status' => 'normal');
        }
        // 已有主键的规则更新，新增规则插入，避免重复生成菜单时主键冲突
        $this->model->saveAll($ruleArr);
    }

    //获取主键
    /**
     * 按规则名称获取已有主键
     * @param string $name 规则名称
     * @return int|null
     */
    protected function getAuthRulePK($name)
    {
        if (!empty($name)) {
            $id = $this->model
                ->where('name', $name)
                ->value('id');
            return $id ?: null;
        }
    }

    /**
     * 解析完整模型类名和导入别名
     * @param string $name 模型名称
     * @param string $classContent 控制器源码
     * @return string|null
     */
    protected function resolveModelClass($name, $classContent)
    {
        $name = trim($name, '\\');
        if (class_exists('\\' . $name)) {
            return '\\' . $name;
        }

        if (preg_match_all('/^use\s+([^;]+);/mi', $classContent, $uses)) {
            foreach ($uses[1] as $use) {
                $use = trim($use);
                if (stripos($use, ' as ') !== false) {
                    [$class, $alias] = preg_split('/\s+as\s+/i', $use);
                } else {
                    $class = $use;
                    $parts = explode('\\', $class);
                    $alias = end($parts);
                }
                if (strcasecmp($alias, $name) === 0 && class_exists('\\' . ltrim($class, '\\'))) {
                    return '\\' . ltrim($class, '\\');
                }
            }
        }

        $modelClass = '\\app\\admin\\model\\' . $name;
        if (class_exists($modelClass)) {
            return $modelClass;
        }

        $modelClass = '\\app\\common\\model\\' . $name;
        if (class_exists($modelClass)) {
            return $modelClass;
        }

        return null;
    }
}
