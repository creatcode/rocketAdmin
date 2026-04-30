<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\Token as TokenLib;
use util\Random;

/**
 * Token接口
 */
class Token extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /**
     * Token默认过期时间：30天（秒）
     */
    const TOKEN_EXPIRE = 2592000;

    /**
     * 检测Token是否过期
     *
     */
    public function check()
    {
        $token = $this->auth->getToken();
        $tokenInfo = TokenLib::get($token);
        $this->success('', ['token' => $tokenInfo['token'], 'expires_in' => $tokenInfo['expires_in']]);
    }

    /**
     * 刷新Token
     *
     */
    public function refresh()
    {
        //删除源Token
        $token = $this->auth->getToken();
        TokenLib::delete($token);
        //创建新Token
        $token = Random::uuid();
        TokenLib::set($token, $this->auth->id, self::TOKEN_EXPIRE);
        $tokenInfo = TokenLib::get($token);
        $this->success('', ['token' => $tokenInfo['token'], 'expires_in' => $tokenInfo['expires_in']]);
    }
}
