<?php

declare(strict_types=1);

use think\facade\Event;
use think\facade\App;
use think\facade\Config;
use Symfony\Component\VarExporter\VarExporter;


// 插件类库自动载入
// 插件类库自动载入
spl_autoload_register(function ($class) {
    $class = ltrim($class, '\\');

    $namespace = 'addons\\';
    if (strpos($class, $namespace) !== 0) {
        return false;
    }

    $dir = defined('ADDON_PATH') ? ADDON_PATH : App::getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
    $class = substr($class, strlen($namespace));
    $file = $dir . str_replace(['\\', '_'], DIRECTORY_SEPARATOR, $class) . '.php';

    if (is_file($file)) {
        include $file;
        return true;
    }

    return false;
});

if (!function_exists('hook')) {
    /**
     * 处理插件钩子
     * @param string $event 钩子名称
     * @param array|null $params 传入参数
     * @param bool $once 是否只返回一个结果
     * @return mixed
     */
    function hook($event, $params = null, bool $once = false)
    {
        $result = Event::trigger($event, $params, $once);

        return join('', $result);
    }
}

if (!function_exists('remove_empty_folder')) {
    /**
     * 移除空目录
     * @param string $dir 目录
     */
    function remove_empty_folder($dir)
    {
        try {
            $isDirEmpty = !(new \FilesystemIterator($dir))->valid();
            if ($isDirEmpty) {
                @rmdir($dir);
                remove_empty_folder(dirname($dir));
            }
        } catch (\UnexpectedValueException $e) {
        } catch (\Exception $e) {
        }
    }
}

if (!function_exists('get_addon_list')) {
    /**
     * 获得插件列表.
     *
     * @return array
     */
    function get_addon_list()
    {
        if (!defined('ADDON_PATH') || !is_dir(ADDON_PATH)) {
            return [];
        }
        $results = scandir(ADDON_PATH);
        $list = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file(ADDON_PATH . $name)) {
                continue;
            }
            $addonDir = ADDON_PATH . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($addonDir)) {
                continue;
            }

            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }

            //这里不采用get_addon_info是因为会有缓存
            //$info = get_addon_info($name);
            $info_file = $addonDir . 'info.ini';
            if (!is_file($info_file)) {
                continue;
            }
            $info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
            //$info = Config::parse($info_file, '', "addon-info-{$name}");
            if (!isset($info['name'])) {
                continue;
            }
            $info['url'] = addon_url($name);
            $list[$name] = $info;
        }

        return $list;
    }
}

if (!function_exists('get_addon_service')) {
    /**
     * 获得插件内的服务类.
     *
     * @return array
     */
    function get_addon_service()
    {
        $addons = get_addon_list();
        $list = [];
        foreach ($addons as $name => $addon) {
            if (!$addon['state']) {
                continue;
            }
            $addonServiceDir = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR;

            if (!is_dir($addonServiceDir)) {
                continue;
            }

            $service_files = is_dir($addonServiceDir) ? scandir($addonServiceDir) : [];
            $namespace = 'addons\\' . $name . '\\service\\';
            foreach ($service_files as $file) {
                if (strpos($file, '.php') !== false) {
                    $className = str_replace('.php', '', $file);
                    $class = $namespace . $className;
                    if (class_exists($class)) {
                        $list[] = $class;
                    }
                }
            }
        }

        return $list;
    }
}

if (!function_exists('get_addon_autoload_config')) {
    /**
     * 获得插件自动加载的配置.
     *
     * @param  bool  $truncate  是否清除手动配置的钩子
     *
     * @return array
     */
    function get_addon_autoload_config($truncate = false)
    {
        // 读取addons的配置
        $config = (array) Config::get('addons');
        if ($truncate) {
            // 清空手动配置的钩子
            $config['hooks'] = [];
        }
        $route = [];
        // 读取插件目录及钩子列表
        $base = get_class_methods('\\creatcode\\easyaddons\\Addons');
        $base = array_merge($base, ['install', 'uninstall', 'enable', 'disable']);
        // $url_domain_deploy = Config::get('url_domain_deploy');
        $url_domain_deploy = true;
        $addons = get_addon_list();
        $domain = [];
        foreach ($addons as $name => $addon) {
            if (!$addon['state']) {
                continue;
            }

            // 读取出所有公共方法
            $methods = (array) get_class_methods('\\addons\\' . $name . '\\' . ucfirst($name));
            // 跟插件基类方法做比对，得到差异结果
            $hooks = array_diff($methods, $base);
            // 循环将钩子方法写入配置中
            foreach ($hooks as $hook) {
                $hook = parse_name($hook, 0, false);
                if (!isset($config['hooks'][$hook])) {
                    $config['hooks'][$hook] = [];
                }
                // 兼容手动配置项
                if (is_string($config['hooks'][$hook])) {
                    $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                }
                if (!in_array($name, $config['hooks'][$hook])) {
                    $config['hooks'][$hook][] = $name;
                }
            }
            $conf = get_addon_config($addon['name']);
            if ($conf) {
                $conf['rewrite'] = isset($conf['rewrite']) && is_array($conf['rewrite']) ? $conf['rewrite'] : [];
                $rule = array_map(function ($value) use ($addon) {
                    return "{$addon['name']}/{$value}";
                }, array_flip($conf['rewrite']));
                if ($url_domain_deploy && isset($conf['domain']) && $conf['domain']) {
                    $domain[] = [
                        'addon'  => $addon['name'],
                        'domain' => $conf['domain'],
                        'rule'   => $rule,
                    ];
                } else {
                    $route = array_merge($route, $rule);
                }
            }
        }
        $config['service'] = get_addon_service();
        $config['route'] = $route;
        $config['route'] = array_merge($config['route'], $domain);

        return $config;
    }
}

