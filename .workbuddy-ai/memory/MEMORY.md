# rocket-admin 项目约定

> 只放硬约束与陷阱，实现细节见同目录日志（2026-09-21/23/24/28.md）。**上限约 1 万字符，新增前先删旧的。**

## 协作铁律
1. 参考截图 ≠ 授权改动：改观感前先说明「打算改成 X」并获确认。
2. 改前 `git show HEAD:<file>` 核对原值；用户说「之前没有／怎么变了」→ 先 `git diff HEAD`。未跟踪文件无基线 → 反向还原出「改前」再对照。**落笔前必须确认改动真在工作区**：曾出现日志记「已改+已沙箱验证」而文件与 HEAD 逐字节相同（疑似被并发会话/回退覆盖）→ 声称修复后要 grep/哈希复核，别信记录。
3. 主观负面反馈（"太丑了"）不猜着改：诊断 → 出 2~3 方向 → `AskUserQuestion` 选定后才动代码。用户跳过选择 ≠ 授权。
4. 用户对位置的描述常与实际指代不符 → 改观感前必须让他指认到具体元素。
5. 只改用户点到的属性；范围有歧义先问。不新增文件（项目内验证脚本也不行；临时验证页放系统 temp 并清理）。
6. 注释只标注参数/返回值/关键意图：禁写变更来源（版本号/公告编号/上游出处）、禁复述代码、不为单一调用点抽方法。标点全角，`//` 后不加空格。

