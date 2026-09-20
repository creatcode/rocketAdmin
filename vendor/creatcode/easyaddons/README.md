# easyaddons

`easyaddons` 是一个面向 ThinkPHP 6+ 项目体系的插件开发与运行支持包，提供插件安装、启用、禁用、升级、
配置读取、资源发布、路由接入与授权相关能力。

## 适用范围

面向 **TP6+ 内核、FastAdmin 风格项目结构**的插件扩展包，可直接运行 FastAdmin 生态的插件包
（含使用 `use think\Addons;` 等旧版命名空间的老插件）。

包内已内置以下兼容能力，宿主无需额外提供：

- **旧版命名空间别名**：`think\Addons`、`think\addons\Service` / `Controller` / `AddonException` / `Route`
- **TP5 常量**：`DS`、`EXT`、`ROOT_PATH`、`APP_PATH`、`CONF_PATH`、`RUNTIME_PATH`
  （用 `defined() || define()` 定义，不覆盖宿主已有定义）
- **文件操作**：`rmdirs`、`copydirs`、`is_really_writable`、`var_export_short` 等全局函数
  已由 `addons\support\File` 收拢，优先复用宿主同名函数，缺失时用内置实现

包依赖项目中存在以下基础类或等价实现：

- `app\BaseController`
- `app\common\middleware\CommonInit`
- `app\common\library\Auth`
- `app\common\library\Menu`
- `app\common\model\Config`

## 功能简介

- 插件目录约定与自动加载
- 插件信息与配置读取
- 插件安装、卸载、启用、禁用、升级
- 插件资源文件发布与回收
- 插件路由注册与事件挂载
- 插件配置刷新与缓存更新
- 插件授权相关处理能力
- 插件自带 `service/` 目录自动注册

## 运行要求

- PHP >= 7.2.5（受 `symfony/var-exporter ^5.4` 与 `guzzlehttp/guzzle ^7.0` 约束）
- ThinkPHP >= 6.1（支持 6.1 / 7 / 8）
- MySQL 5.7+ 或 8.0+

## 安装方式

```bash
composer require creatcode/easyaddons
```

安装后执行配置发布：

```bash
php think vendor:publish
```

## 配置

配置项位于 `config/easyaddons.php`，由 `vendor:publish` 从包内发布。
宿主可在自己的配置中覆盖任意一项。

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `unknownsources` | `true` | 调试模式下是否允许未知来源的插件包（同时受 `app_debug` 约束） |
| `backup_global_files` | `true` | 启用或禁用插件时是否备份将被覆盖的全局文件 |
| `addon_pure_mode` | `true` | 插件纯净模式，启用后删除插件目录的 `application`、`public`、`assets` |
| `addon_auth_check` | `false` | 是否启用插件授权校验 |
| `ssl_verify` | `true` | 是否校验插件市场 HTTPS 证书 |

包内代码的兜底默认值与上表**完全一致**，因此**整份删除 `config/easyaddons.php` 不会改变行为** ——
该文件的作用是把可调开关暴露给宿主，而不是定义"另一套默认"。

**注意**：`vendor:publish` 对**已存在**的配置文件不会覆盖。
从旧版本升级时需手动同步新增的配置项。

## 插件市场地址

**本包不定义市场地址** —— 地址属于部署配置（不同环境、不同市场各不相同），
由宿主提供，包只负责读取与调用：

```php
Service::marketApiUrl();   // 读取宿主 config('rocket.addon_market_api_url')
```

宿主在 `config/rocket.php` 中配置：

```php
'addon_market_api_url' => 'https://api.fastadmin.net',
```

未配置时返回空字符串，插件市场的在线安装入口会自动隐藏（宿主视图据此判断）。

## 插件钩子说明

FastAdmin 插件的钩子方法普遍使用**引用参数**修改传入数据：

```php
public function configInit(&$params) { $params['summernote'] = [...]; }
```

包内以闭包形式注册插件钩子，闭包内部用引用调用插件方法并把修改后的参数作为**返回值回传**，
宿主侧需用返回值接收：

```php
$config = Event::trigger("config_init", $config, true) ?: $config;
```

> `Event::trigger($event, $params, true)` 在无监听器时返回 `false`，`?:` 兜底不可省略。

插件代码内部触发钩子请使用 `hook()` 函数（插件专属，支持引用传递）；
宿主侧请使用 `Event::trigger()`。

## 许可

Apache-2.0