if (!function_exists('get_addon_class')) {
    /**
     * 获取插件类的类名.
     *
     * @param  string  $name  插件名
     * @param  string  $type  返回命名空间类型
     * @param  string  $class  当前类名
     *
     * @return string
     */
    function get_addon_class($name, $type = 'hook', $class = null)
    {
        $name = parse_name($name);
        // 处理多级控制器情况
        if (!is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);

            $class[count($class) - 1] = parse_name(end($class), 1);
            $class = implode('\\', $class);
        } else {
            $class = parse_name(is_null($class) ? $name : $class, 1);
        }
        switch ($type) {
            case 'controller':
                $namespace = "\\addons\\" . $name . "\\controller\\" . $class;
                break;
            default:
                $namespace = "\\addons\\" . $name . "\\" . $class;
        }
        return class_exists($namespace) ? $namespace : '';
    }
}

if (!function_exists('get_addon_info')) {
    /**
     * 读取插件的基础信息.
     *
     * @param  string  $name  插件名
     *
     * @return array
     */
    function get_addon_info($name)
    {
        $addon = get_addon_instance($name);
        if (!$addon) {
            return [];
        }
        return $addon->getInfo($name);
    }
}

if (!function_exists('get_addon_fullconfig')) {
    /**
     * 获取插件类的配置数组.
     *
     * @param  string  $name  插件名
     *
     * @return array
     */
    function get_addon_fullconfig($name)
    {
        $addon = get_addon_instance($name);
        if (!$addon) {
            return [];
        }
        return $addon->getFullConfig($name);
    }
}

if (!function_exists('get_addon_config')) {
    /**
     * 获取插件类的配置值值
     *
     * @param  string  $name  插件名
     *
     * @return array
     */
    function get_addon_config($name)
    {
        $addon = get_addon_instance($name);
        if (!$addon) {
            return [];
        }
        return $addon->getConfig($name);
    }
}

if (!function_exists('get_addon_instance')) {
    /**
     * 获取插件的单例.
     *
     * @param  string  $name  插件名
     *
     * @return mixed|null
     */
    function get_addon_instance($name)
    {
        static $_addons = [];
        if (isset($_addons[$name])) {
            return $_addons[$name];
        }
        $class = get_addon_class($name);
        if (class_exists($class)) {
            $_addons[$name] = new $class();
            return $_addons[$name];
        }
        return null;
    }
}

if (!function_exists('get_addon_tables')) {
    /**
     * 获取插件创建的数据表
     * @param string $name 插件名称
     * @return array
     */
    function get_addon_tables($name)
    {
        $addonInfo = get_addon_info($name);
        if (!$addonInfo) {
            return [];
        }

        $sqlFile = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'install.sql';
        if (!is_file($sqlFile)) {
            return [];
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false || $sql === '') {
            return [];
        }

        $regex = "/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`[^`]+`\.)?`?([^`\s(]+)`?/mi";
        preg_match_all($regex, $sql, $matches);

        if (!$matches || empty($matches[1])) {
            return [];
        }

        $default = \think\facade\Config::get('database.default');
        $prefix = \think\facade\Config::get('database.connections.' . $default . '.prefix', '');

        $tables = array_map(function ($table) use ($prefix) {
            return str_replace('__PREFIX__', (string)$prefix, $table);
        }, $matches[1]);

        $tables = array_filter($tables, function ($table) {
            return preg_match('/^[a-zA-Z0-9_]+$/', $table);
        });

        return array_values(array_unique($tables));
    }
}

