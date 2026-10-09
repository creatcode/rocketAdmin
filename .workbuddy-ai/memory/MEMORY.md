# rocket-admin 项目约定

> 只放硬约束与陷阱；细节见同目录日志。**上限 1 万字符。**
> **定位**：FastAdmin 的 TP 内核升级版（官方不做内核升级，故有此项目；准备开源）→ **兼容存量 TP5 插件是核心竞争力**。

## 协作铁律
1. 参考截图 ≠ 授权改动：改观感前先说明「打算改成 X」并获确认。
2. 改前 `git show HEAD:<file>` 核对原值；用户说「之前没有／怎么变了」→ 先 `git diff HEAD`；未跟踪文件反向还原「改前」再对照。**落笔后必须确认改动真在工作区**，声称修复后 grep/哈希复核（曾出现日志记「已改+已验证」而文件与 HEAD 逐字节相同）。
3. 主观负面反馈（"太丑了"）不猜着改：诊断 → 出 2~3 方向 → `AskUserQuestion` 选定后才动代码；用户跳过选择 ≠ 授权。
4. 用户对位置的描述常与实际指代不符 → 改观感前必须让他指认到具体元素。
5. 只改用户点到的属性；范围有歧义先问。不新增文件（含项目内验证脚本；临时验证页放系统 temp 并清理）。
6. 注释只标注参数/返回值/关键意图：禁写变更来源、禁复述代码、不为单一调用点抽方法。标点全角，`//` 后不加空格。

## 样式铁律
- 全局元素选择器 ≤ 0,1,1；组件链接规则一律 0,1,2（`.dropdown-menu>li>a`、`.pagination>li>a`、`.nav-tabs>li>a`、`.breadcrumb>li>a`），同权重后加载者胜。skin-white.css 被 `backend.css:3` @import 且被 backend.min.css 内联 → **常驻基线**，写 0,1,2 会把整族组件链接静默染成主色。正确写法：`.skin-white a:where(:not(.btn,.text-primary,.text-success,.text-info,.text-warning,.text-danger,.text-muted)), .inside-header a:where(:not(同上)){color:#165dff}`；`.inside-header a`=0,1,1，**不要**写 `body.inside-header a`。
- 排除类名必须 `:where(:not(...))`；`:not(.btn)` 会抬到 0,2,1 反向盖过组件规则。不得命中 `.btn`（FastAdmin 用 `<a class="btn">` 做按钮，`.btn-*` 字色仅 0,1,0）。
- 语义色按钮靠源码顺序决定双类叠加胜负（模板大量 `btn-primary btn-success` 叠加）；皮肤给任一语义色加 `!important`/抬高特异性会静默打破，补规则顺序须同 bootstrap：primary→success→info→warning→danger。
- 皮肤不得覆盖 `.text-*` 语义色取值（均 0,1,0）：primary `#444c69`、success `#18bc9c`、info `#1688f1`、warning `#f39c12`、danger `#f75444`、muted `#777`。
- 基线 id 选择器会静默截断皮肤规则（如 `#secondnav .nav-addtabs>li>a{background:none}`，(1,1,2)）；判「皮肤规则没生效」前先查基线有无 id 抢权重。
- 皮肤隔离唯一手段 = 前缀且成对：`.skin-white`（外层框架 body）+ `.inside-header`（iframe 内页 body，`layout/default.html:7`）。弹窗/内页**滚动条**属内页文档（`fastadmin.css:20-25`，弹窗是 iframe）→ 须写 `html::`/`body::`/`.inside-header ::` 三个变体；内页读不到皮肤名 → 无法按皮肤隔离。
- 皮肤加载：`meta.html:12-13` 一次只加载一个 `skins/{adminskin}.css`；**内页 `adminskin` 恒为 `''`**（白名单只在 `Index::index():37-43`）→ gray-light/sky-light 的 `body.inside-header` 规则是死代码（不修）；登录页同样读不到。
- **尺寸类例外只有 `.btn-xs`**：skin-white 只给 `.btn.btn-xs` 补 `padding:1px 5px !important`（`:631`）；`.btn.btn-sm` 吃通用 `.btn{padding:6px 20px !important}`（`:65`）→ 表格单元图标按钮（90px 操作列）会换行堆叠；项目内惯例是 `btn-xs`（`require-table.js:107`）。表格工具栏右侧 = `.fixed-table-toolbar .columns-right` + `.search`，skin-white 的 `.pull-right.columns-right .btn{border-color:transparent !important}` 会吃掉按钮描边。
- `bootstrap.css`+`fastadmin.css` = 20 套皮肤共用基线，严禁加皮肤前缀或改品牌色。原版 18 皮肤与 `_all-skins.css` 不要动；`skin-white.css:1141+` 的「色调统一补充」块属皮肤自己的设计层，不要提议回退。三自建皮肤不含暗色主题。「原版皮肤判据」只指「皮肤覆盖 `.text-*` 语义色取值」，禁止泛化。
- 同层叠上下文「后续兄弟绘制在上」：外阴影/`::after` 覆盖层指向后续兄弟区域会看不见 → 该元素加 `position: relative`。`rgba(0,0,0,.1)` 配大 blur ≠ 同色 1px 实线，浅色分隔线用 1px 实线。
- 三皮肤把 `.btn-info` 与 `.btn-info.active` 写成同色同 `!important` → 选中反馈须靠 `.btn-group:has(>.btn-info.active)>.btn-info:not(.active)` 补描边（须成对补 `:hover/:focus`）；改描边会暴露 `.btn:active:focus` 的 5px 黑焦点环（`bootstrap.css:3012`）→ 同组补 `outline:none!important`。

