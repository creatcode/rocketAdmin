<?php

namespace app\admin\controller;

use app\admin\service\AddonCompat;
use app\common\controller\Backend;
use creatcode\easyaddons\addons\AddonException;
use creatcode\easyaddons\addons\Service;
use Exception;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\facade\Env;

/**
 * 插件管理
 *
 * @icon   fa fa-cube
 * @remark 可在线安装、卸载、禁用、启用、配置、升级插件，插件升级前请做好备份。
 */
class Addon extends Backend
{
    protected $model = null;
    protected $noNeedRight = ['get_table_list'];

    public function initialize()
    {
        parent::initialize();
        if (!$this->auth->isSuperAdmin() && in_array($this->request->action(), ['install', 'uninstall', 'local', 'upgrade', 'testdata'])) {
            $this->error(__('Access is allowed only to the super management group'));
        }
    }

    /**
     * 插件列表
     */
    public function index()
    {
        $addons = get_addon_list();
        foreach ($addons as $k => &$v) {
            $config = get_addon_config($v['name']);
            $v['config'] = $config ? 1 : 0;
            $v['url'] = str_replace($this->request->server('SCRIPT_NAME'), '', $v['url']);
        }
        $this->assignconfig([
            'addons'     => $addons,
            'api_url'    => Service::marketApiUrl(),
            'faversion'  => config('rocket.version'),
        ]);

        return $this->view->fetch();
    }

    /**
     * 静态源插件列表
     */
    public function online()
    {
        $offset = (int)$this->request->get('offset');
        $limit = (int)$this->request->get('limit');
        $search = (string)$this->request->get('search', '');
        $list = [];
        foreach ($this->readSource()['plugins'] as $item) {
            $name = (string)($item['name'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9]+$/', $name)) {
                continue;
            }
            if ($search !== '' && stripos($name, $search) === false
                && stripos((string)($item['title'] ?? ''), $search) === false
                && stripos((string)($item['intro'] ?? ''), $search) === false) {
                continue;
            }
            $item['download'] = $this->resolveDownload((string)($item['download'] ?? ''));
            $item['url'] = str_replace($this->request->server('SCRIPT_NAME'), '', addon_url($name));
            //存在前台入口的插件才允许点击插件名
            $item['has_front'] = is_file(ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'Index.php');
            //适配声明状态：ok 已声明完美适配 / undeclared 未声明或非真值
            $item['compatible_state'] = $this->isCompatible((string)($item['compatible'] ?? '')) ? 'ok' : 'undeclared';
            $item = array_merge([
                'category_id' => 0,
                'flag'        => '',
                'banner'      => '',
                'image'       => '',
                'demourl'     => '',
                'price'       => __('None'),
                'screenshots' => [],
            ], $item);
            //releases 提供多版本选择，前端按 releaselist 渲染版本下拉
            $item['releaselist'] = is_array($item['releases'] ?? null) ? array_values($item['releases']) : [];
            $list[] = $item;
        }
        $total = count($list);
        if ($limit) {
            $list = array_slice($list, $offset, $limit);
        }

        $callback = $this->request->get('callback') ? "jsonp" : "json";

        return $callback(['total' => $total, 'rows' => $list]);
    }