if (!function_exists('addon_url')) {
    /**
     * 插件显示内容里生成访问插件的url.
     *
     * @param  string  $url  地址 格式：插件名/控制器/方法
     * @param  array  $vars  变量参数
     * @param  bool|string  $suffix  生成的URL后缀
     * @param  bool|string  $domain  域名
     *
     * @return bool|string
     */
    function addon_url($url, $vars = [], $suffix = true, $domain = false)
    {
        $url = ltrim($url, '/');
        $slashPos = stripos($url, '/');
        $addon = $slashPos === false ? $url : substr($url, 0, $slashPos);
        if (!is_array($vars)) {
            parse_str($vars, $params);
            $vars = $params;
        }
        $params = [];
        foreach ($vars as $k => $v) {
            if (substr($k, 0, 1) === ':') {
                $params[$k] = $v;
                unset($vars[$k]);
            }
        }
        $val = "@addons/{$url}";
        $config = get_addon_config($addon);

        $rewrite = $config && isset($config['rewrite']) && $config['rewrite'] ? $config['rewrite'] : [];

        if ($rewrite) {
            $path = substr($url, stripos($url, '/') + 1);
            if (isset($rewrite[$path]) && $rewrite[$path]) {
                $val = $rewrite[$path];
                array_walk($params, function ($value, $key) use (&$val) {
                    $val = str_replace("[{$key}]", $value, $val);
                });
                $val = str_replace(['^', '$'], '', $val);
                if (substr($val, -1) === '/') {
                    $suffix = false;
                }
            } else {
                // 如果采用了域名部署,则需要去掉前两段
                /*if ($indomain && $domainprefix) {
                $arr = explode("/", $val);
                $val = implode("/", array_slice($arr, 2));
            }*/
            }
        } else {
            // 如果采用了域名部署,则需要去掉前两段
            /*if ($indomain && $domainprefix) {
            $arr = explode("/", $val);
            $val = implode("/", array_slice($arr, 2));
        }*/
            foreach ($params as $k => $v) {
                $vars[substr($k, 1)] = $v;
            }
        }
        $url = (string)url($val, [], $suffix, $domain) . ($vars ? '?' . http_build_query($vars) : '');
        $url = preg_replace("/\/((?!index)[\w]+)\.php\//i", '/', $url);

        return $url;
    }
}

if (!function_exists('set_addon_info')) {
    /**
     * 设置基础配置信息
     * @param string $name  插件名
     * @param array  $array 配置数据
     * @return boolean
     * @throws Exception
     */
    function set_addon_info($name, $array)
    {
        $file = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'info.ini';
        $addon = get_addon_instance($name);
        $array = $addon->setInfo($name, $array);
        if (!isset($array['name']) || !isset($array['title']) || !isset($array['version'])) {
            throw new Exception('插件配置写入失败');
        }
        $res = [];
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $res[] = "[$key]";
                foreach ($val as $skey => $sval) {
                    $res[] = "$skey = " . (is_numeric($sval) ? $sval : $sval);
                }
            } else {
                $res[] = "$key = " . (is_numeric($val) ? $val : $val);
            }
        }
        if ($handle = fopen($file, 'w')) {
            fwrite($handle, implode("\n", $res) . "\n");
            fclose($handle);
            //清空当前配置缓存
            Config::set([$name => null], 'addoninfo');
        } else {
            throw new Exception('文件没有写入权限');
        }

        return true;
    }
}

if (!function_exists('set_addon_config')) {
    /**
     * 写入配置文件
     * @param string  $name      插件名
     * @param array   $config    配置数据
     * @param boolean $writefile 是否写入配置文件
     * @return bool
     * @throws Exception
     */
    function set_addon_config($name, $config, $writefile = true)
    {
        $addon = get_addon_instance($name);
        $addon->setConfig($name, $config);
        $fullconfig = get_addon_fullconfig($name);
        foreach ($fullconfig as $k => &$v) {
            if (isset($config[$v['name']])) {
                $value = $v['type'] !== 'array' && is_array($config[$v['name']]) ? implode(',', $config[$v['name']]) : $config[$v['name']];
                $v['value'] = $value;
            }
        }
        if ($writefile) {
            // 写入配置文件
            set_addon_fullconfig($name, $fullconfig);
        }
        return true;
    }
}

if (!function_exists('set_addon_fullconfig')) {
    /**
     * 写入配置文件
     *
     * @param string $name  插件名
     * @param array  $array 配置数据
     * @return boolean
     * @throws Exception
     */
    function set_addon_fullconfig($name, $array)
    {
        $file = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'config.php';
        $ret = file_put_contents($file, "<?php\n\n" . "return " . VarExporter::export($array) . ";\n", LOCK_EX);
        if (!$ret) {
            throw new Exception("配置写入失败");
        }
        return true;
    }
}
