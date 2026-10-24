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
     * @param ResponseInterface|\Symfony\Component\HttpFoundation\Response $response EasyWeChat 响应
     * @return Response
     */
    public static function toThink($response): Response
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $headers = array_map(static function ($values) { return implode(', ', $values); }, $response->headers->all());
            return response($response->getContent(), $response->getStatusCode(), $headers);
        }
        if (!$response instanceof ResponseInterface) {
            throw new \InvalidArgumentException('微信响应类型不正确');
        }
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return response((string) $response->getBody(), $response->getStatusCode(), $headers);
    }
}
