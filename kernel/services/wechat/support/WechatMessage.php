<?php

declare(strict_types=1);

namespace kernel\services\wechat\support;

/**
 * 微信消息
 */
class WechatMessage
{
    /**
     * 文本消息
     *
     * @param string $content 文本内容
     * @return array<string, mixed>
     */
    public static function text(string $content): array
    {
        return [
            'MsgType' => 'text',
            'Content' => $content,
        ];
    }

    /**
     * 图片消息
     *
     * @param string $mediaId 媒体资源 ID
     * @return array<string, mixed>
     */
    public static function image(string $mediaId): array
    {
        return [
            'MsgType' => 'image',
            'Image' => [
                'MediaId' => $mediaId,
            ],
        ];
    }

    /**
     * 语音消息
     *
     * @param string $mediaId 媒体资源 ID
     * @return array<string, mixed>
     */
    public static function voice(string $mediaId): array
    {
        return [
            'MsgType' => 'voice',
            'Voice' => [
                'MediaId' => $mediaId,
            ],
        ];
    }

    /**
     * 视频消息
     *
     * @param string $mediaId 媒体资源 ID
     * @param string $title 标题
     * @param string $description 描述
     * @param string|null $thumbMediaId 封面资源 ID
     * @return array<string, mixed>
     */
    public static function video(string $mediaId, string $title = '', string $description = '', ?string $thumbMediaId = null): array
    {
        return [
            'MsgType' => 'video',
            'Video' => array_filter([
                'MediaId' => $mediaId,
                'Title' => $title,
                'Description' => $description,
                'ThumbMediaId' => $thumbMediaId,
            ], static fn($value) => $value !== '' && $value !== null),
        ];
    }

    /**
     * 图文消息
     *
     * @param array<int, array<string, string>> $articles 图文列表
     * @return array<string, mixed>
     */
    public static function news(array $articles): array
    {
        $items = [];
        foreach ($articles as $article) {
            $items[] = [
                'Title' => (string) ($article['title'] ?? $article['Title'] ?? ''),
                'Description' => (string) ($article['description'] ?? $article['Description'] ?? ''),
                'PicUrl' => (string) ($article['image'] ?? $article['pic_url'] ?? $article['PicUrl'] ?? ''),
                'Url' => (string) ($article['url'] ?? $article['Url'] ?? ''),
            ];
        }

        return [
            'MsgType' => 'news',
            'ArticleCount' => count($items),
            'Articles' => $items,
        ];
    }
}
