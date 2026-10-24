<?php

declare(strict_types=1);

namespace kernel\services\wechat\support;

use Closure;
use stdClass;
use EasyWeChat\Kernel\Messages;
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

    protected string $name;

    public function __construct(string $name = 'default')
    {
        $this->name = $name;
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
    public function onMessage(callable $handler): self
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
    public function onEvent(callable $handler): self
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
        $server = $this->app->server;

        if (!$this->serverRegistered) {
            $server->push(function ($message) {
                $reply = $this->dispatch((object)$message);
                if (!is_array($reply)) {
                    return $reply;
                }
                switch ($reply['MsgType'] ?? '') {
                    case 'text':
                        return new Messages\Text($reply['Content']);
                    case 'image':
                        return new Messages\Image($reply['Image']['MediaId']);
                    case 'voice':
                        return new Messages\Voice($reply['Voice']['MediaId']);
                    case 'video':
                        $video = $reply['Video'];
                        return new Messages\Video($video['MediaId'], [
                            'title' => $video['Title'] ?? '',
                            'description' => $video['Description'] ?? '',
                            'thumb_media_id' => $video['ThumbMediaId'] ?? '',
                        ]);
                    case 'news':
                        return new Messages\News(array_map(static function ($item) {
                            return new Messages\NewsItem([
                                'title' => $item['Title'], 'description' => $item['Description'],
                                'url' => $item['Url'], 'image' => $item['PicUrl'],
                            ]);
                        }, $reply['Articles']));
                    default:
                        throw new \InvalidArgumentException('不支持的微信回复消息类型');
                }
            });
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
        return $this->app->menu->create($buttons);
    }

    /**
     * 获取自定义菜单
     *
     * @return array<string, mixed>
     */
    public function getMenu(): array
    {
        return $this->app->menu->list();
    }

    /**
     * 删除自定义菜单
     *
     * @return array<string, mixed>
     */
    public function deleteMenu(): array
    {
        return $this->app->menu->delete();
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
        return $this->app->user->get($openid, $lang);
    }

    /**
     * 获取用户列表
     *
     * @param string|null $nextOpenid 下一个 openid
     * @return array<string, mixed>
     */
    public function getUsers(?string $nextOpenid = null): array
    {
        return $this->app->user->list($nextOpenid);
    }

    /**
     * 发送模板消息
     *
     * @param array<string, mixed> $message 模板消息内容
     * @return array<string, mixed>
     */
    public function sendTemplateMessage(array $message): array
    {
        return $this->app->template_message->send($message);
    }

    /**
     * 获取网页授权对象
     *
     * @return mixed
     */
    public function oauth()
    {
        return $this->app->oauth;
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

    /**
     * 分发公众号消息
     *
     * @param stdClass $message 微信消息
     * @return array<string, mixed>|string
     */
    protected function dispatch(stdClass $message)
    {
        if ($message->MsgType === 'event') {
            return $this->handleEvent($message);
        }

        return $this->handleMessage($message);
    }

    /**
     * 处理普通消息
     *
     * @param stdClass $message 微信消息
     * @return array<string, mixed>|string
     */
    protected function handleMessage(stdClass $message)
    {
        if ($this->messageHandler !== null) {
            return ($this->messageHandler)($message);
        }

        $replies = [
            'text' => '收到文字消息', 'image' => '收到图片消息', 'voice' => '收到语音消息',
            'video' => '收到视频消息', 'location' => '收到坐标消息', 'link' => '收到链接消息',
        ];
        return isset($replies[$message->MsgType]) ? WechatMessage::text($replies[$message->MsgType]) : '';
    }

    /**
     * 处理事件消息
     *
     * @param stdClass $message 微信消息
     * @return array<string, mixed>|string
     */
    protected function handleEvent(stdClass $message)
    {
        if ($this->eventHandler !== null) {
            return ($this->eventHandler)($message);
        }

        $replies = ['subscribe' => '欢迎关注', 'scan' => '欢迎回来'];
        $event = strtolower((string)$message->Event);
        return isset($replies[$event]) ? WechatMessage::text($replies[$event]) : '';
    }
}
