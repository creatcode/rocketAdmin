# rocket-admin 项目约定

> 只放硬约束与陷阱；实现细节见同目录日志（2026-09-21/23/24/28/10-10.md）。**上限约 1 万字符，新增前先删旧的。**

## 协作铁律
1. 参考截图 ≠ 授权改动：改观感前先说明「打算改成 X」并获确认。**用户指令优先于项目文档记录**（文档与现状不符时按用户说法更新文档）。
2. 改前 `git show HEAD:<file>` 核对原值；用户说「之前没有／怎么变了」→ 先 `git diff HEAD`。未跟踪文件无基线 → 反向还原「改前」再对照。**落笔后必须 grep/哈希复核改动真在工作区**。
3. 主观负面反馈（"太丑了"）不猜着改：诊断 → 出 2~3 方向 → `AskUserQuestion` 选定后才动代码。用户跳过选择 ≠ 授权。
4. 用户对位置的描述常与实际指代不符 → 改观感前必须让他指认到具体元素。
5. 只改用户点到的属性；范围有歧义先问。不新增文件（项目内验证脚本也不行；临时验证页放系统 temp 并清理）。
6. 注释只标注参数/返回值/关键意图：禁写变更来源（版本号/公告编号/上游出处）、禁复述代码、不为单一调用点抽方法。标点全角，`//` 后不加空格。

## 样式铁律
- 全局元素选择器 ≤ 0,1,1；组件链接规则一律 0,1,2，同权重后加载者胜。skin-white.css 被 `backend.css:3` @import 且被 `backend.min.css` 内联 → 是**常驻基线**，写 0,1,2 会把整族组件链接（`.dropdown-menu>li>a`、`.pagination>li>a`、`.nav-tabs>li>a`、`.breadcrumb>li>a`）静默染成主色。正确写法：`.skin-white a:where(:not(.btn,.text-primary,.text-success,.text-info,.text-warning,.text-danger,.text-muted)), .inside-header a:where(:not(同上)){color:#165dff}`。`.inside-header a` = 0,1,1，**不要**写 `body.inside-header a`（0,1,2 撞车）。
- 排除类名必须 `:where(:not(...))`；`:not(.btn)` 会抬到 0,2,1 反向盖过组件规则。不得命中 `.btn`（FastAdmin 用 `<a class="btn">` 做按钮，`.btn-*` 字色仅 0,1,0）。
- 语义色按钮靠源码顺序决定双类叠加胜负（模板大量 `btn-primary btn-success` 叠加，`addon/index.html:207,227,241,243`）；皮肤给任一语义色加 `!important` 或抬高特异性会静默打破。补规则顺序须同 bootstrap：primary→success→info→warning→danger。
- 皮肤不得覆盖 `.text-*` 语义色（均 0,1,0）：primary `#444c69`、success `#18bc9c`、info `#1688f1`、warning `#f39c12`、danger `#f75444`、muted `#777`。「原版皮肤判据」只指这一条，禁止泛化成「皮肤不得重绘基线组件」。
- 基线 id 选择器会静默截断皮肤规则（`backend.css:768` 的 `#secondnav .nav-addtabs>li>a` (1,1,2) 压过 `.skin-white .nav-addtabs>li.active>a` 0,3,2）→ 判断「皮肤规则没生效」前先查基线有无 id 抢权重。
- 皮肤隔离唯一手段 = 前缀且成对：`.skin-white`（外层框架 body）+ `.inside-header`（iframe 内页 body，`layout/default.html:7`）。
- **弹窗/内页滚动条**：弹窗是 iframe（`fast.js:143` type 2），滚动条属**内页文档** → `.skin-white` 够不到；规则必须写 `html::`/`body::`/`.inside-header ::` 三个变体。内页读不到皮肤名 → 无法按皮肤隔离。
- 皮肤加载：`meta.html:12-13` 一次只加载一个 `skins/{adminskin}.css`；`backend.min.css` 只合并 white。**内页 `adminskin` 恒为 `''`**（白名单只在 `Index::index():37-43`）→ gray-light/sky-light 的 221 条 `body.inside-header` 是死代码；修法 = 白名单挪到 `Backend::initialize()` 开头（2026-09-23 决定不动）。
- 表格工具栏右侧 = `.fixed-table-toolbar .columns-right` + `.search`。skin-white 易踩：`.btn{padding:6px 20px !important}`（图标按钮被撑宽）、`.pull-right.columns-right .btn{border-color:transparent !important}`。
- **表格单元图标按钮一律 `.btn-xs`**：skin-white 只给 `.btn.btn-xs` 补 `padding:1px 5px !important`（`:631`），`.btn.btn-sm` 无对应规则 → 吃通用 `.btn{padding:6px 20px !important}`（`:65`）而破版（90px 操作列）。`require-table.js:107` 同惯例。
- **`fieldlist` 表格型模板官方写法**：`app/admin/command/Crud/stubs/html/fieldlist-template.stub` = `table.fieldlist` + `<td width="90">` + `btn-sm`（danger/success/primary）。原版基线 90px 装得下 → **这类"被撑宽"不是模板写错类名**，不要为它改皮肤。
- `bootstrap.css`+`fastadmin.css` = 20 套皮肤共用基线，严禁加皮肤前缀或改品牌色。原版 18 个皮肤与 `_all-skins.css` 不要动。自建皮肤不含暗色主题：日后另建皮肤文件。
- 外阴影两个坑：① 同层叠上下文「后续兄弟绘制在上」→ 指向后续兄弟区域的外阴影会看不见，需给该元素加 `position: relative`；② `rgba(0,0,0,.1)` 配大 blur ≠ 同色 1px 实线，浅色分隔线用 1px 实线。
- 三皮肤把 `.btn-info` 与 `.btn-info.active` 写成同色同 `!important` → `.btn-group` 选中反馈须靠 `.btn-group:has(>.btn-info.active)>.btn-info:not(.active)` 补描边态；该规则 (0,6,0) 会连 hover 一起吃掉，须成对补 `:hover/:focus`。改描边态会暴露 `.btn:active:focus` 的 5px 黑焦点环（`bootstrap.css:3012`）→ 同组补 `outline:none!important`。

