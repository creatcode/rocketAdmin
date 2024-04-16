<?php

// 公共助手函数

use app\common\model\Config as ModelConfig;
use think\exception\HttpResponseException;
use think\facade\Config;
use think\facade\Db;
use think\Response;

if (!function_exists('__')) {
    /**
     * 获得插件列表.
     *
     * @return array
     */
    function get_addon_list()
    {
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
                if (strpos($file, '.php')) {
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
        $base = get_class_methods('\\think\\Addons');
        $base = array_merge($base, ['install', 'uninstall', 'enable', 'disable']);
        $url_domain_deploy = false;
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
                if (isset($conf['domain']) && $conf['domain']) {
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
        } else {
            return;
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

    if (!function_exists('get_addon_tables')) {
        /**
         * 获取插件创建的表
         * @param string $name 插件名
         * @return array
         */
        function get_addon_tables($name)
        {
            $addonInfo = get_addon_info($name);
            if (!$addonInfo) {
                return [];
            }
            $regex = "/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z_]+)`?/mi";
            $sqlFile = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'install.sql';
            $tables = [];
            if (is_file($sqlFile)) {
                preg_match_all($regex, file_get_contents($sqlFile), $matches);
                if ($matches && isset($matches[2]) && $matches[2]) {
                    $prefix = env('database.prefix');
                    $tables = array_map(function ($item) use ($prefix) {
                        return str_replace("__PREFIX__", $prefix, $item);
                    }, $matches[2]);
                }
            }
            return $tables;
        }
    }


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
        $addon = substr($url, 0, stripos($url, '/'));
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

    /**
     * 设置基础配置信息.
     *
     * @param  string  $name  插件名
     * @param  array  $array  配置数据
     *
     * @throws Exception
     * @return bool
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

    /**
     * 写入配置文件.
     *
     * @param  string  $name  插件名
     * @param  array  $config  配置数据
     * @param  bool  $writefile  是否写入配置文件
     *
     * @throws Exception
     * @return bool
     */
    function set_addon_config($name, $config, $writefile = true)
    {
        $addon = get_addon_instance($name);
        $addon->setConfig($name, $config);
        $fullconfig = get_addon_fullconfig($name);
        foreach ($fullconfig as $k => &$v) {
            if (isset($config[$v['name']])) {
                $value = $v['type'] !== 'array' && is_array($config[$v['name']]) ? implode(
                    ',',
                    $config[$v['name']]
                ) : $config[$v['name']];
                $v['value'] = $value;
            }
        }
        if ($writefile) {
            // 写入配置文件
            set_addon_fullconfig($name, $fullconfig);
        }

        return true;
    }

    /**
     * 写入配置文件.
     *
     * @param  string  $name  插件名
     * @param  array  $array  配置数据
     *
     * @throws Exception
     * @return bool
     */
    function set_addon_fullconfig($name, $array)
    {
        $file = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_really_writable($file)) {
            throw new Exception('文件没有写入权限');
        }
        if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . 'return ' . var_export($array, true) . ";\n");
            fclose($handle);
        } else {
            throw new Exception('文件没有写入权限');
        }

        return true;
    }
    /**
     * 获取语言变量值
     * @param string $name 语言变量名
     * @param array  $vars 动态变量值
     * @param string $lang 语言
     * @return mixed
     */
    function __($name, $vars = [], $lang = '')
    {
        if (is_numeric($name) || !$name) {
            return $name;
        }
        if (!is_array($vars)) {
            $vars = func_get_args();
            array_shift($vars);
            $lang = '';
        }
        return app()->lang->get($name, $vars, $lang);
    }
}

if (!function_exists('format_bytes')) {

    /**
     * 将字节转换为可读文本
     * @param int    $size      大小
     * @param string $delimiter 分隔符
     * @param int    $precision 小数位数
     * @return string
     */
    function format_bytes($size, $delimiter = '', $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
        for ($i = 0; $size >= 1024 && $i < 5; $i++) {
            $size /= 1024;
        }
        return round($size, $precision) . $delimiter . $units[$i];
    }
}

