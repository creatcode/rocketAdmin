<?php

namespace app\common\library\token\driver;

use app\common\library\token\Driver;
use think\facade\Db;

/**
 * Token操作类
 */
class Mysql extends Driver
{

    /**
     * 默认配置
     * @var array
     */
    protected $options = [
        'table'      => 'user_token',
        'expire'     => 2592000,
        'connection' => [],
    ];


    /**
     * 构造函数
     * @param array $options 参数
     * @access public
     */
    public function __construct($options = [])
    {
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        if ($this->options['connection']) {
            $this->handler = Db::connect($this->options['connection'])->name($this->options['table']);
        } else {
            $this->handler = Db::name($this->options['table']);
        }
        $time = time();
        $tokentime = cache('tokentime');
        if (!$tokentime || $tokentime < $time - 86400) {
            cache('tokentime', $time);
            $this->handler->newQuery()->where('expiretime', '<', $time)->where('expiretime', '>', 0)->delete();
        }
    }

    /**
     * 存储Token
     * @param string $token   Token
     * @param int    $user_id 会员ID
     * @param int    $expire  过期时长,0表示无限,单位秒
     * @return bool
     */
    public function set($token, $user_id, $expire = null)
    {
        // 未显式传入过期时间时，使用驱动配置中的默认值
        if ($expire === null) {
            $expire = $this->options['expire'];
        }
        if ($expire instanceof \DateTime) {
            $expire = $expire->getTimestamp() - time();
        }
        $expiretime = $expire !== 0 ? time() + $expire : 0;
        $token = $this->getEncryptedToken($token);
        $this->handler->newQuery()->insert(['token' => $token, 'user_id' => $user_id, 'createtime' => time(), 'expiretime' => $expiretime]);
        return true;
    }

    /**
     * 在同一事务中验证并替换旧令牌，撤销或并发刷新后不得重新签发。
     */
    public function rotate($oldToken, $newToken, $user_id, $expire = null): bool
    {
        if ($oldToken === $newToken) {
            return false;
        }
        return $this->handler->getConnection()->transaction(function () use ($oldToken, $newToken, $user_id, $expire) {
            $old = $this->handler->newQuery()->where('token', $this->getEncryptedToken($oldToken))
                ->where('user_id', $user_id)->lock(true)->find();
            if (!$old || ($old['expiretime'] && $old['expiretime'] <= time())) {
                return false;
            }
            if (!$this->set($newToken, $user_id, $expire)) {
                throw new \RuntimeException('Token 写入失败');
            }
            $this->delete($oldToken);
            return true;
        });
    }

    /**
     * 获取Token内的信息
     * @param string $token
     * @return  array
     */
    public function get($token)
    {
        $data = $this->handler->newQuery()->where('token', $this->getEncryptedToken($token))->find();
        if ($data) {
            if (!$data['expiretime'] || $data['expiretime'] > time()) {
                //返回未加密的token给客户端使用
                $data['token'] = $token;
                //返回剩余有效时间
                $data['expires_in'] = $this->getExpiredIn($data['expiretime']);
                return $data;
            } else {
                self::delete($token);
            }
        }
        return [];
    }

    /**
     * 判断Token是否可用
     * @param string $token   Token
     * @param int    $user_id 会员ID
     * @return  boolean
     */
    public function check($token, $user_id)
    {
        $data = $this->get($token);
        return $data && $data['user_id'] == $user_id ? true : false;
    }

    /**
     * 删除Token
     * @param string $token
     * @return  boolean
     */
    public function delete($token)
    {
        $this->handler->newQuery()->where('token', $this->getEncryptedToken($token))->delete();
        return true;
    }

    /**
     * 删除指定用户的所有Token
     * @param int $user_id
     * @return  boolean
     */
    public function clear($user_id)
    {
        $this->handler->newQuery()->where('user_id', $user_id)->delete();
        return true;
    }
}