    /**
     * 配置
     */
    public function config($name = null)
    {
        $name = $name ?: $this->request->get("name");
        if (!$name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        $this->checkAddonName($name);
        $info = get_addon_info($name);
        $config = get_addon_fullconfig($name);
        if (!$info) {
            $this->error(__('Addon not exists'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a", [], 'trim');
            if ($params) {
                foreach ($config as $k => &$v) {
                    if (isset($params[$v['name']])) {
                        if ($v['type'] == 'array') {
                            $params[$v['name']] = is_array($params[$v['name']]) ? $params[$v['name']] : (array)json_decode($params[$v['name']], true);
                            $value = $params[$v['name']];
                        } else {
                            $value = is_array($params[$v['name']]) ? implode(',', $params[$v['name']]) : $params[$v['name']];
                        }
                        $v['value'] = $value;
                    }
                }
                try {
                    $addon = get_addon_instance($name);
                    //插件自定义配置实现逻辑
                    if (method_exists($addon, 'config')) {
                        $addon->config($name, $config);
                    } else {
                        //更新配置文件
                        set_addon_fullconfig($name, $config);
                        Service::refresh();
                    }
                } catch (Exception $e) {
                    $this->error(__($e->getMessage()));
                }
                $this->success();
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $tips = [];
        $groupList = [];
        $ungroupList = [];
        foreach ($config as $index => &$item) {
            //如果有设置分组
            if (isset($item['group']) && $item['group']) {
                if (!in_array($item['group'], $groupList)) {
                    $groupList["custom" . (count($groupList) + 1)] = $item['group'];
                }
            } elseif ($item['name'] != '__tips__') {
                $ungroupList[] = $item['name'];
            }
            if ($item['name'] == '__tips__') {
                $tips = $item;
                unset($config[$index]);
            }
        }
        if ($ungroupList) {
            $groupList['other'] = '其它';
        }
        $this->view->assign("groupList", $groupList);
        $this->view->assign("addon", ['info' => $info, 'config' => $config, 'tips' => $tips]);
        $configFile = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'config.html';
        $viewFile = is_file($configFile) ? $configFile : '';
        return $this->view->fetch($viewFile);
    }

    /**
     * 安装
     */
    public function install()
    {
        $name = $this->request->post("name");
        $force = (int)$this->request->post("force");
        if (!$name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        $this->checkAddonName($name);

        $info = [];
        $report = null;
        $needConfirm = false;
        try {
            $version = $this->request->post("version");
            $faversion = $this->request->post("faversion");
            $extend = [
                'version'   => $version,
                'faversion' => $faversion
            ];
            $tmpFile = $this->downloadFromSource($name, (string)$version);
            //解压前体检，有问题时先让用户确认
            $report = $this->checkPackage($tmpFile);
            $needConfirm = (bool)$report && !$this->request->post('skipcheck');
            if (!$needConfirm) {
                if (is_dir(ADDON_PATH . $name)) {
                    //已安装的插件走升级流程，需先禁用；version 会被写回插件信息
                    $addonInfo = $this->readAddonInfo($tmpFile);
                    $extend['oldversion'] = get_addon_info($name)['version'] ?? '';
                    $extend['version'] = $version !== '' ? (string)$version : (string)($addonInfo['version'] ?? '');
                    $info = Service::upgrade($name, $extend, $tmpFile);
                } else {
                    $info = Service::install($name, $force, $extend, $tmpFile);
                }
            }
        } catch (AddonException $e) {
            $this->result($e->getData(), $e->getCode(), __($e->getMessage()));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()));
        }
        //体检发现问题时先返回报告，用户确认后带 skipcheck 重试
        if ($needConfirm) {
            $msg = $report['errors'] + $report['warnings'] > 0
                ? __('Addon compatibility tips', [$report['errors'], $report['warnings']])
                : __('Undeclared compatibility tips');
            $this->result(['report' => $report], -4, $msg);
        }
        $this->success(__('Install successful'), '', ['addon' => $info]);
    }

    /**
     * 卸载
     */
    public function uninstall()
    {
        $name = $this->request->post("name");
        $force = (int)$this->request->post("force");
        $droptables = (int)$this->request->post("droptables");
        if (!$name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        $this->checkAddonName($name);
        //只有开启调试且为超级管理员才允许删除相关数据库
        $tables = [];
        if ($droptables && Env::get("app_debug") && $this->auth->isSuperAdmin()) {
            $tables = get_addon_tables($name);
        }
        try {
            Service::uninstall($name, $force);
            if ($tables) {
                $default = Config::get('database.default');
                $prefix = Config::get('database.connections.' . $default . '.prefix');
                //删除插件关联表
                foreach ($tables as $index => $table) {
                    //忽略非插件标识的表名
                    if (!$this->isAddonTable($table, $name, $prefix)) {
                        continue;
                    }
                    Db::execute("DROP TABLE IF EXISTS `{$table}`");
                }
            }
        } catch (AddonException $e) {
            $this->result($e->getData(), $e->getCode(), __($e->getMessage()));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()));
        }
        $this->success(__('Uninstall successful'));
    }

    /**
     * 禁用启用
     */
    public function state()
    {
        $name = $this->request->post("name");
        $action = $this->request->post("action");
        $force = (int)$this->request->post("force");
        if (!$name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        $this->checkAddonName($name);
        try {
            $action = $action == 'enable' ? $action : 'disable';
            //调用启用、禁用的方法
            Service::$action($name, $force);
            Cache::delete('__menu__');
        } catch (AddonException $e) {
            $this->result($e->getData(), $e->getCode(), __($e->getMessage()));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()));
        }
        $this->success(__('Operate successful'));
    }

    /**
     * 本地上传
     */
    public function local()
    {
        $info = [];
        $report = null;
        $needConfirm = false;
        $file = $this->request->file('file');
        try {
            if (Service::addonConfig('addon_auth_check', false)) {
                throw new Exception(__('当前仅支持本地安装和 JSON 源，不支持市场授权校验'));
            }
            $faversion = $this->request->post("faversion");
            $force = (int)$this->request->post("force", 0);
            $extend = [
                'faversion' => $faversion
            ];
            //解压前体检，有问题时先让用户确认
            $realPath = $file ? $file->getRealPath() : '';
            $report = $realPath ? $this->checkPackage($realPath) : null;
            if ($realPath && !$this->isCompatible((string)($this->readAddonInfoQuiet($realPath)['compatible'] ?? ''))) {
                $report = $report ?: ['files' => 0, 'errors' => 0, 'warnings' => 0, 'issues' => []];
                $report['undeclared'] = true;
            }
            $needConfirm = (bool)$report && !$this->request->post('skipcheck');
            if (!$needConfirm) {
                $info = Service::local($file, $extend, $force);
            }
        } catch (AddonException $e) {
            $this->result($e->getData(), $e->getCode(), __($e->getMessage()));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()));
        }
        //体检发现问题时先返回报告，用户确认后带 skipcheck 重试
        if ($needConfirm) {
            $msg = $report['errors'] + $report['warnings'] > 0
                ? __('Addon compatibility tips', [$report['errors'], $report['warnings']])
                : __('Undeclared compatibility tips');
            $this->result(['report' => $report], -4, $msg);
        }
        $this->success(__('Offline installed tips'), '', ['addon' => $info]);
    }