if (!function_exists('datetime')) {

    /**
     * 将时间戳转换为日期时间
     * @param int    $time   时间戳
     * @param string $format 日期时间格式
     * @return string
     */
    function datetime($time, $format = 'Y-m-d H:i:s')
    {
        $time = is_numeric($time) ? $time : strtotime($time);
        return date($format, $time);
    }
}

if (!function_exists('human_date')) {

    /**
     * 获取语义化时间
     * @param int $time  时间
     * @param int $local 本地时间
     * @return string
     */
    function human_date($time, $local = null)
    {
        return \util\Date::human($time, $local);
    }
}

if (!function_exists('cdnurl')) {

    /**
     * 获取上传资源的CDN的地址
     * @param string  $url    资源相对地址
     * @param boolean $domain 是否显示域名 或者直接传入域名
     * @return string
     */
    function cdnurl($url, $domain = false)
    {
        $regex = "/^((?:[a-z]+:)?\/\/|data:image\/)(.*)/i";
        $cdnurl = Config::get('upload.cdnurl');
        if (is_bool($domain) || stripos($cdnurl, '/') === 0) {
            $url = preg_match($regex, $url) || ($cdnurl && stripos($url, $cdnurl) === 0) ? $url : $cdnurl . $url;
        }
        if ($domain && !preg_match($regex, $url)) {
            $domain = is_bool($domain) ? request()->domain() : $domain;
            $url = $domain . $url;
        }
        return $url;
    }
}


if (!function_exists('is_really_writable')) {

    /**
     * 判断文件或文件夹是否可写
     * @param string $file 文件或目录
     * @return    bool
     */
    function is_really_writable($file)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return is_writable($file);
        }
        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/' . md5(mt_rand());
            if (($fp = @fopen($file, 'ab')) === false) {
                return false;
            }
            fclose($fp);
            @chmod($file, 0777);
            @unlink($file);
            return true;
        } elseif (!is_file($file) or ($fp = @fopen($file, 'ab')) === false) {
            return false;
        }
        fclose($fp);
        return true;
    }
}

if (!function_exists('rmdirs')) {

    /**
     * 删除文件夹
     * @param string $dirname  目录
     * @param bool   $withself 是否删除自身
     * @return boolean
     */
    function rmdirs($dirname, $withself = true)
    {
        if (!is_dir($dirname)) {
            return false;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        if ($withself) {
            @rmdir($dirname);
        }
        return true;
    }
}

if (!function_exists('copydirs')) {

    /**
     * 复制文件夹
     * @param string $source 源文件夹
     * @param string $dest   目标文件夹
     */
    function copydirs($source, $dest)
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        foreach ($iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            if ($item->isDir()) {
                $sontDir = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                if (!is_dir($sontDir)) {
                    mkdir($sontDir, 0755, true);
                }
            } else {
                copy($item, $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            }
        }
    }
}

if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst($string)
    {
        return mb_strtoupper(mb_substr($string, 0, 1)) . mb_strtolower(mb_substr($string, 1));
    }
}

