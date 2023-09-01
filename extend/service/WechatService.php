<?php

declare(strict_types=1);

namespace service;

use EasyWeChat\Factory;
use EasyWeChat\Kernel\Messages\Article;
use EasyWeChat\Kernel\Messages\Image;
use EasyWeChat\Kernel\Messages\Media;
use EasyWeChat\Kernel\Messages\News;
use EasyWeChat\Kernel\Messages\Text;
use EasyWeChat\Kernel\Messages\Video;
use EasyWeChat\Kernel\Messages\Voice;
use EasyWeChat\OfficialAccount\Server\Guard;
use EasyWeChatComposer\EasyWeChat;
use think\Response;

class WechatService
{
    protected static $application;

    /**
     * 获取配置
     *
     * @return Array
     */
    public static function getConfig(): array
    {
        $config = [
            'app_id' => 'wxef4feffdb476b85f',
            'secret' => '0279344f37ead42b65e58ce06c7f3241',
            'token' => 'wechat',
            'http' => [
                'timeout' => 10.0, // 超时时间（秒）
                'verify' => false
            ],
            'log' => [
                'default' => 'dev', // 默认使用的 channel，生产环境可以改为下面的 prod
                'channels' => [
                    // 测试环境
                    'dev' => [
                        'driver' => 'single',
                        'path' => root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'easywechat.log',
                        'level' => 'debug',
                    ],
                    // 生产环境
                    'prod' => [
                        'driver' => 'daily',
                        'path' => root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'easywechat.log',
                        'level' => 'info',
                    ],
                ],
            ],
        ];
        return $config;
    }

    /**
     * 创建实例
     *
     * @return \EasyWeChat\OfficialAccount\Application
     */
    public static function instance()
    {
        (self::$application === null) && (self::$application = Factory::officialAccount(self::getConfig()));
        return self::$application;
    }

    /**
     * 公众号消息通讯
     * 注：token验证时关闭Debug调试,否则会验证不通过
     * @return Response
     */
    public function serve()
    {
        $server = self::instance()->server;
        $this->msgHandle($server);
        $response = $server->serve();
        // $response->send();
        return response($response->getContent());
    }

    /**
     * 消息类型
     *
     * @param Guard $server
     * @return void
     */
    private function msgHandle(Guard $server)
    {
        $server->push(function ($message) {
            switch ($message['MsgType']) {
                case 'event':
                    return $this->eventHandle($message);
                    break;
                case 'text':
                    return '收到文字消息';
                    break;
                case 'image':
                    return '收到图片消息';
                    break;
                case 'voice':
                    return '收到语音消息';
                    break;
                case 'video':
                    return '收到视频消息';
                    break;
                case 'location':
                    return '收到坐标消息';
                    break;
                case 'link':
                    return '收到链接消息';
                    break;
                case 'file':
                    return '收到文件消息';
                default:
                    return '';
                    break;
            }
        });
    }

    /**
     * 事件推送
     *
     * @param array $message
     * @return void
     */
    private function eventHandle(array $message)
    {
        switch (strtolower($message['Event'])) {
                //关注
            case 'subscribe':
                return '欢迎关注';
                break;
                // 取消关注
            case 'unsubscribe':
                return '欢迎关注';
                break;
                // 扫码
            case 'scan':
                return '欢迎关注';
                break;
                // 上报地理位置
            case 'location':
                return '欢迎关注';
                break;
                // 点击自定义菜单
            case 'click':
                return '欢迎关注';
                break;
                // 点击菜单跳转链接
            case 'view':
                return '欢迎关注';
                break;
            default:
                return '';
                break;
        }
    }

    /**
     * 回复文本消息
     * @param string $content 文本内容
     * @return Text
     */
    public static function textMessage($content)
    {
        return new Text($content);
    }

    /**
     * 回复图片消息
     * @param string $media_id 媒体资源 ID
     * @return Image
     */
    public static function imageMessage($media_id)
    {
        return new Image($media_id);
    }

    /**
     * 回复视频消息
     * @param string $media_id 媒体资源 ID
     * @param string $title 标题
     * @param string $description 描述
     * @param null $thumb_media_id 封面资源 ID
     * @return Video
     */
    public static function videoMessage($media_id, $title = '', $description = '...', $thumb_media_id = null)
    {
        return new Video($media_id, compact('title', 'description', 'thumb_media_id'));
    }

    /**
     * 回复声音消息
     * @param string $media_id 媒体资源 ID
     * @return Voice
     */
    public static function voiceMessage($media_id)
    {
        return new Voice($media_id);
    }

    /**
     * 回复图文消息
     * @param string|array $title 标题
     * @param string $description 描述
     * @param string $url URL
     * @param string $image 图片链接
     */
    public static function newsMessage($title, $description = '...', $url = '', $image = '')
    {
        if (is_array($title)) {
            if (isset($title[0]) && is_array($title[0])) {
                $newsList = [];
                foreach ($title as $news) {
                    $newsList[] = self::newsMessage($news);
                }
                return $newsList;
            } else {
                $data = $title;
            }
        } else {
            $data = compact('title', 'description', 'url', 'image');
        }
        return new News($data);
    }

    /**
     * 回复文章消息
     * @param string|array $title 标题
     * @param string $thumb_media_id 图文消息的封面图片素材id（必须是永久 media_ID）
     * @param string $source_url 图文消息的原文地址，即点击“阅读原文”后的URL
     * @param string $content 图文消息的具体内容，支持HTML标签，必须少于2万字符，小于1M，且此处会去除JS
     * @param string $author 作者
     * @param string $digest 图文消息的摘要，仅有单图文消息才有摘要，多图文此处为空
     * @param int $show_cover_pic 是否显示封面，0为false，即不显示，1为true，即显示
     * @param int $need_open_comment 是否打开评论，0不打开，1打开
     * @param int $only_fans_can_comment 是否粉丝才可评论，0所有人可评论，1粉丝才可评论
     * @return Article
     */
    public static function articleMessage($title, $thumb_media_id, $source_url, $content = '', $author = '', $digest = '', $show_cover_pic = 0, $need_open_comment = 0, $only_fans_can_comment = 1)
    {
        $data = is_array($title) ? $title : compact('title', 'thumb_media_id', 'source_url', 'content', 'author', 'digest', 'show_cover_pic', 'need_open_comment', 'only_fans_can_comment');
        return new Article($data);
    }

    /**
     * 回复素材消息
     * @param string $type [mpnews、 mpvideo、voice、image]
     * @param string $media_id 素材 ID
     * @return Media
     */
    public static function materialMessage($type, $media_id)
    {
        return new Media($type, $media_id);
    }
}