## 色值基线（对齐 Chuiot simple.css，勿自创）
容器底/表头 `#f6f8fb`（弹层标题栏例外：三皮肤已统一纯白 `#fff`）；hover `#f4f4f5`；激活态 `#f0f5ff`；分割线/内容区底 `#f1f4f6`；主色/hover/浅底/浅描边 `#165dff`/`#0e48d8`/`#f0f5ff`/`#adc6ff`；正文/次级/三级 `#1d2129`/`#4e5969`/`#86909c`；描边 `#dcdfe6`；盒子布局底 `#e9edf2`；**灰底上的分割线 `#e5e6eb`**（`#f1f4f6` 只在白底可见）。按钮语义色：primary #fff/#444c69、success #fff/#18bc9c、danger #fff/#f75444、warning #fff/#f39c12、info #fff/#1688f1、outline #fff/transparent（primary/info 皮肤改 #165dff）；`*-light` 不动。

## 构建 / 模板 / 通用陷阱
- `php think min -m backend -r css`（`app/admin/command/Min.php`）：生产读 backend.min.css，改 CSS 必须重建（回退 `git checkout -- public/assets/css/backend.min.css`）；不要用 `Gruntfile.js` 的 `backend:css`；改 `.html`/`.js`/`.php` 不需重建。本地 `APP_DEBUG=true` 加载 `backend.css` → 改皮肤即时生效。坑：`php think min` 会触发插件系统重写 `config/addons.php` 与 `public/assets/js/addons.js` → 构建后 `git checkout --` 回退这两个文件。
- TP6 已自动 htmlentities：写 `{$var}` 即可，加 `|htmlentities` 会转义两次。
- 页面 JS 加载：`Backend.php:204` 令 `jsname='backend/'.$controllername` → 控制器 `Foo` 必须有 `public/assets/js/backend/foo.js`。
- 行尾以 **CRLF** 为主（`view/addon/index.html`、`js/backend/addon.js` 纯 CRLF；上游 `config/rocket.php` 是 LF）→ **逐个文件按原状态保持，不要统一**；并行 Edit 同一文件会互相覆盖 → 串行改完 grep 复核。新建文件默认 LF，须按目标目录惯例转换。
- **`$Think.config.*` 在模板里不生效**（`parseThinkVar()` 只识别 const/now/ldelim/rdelim，其余编译成 `''`）：`{if $Think.config.rocket.x}` 恒假、`{$Think.config.rocket.x|htmlentities}` 输出空。必须用助手函数：`{if config('rocket.x')}`、`{:htmlentities(config('rocket.x'))}`。
- jQuery `.attr('checked','checked')` 不驱动 CSS `:checked`，必须 `.prop('checked', bool)`（本项目有意改进，同步时不要改回）。
- 其他陷阱：`Attachment` 的 `createtime` 读出已是字符串 → 用 `is_numeric($v)?(int)$v:strtotime($v)`；`assignconfig()` 必须在 `Backend.php:220` 的 `assign('config', $config)` 之后；`grid` 的 `repeat(auto-fit, minmax(Npx,1fr))` 不限列数，控列须写死 `repeat(3, minmax(0,1fr))`；think-orm 下「独立一行 `$model->where()/order()`」是死代码，条件须并入 `where()` 数组参数。
- 验证手法：模板离线渲染见 skill `thinkphp-template-offline-render`（CLI 下 `{:token_field()}` 必崩）；需登录态/破坏性功能端到端见 `fastadmin-authenticated-page-probe`（沙箱副本 + 免登录改 `Addon.php`）；几何/样式验证用系统 temp 临时页 + 内置浏览器读几何。