if (!function_exists('addtion')) {

    /**
     * 附加关联字段数据
     * @param array $items  数据列表
     * @param mixed $fields 渲染的来源字段
     * @return array
     */
    function addtion($items, $fields)
    {
        if (!$items || !$fields) {
            return $items;
        }
        $fieldsArr = [];
        if (!is_array($fields)) {
            $arr = explode(',', $fields);
            foreach ($arr as $k => $v) {
                $fieldsArr[$v] = ['field' => $v];
            }
        } else {
            foreach ($fields as $k => $v) {
                if (is_array($v)) {
                    $v['field'] = $v['field'] ?? $k;
                } else {
                    $v = ['field' => $v];
                }
                $fieldsArr[$v['field']] = $v;
            }
        }
        foreach ($fieldsArr as $k => &$v) {
            $v = is_array($v) ? $v : ['field' => $v];
            $v['display'] = $v['display'] ?? str_replace(['_ids', '_id'], ['_names', '_name'], $v['field']);
            $v['primary'] = $v['primary'] ?? '';
            $v['column'] = $v['column'] ?? 'name';
            $v['model'] = $v['model'] ?? '';
            $v['table'] = $v['table'] ?? '';
            $v['name'] = $v['name'] ?? str_replace(['_ids', '_id'], '', $v['field']);
        }
        unset($v);
        $ids = [];
        $fields = array_keys($fieldsArr);
        foreach ($items as $k => $v) {
            foreach ($fields as $m => $n) {
                if (isset($v[$n])) {
                    $ids[$n] = array_merge(isset($ids[$n]) && is_array($ids[$n]) ? $ids[$n] : [], explode(',', $v[$n]));
                }
            }
        }
        $result = [];
        foreach ($fieldsArr as $k => $v) {
            if ($v['model']) {
                $model = new $v['model'];
            } else {
                $model = $v['name'] ? Db::name($v['name']) : Db::table($v['table']);
            }
            $primary = $v['primary'] ? $v['primary'] : $model->getPk();
            $result[$v['field']] = isset($ids[$v['field']]) ? $model->where($primary, 'in', $ids[$v['field']])->column($v['column'], $primary) : [];
        }

        foreach ($items as $k => &$v) {
            foreach ($fields as $m => $n) {
                if (isset($v[$n])) {
                    $curr = array_flip(explode(',', $v[$n]));

                    $linedata = array_intersect_key($result[$n], $curr);
                    $v[$fieldsArr[$n]['display']] = $fieldsArr[$n]['column'] == '*' ? $linedata : implode(',', $linedata);
                }
            }
        }
        return $items;
    }
}

if (!function_exists('var_export_short')) {

    /**
     * 使用短标签打印或返回数组结构
     * @param mixed   $data
     * @param boolean $return 是否返回数据
     * @return string
     */
    function var_export_short($data, $return = true)
    {
        // return var_export($data, $return);
        $replaced = [];
        $count = 0;

        //判断是否是对象
        if (is_resource($data) || is_object($data)) {
            return var_export($data, $return);
        }

        //判断是否有特殊的键名
        $specialKey = false;
        array_walk_recursive($data, function (&$value, &$key) use (&$specialKey) {
            if (is_string($key) && (stripos($key, "\n") !== false || stripos($key, "array (") !== false)) {
                $specialKey = true;
            }
        });
        if ($specialKey) {
            return var_export($data, $return);
        }
        array_walk_recursive($data, function (&$value, &$key) use (&$replaced, &$count, &$stringcheck) {
            if (is_object($value) || is_resource($value)) {
                $replaced[$count] = var_export($value, true);
                $value = "##<{$count}>##";
            } else {
                if (is_string($value) && (stripos($value, "\n") !== false || stripos($value, "array (") !== false)) {
                    $index = array_search($value, $replaced);
                    if ($index === false) {
                        $replaced[$count] = var_export($value, true);
                        $value = "##<{$count}>##";
                    } else {
                        $value = "##<{$index}>##";
                    }
                }
            }
            $count++;
        });

        $dump = var_export($data, true);

        $dump = preg_replace('#(?:\A|\n)([ ]*)array \(#i', '[', $dump); // Starts
        $dump = preg_replace('#\n([ ]*)\),#', "\n$1],", $dump); // Ends
        $dump = preg_replace('#=> \[\n\s+\],\n#', "=> [],\n", $dump); // Empties
        $dump = preg_replace('#\)$#', "]", $dump); //End

        if ($replaced) {
            $dump = preg_replace_callback("/'##<(\d+)>##'/", function ($matches) use ($replaced) {
                return $replaced[$matches[1]] ?? "''";
            }, $dump);
        }

        if ($return === true) {
            return $dump;
        } else {
            echo $dump;
        }
    }
}