## 样式铁律
- 全局元素选择器 ≤ 0,1,1；组件链接规则一律 0,1,2（`.dropdown-menu>li>a`、`.pagination>li>a`、`.nav-tabs>li>a`、`.breadcrumb>li>a`），同权重后加载者胜。skin-white.css 被 `backend.css:3` @import（在 bootstrap.css/fastadmin.css 之后）且被 `backend.min.css` 内联 → 是**常驻基线**，写 0,1,2 会把整族组件链接静默染成主色。正确写法：`.skin-white a:where(:not(.btn,.text-primary,.text-success,.text-info,.text-warning,.text-danger,.text-muted)), .inside-header a:where(:not(同上)){color:#165dff}`。`.inside-header a` = 0,1,1，**不要**写 `body.inside-header a`（0,1,2 撞车）。
- 排除类名必须 `:where(:not(...))`；`:not(.btn)` 会抬到 0,2,1 反向盖过组件规则。不得命中 `.btn`（FastAdmin 用 `<a class="btn">` 做按钮，`.btn-*` 字色仅 0,1,0）。
- 语义色按钮靠源码顺序决定双类叠加胜负（模板大量 `btn-primary btn-success` 叠加，`addon/index.html:207,227,241,243`）；皮肤给任一语义色加 `!important` 或抬高特异性会静默打破。补规则顺序须同 bootstrap：primary→success→info→warning→danger。
- 皮肤不得覆盖 `.text-*` 语义色取值（均 0,1,0）：primary `#444c69`、success `#18bc9c`、info `#1688f1`、warning `#f39c12`、danger `#f75444`、muted `#777`。（`.text-primary` 收敛成 `#165dff` 曾让权限页整列变蓝。）
- 基线 id 选择器会静默截断皮肤规则：`#secondnav .nav-addtabs>li>a{background:none}`（backend.css:768，(1,1,2)）压过 `.skin-white .nav-addtabs>li.active>a`（0,3,2）。判断「皮肤规则没生效」前先查基线有无 id 抢权重。
- 皮肤隔离唯一手段 = 前缀且成对：`.skin-white`（外层框架 body）+ `.inside-header`（iframe 内页 body，`layout/default.html:7` 的 `class="inside-header inside-aside [is-dialog]"`）。
- **弹窗/内页滚动条**：弹窗是 iframe（`fast.js:143` type 2），滚动条属**内页文档**（`fastadmin.css:20-25` 的 body overflow，`html` 未设 overflow → 归属视口）→ `.skin-white` 前缀够不到；规则必须写 `html::`/`body::`/`.inside-header ::` 三个变体。内页读不到皮肤名 → 这类规则**无法按皮肤隔离**。
- 皮肤加载：`meta.html:12-13` 一次只加载一个 `skins/{adminskin}.css`；`backend.min.css` 只合并 white。**内页 `adminskin` 恒为 `''`**（白名单只在 `Index::index():37-43`，`Backend::initialize()` 不读 cookie）→ gray-light/sky-light 的 221 条 `body.inside-header` 规则是死代码；修法 = 白名单挪到 `Backend::initialize()` 开头（早于 `:173`，2026-09-23 决定不动）；登录页同样读不到。
- 表格工具栏右侧 = `.fixed-table-toolbar .columns-right` + `.search`。skin-white 易踩：`.btn{padding:6px 20px !important}`（图标按钮被撑宽）、`.pull-right.columns-right .btn{border-color:transparent !important}`。
- **尺寸类例外只有 `.btn-xs`（2026-09-28 实测定案）**：skin-white 只给 `.btn.btn-xs` 补了 `padding:1px 5px !important`（`skin-white.css:631`），`.btn.btn-sm` 无对应规则 → 吃到通用 `.btn{padding:6px 20px !important}`（`:65`）。实测（90px 操作列）：`btn-sm` 两图标按钮 51+54=105px → 换行堆叠、行高 81px、溢出去压「追加」；补 `5px 10px` 后 31+34=65px 正常；改 `btn-xs` 后 21+24=45px 正常。全站 `btn-sm` 仅 7 处（addon/config、command/add、general/config×2、system/systemgroup/fields×3），其余 4 处是带文字的「追加」按钮（大 padding 只是偏胖不破版）。
- **`fieldlist` 表格型模板的官方写法**：`app/admin/command/Crud/stubs/html/fieldlist-template.stub` = `table.fieldlist` + `<td width="90">` + `btn btn-sm btn-danger btn-remove` / `btn btn-sm btn-primary btn-dragsort` / `btn btn-sm btn-success btn-append`（上游同款）。原版基线（无皮肤）下 90px 装得下 → **这类"被撑宽"不是模板写错类名**。项目内「表格单元里的图标按钮」惯例是 `btn-xs`（`require-table.js:107` 的 `btn btn-xs btn-primary btn-dragsort`）。`require-form.js:361,413,442` 用 `container.is("table")?"tr":"dd"` 同时支持 table/dl 两种结构。
- `bootstrap.css`+`fastadmin.css` = 20 套皮肤共用基线，严禁加皮肤前缀或改品牌色。原版 18 个皮肤与 `_all-skins.css` 不要动；`skin-white.css:1141+` 的「色调统一补充」块属皮肤自己的设计层，不要提议回退或审计。
- 三个自建皮肤不含暗色主题：日后做暗色主题另建皮肤文件，不要在现有皮肤加 `.darktheme` 分支。
- 「原版皮肤判据」只指「皮肤覆盖 `.text-*` 语义色取值」，禁止泛化成「皮肤不得重绘基线组件」。用户反感「啥玩意都扯上原版皮肤」。
- 同层叠上下文「后续兄弟绘制在上」：给元素加外阴影（或 `::after` 覆盖层）指向后续兄弟区域会看不见 → 该元素加 `position: relative`（z-index: auto 即可）。
- `rgba(0,0,0,.1)` 配大 blur 的外阴影 ≠ 同色 1px 实线（边缘 alpha 只有设定值一半）。浅色分隔线用 1px 实线。
- 三皮肤把 `.btn-info` 与 `.btn-info.active` 写成同色同 `!important` → `.btn-group` 选中反馈须靠 `.btn-group:has(>.btn-info.active)>.btn-info:not(.active)` 补描边态；该规则 (0,6,0) 会连 hover 一起吃掉，须成对补 `:hover/:focus`。`:has()` 是唯一能「只命中有选中项的按钮组」的判据。改描边态会暴露 `.btn:active:focus` 的 5px 黑焦点环（`bootstrap.css:3012`）→ 同组补 `outline:none!important`；`.skin-white button{outline:none}` 只匹配 `<button>`。