    /**
     * 更新插件
     */
    public function upgrade()
    {
        $name = $this->request->post("name");
        $addonTmpDir = runtime_path() . 'addons' . DIRECTORY_SEPARATOR;
        if (!$name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        $this->checkAddonName($name);
        if (!is_dir($addonTmpDir)) {
            @mkdir($addonTmpDir, 0755, true);
        }

        $info = [];
        $report = null;
        $needConfirm = false;
        try {
            $info = get_addon_info($name);
            $version = $this->request->post("version");
            $faversion = $this->request->post("faversion");
            $extend = [
                'version'    => $version,
                'oldversion' => $info['version'] ?? '',
                'faversion'  => $faversion
            ];
            $tmpFile = $this->downloadFromSource($name, (string)$version);
            //解压前体检，有问题时先让用户确认
            $report = $this->checkPackage($tmpFile);
            $needConfirm = (bool)$report && !$this->request->post('skipcheck');
            if (!$needConfirm) {
                $info = Service::upgrade($name, $extend, $tmpFile);
            }
            Cache::delete('__menu__');
        } catch (AddonException $e) {
            $this->result($e->getData(), $e->getCode(), __($e->getMessage()));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()));
        }
        //体检发现问题时先返回报告，用户确认后带 skipcheck 重试
        if ($needConfirm) {
            $msg = $report['errors'] + $report['warnings'] > 0
                ? __('Addon compatibility tips', [$report['errors'], $report['warnings']])
                : __('Undeclared compatibility tips');
            $this->result(['report' => $report], -4, $msg);
        }
        $this->success(__('Operate successful'), '', ['addon' => $info]);
    }

    /**
     * 测试数据
     */
    public function testdata()
    {
        $name = $this->request->post("name");
        if (!$name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        $this->checkAddonName($name);

        try {
            Service::importsql($name, 'testdata.sql');
        } catch (AddonException $e) {
            $this->result($e->getData(), $e->getCode(), __($e->getMessage()));
        } catch (Exception $e) {
            $this->error(__($e->getMessage()), $e->getCode());
        }
        $this->success(__('Import successful'), '');
    }

    /**
     * 已装插件
     */
    public function downloaded()
    {
        $offset = (int)$this->request->get("offset");
        $limit = (int)$this->request->get("limit");
        $filter = $this->request->get("filter", '');
        $search = $this->request->get("search", '', 'strip_tags,htmlspecialchars');
        $filter = (array)json_decode($filter, true);
        $addons = get_addon_list();
        $list = [];
        foreach ($addons as $k => $v) {
            if ($search && stripos($v['name'], $search) === false && stripos($v['title'], $search) === false && stripos($v['intro'], $search) === false) {
                continue;
            }

            $v['category_id'] = 0;
            $v['flag'] = '';
            $v['banner'] = '';
            $v['image'] = '';
            $v['demourl'] = '';
            $v['price'] = __('None');
            $v['screenshots'] = [];
            $v['releaselist'] = [];
            $v['url'] = addon_url($v['name']);
            $v['url'] = str_replace($this->request->server('SCRIPT_NAME'), '', $v['url']);
            $v['createtime'] = filemtime(ADDON_PATH . $v['name']);
            //适配声明状态：ok 已声明完美适配 / undeclared 未声明或非真值
            $v['compatible_state'] = $this->isCompatible((string)($v['compatible'] ?? '')) ? 'ok' : 'undeclared';
            //存在前台入口的插件才允许点击插件名
            $v['has_front'] = is_file(ADDON_PATH . $v['name'] . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'Index.php');
            if ($filter && isset($filter['category_id']) && is_numeric($filter['category_id']) && $filter['category_id'] != $v['category_id']) {
                continue;
            }
            $list[] = $v;
        }
        $total = count($list);
        if ($limit) {
            $list = array_slice($list, $offset, $limit);
        }
        $result = array("total" => $total, "rows" => $list);

        $callback = $this->request->get('callback') ? "jsonp" : "json";
        return $callback($result);
    }

    /**
     * 获取插件相关表
     */
    public function get_table_list()
    {
        $name = $this->request->post("name");
        $this->checkAddonName($name);

        try {
            $tables = get_addon_tables($name);
            $default = Config::get('database.default');
            $prefix = Config::get('database.connections.' . $default . '.prefix');
            foreach ($tables as $index => $table) {
                //忽略非插件标识的表名
                if (!$this->isAddonTable($table, $name, $prefix)) {
                    unset($tables[$index]);
                }
            }
            $tables = array_values($tables);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }

        $this->success('', null, ['tables' => $tables]);
    }

    /**
     * 读取插件压缩包内的 info.ini
     *
     * @param string $zipFile 插件压缩包路径
     * @return array
     */
    protected function readAddonInfo($zipFile)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            $this->error(__('Unable to open the zip file'));
        }
        $info = $zip->getFromName('info.ini');
        $zip->close();
        if ($info === false) {
            $this->error(__('Addon info file data incorrect'));
        }

        return parse_ini_string($info) ?: [];
    }

    /**
     * 判断插件是否声明完美适配本项目
     *
     * @param string $declared 插件声明的 compatible 值
     * @return bool
     */
    protected function isCompatible($declared)
    {
        return filter_var($declared, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 插件源是否为静态清单
     *
     * @return bool
     */
    protected function isStaticSource()
    {
        return (bool)preg_match('/\.json(\?.*)?$/i', (string)Config::get('rocket.addon_market_api_url'));
    }

    /**
     * 读取静态清单
     *
     * @return array
     */
    protected function readSource()
    {
        if (!$this->isStaticSource()) {
            $this->error(__('Addon source data invalid'));
        }
        $url = (string)Config::get('rocket.addon_market_api_url');
        $cacheKey = 'addon_source_' . md5($url);
        $data = Cache::get($cacheKey);
        if (is_array($data)) {
            return $data;
        }
        $json = @file_get_contents($url, false, stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 5],
        ]));
        if ($json === false) {
            $this->error(__('Addon source request failed'));
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['plugins']) || !is_array($data['plugins'])) {
            $this->error(__('Addon source data invalid'));
        }
        Cache::set($cacheKey, $data, 60);

        return $data;
    }

    /**
     * 解析清单内的包地址，相对路径按清单所在目录展开
     *
     * @param string $path
     * @return string
     */
    protected function resolveDownload($path)
    {
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim(dirname((string)Config::get('rocket.addon_market_api_url')), '/') . '/' . ltrim($path, '/');
    }

    /**
     * 从静态清单源下载插件包
     *
     * @param string $name    插件标识
     * @param string $version 指定版本，留空时取清单默认版本
     * @return string 本地压缩包路径
     */
    protected function downloadFromSource($name, $version = '')
    {
        $item = [];
        foreach ($this->readSource()['plugins'] as $row) {
            if ((string)($row['name'] ?? '') !== $name) {
                continue;
            }
            if ($version === '' || (string)($row['version'] ?? '') === $version) {
                $item = $row;
                break;
            }
            foreach ((array)($row['releases'] ?? []) as $release) {
                if ((string)($release['version'] ?? '') === $version) {
                    $item = $release;
                    break 2;
                }
            }
        }
        $url = $this->resolveDownload((string)($item['download'] ?? ''));
        if ($url === '') {
            $this->error($version === '' ? __('Addon not exists') : __('Addon version not exists'));
        }

        $dir = runtime_path() . 'addons' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . $name . '.zip';
        $declared = (string)($item['version'] ?? '');
        //复用版本一致的已下载包，避免重复下载
        if (is_file($file) && ($declared === '' || (string)($this->readAddonInfoQuiet($file)['version'] ?? '') === $declared)) {
            return $file;
        }
        $content = @file_get_contents($url, false, stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 300, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 5],
        ]));
        if ($content === false || $content === '' || file_put_contents($file, $content) === false) {
            @unlink($file);
            $this->error(__('Addon package download failed'));
        }
        //校验包内版本与清单声明一致
        $actual = (string)($this->readAddonInfoQuiet($file)['version'] ?? '');
        if ($declared !== '' && $actual !== $declared) {
            @unlink($file);
            $this->error(__('Addon version mismatch tips', [$declared, $actual]));
        }

        return $file;
    }

    /**
     * 解压前体检，返回报告；无问题时返回 null
     *
     * @param string $zipFile 插件压缩包路径
     * @return array|null
     */
    protected function checkPackage($zipFile)
    {
        $report = AddonCompat::checkZip($zipFile);

        return $report['errors'] > 0 || $report['warnings'] > 0 ? $report : null;
    }

    /**
     * 静默读取压缩包内 info.ini，失败时返回空数组
     *
     * @param string $zipFile 插件压缩包路径
     * @return array
     */
    protected function readAddonInfoQuiet($zipFile)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return [];
        }
        $info = $zip->getFromName('info.ini');
        $zip->close();

        return $info === false ? [] : (parse_ini_string($info) ?: []);
    }

    protected function checkAddonName($name)
    {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            $this->error(__('Addon name incorrect'));
        }
    }

    /**
     * 判断表名是否属于当前插件
     */
    protected function isAddonTable($table, $name, $prefix)
    {
        $table = (string)$table;
        $name = (string)$name;
        $prefix = (string)$prefix;

        return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $table)
            && preg_match('/^' . preg_quote($prefix . $name, '/') . '/', $table);
    }
}