if (!function_exists('letter_avatar')) {
    /**
     * 首字母头像
     * @param $text
     * @return string
     */
    function letter_avatar($text)
    {
        $total = unpack('L', hash('adler32', $text, true))[1];
        $hue = $total % 360;
        list($r, $g, $b) = hsv2rgb($hue / 360, 0.3, 0.9);

        $bg = "rgb({$r},{$g},{$b})";
        $color = "#ffffff";
        $first = mb_strtoupper(mb_substr($text, 0, 1));
        $src = base64_encode('<svg xmlns="http://www.w3.org/2000/svg" version="1.1" height="100" width="100"><rect fill="' . $bg . '" x="0" y="0" width="100" height="100"></rect><text x="50" y="50" font-size="50" text-copy="fast" fill="' . $color . '" text-anchor="middle" text-rights="admin" dominant-baseline="central">' . $first . '</text></svg>');
        $value = 'data:image/svg+xml;base64,' . $src;
        return $value;
    }
}

if (!function_exists('hsv2rgb')) {
    function hsv2rgb($h, $s, $v)
    {
        $r = $g = $b = 0;

        $i = floor($h * 6);
        $f = $h * 6 - $i;
        $p = $v * (1 - $s);
        $q = $v * (1 - $f * $s);
        $t = $v * (1 - (1 - $f) * $s);

        switch ($i % 6) {
            case 0:
                $r = $v;
                $g = $t;
                $b = $p;
                break;
            case 1:
                $r = $q;
                $g = $v;
                $b = $p;
                break;
            case 2:
                $r = $p;
                $g = $v;
                $b = $t;
                break;
            case 3:
                $r = $p;
                $g = $q;
                $b = $v;
                break;
            case 4:
                $r = $t;
                $g = $p;
                $b = $v;
                break;
            case 5:
                $r = $v;
                $g = $p;
                $b = $q;
                break;
        }

        return [
            floor($r * 255),
            floor($g * 255),
            floor($b * 255)
        ];
    }
}

if (!function_exists('check_nav_active')) {
    /**
     * 检测会员中心导航是否高亮
     */
    function check_nav_active($url, $classname = 'active')
    {
        $auth = \app\common\library\Auth::instance();
        $requestUrl = $auth->getRequestUri();
        $url = ltrim($url, '/');
        return $requestUrl === str_replace(".", "/", $url) ? $classname : '';
    }
}

if (!function_exists('check_cors_request')) {
    /**
     * 跨域检测
     */
    function check_cors_request()
    {
        if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] && config('fastadmin.cors_request_domain')) {
            $info = parse_url($_SERVER['HTTP_ORIGIN']);
            $domainArr = explode(',', config('fastadmin.cors_request_domain'));
            $domainArr[] = request()->host(true);
            if (in_array("*", $domainArr) || in_array($_SERVER['HTTP_ORIGIN'], $domainArr) || (isset($info['host']) && in_array($info['host'], $domainArr))) {
                header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
            } else {
                $response = Response::create('跨域检测无效', 'html', 403);
                throw new HttpResponseException($response);
            }

            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');

            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
                }
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                    header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
                }
                $response = Response::create('', 'html');
                throw new HttpResponseException($response);
            }
        }
    }
}

if (!function_exists('xss_clean')) {
    /**
     * 清理XSS
     */
    function xss_clean($content, $is_image = false)
    {
        return \app\common\library\Security::instance()->xss_clean($content, $is_image);
    }
}

if (!function_exists('url_clean')) {
    /**
     * 清理URL
     */
    function url_clean($url)
    {
        if (!check_url_allowed($url)) {
            return '';
        }
        return xss_clean($url);
    }
}

if (!function_exists('check_ip_allowed')) {
    /**
     * 检测IP是否允许
     * @param string $ip IP地址
     */
    function check_ip_allowed($ip = null)
    {
        $ip = is_null($ip) ? request()->ip() : $ip;
        $forbiddenipArr = config('site.forbiddenip');
        $forbiddenipArr = !$forbiddenipArr ? [] : $forbiddenipArr;
        $forbiddenipArr = is_array($forbiddenipArr) ? $forbiddenipArr : array_filter(explode("\n", str_replace("\r\n", "\n", $forbiddenipArr)));
        if ($forbiddenipArr && \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $forbiddenipArr)) {
            $response = Response::create('请求无权访问', 'html', 403);
            throw new HttpResponseException($response);
        }
    }
}

