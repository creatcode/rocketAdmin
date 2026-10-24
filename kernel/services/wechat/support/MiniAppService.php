<?php

declare(strict_types=1);

namespace kernel\services\wechat\support;

use EasyWeChat\MiniProgram\Application;
use Psr\Http\Message\ResponseInterface;

/**
 * 小程序服务
 */
class MiniAppService
{
    protected Application $app;

    protected string $name;

    public function __construct(string $name = 'default')
    {
        $this->name = $name;
        $this->app = new Application(WechatConfig::miniApp($name));
    }

    /**
     * 获取 EasyWeChat 小程序应用实例
     *
     * @return Application
     */
    public function app(): Application
    {
        return $this->app;
    }

    /**
     * 登录凭证校验
     *
     * @param string $code 小程序登录 code
     * @return array<string, mixed>
     */
    public function codeToSession(string $code): array
    {
        return $this->app->auth->session($code);
    }

    /**
     * 解密开放数据
     *
     * @param string $sessionKey 会话密钥
     * @param string $iv 初始向量
     * @param string $ciphertext 加密数据
     * @return array<string, mixed>
     */
    public function decryptSession(string $sessionKey, string $iv, string $ciphertext): array
    {
        return $this->app->encryptor->decryptData($sessionKey, $iv, $ciphertext);
    }

    /**
     * 获取手机号
     *
     * @param string $code 手机号授权 code
     * @return array<string, mixed>
     */
    public function getPhoneNumber(string $code): array
    {
        return $this->app->phone_number->getUserPhoneNumber($code);
    }

    /**
     * 发送订阅消息
     *
     * @param array<string, mixed> $message 订阅消息内容
     * @return array<string, mixed>
     */
    public function sendSubscribeMessage(array $message): array
    {
        return $this->app->subscribe_message->send($message);
    }

    /**
     * 获取不限制数量的小程序码
     *
     * @param string $scene 场景值
     * @param string $page 页面路径
     * @param array<string, mixed> $options 其他接口参数
     * @return ResponseInterface
     */
    public function getUnlimitedQRCode(string $scene, string $page = '', array $options = []): ResponseInterface
    {
        $payload = array_replace($options, ['scene' => $scene]);
        if ($page !== '') {
            $payload['page'] = $page;
        }

        return $this->app->app_code->getUnlimit($scene, $payload);
    }

    /**
     * 获取带 access_token 的 HTTP 客户端
     *
     * @return mixed
     */
    public function client(): \EasyWeChat\Kernel\BaseClient
    {
        return new \EasyWeChat\Kernel\BaseClient($this->app);
    }
}
