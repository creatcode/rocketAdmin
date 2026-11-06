# rocket-admin 项目备忘

## 硬红线（优先于其它内容）
- **未经老板明确指令，不做任何 git 写操作**（add/commit/push/reset/restore/checkout/rm --cached/stash 全禁），只做只读查看。
- **不要自作主张抽控制器标识转换函数**（`controller_name()` 已按老板要求删除），命名口径见下节。

## 注释规范（2026-09-29 定稿）
- 注释该写还是要写，但要专业简洁，非必要不写一大堆；不写"谁在用/所以调用方会怎样"，不复述代码。
- 风格跟各自文件：`Crud.php`/`Menu.php`/`Frontend.php` 用 `//无空格`；`Backend.php`/`AdminLog.php`/`Api.php` 用 `// 有空格`。

## 本地环境
- 站点 `http://test.rocket.com`（Nginx vhost → web 根 `public/`）；后台 `admin`/`123456`。
- 登录 POST `/index.php/admin/index/login`，必须带页面隐藏域 `__token__`；无验证码。
- 数据库 `rocketadmin`，root/root，表前缀 `im_`（.env 为 `[DATABASE]` 分段式，键名 `DATABASE =`，不是 `DB_NAME =`——切库脚本曾因此静默失效）。
- playwright 一律 `--browser=msedge`；本地服务不用 file://。

## 命名体系（2026-09-29 定案并落地，勿再讨论旧方案）
- **类名/类文件保持大驼峰**（`SystemGroup.php`），老板明确否决改类名。
- 资源名由类名按框架 `Str::snake` 派生：视图 `view/system/system_group/`、JS `backend/system/system_group.js`、语言包 `lang/zh-cn/system/system_group.php`、权限节点 `system.system_group[/action]`（**点号+下划线**）。
- **派生只在一处**：`app/BaseController.php` 构造函数里 `$this->request->controllerPath = implode('.', array_map([Str::class,'snake'], explode('.', $this->request->controller())));`；属性在 `app/Request.php` 声明；读取方一律读属性（Backend/Api/Frontend/TenantBackend、AdminLog/TenantAdminLog、common.php 的 build_toolbar/build_heading）。斜杠形态用 `str_replace('.', '/', ...)`。
- `MultiLevelControllerUrl` 中间件已删除；`jsname`/`loadlang` 仍须把点号换成 `/`（那是文件路径）。
- 生成器口径：`-c system/SystemGroup` → 类 `SystemGroup`、JS `system/system_group.js`、URL·节点 `system.system_group`（点号）；`Crud::getControllerUrl()` 恒返回点号，`Menu.php` 节点名 `implode('.', ...)`，`--controller` 形参仍是斜杠解析。
- tp8-fastadmin 已同步同一套（6 派生点 + common.php + 生成器 + 21 模板 62 处 `$auth->check` + 3 JS + 删中间件 + 重打包）。tp8 有独立 `im_tenant_auth_rule`（42 条旧斜杠名 + `AdminAuth::getRuleNameAliases()` 别名映射），属待清理陈旧数据，未动。
- ⚠️ Windows 上 case-only 重命名，git 索引保留旧名（`git rm --cached` + `git add` 后用 `git ls-files` 复核）。
- 实测验证已过：组合数据两页 + auth.admin/general.attachment/user.user/index 全 200，`ajax/lang?controllername=system.system_group` 正常。

## 弹窗尺寸
- 默认弹窗宽高唯一入口：`fast.js` 的 `Fast.api.open`，公式 `[min(vw-40,1120)+'px', min(max(vh-40,320),800)+'px']`（rocket-admin:139 / tp8:138）。
- **只改默认值这一处**，页面单独设定尺寸（addon.js、rule.js、upgrade.js、frontend/user.js）一律不动。
- 优先级：`options.area` > `Fast.config.openArea`（未设）> 默认公式 > `<480px/iPad` 强制整屏。
- layer `area` 必须字符串数组，传数字退化成内容自适应。
- **两项目 fast.js 唯一差异**：rocket-admin 在 ajax error 里对 msg 做了 HTML 转义（fast.js:85-86）→ 同步时不能整文件覆盖，只同步目标行。

## 前端打包
- 加载哪个 JS：`APP_DEBUG=true` 走源码，false 走 `.min`。
- 命令：`"E:/phpstudy_pro/Extensions/php/php8.0.2nts/php.exe" think min -m all -r all -o uglify`（不需要 node_modules；只打 js 用 `-r js`；fast.js 被 backend/frontend 两包引用须一起打）。
- 永远不手改 `.min.js/.min.css`；改皮肤必须重打包 css（skins 经 backend.css @import 合并）。
- 缓存 version 在 `config/site.php`（1.0.3），不生效就强刷或递增。

## 组合数据（system/systemgroup）
- 共用表单片段一律内联进 add/edit.html，不抽 include（老板定）。
- 不锁字段结构：已有数据也允许改字段定义，edit.html 顶部留黄色 alert 提示；丢值只发生在改字段标识/删字段。
- fieldlist 列宽：前几列百分比要留够操作列（现 20/20/15/28 + width="110"）。
- `data-favisible` 在 fieldlist 行内不可用（正则只吃顶层名）；fieldlist 的 `<textarea name="row[xxx]">` 必须常驻（可 hide）。

## 验证套路（本机）
- playwright-cli 的 open/goto/eval/screenshot 写在同一条命令里；**eval 只接受单个表达式**（多语句要包 `(function(){...})()`）；返回值在第 2 行（`| sed -n '2p'`）。
- 弹窗表单在 iframe 里：`layer.querySelector('iframe').contentDocument`。
- `resize <w> <h>` 改视口；测 layer 尺寸取 `.layui-layer` 最后一个。
- 纯 PHP 回归：`.workbuddy/tmp/xxx_probe.php` 里 `new \think\App(); $app->initialize();` 后直接调静态方法，改前存 baseline、改后 diff。
- 临时探测代码带 `TEMP-PROBE` 注释，收尾 grep 清干净；测试产物进 `.workbuddy/tmp/`，跑完清 `.playwright-cli/`。
- 验证生成器不心动真库：mysqldump → 副本库 → 改 .env → 跑 → 查 → 还原 → DROP；切库前必须 grep .env 确认键名。
- `php think menu` 会用 docblock 覆盖节点 title；`php think crud` 会重写文件，别随便跑。
- bash 里 grep 含 `$(window)` 的 JS 别用双引号（命令替换），用单引号或 sed 先抽片段。
- PowerShell stdout 不回显 → 落盘 `.workbuddy/tmp/` 再 Read。