## 双项目同步（rocket-admin ↔ tp8-fastadmin）
- 上游 `E:\phpstudy_pro\extraproject\tp8-fastadmin` = TP8、`1.6.2.20260323`；本项目 = TP6、`1.6.1.20250430`。两仓 git 历史独立 → 不可 cherry-pick。本机无 PHP 8 → tp8 只能静态核验。
- 内核差异（照抄上游必崩）：本项目 think-orm 2.0.62 / php ≥7.4.3，上游 4.0.51 / php ≥8.0。① 无 `Db::quote()` → 用 `'\''.addslashes((string)$v).'\''`；② `setInc()` 仅 4.x 有 → 用 `->inc('times')->update()`；③ `str_starts_with()` 需 PHP 8 → 用 `strpos($a,$b) === 0`。两版通用：`lock(true)`、`inc()/dec()`、`where([])`。
- 模板语法不同：上游 `{:config('rocket.x')}`／`{:request()->cookie('x')}`，本项目 `{$Think.config.rocket.x}`／`{:$Request.cookie.x}` → 移植保留上游写法，只搬语义。验证安全类结论必须走真实调用路径（绕过校验层直接调 ORM 会误报漏洞）。
- 同步方法：三方合并 `git merge-file -p ours base theirs`（base = 本项目 `HEAD:<file>`，ours = 上游工作区，theirs = 本项目工作区）再人工解冲突；上游自身已有差异（勿当作新改动同步）见 `2026-10-08.md`。

## 插件管理（Addon）
- 源配置 `config/rocket.php` 的 `addon_market_api_url`：以 `.json` 结尾 → 静态清单源（`Addon::isStaticSource()`）；否则官方市场 API（**`https://api.fastadmin.net`**，仓库基线值；曾误记为 `api.f1astadmin.net`，该域名不存在）。当前工作区指向自建 Gitee 源 `https://gitee.com/willrook/rocket-admin-plugin-repository/raw/master/plugins.json`。官方市场 `/addon/index` 返回 313 条；`/addon/download` 不带账号返回 `code:0`「需要登录」→ **下载/安装必须 FastAdmin 账号（uid+token）**；本机 Guzzle+curl（TLS 校验开启）正常。
- 静态源实现：`readSource()`/`resolveDownload()`/`downloadFromSource()`/`online()`；`install()`/`upgrade()` 各有静态源分支（**改下载来源必须逐个入口核对**，曾漏改 `upgrade()`）。`download` 支持相对路径（按清单目录展开）与绝对 URL。Gitee raw 会 302 → `follow_location=1`；**禁用 `/repository/archive/*.zip`**（触发人机验证）。
- 清单字段：`{source,updated,plugins:[{name,title,intro,author,version,compatible,download,homepage,screenshots,releases[]}]}`；`releases` **手动维护**，顶层为默认/最高版本。**已去掉 md5**，改为「包内 version vs 清单 version」一致性校验。
- **兼容策略（用户定案）**：不做自动改写 → 「`compatible` 标识 + 脚手架 + check 只出报告」。`compatible` 是**真值布尔**（`true`/`1`/`yes`/`on` = 已声明适配，不显示标签；其余/空 = `undeclared`）→ 列表黄色「未声明兼容性」+ 安装/升级前 `Layer.confirm` 提示但**仍放行**。
- 前端：`data-compatible` 由 `.operate` 容器承载；`has_front`（`ADDON_PATH/<name>/controller/Index.php`）控制插件名/首页/版本号是否可点，市场源 `undefined` 一律可点。**改「某列不可点」要 grep 全部 `row.url`/`row.addon.url` 使用点**。`isMarketSource` 为假（本地/静态源）→ 工具栏仅「刷新 + 本地安装」。
- 源码包 `E:\phpstudy_pro\extraproject\addon-source`（**独立 git 仓库**，非项目内；Gitee `willrook/rocket-admin-plugin-repository`，默认 master）：`plugins.json` + `packages/*.zip` + `README.md`。**打源包必须排除运行时产物**（`.addonrc` 及 `.git/.svn/.vscode/.idea`）。
- `php think addon:manifest -p <源目录>`（`app/admin/command/AddonManifest.php`，已注册 `config/console.php`）扫描 packages 生成清单，顶层字段取最高版本。**注意：easyaddons 的 `AddonCommand` 也叫 `addon`，本项目 `app\admin\command\Addon` 的改动永远不生效**。
- **未落地**：`addon:check` 体检命令；插件脚手架（`addon:create`）；插件源协议文档完整化。
- easyaddons 已覆盖旧命名空间别名、TP5 常量、文件函数、目录映射（`application→app`、`assets→public/assets/addons/<name>/`）、纯净模式、`hook()` 桥接；**未覆盖 TP5 静态调用**（静态调用 fatal、`think\{Loader,Hook,Url}` 不存在、模板 `$Think.config.*` 静默失效）→ 这是「官方插件装上用不了」的根因；不能用全局 class_alias 修（类名已被 TP6 真实类占用），**只要把 `use think\X;` 指向 `think\facade\X` 即可**（import 重定向）。easyaddons 由自己维护：已把 `Service::importsql()` 的 `Db::getPdo()->exec()` 改成 `Db::execute()`（TP6 惰性连接 → 无连接时 `getPdo()` 返回 false）。官方市场校验 `Service::valid()` POST `/addon/valid`、默认关闭、官方插件不声明兼容性 → 我们的 `compatible` 是其替代方案。

