# easyaddons

`easyaddons` 是一个面向 ThinkPHP 6+ 项目体系的插件开发与运行支持包，用于提供插件安装、启用、禁用、升级、配置读取、资源发布、路由接入与授权相关能力。

## 适用范围

easyaddons 是面向 TP6+ 内核、FastAdmin 风格项目结构的插件扩展包。

该包依赖项目中存在以下基础类或等价实现：

- app\BaseController
- app\common\middleware\CommonInit
- app\common\library\Auth
- app\common\library\Menu
- app\common\model\Config

## 功能简介

该包主要提供以下能力：

- 插件目录约定与自动加载
- 插件信息与配置读取
- 插件安装、卸载、启用、禁用、升级
- 插件资源文件发布
- 插件路由注册与事件挂载
- 插件配置刷新与缓存更新
- 插件授权相关处理能力

## 运行要求

- PHP >= 7.1
- ThinkPHP >= 6.1
- MySQL 5.7+ or 8.0+

## 安装方式

通过 Composer 引入：

```bash
composer require creatcode/easyaddons
