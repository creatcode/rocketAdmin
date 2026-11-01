<?php

namespace app\common\library\token\driver;

use app\common\library\token\Driver;
use think\facade\Config;

/**
 * Token操作类
 */
class Redis extends Driver
{
    private const INDEX_EXPIRY = <<<'LUA'
local longest = 0
local permanent = false
for _, key in ipairs(redis.call('SMEMBERS', KEYS[2])) do
    local ttl = redis.call('PTTL', key)
    if ttl == -2 then
        redis.call('SREM', KEYS[2], key)
    elseif ttl == -1 then
        permanent = true
    elseif ttl > longest then
        longest = ttl
    end
end
if permanent then
    redis.call('PERSIST', KEYS[2])
else
    redis.call('PEXPIRE', KEYS[2], longest)
end
return 1
LUA;


    protected $options = [
        'host'        => '127.0.0.1',
        'port'        => 6379,
        'password'    => '',
        'select'      => 0,
        'timeout'     => 0,
        'expire'      => 0,
        'persistent'  => false,
        'userprefix'  => 'up:',
        'tokenprefix' => 'tp:',
    ];

    /**
     * 构造函数
     * @param array $options 缓存参数
     * @throws \BadFunctionCallException
     * @access public
     */
    public function __construct($options = [])
    {
        if (!extension_loaded('redis')) {
            throw new \BadFunctionCallException('not support: redis');
        }
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $this->handler = new \Redis;
        if ($this->options['persistent']) {
            $this->handler->pconnect($this->options['host'], $this->options['port'], $this->options['timeout'], 'persistent_id_' . $this->options['select']);
        } else {
            $this->handler->connect($this->options['host'], $this->options['port'], $this->options['timeout']);
        }

        if ('' != $this->options['password']) {
            $this->handler->auth($this->options['password']);
        }

        if (0 != $this->options['select']) {
            $this->handler->select($this->options['select']);
        }
    }

    /**
     * 获取加密后的Token
     * @param string $token Token标识
     * @return string
     */
    protected function getEncryptedToken($token)
    {
        $config = Config::get('token');
        $token = $token ?? ''; // 为兼容 php8
        return $this->options['tokenprefix'] . hash_hmac($config['hashalgo'], $token, $config['key']);
    }

    /**
     * 获取会员的key
     * @param $user_id
     * @return string
     */
    protected function getUserKey($user_id)
    {
        return $this->options['userprefix'] . $user_id;
    }

    /**
     * 存储Token
     * @param   string $token   Token
     * @param   int    $user_id 会员ID
     * @param   int    $expire  过期时长,0表示无限,单位秒
     * @return bool
     */
    public function set($token, $user_id, $expire = 0)
    {
        if (is_null($expire)) {
            $expire = $this->options['expire'];
        }
        if ($expire instanceof \DateTime) {
            $expire = $expire->getTimestamp() - time();
        }
        if ($expire < 0) {
            throw new \InvalidArgumentException('Token 有效期不能为负数');
        }
        // 写入与索引维护必须原子执行，索引有效期覆盖所有仍有效的 Token。
        $script = <<<'LUA'
if tonumber(ARGV[2]) > 0 then
    redis.call('SETEX', KEYS[1], ARGV[2], ARGV[1])
else
    redis.call('SET', KEYS[1], ARGV[1])
end
redis.call('SADD', KEYS[2], KEYS[1])

LUA;
        $result = $this->handler->eval($script . self::INDEX_EXPIRY, [$this->getEncryptedToken($token), $this->getUserKey($user_id), $user_id, $expire], 2);
        if ($result === false) {
            throw new \RuntimeException('Token 写入失败');
        }
        return true;
    }

    /**
     * Lua 原子轮换，与 clear/delete 串行执行，防止已撤销会话复活。
     */
    public function rotate($oldToken, $newToken, $user_id, $expire = null): bool
    {
        if ($oldToken === $newToken) {
            return false;
        }
        $expire = $expire ?? $this->options['expire'];
        if ($expire instanceof \DateTime) {
            $expire = $expire->getTimestamp() - time();
        }
        if ($expire < 0) {
            throw new \InvalidArgumentException('Token 有效期不能为负数');
        }
        $script = <<<'LUA'
if redis.call('GET', KEYS[3]) ~= ARGV[1] or redis.call('EXISTS', KEYS[1]) == 1 then
    return 0
end
if tonumber(ARGV[2]) > 0 then
    redis.call('SETEX', KEYS[1], ARGV[2], ARGV[1])
else
    redis.call('SET', KEYS[1], ARGV[1])
end
redis.call('SADD', KEYS[2], KEYS[1])
redis.call('DEL', KEYS[3])
redis.call('SREM', KEYS[2], KEYS[3])

LUA;
        $result = $this->handler->eval($script . self::INDEX_EXPIRY, [
            $this->getEncryptedToken($newToken), $this->getUserKey($user_id),
            $this->getEncryptedToken($oldToken), $user_id, $expire,
        ], 3);
        if ($result === false) {
            throw new \RuntimeException('Token 轮换失败');
        }
        return $result === 1;
    }

    /**
     * 获取Token内的信息
     * @param   string $token
     * @return  array
     */
    public function get($token)
    {
        $key = $this->getEncryptedToken($token);
        $value = $this->handler->get($key);
        if (is_null($value) || false === $value) {
            return [];
        }
        //获取有效期
        $expire = $this->handler->ttl($key);
        $expire = $expire < 0 ? 0 : $expire;
        $expiretime = $expire ? time() + $expire : 0;
        //解决使用redis方式储存token时api接口Token刷新与检测因expires_in拼写错误报错的BUG
        $result = ['token' => $token, 'user_id' => $value, 'expiretime' => $expiretime, 'expires_in' => $expire];

        return $result;
    }

    /**
     * 判断Token是否可用
     * @param   string $token   Token
     * @param   int    $user_id 会员ID
     * @return  boolean
     */
    public function check($token, $user_id)
    {
        $data = self::get($token);
        return $data && $data['user_id'] == $user_id ? true : false;
    }

    /**
     * 删除Token
     * @param   string $token
     * @return  boolean
     */
    public function delete($token)
    {
        $data = $this->get($token);
        if ($data) {
            $script = "redis.call('DEL', KEYS[1])\nredis.call('SREM', KEYS[2], KEYS[1])\n" . self::INDEX_EXPIRY;
            if ($this->handler->eval($script, [$this->getEncryptedToken($token), $this->getUserKey($data['user_id'])], 2) === false) {
                throw new \RuntimeException('Token 删除失败');
            }
        }
        return true;
    }

    /**
     * 删除指定用户的所有Token
     * @param   int $user_id
     * @return  boolean
     */
    public function clear($user_id)
    {
        $script = <<<'LUA'
for _, key in ipairs(redis.call('SMEMBERS', KEYS[1])) do
    redis.call('DEL', key)
end
redis.call('DEL', KEYS[1])
return 1
LUA;
        if ($this->handler->eval($script, [$this->getUserKey($user_id)], 1) === false) {
            throw new \RuntimeException('Token 清理失败');
        }
        return true;
    }
}