if (!function_exists('check_url_allowed')) {
    /**
     * 检测URL是否允许
     * @param string $url URL
     * @return bool
     */
    function check_url_allowed($url = '')
    {
        //允许的主机列表
        $allowedHostArr = [
            strtolower(request()->host())
        ];

        if (empty($url)) {
            return true;
        }

        //如果是站内相对链接则允许
        if (preg_match("/^[\/a-z][a-z0-9][a-z0-9\.\/]+((\?|#).*)?\$/i", $url) && substr($url, 0, 2) !== '//') {
            return true;
        }

        //如果是站外链接则需要判断HOST是否允许
        if (preg_match("/((http[s]?:\/\/)+((?>[a-z\-0-9]{2,}\.)+[a-z]{2,8}|((?>([0-9]{1,3}\.)){3}[0-9]{1,3}))(:[0-9]{1,5})?)(?:\s|\/)/i", $url)) {
            $chkHost = parse_url(strtolower($url), PHP_URL_HOST);
            if ($chkHost && in_array($chkHost, $allowedHostArr)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('build_suffix_image')) {
    /**
     * 生成文件后缀图片
     * @param string $suffix 后缀
     * @param null   $background
     * @return string
     */
    function build_suffix_image($suffix, $background = null)
    {
        $suffix = mb_substr(strtoupper($suffix), 0, 4);
        $total = unpack('L', hash('adler32', $suffix, true))[1];
        $hue = $total % 360;
        list($r, $g, $b) = hsv2rgb($hue / 360, 0.3, 0.9);

        $background = $background ? $background : "rgb({$r},{$g},{$b})";

        $icon = <<<EOT
        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 512 512" style="enable-background:new 0 0 512 512;" xml:space="preserve">
            <path style="fill:#E2E5E7;" d="M128,0c-17.6,0-32,14.4-32,32v448c0,17.6,14.4,32,32,32h320c17.6,0,32-14.4,32-32V128L352,0H128z"/>
            <path style="fill:#B0B7BD;" d="M384,128h96L352,0v96C352,113.6,366.4,128,384,128z"/>
            <polygon style="fill:#CAD1D8;" points="480,224 384,128 480,128 "/>
            <path style="fill:{$background};" d="M416,416c0,8.8-7.2,16-16,16H48c-8.8,0-16-7.2-16-16V256c0-8.8,7.2-16,16-16h352c8.8,0,16,7.2,16,16 V416z"/>
            <path style="fill:#CAD1D8;" d="M400,432H96v16h304c8.8,0,16-7.2,16-16v-16C416,424.8,408.8,432,400,432z"/>
            <g><text><tspan x="220" y="380" font-size="124" font-family="Verdana, Helvetica, Arial, sans-serif" fill="white" text-anchor="middle">{$suffix}</tspan></text></g>
        </svg>
EOT;
        return $icon;
    }

    if (!function_exists('sys_config')) {
        /**
         * 获取系统单个配置
         * @param string $name
         * @param string $default
         * @return string
         */
        function sys_config(string $name, $default = '')
        {
            if (empty($name))
                return $default;

            $config = trim(ModelConfig::where('name', $name)->find());
            if (!$config || $config === false) {
                return $default;
            } else {
                return $config['value'];
            }
        }
    }

    if (!function_exists('model')) {
        /**
         * 实例化Model.
         *
         * @param  string  $name
         * @param  string  $layer
         * @param  bool  $appendSuffix
         *
         * @throws \think\Exception
         * @return Model
         */
        function model($name = '', $layer = 'model', $appendSuffix = false)
        {
            if (class_exists($name)) {
                return new $name();
            }
            $class = app()->getNamespace() . '\\' . $layer . '\\' . $name;
            if (class_exists($class)) {
                return new $class();
            }
            $class = 'app\\common\\' . $layer . '\\' . $name;
            if (class_exists($class)) {
                return new $class();
            } else {
                throw new \think\Exception('model not found');
            }
        }
    }
}