## 系统升级（Upgrade，细节见 `2026-09-23.md`）
`app/admin/service/UpgradeService.php`（`Upgrade.php` 是门面），批次目录 `runtime/admin/upgrade/<YmdHis>/`。
- `fetchVersionInfo()` 读 `config('rocket.upgrade.version_info_url')`（默认 `/upgrade.json`），只接受恰好 3 键 `version`/`url`/`notes`；升级包要求 `manifest.from_version === config('rocket.version')` 严格相等。根 `upgrade.json` = 发布清单模板，`public/upgrade.json` = 站点根版本信息。
- 网络层只能用 PHP stream，**禁 curl**（本机 `curl.cainfo` 为空 → cURL error 60）。`protectedPaths` = `config/{site,database,token,rocket}.php`。关闭态只渲染空状态卡 + 日志表；破坏性动作已加 CSRF，`Fast.api.ajax` 不带 token → JS 必须手动带并回填。

## 页面增强 / 右侧控制栏 / 首页 dashboard（坑见 `2026-09-24.md`、`2026-09-21.md`）
- 水印 + 无操作自动登出：配置在 `config/rocket.php`，由 `Index::index()` 经 `assignconfig` 传前端。水印服务端静态渲染（勿改回 JS）：平铺块 320×180、`#1d2129`+`fill-opacity=".07"`、内页不加。自动登出 `auto_logout`（秒，0=关闭）。
- 右侧控制栏（`common/control.html` + `index.js`）：齿轮触发 AdminLTE `.control-sidebar`；不新增 JS 文件，JS 一律放 `index.js`；`.lc-panel{z-index:1050}` 压过 `.main-header` 的 1030。绑定靠 `[data-skin]`/`[data-config]`/`[data-layout]`/`[data-enable]`/`[data-menu]`；新增皮肤必须同步加进 `my_skins`（决定切皮肤 `removeClass` 哪些 class）。遮罩规则须并列同特异性 + `!important`；`{include file="common/control" /}` 必须在 `.control-sidebar-bg` 之前。`Index.php:37` 白名单把 `adminskin`/`multiplenav`/`multipletab`/`show_submenu` 写回 `Config::set('rocket.*')`。
- 首页 dashboard：必须走**文档级滚动** —— `.dash-wrap` 只留 `margin:-15px`；`.dashboard-shell{min-height:calc(100vh - 37px)}`（37px = `#ribbon` 高），**不要**加 `height`/`overflow-y:auto`（父容器 `height:auto` 时百分比 `min-height` 解析为 0）。`.dashboard-shell{overflow:hidden}` 成层叠上下文 → 已加 `#think_page_trace{z-index:1000001 !important}`；层级：弹层 `19891014` > trace `999999` > 水印 `100000`。

## 组合数据模块（2026-09-28，本项目新增、上游无）
方案见根目录 `COMBINATION_DATA_IMPLEMENTATION_PLAN.md`：`system_group`（`fields` 存字段 JSON）+ `system_group_data`（`value` 存 JSON）；「字段定义」用 `fieldlist` + 模板 `view/system/systemgroup/fields.html`。

## FastAdmin 官方版本同步
版本基线 `config/rocket.php:42 = 1.6.1.20250430`（不是 `config/site.php` 的 `1.0.3`）。官方 1.x 是 TP5 → 补丁包不可直接套用；官方 1.6.2~1.6.4 的 SelectPage 修复在本项目内联在 `Backend::selectpage()`。未做：验证码改 6 位、`Sms/Ems::check` 加第 4 参 `true`。
