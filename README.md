基于ThinkPHP6+Fastadmin的后台系统
===============

> 运行环境要求PHP7.4+

```
fastadmin: V1.5.2.20240906

thinkphp: v6.1.2
```


## 主要特性

* 基于`Auth`验证的权限管理系统
    * 支持无限级父子级权限继承，父级的管理员可任意增删改子级管理员及权限设置
    * 支持单管理员多角色
    * 支持管理子级数据或个人数据
* 强大的一键生成功能
    * 一键生成CRUD,包括控制器、模型、视图、JS、语言包、菜单、回收站等
    * 一键压缩打包JS和CSS文件，一键CDN静态资源部署
    * 一键生成控制器菜单和规则
    * 一键生成API接口文档
* 完善的前端功能组件开发
    * 基于`AdminLTE`二次开发
    * 基于`Bootstrap`开发，自适应手机、平板、PC
    * 基于`RequireJS`进行JS模块管理，按需加载
    * 基于`Less`进行样式开发
* 强大的插件扩展功能，在线安装卸载升级插件
* 通用的会员模块和API模块
* 共用同一账号体系的Web端会员中心权限验证和API接口会员权限验证
* 二级域名部署支持，同时域名支持绑定到应用插件
* 多语言支持，服务端及客户端支持
* 支持大文件分片上传、剪切板粘贴上传、拖拽上传，进度条显示，图片上传前压缩
* 支持表格固定列、固定表头、跨页选择、Excel导出、模板渲染等功能
* 强大的第三方应用模块支持([CMS](https://www.fastadmin.net/store/cms.html)、[博客](https://www.fastadmin.net/store/blog.html)、[知识付费问答](https://www.fastadmin.net/store/ask.html)、[在线投票系统](https://www.fastadmin.net/store/vote.html)、[B2C商城](https://www.fastadmin.net/store/shopro.html)、[B2B2C商城](https://www.fastadmin.net/store/wanlshop.html))
* 支持CMS、博客、知识付费问答无缝整合[Xunsearch全文搜索](https://www.fastadmin.net/store/xunsearch.html)
* 第三方小程序支持([CMS小程序](https://www.fastadmin.net/store/cms.html)、[预订小程序](https://www.fastadmin.net/store/ball.html)、[问答小程序](https://www.fastadmin.net/store/ask.html)、[点餐小程序](https://www.fastadmin.net/store/unidrink.html)、[B2C小程序](https://www.fastadmin.net/store/shopro.html)、[B2B2C小程序](https://www.fastadmin.net/store/wanlshop.html)、[博客小程序](https://www.fastadmin.net/store/blog.html))
* 整合第三方短信接口(阿里云、腾讯云短信)
* 无缝整合第三方云存储(七牛云、阿里云OSS、又拍云)功能，支持云储存分片上传
* 第三方富文本编辑器支持(Summernote、Kindeditor、百度编辑器)
* 第三方登录(QQ、微信、微博)整合
* 第三方支付(微信、支付宝)无缝整合，微信支持PC端扫码支付
* 丰富的插件应用市场

## 安装使用

https://doc.fastadmin.net

## 在线演示

https://demo.fastadmin.net

用户名：admin

密　码：123456

提　示：演示站数据无法进行修改，请下载源码安装体验全部功能

## 界面截图
![控制台](https://images.gitee.com/uploads/images/2020/0929/202947_8db2d281_10933.gif "控制台")

## 问题反馈

在使用中有任何问题，请使用以下联系方式联系我们

交流社区: https://ask.fastadmin.net

## 特别鸣谢

感谢以下的项目,排名不分先后

Fastadmin：http://www.fastadmin.net

ThinkPHP：http://www.thinkphp.cn

AdminLTE：https://adminlte.io

Bootstrap：http://getbootstrap.com

jQuery：http://jquery.com

Bootstrap-table：https://github.com/wenzhixin/bootstrap-table

Nice-validator: https://validator.niceue.com

SelectPage: https://github.com/TerryZ/SelectPage

Layer: https://layuion.com/layer/

DropzoneJS: https://www.dropzonejs.com


## 版权信息

FastAdmin遵循Apache2开源协议发布，并提供免费使用。

本项目包含的第三方源码和二进制文件之版权信息另行标注。

版权所有Copyright © 2017-2022 by FastAdmin (https://www.fastadmin.net)

All rights reserved。

## 安全修复后的数据库升级

已有数据库部署前须备份并暂停业务写入，再执行 `php think user:secure-schema`，补齐会员登录失败时间和身份唯一约束。发现重复账号时命令会停止，不自动合并或删除；未绑定的空邮箱、手机号会转换为 `NULL`，模型对外仍保持空字符串。

执行后重启 PHP 服务或清理模型字段缓存，再恢复业务。

## 安全回归测试

运行 `php tests/SecurityRegressionTest.php`。需要 PHP CLI 的 `pdo_mysql`、`proc_open`，以及当前 `.env` 数据库账号的建库和删库权限。

脚本创建随机命名的独立测试库和临时上传文件，结束时清理，不修改业务库或 `.env`。覆盖注册凭据校验、密码原值传递、并发登录限次、余额冲突、附件归属、分片大小限制、令牌轮换及导入权限和回滚；TP6 另验证旧安装锁及小程序码错误处理。HTTP 上传落盘动作在 CLI 中用本地移动替代，不代表浏览器上传验收。

导入保留更新能力：文件包含已有 ID 时，必须具有当前控制器的编辑权限且记录处于当前数据范围内；数据归属不能通过导入更新改变。新增和更新使用同一批次事务，任一行失败整批回滚。CSV 由原生读取器处理，支持引号、字段内逗号、换行及原有编码识别。

Redis 集成测试需要可连接的本机 Redis。执行前设置进程环境变量 `$env:SECURITY_TEST_REDIS_PORT = '16379'`，脚本只使用随机测试前缀并清理自身键；未设置时明确跳过 Redis 集成检查。
