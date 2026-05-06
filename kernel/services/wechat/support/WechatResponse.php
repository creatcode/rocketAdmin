<?php

declare(strict_types=1);

namespace kernel\services\wechat\support;

use Psr\Http\Message\ResponseInterface;
use think\Response;

/**
 * 微信响应
 */
class WechatResponse
{
    /**
     * 将 PSR-7 响应转换为 ThinkPHP 响应
     *
     * @param ResponseInterface $response EasyWeChat 响应
     * @return Response
     */
    public static function toThink(ResponseInterface $response): Response
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return response((string) $response->getBody(), $response->getStatusCode(), $headers);
    }
}
