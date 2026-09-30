<?php

namespace app\common\library\token;

use InvalidArgumentException;

/**
 * 使用框架驱动管理器创建 Token 存储，保留命名连接及强制新建语义。
 */
class Manager extends \think\Manager
{
    protected $namespace = '\\app\\common\\library\\token\\driver\\';

    /** @var array 各命名连接对应的驱动配置 */
    protected array $options = [];

    /**
     * 获取 Token 存储连接。
     *
     * @param array $options 驱动配置
     * @param bool|string $name 连接名称，true 表示强制新建
     * @return Driver Token 存储驱动
     */
    public function connect(array $options = [], $name = false): Driver
    {
        $key = is_bool($name) ? md5(serialize($options)) : $name;
        if ($name === true) {
            // 强制新建不修改已有连接的配置或实例。
            $class = $this->resolveClass($options['type'] ?? 'File');
            return $this->app->invokeClass($class, [$options]);
        }
        $this->options[$key] ??= $options;
        return $this->drivers[$key] = $this->getDriver($key);
    }

    /**
     * 根据连接名称获取存储类型。
     *
     * @param string $name 连接名称
     * @return string 驱动类型
     */
    protected function resolveType(string $name): string
    {
        return $this->options[$name]['type'] ?? 'File';
    }

    /**
     * 提供驱动构造所需配置。
     *
     * @param string $name 连接名称
     * @return array 驱动配置
     */
    protected function resolveConfig(string $name): array
    {
        return $this->options[$name];
    }

    /**
     * 校验驱动契约，避免配置错误时创建无关对象。
     *
     * @param string $type 驱动类型或类名
     * @return string 驱动类名
     */
    protected function resolveClass(string $type): string
    {
        $class = parent::resolveClass($type);
        if (!is_subclass_of($class, Driver::class)) {
            throw new InvalidArgumentException('Token 驱动必须继承 ' . Driver::class);
        }
        return $class;
    }

    /**
     * 返回配置中的默认驱动。
     *
     * @return string 默认驱动类型
     */
    public function getDefaultDriver(): string
    {
        return $this->app->config->get('token.type', 'Mysql');
    }
}