## 色值基线（对齐 Chuiot simple.css，勿自创）
容器底/表头 `#f6f8fb`（弹层标题栏例外：三皮肤已统一纯白 `#fff`）；hover `#f4f4f5`；激活态 `#f0f5ff`；分割线/内容区底 `#f1f4f6`；主色/hover/浅底/浅描边 `#165dff`/`#0e48d8`/`#f0f5ff`/`#adc6ff`；正文/次级/三级 `#1d2129`/`#4e5969`/`#86909c`；描边 `#dcdfe6`；盒子布局底 `#e9edf2`；**灰底上的分割线 `#e5e6eb`**。
按钮语义色：primary #fff/#444c69(皮肤改 #165dff)、success #fff/#18bc9c、danger #fff/#f75444、warning #fff/#f39c12、info #fff/#1688f1(皮肤改 #165dff)、outline #fff/transparent；`*-light` 不动。

## 构建 / 模板 / 通用陷阱
- `php think min -m backend -r css`（`app/admin/command/Min.php`）。生产读 backend.min.css，改 CSS 必须重建；回退 `git checkout -- public/assets/css/backend.min.css`。不要用 `Gruntfile.js` 的 `backend:css`。改 `.html`/`.js`/`.php` 不需要重建。**本地 `APP_DEBUG=true` 加载的是 `backend.css`（@import 各源文件），不是 min** → 改皮肤源文件即时生效。
- 坑：`php think min` 会触发插件系统重写 `config/addons.php` 与 `public/assets/js/addons.js` → 构建后必须 `git checkout --` 回退这两个文件。
- TP6 已自动 htmlentities：写 `{$var}` 即可，加 `|htmlentities` 会转义两次。
- 页面 JS 加载：`Backend.php:204` 令 `jsname='backend/'.$controllername` → 控制器 `Foo` 必须有 `public/assets/js/backend/foo.js`。
- jQuery `.attr('checked','checked')` 不驱动 CSS `:checked`，必须 `.prop('checked', bool)`（本项目有意改进，同步时不要改回）。
- `Attachment` 的 `createtime` 读出已是字符串，`(int)` 强转得 2017 → 必须 `is_numeric($v)?(int)$v:strtotime($v)`。
- `assignconfig()` 必须放在 `Backend.php:220` 的 `assign('config', $config)` 之后，否则被静默覆盖。
- `grid` 的 `repeat(auto-fit, minmax(Npx, 1fr))` 只保证铺满、不限制列数 —— 容器越宽列数越多。要控制列数必须写死 `repeat(3, minmax(0, 1fr))` + 媒体查询降列。
- think-orm 下「独立一行 `$model->where()/order()`」是死代码（`Model::__call` 每次新建 Query），条件必须并入 `where()` 数组参数。`Backend.php:546-549` 的 selectpage dataLimit 注入即因此失效；不要照抄官方 TP5 的 `SelectPage::applyDataLimit()`。
- 离线验证手法见 `2026-09-28.md`；模板渲染另见 skill `thinkphp-template-offline-render`（`cache_path` 必显式给；CLI 下 `{:token_field()}` 必崩 → 换静态片段）。

