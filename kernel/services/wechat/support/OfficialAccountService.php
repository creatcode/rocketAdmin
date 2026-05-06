<?php

declare(strict_types=1);

namespace kernel\services\wechat\support;

use Closure;
use EasyWeChat\Kernel\Message;
use EasyWeChat\OfficialAccount\Application;
use think\Response;

/**
 * 公众号服务
 */
class OfficialAccountService
{
    protected Application $app;

    protected ?Closure $messageHandler = null;

    protected ?Closure $eventHandler = null;

    protected bool $serverRegistered = false;

    public function __construct(protected string $name = 'default')
    {
        $this->app = new Application(WechatConfig::officialAccount($name));
    }

    /**
     * 获取 EasyWeChat 公众号应用实例
     *
     * @return Application
     */
    public function app(): Application
    {
        return $this->app;
    }

    /**
     * 设置普通消息处理器
     *
     * @param callable $handler 处理器
     * @return $this
     */
    public function onMessage(callable $handler): static
    {
        $this->messageHandler = Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 设置事件消息处理器
     *
     * @param callable $handler 处理器
     * @return $this
     */
    public function onEvent(callable $handler): static
    {
        $this->eventHandler = Closure::fromCallable($handler);

        return $this;
    }

    /**
     * 处理公众号服务器推送
     *
     * @return Response
     */
    public function serve(): Response
    {
        $server = $this->app->getServer();

        if (!$this->serverRegistered) {
            $server->with(fn (Message $message) => $this->dispatch($message));
            $this->serverRegistered = true;
        }

        return WechatResponse::toThink($server->serve());
    }

    /**
     * 创建自定义菜单
     *
     * @param array<int, array<string, mixed>> $buttons 菜单按钮
     * @return array<string, mixed>
     */
    public function createMenu(array $buttons): array
    {
        return $this->client()->postJson('/cgi-bin/menu/create', [
            'button' => $buttons,
        ])->toArray(false);
    }

    /**
     * 获取自定义菜单
     *
     * @return array<string, mixed>
     */
    public function getMenu(): array
    {
        return $this->client()->get('/cgi-bin/menu/get')->toArray(false);
    }

    /**
     * 删除自定义菜单
     *
     * @return array<string, mixed>
     */
    public function deleteMenu(): array
    {
        return $this->client()->get('/cgi-bin/menu/delete')->toArray(false);
    }

    /**
     * 获取用户基本信息
     *
     * @param string $openid 用户 openid
     * @param string $lang 返回语言
     * @return array<string, mixed>
     */
    public function getUser(string $openid, string $lang = 'zh_CN'): array
    {
        return $this->client()->get('/cgi-bin/user/info', [
            'openid' => $openid,
            'lang' => $lang,
        ])->toArray(false);
    }

    /**
     * 获取用户列表
     *
     * @param string|null $nextOpenid 下一个 openid
     * @return array<string, mixed>
     */
    public function getUsers(?string $nextOpenid = null): array
    {
        $query = [];
        if ($nextOpenid !== null && $nextOpenid !== '') {
            $query['next_openid'] = $nextOpenid;
        }

        return $this->client()->get('/cgi-bin/user/get', $query)->toArray(false);
    }

    /**
     * 发送模板消息
     *
     * @param array<string, mixed> $message 模板消息内容
     * @return array<string, mixed>
     */
    public function sendTemplateMessage(array $message): array
    {
        return $this->client()->postJson('/cgi-bin/message/template/send', $message)->toArray(false);
    }

    /**
     * 获取网页授权对象
     *
     * @return mixed
     */
    public function oauth(): mixed
    {
        return $this->app->getOAuth();
    }

    /**
     * 获取带 access_token 的 HTTP 客户端
     *
     * @return mixed
     */
    public function client(): mixed
    {
        return $this->app->createClient();
    }

    /**
     * 分发公众号消息
     *
     * @param Message $message 微信消息
     * @return array<string, mixed>|string
     */
    protected function dispatch(Message $message): array|string
    {
        if ($message->MsgType === 'event') {
            return $this->handleEvent($message);
        }

        return $this->handleMessage($message);
    }

    /**
     * 处理普通消息
     *
     * @param Message $message 微信消息
     * @return array<string, mixed>|string
     */
    protected function handleMessage(Message $message): array|string
    {
        if ($this->messageHandler !== null) {
            return ($this->messageHandler)($message);
        }

        return match ($message->MsgType) {
            'text' => WechatMessage::text('收到文字消息'),
            'image' => WechatMessage::text('收到图片消息'),
            'voice' => WechatMessage::text('收到语音消息'),
            'video' => WechatMessage::text('收到视频消息'),
            'location' => WechatMessage::text('收到坐标消息'),
            'link' => WechatMessage::text('收到链接消息'),
            default => '',
        };
    }

    /**
     * 处理事件消息
     *
     * @param Message $message 微信消息
     * @return array<string, mixed>|string
     */
    protected function handleEvent(Message $message): array|string
    {
        if ($this->eventHandler !== null) {
            return ($this->eventHandler)($message);
        }

        return match (strtolower((string) $message->Event)) {
            'subscribe' => WechatMessage::text('欢迎关注'),
            'scan' => WechatMessage::text('欢迎回来'),
            default => '',
        };
    }
}