## 色值基线（对齐 Chuiot simple.css，勿自创）
容器底/表头 `#f6f8fb`（弹层标题栏例外：三皮肤已统一纯白 `#fff`）；hover `#f4f4f5`；激活态 `#f0f5ff`；分割线/内容区底 `#f1f4f6`；主色/hover/浅底/浅描边 `#165dff`/`#0e48d8`/`#f0f5ff`/`#adc6ff`；正文/次级/三级 `#1d2129`/`#4e5969`/`#86909c`；描边 `#dcdfe6`；盒子布局底 `#e9edf2`；**灰底上的分割线 `#e5e6eb`**（`#f1f4f6` 只在白底可见）。
按钮语义色：primary #fff/#444c69(皮肤改 #165dff)、success #fff/#18bc9c、danger #fff/#f75444、warning #fff/#f39c12、info #fff/#1688f1(皮肤改 #165dff)、outline #fff/transparent；`*-light` 不动。

## 构建 / 模板 / 通用陷阱
- `php think min -m backend -r css`（`app/admin/command/Min.php`）。生产读 backend.min.css，改 CSS 必须重建；回退 `git checkout -- public/assets/css/backend.min.css`。不要用 `Gruntfile.js` 的 `backend:css`。改 `.html`/`.js`/`.php` 不需要重建。**本地 `APP_DEBUG=true` 时加载的是 `backend.css`（@import 各源文件），不是 min** → 改皮肤源文件即时生效；只有生产路径才依赖 min。
- 坑：`php think min` 会触发插件系统重写 `config/addons.php` 与 `public/assets/js/addons.js` → 构建后必须 `git checkout --` 回退这两个文件。
- TP6 已自动 htmlentities：写 `{$var}` 即可，加 `|htmlentities` 会转义两次。
- 页面 JS 加载：`Backend.php:204` 令 `jsname='backend/'.$controllername` → 控制器 `Foo` 必须有 `public/assets/js/backend/foo.js`。
- 行尾以 git 仓库为准（实测均 LF）；并行 Edit 同一文件会互相覆盖 → 串行改完 grep 复核。
- `$Think.config.*` 在模板里正常工作；模板配置输出看编译产物 `runtime/admin/temp/<hash>.php`。
- jQuery `.attr('checked','checked')` 不驱动 CSS `:checked`，必须 `.prop('checked', bool)`（本项目有意改进，同步时不要改回）。
- `Attachment` 的 `createtime` 读出已是字符串，`(int)` 强转得 2017 → 必须 `is_numeric($v)?(int)$v:strtotime($v)`。
- `assignconfig()` 必须放在 `Backend.php:220` 的 `assign('config', $config)` 之后，否则被静默覆盖。
- `grid` 的 `repeat(auto-fit, minmax(Npx, 1fr))` 只保证铺满、不限制列数 —— 容器越宽列数越多。要控制列数必须写死 `repeat(3, minmax(0, 1fr))` + 媒体查询降列。
- think-orm 下「独立一行 `$model->where()/order()`」是死代码（`Model::__call` 每次新建 Query），条件必须并入 `where()` 数组参数。`Backend.php:546-549` 的 selectpage dataLimit 注入即因此失效；不要照抄官方 TP5 的 `SelectPage::applyDataLimit()`。
- 模板离线渲染验证见 skill `thinkphp-template-offline-render`：`cache_path` 必须显式给（否则编译产物落到项目根）；CLI 下 `{:token_field()}` 必崩 → 换静态片段；`__()` 要手动 `Lang::load`；`build_toolbar` 需手动 `require_once app/admin/common.php`。
- 几何/样式验证低成本手法（2026-09-28 实测可用）：系统 temp 里写一页 HTML，`<link>` 直接指向项目里的 bootstrap.css / fastadmin.css / skins/*.css + font-awesome，body 加 `class="inside-header is-dialog"`，复制目标 DOM 片段；用 `msedge --headless=new --disable-gpu --allow-file-access-from-files --virtual-time-budget=2500 --dump-dom` 把 `getBoundingClientRect` 结果写进 `document.title` 再 grep。不需要登录、不需要起服务、不碰项目文件。

## 已知非本项目缺陷（勿擅自修）
- `location.reload()` 后 URL 重复 `index.php` → cookie 失效（`createCookie` 的 path = `Config.moduleurl`）。嫌疑 `fast.js:96`。
- 全站水平溢出 15px：Bootstrap `margin:-15px` 未配容器，框架级。
- 页头三次「控制台」：`layout/default.html` 的 `#ribbon` 左侧固定一个 + 右侧当前页包屑 + 顶部 addtab。

## 双项目同步（rocket-admin ↔ tp8-fastadmin）
- 上游 `E:\phpstudy_pro\extraproject\tp8-fastadmin` = TP8、`1.6.2.20260323`；本项目 = TP6、`1.6.1.20250430`。两仓 git 历史独立（无共同 commit）→ 不可 cherry-pick。
- 内核差异（照抄上游必崩）：本项目 think-orm 2.0.62 / php ≥7.4.3，上游 4.0.51 / php ≥8.0。① 无 `Db::quote()` → 主键值转义用 `'\''.addslashes((string)$v).'\''`；② `setInc()` 仅 4.x 有 → 2.0.62 用 `->inc('times')->update()`；③ `str_starts_with()` 需 PHP 8 → 用 `strpos($a,$b) === 0`。两版通用：`lock(true)`、`inc()/dec()`、`where([])`。
- 验证安全类结论必须走真实调用路径（绕过校验层直接调 ORM 会误报漏洞）。上游 selectpage 不可注入：`checkSelectpageParams()` 校验严；本项目 `selectpage()` 用正则白名单（就地内联）。
- 模板语法不同：上游 `{:config('rocket.x')}`／`{:request()->cookie('x')}`，本项目 `{$Think.config.rocket.x}`／`{:$Request.cookie.x}` → 移植必须保留上游写法，只搬语义。
- 同步方法：三方合并 `git merge-file -p ours base theirs`（base = 本项目 `HEAD:<file>`，ours = 上游工作区，theirs = 本项目工作区），再人工解冲突。上游文件行尾不统一、gray-light/sky-light 皮肤带 BOM → 按各文件原状态写入。
- 上游自身已有差异（勿当作新改动同步）：`Index.php` 用 `$msg ? $msg : ...`；`index.js` 4 处 `.attr('checked','checked')` 且缺「同步右侧布局面板开关状态」一行；`index.html` 遮罩注释、`config/rocket.php` 版本号与 `lang_switch` 缩进；无 `package.json`/`Gruntfile.js`。

## 系统升级（Upgrade）
细节见 `2026-09-23.md`。逻辑全在 `app/admin/service/UpgradeService.php`（`Upgrade.php` 是门面），批次目录 `runtime/admin/upgrade/<YmdHis>/`。
- `fetchVersionInfo()` 读 `config('rocket.upgrade.version_info_url')`（默认 `/upgrade.json`），只接受恰好 3 键 `version`/`url`/`notes`；升级包要求 `manifest.from_version === config('rocket.version')` 严格相等。
- 两个 `upgrade.json` 别混：根 `upgrade/upgrade.json` = 发布清单模板 `{version, from_version, delete, sql}`（`scripts/build_upgrade_release.php:149` 校验）；`public/upgrade.json` = 站点根版本信息 `{version, url, notes}`。
- 网络层只能用 PHP stream，**禁 curl**（本机 `curl.cainfo` 为空 → cURL error 60）。`protectedPaths` = `config/{site,database,token,rocket}.php`；`rocket.php` 含实例个性化设置，被覆盖会静默重置。
- 关闭态（`enabled=false`）只渲染空状态卡 + 日志表；`recover` 有意不校验 enabled，`check`/`run` 校验。破坏性动作已加 CSRF，`Fast.api.ajax` 不带 token → JS 必须手动带并回填。往未被测过的机制里加保护用户数据的规则前，必须补专项测试。

## 页面增强：水印 + 无操作自动登出
配置在 `config/rocket.php`，由 `Index::index()` 经 `assignconfig` 传前端。**坑全部见 `2026-09-24.md`（iframe 事件不冒泡、多标签页 localStorage、`Layer` 必须大写等），改动前先读日志。**
- 水印：服务端静态渲染，勿改回 JS。平铺块 320×180；固定 `#1d2129`+`fill-opacity=".07"`；内页不加。
- 自动登出：`auto_logout`（秒，0=关闭）。用户只要这两项增强，其余候选不要主动铺开。

## 右侧控制栏（common/control.html + index.js）
- 齿轮触发 AdminLTE `.control-sidebar`。不新增 JS 文件，JS 一律放 `index.js`；control.html 只含 DOM + 内联 `<style>`。`.lc-panel{z-index:1050}` 压过 `.main-header` 的 1030。
- 绑定靠 `[data-skin]`/`[data-config]`/`[data-layout]`/`[data-enable]`/`[data-menu]`；`my_skins` 数组决定切皮肤能 `removeClass` 哪些 class，新增皮肤必须同步加进去。
- 坑：遮罩规则必须并列同特异性选择器 + `!important`（基线 `.control-sidebar-* + .control-sidebar-bg` 是 (0,2,0)、带不透明底色）；`{include file="common/control" /}` 必须在 `.control-sidebar-bg` 之前（用 `~` 兄弟选择器）。详见 `2026-09-21.md`。
- cookie 裸名（`Config.cookie.prefix` 为空）；`Index.php:37` 白名单把 `adminskin`/`multiplenav`/`multipletab`/`show_submenu` 写回 `Config::set('rocket.*')`；`sidebar_collapse`/`layout_boxed` 模板直读 cookie。盒子布局限宽在 control.html 内联 style 重写（基线 `fastadmin.css:50` 固定 1250px、`boxed-bg.jpg` 404）。
- 已删的 3 个零效果开关（同步上游时不要加回来）：`[data-sidebarskin]`、`[data-controlsidebar]`、`[data-layout='fixed']`。

## 首页 dashboard（view/dashboard/index.html）
- 必须走**文档级滚动**，与其它内页一致：`.dash-wrap` 只留 `margin:-15px`；`.dashboard-shell{min-height:calc(100vh - 37px)}`（37px = `#ribbon` 面包屑高度）。**不要**再给 `.dash-wrap` 加 `height`/`overflow-y:auto` —— 那会让首页多出一层独立滚动，面包屑在滚动时不动，与其它内页不一致。
- 铺满只能用视口值：父容器 `height:auto` 时百分比 `min-height` 解析为 0，`min-height:100%` 在此失效。
- `.dashboard-shell{overflow:hidden}` 会形成**层叠上下文** → 盖住框架页的 TP trace（在 `.wrapper` 内、iframe 之外）。已加 `#think_page_trace{z-index:1000001 !important}`。**`!important` 必需**：本项目 `page_trace.tpl` 把 z-index 写在**元素内联 style** 里，普通 id 规则压不住（上游 tp8-fastadmin 同一模板是 `<style>` CSS 块 → 两边修法不可照搬）。**在 `.dashboard-shell` 内新增定位于视口层级的效果前，先想清楚是否又在制造层叠上下文。**
- 层级基线（先查这三个值）：弹层 `19891014`（`layer.js` config.zIndex，多弹层 +1、shade 为 `s-1`）> TP trace `999999` > 水印 `100000`（`view/index/index.html`）。抬高层级时务必确认仍低于弹层基准。
- 已知未修：iframe **内页**的 trace 恒 `999999`，而 `.main-header`(1030)/`.lc-panel`(1050) 在**父文档**里 → 父文档定位元素一律盖在普通 iframe 之上。只影响本机开发。
- 上游 `tp8-fastadmin` 有同类问题（`app/{admin,tenant}/view/dashboard/index.html:21`），2026-09-28 同批修复（含本项目没有的 tenant 后台）。上游 token 定义在 `:root`（本项目是 `.dashboard-shell` 局部作用域）。

## FastAdmin 官方版本同步
- 版本基线 `config/rocket.php:42 = 1.6.1.20250430`（不是 `config/site.php` 的 `1.0.3`）。官方 1.x 是 TP5，本项目是 TP6 移植版 → 官方补丁包不可直接套用；官方 1.6.2~1.6.4 的 SelectPage 修复在 `application/common/library/SelectPage.php`，本项目内联在 `Backend::selectpage()`。
- 2026-09-24 已按官方意图落地一批（清单见 `2026-09-24.md`）；未做项：验证码改 6 位（弱化项）、`Sms/Ems::check` 调用点加第 4 参 `true`。

## 组合数据模块（2026-09-28 新增，`system/systemgroup` + `system/systemgroupdata`）
- 本项目**新增**功能（上游 tp8-fastadmin 无此模块），方案见根目录 `COMBINATION_DATA_IMPLEMENTATION_PLAN.md`。两表：`system_group`（组定义，`fields` 存字段定义 JSON）+ `system_group_data`（组内记录，`value` 存 JSON）。
- 表单的「字段定义」用 `fieldlist` + 表格型模板 `app/admin/view/system/systemgroup/fields.html`（含 `fields.html`/`add.html`/`edit.html`/`index.html`）。模板写法取自官方 stub，不要为它改皮肤（见「样式铁律」的尺寸类例外条）。

## 参考项目：CRMEB 4.1.0 可借鉴设计（2026-09-28 调研，未落地）
`E:\phpstudy_pro\extraproject\CRMEB4.1.0\crmeb`（TP6 + Element-UI + form-builder）。本项目系统配置现状 = 单表 `fa_config` + `getTypeList()` 22 种硬编码类型 + `refreshFile()` 写回 `config/site.php` 文件，**运行时全量读文件、改配置要写盘**。
- **配置分类树**（`system_config_tab`，`pid` 自关联 + `eng_title` 英文键 + `menus_id` 关联菜单）。本项目等价物 = `config/site.php` 的 `configgroup` 一维数组 → 只能一级。
- **配置值 DB + tag 缓存**（`SystemConfigService::get` 走 `CacheService::remember('system_config_'.$key)`，按 key 分片；保存后 `CacheService::clear('crmeb')` 即刷）。本项目走文件 → 多实例/容器部署不一致。
- **表单元数据驱动**：配置项自己描述 UI（`type`/`input_type`/`parameter`「1=>男\n2=>女」/`required` JSON 存 regex+min+max/`width`/`high`/`upload_type`），`SystemConfigServices::createForm()` 据 type 自动拼 Element-UI 表单。本项目 `extend_html` 是模板自写 HTML。
- **配置联动**（`level`/`link_id`/`link_value` 三字段 + `appendControl()`）→ 单选选「微信支付」才显示对应密钥项。本项目无。
- **低代码 CRUD 生成器**（`SystemCrudServices` + `crmeb/services/crud/*`，按 `FormTypeEnum`/`SearchEnum` 生成 Controller/Model/Dao/Service/Validate/Route/Vue 页面，`NOT_CRUD_TABANAME` 白名单护住系统表）。FastAdmin 有 `php think crud`（`app/admin/command/Crud.php`），CRMEB 版额外落库 `system_crud` 表做在线管理，且有字段级权限/搜索枚举。
- 其它有价值模块：`system_event`+`system_event_data`（后台可配事件钩子）、`crontab`（Web 管计划任务，本项目 `config/console.php` 只能写代码）、`DataMigrationServices`（分页断点续跑的数据迁移基座）、`system_attachment_category`（附件分类树）、`SystemPemServices`（证书文件管理，本项目升级包已自建同类）、`system_ticket`（工单）、`lang_*` 三表（多语言词条管理）。