## 前端组件与构建（基准 = 官方原版 `1.6.6.20260902`；权威记录 `OPTIMIZATION_AUDIT.md`）
- 2026-10-10 已按官方对齐：`libs` 581 文件 = 官方 577 + 有意保留 4 个（`bootstrap-contextmenu.js` 右键菜单、`font-awesome/less/variables.less`（CRUD `@icon` 映射）、`jquery/external/sizzle/LICENSE.txt`、`toastr/LICENSE`）；`package-lock.json` 与官方逐字节一致；libs 无空目录；`devDependencies` 与 `dists` 已回官方（含 coloris）。
- **必须保留的 2 处 `dists` 差异**：`fastadmin-bootstraptable` 排除 `dist/extensions/filter-control/bootstrap-table-filter-control{,.min}.js`（否则 build 覆盖项目修复）；`font-awesome` 含 `less/variables.less`。
- **必须保留的 `Gruntfile.js` 差异**：Min/r 路径 `./app/admin/...`（官方 `application/`，TP6 必须）；require 配置用 `require('vm').runInNewContext()`（官方用 parse-config-file+jsonminify）；`callback(err)` 传播失败。
- `Min.php` 临时文件写 `runtime/min/`；`Crud.php` Selectpage 元数据用 `$dbconnect->getTableInfo($prefix . $table)`（`Db::name()->getTableInfo()` 不存在，异常被静默吞掉）。
- 回归页 `tests/frontend/filter-control.html`（浏览器直接打开，显示「通过」即成功）。

## 已知非本项目缺陷（勿擅自修）
- `location.reload()` 后 URL 重复 `index.php` → cookie 失效（`createCookie` 的 path = `Config.moduleurl`）。嫌疑 `fast.js:96`。

## 双项目同步（rocket-admin ↔ tp8-fastadmin）
- 上游 `E:\phpstudy_pro\extraproject\tp8-fastadmin` = TP8、`1.6.2.20260323`；本项目 = TP6、`1.6.1.20250430`。两仓 git 历史独立 → 不可 cherry-pick。
- 内核差异（照抄上游必崩）：本项目 think-orm 2.0.62 / php ≥7.4.3，上游 4.0.51 / php ≥8.0。① 无 `Db::quote()` → 主键值转义用 `'\''.addslashes((string)$v).'\''`；② `setInc()` 仅 4.x 有 → 用 `->inc('times')->update()`；③ `str_starts_with()` 需 PHP 8 → 用 `strpos($a,$b) === 0`。两版通用：`lock(true)`、`inc()/dec()`、`where([])`。
- 验证安全类结论必须走真实调用路径（绕过校验层直接调 ORM 会误报漏洞）。上游 selectpage 不可注入；本项目 `selectpage()` 用正则白名单（就地内联）。
- 模板语法不同：上游 `{:config('rocket.x')}`／`{:request()->cookie('x')}`，本项目 `{$Think.config.rocket.x}`／`{:$Request.cookie.x}` → 移植保留上游写法，只搬语义。
- 同步方法：三方合并 `git merge-file -p ours base theirs`（base = 本项目 `HEAD:<file>`，ours = 上游工作区，theirs = 本项目工作区）再人工解冲突。上游行尾不统一、gray-light/sky-light 皮肤带 BOM → 按各文件原状态写入。
- 上游自身已有差异（勿当新改动同步）：`Index.php` 的 `$msg ? $msg : ...`；`index.js` 4 处 `.attr('checked','checked')` 且缺「同步右侧布局面板开关状态」一行；`config/rocket.php` 版本号与 `lang_switch` 缩进；无 `package.json`/`Gruntfile.js`。

