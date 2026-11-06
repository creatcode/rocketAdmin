<?php

namespace app\common\controller;

use app\admin\library\AdminAuth;
use think\facade\Config;
use think\facade\Event;
use think\facade\Session;

/**
 * 后台控制器基类
 */
class Backend extends BackendBase
{

    /**
     * 无需登录的方法,同时也就不需要鉴权了
     * @var array
     */
    protected $noNeedLogin = [];

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = [];

    /**
     * 布局模板
     * @var string
     */
    protected $layout = 'default';

    /**
     * 引入后台控制器的traits
     */
    use \app\admin\library\traits\Backend;

    public function initialize()
    {
        $modulename = $this->app->http->getName();
        $controllername = $this->request->controllerPath;
        $actionname = strtolower($this->request->action());

        // 节点名保持点号形态，与 im_auth_rule.name 一致
        $path = $controllername . '/' . $actionname;

        // 定义是否Addtabs请求（常量保留给模板与插件使用）
        !defined('IS_ADDTABS') && define('IS_ADDTABS', (bool)$this->request->param('addtabs'));

        // 定义是否Dialog请求
        !defined('IS_DIALOG') && define('IS_DIALOG', (bool)$this->request->param('dialog'));

        // 定义是否AJAX请求
        !defined('IS_AJAX') && define('IS_AJAX', $this->request->isAjax());

        // 检测IP是否允许
        check_ip_allowed();

        $this->auth = AdminAuth::instance();

        // 设置当前请求的URI
        $this->auth->setRequestUri($path);
        // 检测是否需要验证登录
        if (!$this->auth->match($this->noNeedLogin)) {
            //检测是否登录
            if (!$this->auth->isLogin()) {
                Event::trigger('admin_nologin', $this);
                $url = Session::get('referer');
                $url = $url ?: $this->request->url();
                if (in_array($this->request->pathinfo(), ['/', 'index/index'])) {
                    $this->redirect((string)url('index/login', [], false), 302, ['referer' => $url]);
                    exit;
                }
                $this->error(__('Please login first'), (string)url('index/login', ['url' => $url]));
            }
            // 判断是否需要验证权限
            if (!$this->auth->match($this->noNeedRight)) {
                // 判断控制器和方法是否有对应权限
                if (!$this->auth->check($actionname === 'weigh' ? $controllername . '/edit' : $path)) {
                    Event::trigger('admin_nopermission', $this);
                    $this->error(__('You have no permission'), '');
                }
            }
        }

        // 非选项卡时重定向
        if (!$this->request->isPost() && !IS_AJAX && !IS_ADDTABS && !IS_DIALOG && $this->request->param('ref') == 'addtabs') {
            $url = preg_replace_callback("/([\?|&]+)ref=addtabs(&?)/i", function ($matches) {
                return $matches[2] == '&' ? $matches[1] : '';
            }, $this->request->url());
            $url = (string)url($url, [], false);
            $this->redirect((string)url('index/index', [], false), 302, ['referer' => $url]);
            exit;
        }

        // 设置面包屑导航数据
        $breadcrumb = [];
        if (!IS_DIALOG && !config('rocket.multiplenav') && config('rocket.breadcrumb')) {
            $breadcrumb = $this->auth->getBreadCrumb($path);
            if (!empty($breadcrumb)) {
                array_pop($breadcrumb);
            }
        }
        $this->view->breadcrumb = $breadcrumb;

        // 如果有使用模板布局
        if ($this->layout) {
            $this->view->engine()->layout('layout/' . $this->layout);
        }

        // 语言检测
        $lang = $this->app->lang->getLangSet();
        $lang = preg_match("/^([a-zA-Z\-_]{2,10})\$/i", $lang) ? $lang : 'zh-cn';

        $site = Config::get("site");

        $upload = \app\common\model\Config::upload();

        // 上传信息配置后
        $upload = Event::trigger("upload_config_init", $upload, true) ?: $upload;

        // 配置信息
        $config = [
            'site'           => array_intersect_key($site, array_flip(['name', 'indexurl', 'cdnurl', 'version', 'timezone', 'languages'])),
            'upload'         => $upload,
            'modulename'     => $modulename,
            'controllername' => $controllername,
            'actionname'     => $actionname,
            'jsname'         => 'backend/' . str_replace('.', '/', $controllername),
            'moduleurl'      => rtrim(dirname((string)url('index/index', [], false), 2), '/\\'),
            'language'       => $lang,
            'referer'        => Session::get("referer")
        ];
        $config = array_merge($config, Config::get("view.tpl_replace_string"));

        Config::set(array_merge(Config::get('upload'), $upload), 'upload');

        // 配置信息后
        $config = Event::trigger("config_init", $config, true) ?: $config;
        //加载当前控制器语言包
        $this->loadlang($controllername);
        //渲染站点配置
        $this->view->assign('site', $site);
        //渲染配置信息
        $this->view->assign('config', $config);
        //渲染权限对象
        $this->view->assign('auth', $this->auth);
        //渲染管理员对象
        $this->view->assign('admin', Session::get('admin'));
    }

    /**
     * 加载语言文件
     * @param string $name
     */
    protected function loadlang($name)
    {
        $name = parse_name($name);
        $name = preg_match("/^([a-zA-Z0-9_\.\/]+)\$/i", $name) ? $name : 'index';
        $lang = $this->app->lang->getLangSet();
        $lang = preg_match("/^([a-zA-Z\-_]{2,10})\$/i", $lang) ? $lang : 'zh-cn';
        $this->app->lang->load(app_path()  . '/lang/' . $lang . '/' . str_replace('.', '/', $name) . '.php');
    }

    /**
     * 渲染配置信息
     * @param mixed $name  键名或数组
     * @param mixed $value 值
     */
    protected function assignconfig($name, $value = '')
    {
        $this->view->config = array_merge($this->view->config ? $this->view->config : [], is_array($name) ? $name : [$name => $value]);
    }
}
