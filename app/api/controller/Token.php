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
     * 检测Token是否过期
     */
    public function check()
    {
        $tokenInfo = $this->getCurrentTokenInfo();
        $this->success('', $this->formatTokenInfo($tokenInfo));
    }

    /**
     * 刷新Token
     */
    public function refresh()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }

        $expire = $this->getTokenExpireFromRequest();
        $oldToken = $this->auth->getToken();
        if (!$oldToken) {
            $this->error(__('Please login first'), null, 401);
        }

        // 新 Token 创建并读取成功后才删除旧 Token，失败时保留原登录状态。
        $token = Random::uuid();
        if (!TokenLib::set($token, $this->auth->id, $expire)) {
            $this->error(__('Operation failed'));
        }
        $tokenInfo = TokenLib::get($token);
        if (!$tokenInfo) {
            TokenLib::delete($token);
            $this->error(__('Operation failed'));
        }
        TokenLib::delete($oldToken);
        $this->success('', $this->formatTokenInfo($tokenInfo));
    }

    /**
     * 获取当前Token信息
     */
    protected function getCurrentTokenInfo(): array
    {
        $token = $this->auth->getToken();
        if (!$token) {
            $this->error(__('Please login first'), null, 401);
        }

        $tokenInfo = TokenLib::get($token);
        if (!$tokenInfo) {
            $this->error(__('Please login first'), null, 401);
        }

        return $tokenInfo;
    }

    /**
     * 格式化Token响应
     */
    protected function formatTokenInfo(array $tokenInfo): array
    {
        return [
            'token'      => $tokenInfo['token'] ?? '',
            'token_type' => 'Bearer',
            'expires_in' => (int)($tokenInfo['expires_in'] ?? 0),
            'expiretime' => (int)($tokenInfo['expiretime'] ?? 0),
        ];
    }

}
