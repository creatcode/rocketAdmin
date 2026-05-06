<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2011 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: luofei614 <weibo.com/luofei614>
// +----------------------------------------------------------------------
// | 修改者: anuo（基于原 3.2.3 权限类调整）
// +----------------------------------------------------------------------

namespace kernel\services;

use think\facade\Config;
use think\facade\Db;
use think\facade\Session;

/**
 * 权限认证类
 */
class AuthService
{
    /**
     * @var object 对象实例
     */
    protected static $instance;

    protected $rules = [];

    // 默认配置
    protected $config = [
        'auth_on'           => 1, // 权限开关
        'auth_type'         => 1, // 认证方式，1 为实时认证；2 为登录认证
        'auth_group'        => 'auth_group', // 用户组数据表名
        'auth_group_access' => 'auth_group_access', // 用户-用户组关系表
        'auth_rule'         => 'auth_rule', // 权限规则表
        'auth_user'         => 'user', // 用户信息表
    ];

    public function __construct()
    {
        if ($auth = Config::get('auth')) {
            $this->config = array_merge($this->config, $auth);
        }
    }

    /**
     * 初始化
     * @access public
     * @param array $options 参数
     * @return static
     */
    public static function instance($options = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new static();
        }

        if (!empty($options) && is_array($options)) {
            self::$instance->config = array_merge(self::$instance->config, $options);
        }

        return self::$instance;
    }

    /**
     * 检查权限
     * @param string|array $name 需要验证的规则列表
     * @param int $uid 认证用户的 id
     * @param string $relation 多规则关系，支持 or / and
     * @param string $mode 验证模式，支持 url / normal
     * @return bool
     */
    public function check($name, $uid, $relation = 'or', $mode = 'url')
    {
        if (!$this->config['auth_on']) {
            return true;
        }

        // 获取用户需要验证的所有有效规则列表
        $rulelist = $this->getRuleList($uid);
        if (in_array('*', $rulelist)) {
            return true;
        }

        if (is_string($name)) {
            $name = strtolower($name);
            if (strpos($name, ',') !== false) {
                $name = explode(',', $name);
            } else {
                $name = [$name];
            }
        } else {
            $name = array_map('strtolower', $name);
        }

        $list = []; // 保存验证通过的规则名
        $requestParams = [];

        if ('url' == $mode) {
            // 仅处理参数名大小写，避免把 token、签名等参数值强制转成小写
            foreach ((array) request()->param() as $key => $value) {
                $requestParams[strtolower((string) $key)] = is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        foreach ($rulelist as $rule) {
            $query = preg_replace('/^.+\?/U', '', $rule);
            if ('url' == $mode && $query != $rule) {
                parse_str($query, $param); // 解析规则中的 param
                $param = array_change_key_case($param, CASE_LOWER);
                $intersect = array_intersect_assoc($requestParams, $param);
                $rule = preg_replace('/\?.*$/U', '', $rule);
                if (in_array($rule, $name) && $intersect == $param) {
                    // 如果节点相符并且 URL 参数满足条件
                    $list[] = $rule;
                }
            } else {
                if (in_array($rule, $name)) {
                    $list[] = $rule;
                }
            }
        }

        if ('or' == $relation && !empty($list)) {
            return true;
        }

        $diff = array_diff($name, $list);
        if ('and' == $relation && empty($diff)) {
            return true;
        }

        return false;
    }

    /**
     * 根据用户 id 获取用户组，返回值为数组
     * @param int $uid 用户 id
     * @return array
     */
    public function getGroups($uid)
    {
        static $groups = [];
        if (isset($groups[$uid])) {
            return $groups[$uid];
        }

        $user_groups = Db::name($this->config['auth_group_access'])
            ->alias('aga')
            ->join($this->config['auth_group'] . ' ag', 'aga.group_id = ag.id', 'LEFT')
            ->field('aga.uid,aga.group_id,ag.id,ag.pid,ag.name,ag.rules')
            ->where(['aga.uid' => $uid, 'ag.status' => 'normal'])
            ->select();

        $groups[$uid] = $user_groups ?: [];

        return $groups[$uid];
    }

    /**
     * 获得权限规则列表
     * @param int $uid 用户 id
     * @return array
     */
    public function getRuleList($uid)
    {
        static $_rulelist = []; // 保存用户验证通过的权限列表
        if (isset($_rulelist[$uid])) {
            return $_rulelist[$uid];
        }
        if (2 == $this->config['auth_type'] && Session::has('_rule_list_' . $uid)) {
            return Session::get('_rule_list_' . $uid);
        }

        // 读取用户规则节点
        $ids = $this->getRuleIds($uid);
        if (empty($ids)) {
            $_rulelist[$uid] = [];

            return [];
        }

        // 筛选条件
        $where = [
            'status' => 'normal',
        ];
        if (!in_array('*', $ids)) {
            $where['id'] = ['in', $ids];
        }

        // 读取用户组拥有的所有权限规则
        $this->rules = Db::name($this->config['auth_rule'])->where($where)->field('id,pid,condition,icon,name,title,ismenu')->select();

        // 循环规则，判断结果
        $rulelist = [];
        if (in_array('*', $ids)) {
            $rulelist[] = '*';
        }
        foreach ($this->rules as $rule) {
            // 超级管理员无需验证 condition
            if (!empty($rule['condition']) && !in_array('*', $ids)) {
                // 根据 condition 进行验证
                $user = $this->getUserInfo($uid); // 获取用户信息，一维数组
                $nums = 0;
                $condition = str_replace(['&&', '||'], "\r\n", $rule['condition']);
                $condition = preg_replace('/\{(\w*?)\}/', '\\1', $condition);
                $conditionArr = explode("\r\n", $condition);
                foreach ($conditionArr as $item) {
                    preg_match("/^(\w+)\s?([\>\<\=]+)\s?(.*)$/", trim($item), $matches);
                    if ($matches && isset($user[$matches[1]]) && version_compare($user[$matches[1]], $matches[3], $matches[2])) {
                        $nums++;
                    }
                }
                if ($conditionArr && ((stripos($rule['condition'], '||') !== false && $nums > 0) || count($conditionArr) == $nums)) {
                    $rulelist[$rule['id']] = strtolower($rule['name']);
                }
            } else {
                // 只要存在就记录
                $rulelist[$rule['id']] = strtolower($rule['name']);
            }
        }
        $_rulelist[$uid] = $rulelist;

        // 登录认证需要保存规则列表
        if (2 == $this->config['auth_type']) {
            Session::set('_rule_list_' . $uid, $rulelist);
        }

        return array_unique($rulelist);
    }

    /**
     * 获取用户所属用户组配置的所有权限规则 id
     * @param int $uid 用户 id
     * @return array
     */
    public function getRuleIds($uid)
    {
        $groups = $this->getGroups($uid);
        $ids = [];
        foreach ($groups as $g) {
            $ids = array_merge($ids, explode(',', trim($g['rules'], ',')));
        }

        return array_unique($ids);
    }

    /**
     * 获得用户资料
     * @param int $uid 用户 id
     * @return mixed
     */
    protected function getUserInfo($uid)
    {
        static $user_info = [];

        $user = Db::name($this->config['auth_user']);
        // 获取用户表主键
        $_pk = is_string($user->getPk()) ? $user->getPk() : 'uid';
        if (!isset($user_info[$uid])) {
            $user_info[$uid] = $user->where($_pk, $uid)->find();
        }

        return $user_info[$uid];
    }
}
