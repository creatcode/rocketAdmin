<?php

namespace app\common\library;

use app\common\library\token\Driver;
use app\common\library\token\Manager;
use think\facade\App;
use think\facade\Config;
use think\facade\Log;

/**
 * Token操作类
 */
class Token
{
    /**
     * @var array Token的实例
     */
    public static $instance = [];

    /**
     * @var object 操作句柄
     */
    public static $handler;

    /**
     * 连接Token驱动
     * @access public
     * @param array       $options 配置数组
     * @param bool|string $name    Token连接标识 true 强制重新连接
     * @return Driver
     */
    public static function connect(array $options = [], $name = false): Driver
    {
        $manager = self::manager();
        $type = $options['type'] ?? 'File';
        // 记录初始化信息
        $key = $name === false ? md5(serialize($options)) : $name;
        if (($name === true || !isset(self::$instance[$key])) && App::isDebug()) {
            Log::record('[ TOKEN ] INIT ' . $type, 'info');
        }
        $driver = $manager->connect($options, $name);
        if ($name !== true) {
            self::$instance[$key] = $driver;
        }
        return $driver;
    }

    /**
     * 自动初始化Token
     * @access public
     * @param array $options 配置数组
     * @return Driver
     */
    public static function init(array $options = [])
    {
        self::manager();
        if (self::$handler === null) {
            if (empty($options) && Config::get('token.type') === 'complex') {
                $default = Config::get('token.default');
                // 获取默认Token配置，并连接
                $options = Config::get('token.' . $default['type']) ?: $default;
            } elseif (empty($options)) {
                $options = Config::get('token');
            }

            self::$handler = self::connect($options);
        }

        return self::$handler;
    }

    /**
     * 判断Token是否可用(check别名)
     * @access public
     * @param string $token   Token标识
     * @param int    $user_id 会员ID
     * @return bool
     */
    public static function has($token, $user_id)
    {
        return self::check($token, $user_id);
    }

    /**
     * 判断Token是否可用
     * @param string $token   Token标识
     * @param int    $user_id 会员ID
     * @return bool
     */
    public static function check($token, $user_id)
    {
        return self::init()->check($token, $user_id);
    }

    /**
     * 读取Token
     * @access public
     * @param string $token   Token标识
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get($token, $default = false)
    {
        return self::init()->get($token) ?: $default;
    }

    /**
     * 写入Token
     * @access public
     * @param string   $token   Token标识
     * @param mixed    $user_id 会员ID
     * @param int|null $expire  有效时间 0为永久
     * @return boolean
     */
    public static function set($token, $user_id, $expire = null)
    {
        return self::init()->set($token, $user_id, $expire);
    }

    /**
     * 删除Token(delete别名)
     * @access public
     * @param string $token Token标识
     * @return boolean
     */
    public static function rm($token)
    {
        return self::delete($token);
    }

    /**
     * 删除Token
     * @param string $token 标签名
     * @return bool
     */
    public static function delete($token)
    {
        return self::init()->delete($token);
    }

    /**
     * 清除Token
     * @access public
     * @param int $user_id 会员ID
     * @return boolean
     */
    public static function clear($user_id = null)
    {
        return self::init()->clear($user_id);
    }


    /**
     * 每个请求单独持有驱动管理器，旧静态属性仅保留兼容读取。
     *
     * @return Manager 当前请求的 Token 驱动管理器
     */
    protected static function manager(): Manager
    {
        $request = request();
        if (!isset($request->tokenManager)) {
            $request->tokenManager = new Manager(app());
            self::$instance = [];
            self::$handler = null;
        }
        return $request->tokenManager;
    }
}