## 系统升级（Upgrade）
细节见 `2026-09-23.md`。逻辑全在 `app/admin/service/UpgradeService.php`（`Upgrade.php` 是门面），批次目录 `runtime/admin/upgrade/<YmdHis>/`。
- `fetchVersionInfo()` 读 `config('rocket.upgrade.version_info_url')`（默认 `/upgrade.json`），只接受恰好 3 键 `version`/`url`/`notes`；升级包要求 `manifest.from_version === config('rocket.version')` 严格相等。
- 两个 `upgrade.json` 别混：根 `upgrade/upgrade.json` = 发布清单模板 `{version, from_version, delete, sql}`；`public/upgrade.json` = 站点根版本信息 `{version, url, notes}`。
- 网络层只能用 PHP stream，**禁 curl**（本机 `curl.cainfo` 为空 → cURL error 60）。`protectedPaths` = `config/{site,database,token,rocket}.php`。
- 关闭态（`enabled=false`）只渲染空状态卡 + 日志表；`recover` 有意不校验 enabled，`check`/`run` 校验。破坏性动作已加 CSRF，`Fast.api.ajax` 不带 token → JS 必须手动带并回填。

## 页面增强：水印 + 无操作自动登出
配置在 `config/rocket.php`，由 `Index::index()` 经 `assignconfig` 传前端。**坑全部见 `2026-09-24.md`（iframe 事件不冒泡、多标签页 localStorage、`Layer` 必须大写等），改动前先读日志。**
- 水印：服务端静态渲染，勿改回 JS。平铺块 320×180；固定 `#1d2129`+`fill-opacity=".07"`；内页不加。
- 自动登出：`auto_logout`（秒，0=关闭）。用户只要这两项增强，其余候选不要主动铺开。

## 右侧控制栏（common/control.html + index.js）
- 齿轮触发 AdminLTE `.control-sidebar`。不新增 JS 文件，JS 一律放 `index.js`；control.html 只含 DOM + 内联 `<style>`。`.lc-panel{z-index:1050}` 压过 `.main-header` 的 1030。
- 绑定靠 `[data-skin]`/`[data-config]`/`[data-layout]`/`[data-enable]`/`[data-menu]`；`my_skins` 数组决定切皮肤能 `removeClass` 哪些 class，新增皮肤必须同步加进去。
- 坑：遮罩规则必须并列同特异性选择器 + `!important`（基线 `.control-sidebar-* + .control-sidebar-bg` 是 (0,2,0)、带不透明底色）；`{include file="common/control" /}` 必须在 `.control-sidebar-bg` 之前（用 `~` 兄弟选择器）。详见 `2026-09-21.md`。
- 已删的 3 个零效果开关（同步上游时不要加回来）：`[data-sidebarskin]`、`[data-controlsidebar]`、`[data-layout='fixed']`。

## 首页 dashboard（view/dashboard/index.html）
- 必须走**文档级滚动**：`.dash-wrap` 只留 `margin:-15px`；`.dashboard-shell{min-height:calc(100vh - 37px)}`（37px = `#ribbon` 高度）。**不要**给 `.dash-wrap` 加 `height`/`overflow-y:auto`（会多出一层独立滚动，面包屑不动）。父容器 `height:auto` 时百分比 `min-height` 解析为 0 → 铺满只能用视口值。
- `.dashboard-shell{overflow:hidden}` 会形成**层叠上下文** → 盖住框架页 TP trace。已加 `#think_page_trace{z-index:1000001 !important}`；**`!important` 必需**（本项目 `page_trace.tpl` 把 z-index 写在**元素内联 style** 里；上游同模板是 `<style>` 块）。在 `.dashboard-shell` 内新增视口层级效果前先想清楚是否又在造层叠上下文。
- 层级基线：弹层 `19891014`（`layer.js` config.zIndex）> TP trace `999999` > 水印 `100000`。抬层级务必低于弹层基准。

## 其它背景（低频）
- **FastAdmin 官方同步**：版本基线 `config/rocket.php:42 = 1.6.1.20250430`（非 `config/site.php` 的 `1.0.3`）。官方 1.x 是 TP5、本项目是 TP6 移植版 → 官方补丁包不可直接套用；官方 1.6.2~1.6.4 的 SelectPage 修复本项目内联于 `Backend::selectpage()`。2026-09-24 已落地一批（见 `2026-09-24.md`）；未做：验证码改 6 位、`Sms/Ems::check` 加第 4 参 `true`。
- **组合数据模块**（2026-09-28 新增，上游无）：`system/systemgroup` + `system/systemgroupdata`，方案见 `COMBINATION_DATA_IMPLEMENTATION_PLAN.md`；「字段定义」用 `fieldlist`，不要为它改皮肤。
