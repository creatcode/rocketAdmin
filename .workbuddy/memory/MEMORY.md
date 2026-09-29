# rocket-admin 项目备忘

## 注释规范（2026-09-29 老板三次纠正，最终口径）
- **注释该写还是要写**，但**注释不是解释**：专业、简洁，不写一大堆。原话："注释可以加，但是注释不是解释，要专业简洁，非必要不要解释一大堆"；"不是非必要不写，非必要不要写一大堆，注释该写还是要写"。
- 判断标准：**非显而易见的地方（格式、形态、为什么这么转换）必须有短注释**；一眼能看懂的行不加；一句话说清"是什么/为什么"就够，不写"谁在用""所以调用方会怎样"，不写多行说明段，不复述代码。
- 反面教材（两次被点名重写）：① `Crud::getControllerUrl()` 里 3 行"……与框架调度、$path 权限节点、以及生成的视图/JS URL 保持一致；调用 Menu 命令时再转回斜杠形参"；② `BaseController` 里"控制器标识(点号+下划线形态：system.SystemGroup → system.system_group)，语言包/JS/权限节点共用"。
- 风格跟各自文件：`Crud.php`/`Menu.php`/`Frontend.php` 用 `//无空格`；`Backend.php`/`AdminLog.php`/`Api.php` 用 `// 有空格`。

## 硬红线（优先于本文件其它任何内容）
- **未经老板明确指令，不做任何 git 写操作**：暂存/`commit`/`push`/`reset`/`restore`/`checkout`/`rm --cached`/`stash` 全禁；只做只读查看（status/diff/log/ls-files）。"我先提交"是他自己提交，不是叫我提交或暂存。细则与事故经过见用户级 `~/.workbuddy/MEMORY.md`。
- **控制器标识那层转换（原 `controller_name()`）老板已要求还原，不要再擅自加回来**；命名口径（`systemgroup` vs `system_group`）等老板定，见文末"TP6 驼峰类名"一节。

## 本地环境
- 站点：`http://test.rocket.com`（E:/phpstudy_pro/Extensions/Nginx1.25.2/conf/vhosts/test.rocket.com_80.conf → rocket-admin，web 根 `public/`）
- 后台：`admin` / `123456`（im_admin id=1）
- 登录接口：POST `/index.php/admin/index/login`，**必须带登录页隐藏域 `__token__`**（`input[name=__token__]`），否则报"__token__不能为空"；`config/rocket.php` 里 `login_captcha=false`，无验证码。
- 数据库：`rocketadmin`，root/root，表前缀 `im_`（.env）
- 本地服务不用 file://，playwright 一律 `--browser=msedge`

## 弹窗尺寸
- **默认弹窗宽高唯一入口**：`public/assets/js/fast.js` 的 `Fast.api.open`（rocket-admin:139 / tp8-fastadmin:138，行号差 1）
  2026-09-29 从 FastAdmin 原生 `[winW>800?'800px':'95%', winH>600?'600px':'95%']` 改为视口自适应：
  `[min(vw-40,1120)+'px', min(max(vh-40,320),800)+'px']`（视口宽高各减 40，居中后四周各 20px；上限 1120x800；**下限只保高度**）
  - 2026-09-29 晚定稿：宽度的 `Math.max(vw-40,320)` 已删 —— vw<360 必然 vw<480，L203 的整屏规则会整个覆盖 `area`，那个下限纯不可达。总行数减 1;`Math.min($(window).width()-40,1120)`
- 优先级：`options.area`（含按钮 `data-area`）> `Fast.config.openArea`（项目未设）> 默认公式 > `<480px / iPad` 强制整屏（fast.js:203）
- **只改默认值这一处**，页面单独设定的尺寸（addon.js、rule.js、upgrade.js、frontend/user.js）一律不动（老板 2026-09-29 定的规矩，曾误改 addon.js 已回退）。
- 坑：layer 的 `area` 必须字符串（`['800px','600px']`），传数字会退化成内容自适应（实测 300x249）。
- `Layer.style(index, {top:0, height:视口高})` 兜底在 fast.js:195（弹窗高 > 视口高时；新公式不超视口，基本不触发）。
- **两个项目的 fast.js 唯一差异**（2026-09-29 查）：rocket-admin 在 ajax error 回调里对 `response.msg||response.message` 做了 `$('<div>').text().html()` 转义（fast.js:85-86），tp8 还是裸 `xhr.statusText`。**所以同步时不能整文件覆盖，只同步要改的那几行**。公式区（138-142）两边逐字节一致。

## 前端打包（两个 FastAdmin 项目通用）
- 加载哪个 JS：`app/admin/view/common/script.html` → `require-backend{$Request.env.app_debug?'':'.min'}.js?v={$site.version}`；APP_DEBUG=true 走源码，false 走 `.min`。
- 命令（自带 r.js，**不需要 node_modules**）：`"E:/phpstudy_pro/Extensions/php/php8.0.2nts/php.exe" think min -m all -r all -o uglify`
  只打 js 用 `-r js`。**fast.js 同时被 backend / frontend 两个包的 include 引用，必须一起打**。
- 官方文档（doc/162）：**永远不要手改 `.min.js` / `.min.css`**，打包会覆盖。仓库里旧 min 是 1.6.1 官方版，业务改动长期未重打包，2026-09-29 才首次补齐（体积 +58K）。
- 皮肤：`public/assets/css/skins/*.css` 经 `backend.css` 的 @import 合并进 `backend.min.css` → **改皮肤必须重打包 css**。
- `skin-white.css` 按钮内边距（2026-09-29 定稿）：原来有一条 `.btn{padding:6px 20px !important}` 把后台按钮整体撑宽，**老板要求删掉**（"加上更丑了，5px/10px 才是合理"）→ 已从两边删除，按钮回归 Bootstrap 默认：`.btn` 6px 12px、`.btn-sm` 5px 10px、`.btn-xs` 1px 5px。
  - 注意：文件里还留着 `.btn.btn-xs{padding:1px 5px !important}`（第 626-629 行），删掉 6px/20px 后它已纯冗余（值等于 Bootstrap 默认），可清可留。
  - fieldlist 的追加/删除/拖拽按钮就是 `.btn-sm`。
- 缓存：urlArgs 取 `config/site.php` 的 `version`（1.0.3），改了不生效就强刷或递增 version。

## 组合数据（system/systemgroup）
- **共用表单片段一律内联，不拆单独文件**（2026-09-29 老板定，两个都执行了）：`systemgroup/fields.html`、`systemgroupdata/value.html` 均已删除，内容直接写进各自的 `add.html`/`edit.html`。老板的原话逻辑："新加一个单独文件毫无意义，放表单内部不更好"。以后再遇到 add/edit 共用片段，默认内联，不要再抽 include。
- **不锁字段结构**（2026-09-29 老板拍板去掉）：组内已有数据时也允许改字段定义，只在 `edit.html` 顶部留一行黄色 alert 提示（`'Field change tips' => '修改字段标识或删除字段会导致已有记录对应值丢失,请谨慎操作'`）。控制器里的 `count()` 查询、服务端 `fieldsSignature` 守卫、`data-options`/`data-fieldlist-options` 全部删除。`systemgroup.js` 的 add/edit 保持裸 `Form.api.bindevent($("form[role=form]"))`。
  - 真正会丢值的只有「改字段标识 / 删字段」（`normalizeValue` 按当前 fields 重建 value，旧 key 被丢弃；`formFields` 按名取值 → 界面立刻读不到）；加字段是安全的。
- fieldlist 表格列宽：前几列的百分比之和要留够最后一列 —— 曾用 20/20/15/35 + `width="90"`，操作列只剩 10%（≈72px），两个 `btn-sm`（31+34px）被挤成两行。改成 20/20/15/28 + `width="110"` 后 1120 弹窗下操作列 122px、960 弹窗下 104px，实测同一行。
- `data-favisible`（require-form.js:540 起）**用不了在 fieldlist 行内**：解析正则 `^([a-z0-9\_]+)([>|<|=|\!]=?)(.*)$/i` 只吃 `[a-z0-9_]` 的顶层名再拼 `row[xxx]`，行内名是 `row[fields][0][type]` → 恒 hidden。实测过，别再试。
- fieldlist 的 `<textarea name="row[xxx]">` 必须常驻（可 hide），缺了 refresh 读不到 JSON 会静默不渲染任何行。

## 验证套路（本机）
- PowerShell stdout 不回显 → 结果 `Set-Content/Add-Content -Encoding UTF8` 落盘到 `.workbuddy/tmp/`，再用 Read 读。
- playwright-cli 的 open/goto/eval/screenshot 必须写在**同一条**命令里（会话清理）。**bash 工具可用且回显正常**，比 PowerShell 顺手。
- **playwright-cli eval 只接受单个表达式**：`a();b` 直接 SyntaxError（Unexpected token ';'），必须 `(function(){...;return x;})()`。JS 里尽量少用单引号（CLI 外层是单引号包裹）。
- 弹窗表单在 **iframe** 里：`layer.querySelector('iframe').contentDocument` 才能摸到 `.fieldlist`。
- 每次 eval 前可先 goto 重载页面，避免 layer 残留干扰测量；测量取 `document.querySelectorAll('.layui-layer')` 最后一个。
- 想验服务端分支（如 `$hasData`）又不想造数据：临时把判断改成读 `$this->request->get('probe')`，一个进程里 `?ids=1` 和 `?ids=1&probe=1` 各测一遍，完事立刻回退。
- 临时探测代码统一带 `TEMP-PROBE` 注释，收尾用 `grep -rn "TEMP-PROBE\|probe"` 确认清干净。
- 测试产物统一进 `.workbuddy/tmp/`，跑完清 `.playwright-cli/`。
- **纯 PHP 逻辑重构的回归套路（不用浏览器）**：`.workbuddy/tmp/xxx_probe.php` 里写
  `require __DIR__.'/../../vendor/autoload.php'; $app = new \think\App(); $app->initialize();`
  然后直接调 `SystemGroup::normalizeFields()` / `SystemGroupData::normalizeValue()` 这类静态方法（`__()` 的 Lang 服务已就绪，路径从 vendor 反推，脚本放哪都行）。
  把各类入参（正常/空值/非法/边界/超长）打成一个 JSON → **重构前先跑一遍存 baseline，改完再跑一遍 diff** → 只有预期的那一处差异才放行。比读代码可靠得多。
- `playwright-cli eval` 的返回值在输出**第 2 行**（第 1 行是 `### Result`），用 `| sed -n '2p'` 取；`| cat -A` 看格式。
- `playwright-cli resize <w> <h>` 可改视口（默认 1280x720）；测 layer 尺寸取 `document.querySelectorAll('.layui-layer')` 最后一个的 `style.width/height`。
- 在 bash 里 grep 带 `$(window)` 的 JS 代码**别用双引号**（`$(...)` 会被当命令替换、静默变成空）→ 用 `grep -A/-o` 或单引号，或干脆先 `sed -n` 抽片段再 diff。
- **验证生成器（`think crud`/`menu`）又不想动真库**：`mysqldump` → 建副本库 → 导入 → 改 `.env` 切库 → 跑命令 → 查副本 → 改回 → `DROP DATABASE`。
  - ⚠️ **rocket-admin 的 `.env` 是 `[DATABASE]` 分段式，键名是 `DATABASE = rocketadmin`**（tp8-fastadmin 才是 `DB_NAME =`）。我按 `DB_NAME` 写替换脚本**静默不生效**，结果生成器写进了真库 —— 切库前必须 `grep DATABASE .env` 确认键名（已踩过，别再犯）。
  - `php think menu` 会用**方法 docblock** 覆盖节点 `title`（733/735 两个标题被改写过，已手工恢复原文）；`php think crud` 会**重写控制器/视图/JS 文件**，不要随便跑。实测幂等性：节点 105→105、无重复、父节点 `system` 标题未被覆盖、产出全点号形态（`Build Successed!`）。

## TP6 驼峰类名 ↔ 下划线标识（Backend.php:120 那行补丁的永久理由）
- **TP6 支持**访问多驼峰名字的控制器：URL 一律用下划线形态（`admin/system.system_group/index`，或斜杠由 `MultiLevelControllerUrl` 中间件转点号），调度器 `Str::studly()` 末段还原成类名去找文件。本项目 `Ajax` 和 `SystemGroup` 在框架层没有任何区别。
- **TP6 不支持的是"反向回吐"**：`Request::controller()` 只给 Studly（`Request.php:1885-1889`，`controller(true)` 只 `strtolower`），没有公开 API 拿 URL 形态。框架自己要反向映射时都是私有地各自 `Str::snake`（`think-view/src/Think.php:200-207` 视图目录）或 `Str::studly`（`App::parseClass:595` 按类名找文件）。
- 症状根源在 FastAdmin：那行 `parse_name($this->request->controller(true))` 是 TP5 时代写的（先 `strtolower` 再 `parse_name`，说明当时拿到的输入本来就是小写下划线形态），TP6 契约换了它没跟上 → 驼峰边界被 `strtolower` 抹平成 `systemgroup`。
- 所以规则：**类名驼峰 + URL/JS/视图/语言包下划线**（`app/admin/command/Crud.php` 生成器口径）；只要类名有 2 个以上驼峰，就必须在 `Backend.php` 派生 `$controllername` 时补 `Str::snake`。单驼峰类名（`Systemgroup`）折叠后刚好还对，所以老代码一直没人发现。
- **2026-09-29 状态：已按老板要求全部还原** —— `controller_name()` 方法已删除，4 处调用回到 HEAD 原写法 `parse_name($this->request->controller(true))`（`app/common/controller/Backend.php:117`、`app/admin/model/AdminLog.php:62`、`app/common/controller/Api.php:112`、`app/common/controller/Frontend.php:45`），全仓残留 0，`app/common.php` 与 HEAD 完全一致。**别再自作主张抽这一层函数**。
- ⚠️ **一致性缺口（未解决，等老板定方向）**：还原后 `$controllername` = `system.systemgroup`，而磁盘上是 `system_group.js` / `system_group.php`、DB 节点是 `system/system_group/*` → 组合数据两个页面会 **JS 404 + 语言包空**、非超管节点失配（超管 `*` 掩盖）。两条收口路径：① js/语言包/DB 节点改回 `systemgroup*`（BuildAdmin 式折叠命名；但 Linux 上 `studly('systemgroup')≠SystemGroup`，除非类文件名也是 `Systemgroup.php`）② 重新引入一层 `Str::snake`（标识 = `system_group`，Windows/Linux 都成立）。
- 为什么不是中间件：值在**控制器实例化**时（`initialize()` / `AdminLog::record()`）就被消费，而 app 级中间件跑在 dispatch 之前（那时 `request->controller()` 还是空），控制器中间件又跑在实例化之后 —— 时间窗根本不存在。也别用中间件 `setController()` 改写，会干扰框架内部按类名找文件的逻辑。
- **只有两种自洽形态，不存在第三种**（决定了"跟着系统"到底跟哪个）：
  - 类文件 `Systemgroup.php` → 系统各环节期望的标识全是 `systemgroup`（URL 由 `MultiLevelControllerUrl` 的 `studly()` 还原、`parseClass` 同样 studly、`Str::snake('Systemgroup')`→`systemgroup` 视图目录）→ js/lang/节点/视图全 `systemgroup`，**Backend 一行不用改**（= git HEAD 原状）。
  - 类文件 `SystemGroup.php` → 系统各环节期望 `system_group`（视图目录 `system_group`、`ajax/lang` 收 `system_group`）→ js/lang/节点必须也是 `system_group` → **必须补那行转换**。
  - **"类文件 `SystemGroup.php` + 标识 `systemgroup`"这个组合系统不认识**，只靠 Windows 大小写不敏感活着。实测（本机）：`is_file('.../system/Systemgroup.php')` = **true**、`class_exists('...\\Systemgroup')` = **true**，尽管真实文件叫 `SystemGroup.php` —— 本地全绿是假象。Linux 上两处会真的坏：① 菜单 href `/admin/system/systemgroup` 因中间件 `is_file(studly('systemgroup').'.php')` 失败而不改写 → TP6 把 `system` 当控制器 → 404；② `/ajax/lang?controllername=system.systemgroup` 找不到类 → 该控制器 JS 语言包静默缺失。
- **实测（反射调私有 `parseTemplate`，脚本跑完即删）**：`system.SystemGroup` → `app/admin/view/system/system_group/index.html`，`general.Attachment` → `.../general/attachment/index.html` —— 驼峰必被拆成下划线。且该方法是 **`private`**，子类定义同名方法也覆盖不到（PHP 私有方法绑定定义类）→ 想改只能整类 fork 驱动再在 `View` 的 `type` 里替换（composer update 会丢），或每处 `fetch('/system/system_group/index')` 显式传模板名（`/` 开头才跳过控制器前缀）。两条都比一个全局函数贵。
- 为什么不是基类：三个控制器确实都继承 `app\BaseController`，但 `AdminLog` 是模型用不上，且 `BaseController` 是骨架文件（addons 也继承）→ 全局函数零 `use`、最小影响面。
- **"直接用 `request->controller()`（原生驼峰值）"方案的真实代价**（老板 2026-09-29 反复提，已算过）：① `Backend::loadlang()` 里那句 `parse_name($name)` 会把 `system.SystemGroup` 切成 **`system._system_group`**（实测，`auth.About` → `auth._about`）→ 语言包路径多一个下划线前缀，所以**核心照样得改一行**（这不是零改动方案）；② `$controllername` 是**所有控制器共用**的，用原生值等于全站跟着走：21 个 JS 文件、21 个语言包、**105 条 `im_auth_rule.name`** 全部要改成驼峰（`about.js`→`About.js`、`auth/about/index`→`auth/About/index`），不是只动 SystemGroup 这两个；③ 之后每次 CRUD 生成（`Crud.php` 产 `system_group.js`）都要手工返工；④ 可跑通的部分：`/ajax/lang` 的 `preg_match("/^[a-z0-9_\.]+$/i")` 允许大写、`App::parseClass` 的 studly 也命中，`MultiLevelControllerUrl` 的 `studly('SystemGroup')` 原样保留也命中。→ 结论：S 方案（驼峰文件）每一项成本都高于现状 B，唯一收益是"文件名带驼峰"。
- **CRUD 生成器对大驼峰的口径（反射调 `Crud::getParseNameData()`/`getControllerUrl()` 实测，表 `system_group`）**：
  | 命令 | 类名 | 控制器文件 | JS | 视图目录 | 语言包 | URL/节点 |
  |---|---|---|---|---|---|---|
  | `crud -t system_group` | `Group` | `controller/system/Group.php` | `backend/system/group.js` | `view/system/group/` | `lang/zh-cn/system/group.php` | `system/group` |
  | `-c SystemGroup` | `SystemGroup` | `controller/SystemGroup.php` | `backend/system_group.js` | `view/system_group/` | `lang/zh-cn/system_group.php` | `system_group` |
  | `-c system/SystemGroup` | `SystemGroup` | `controller/system/SystemGroup.php` | `backend/system/system_group.js` | `view/system/system_group/` | `lang/zh-cn/system/system_group.php` | `system/system_group` |
  → **类名大驼峰，JS/视图/语言包/URL 一律下划线**（`Crud.php:511` 与 `:520` 用 `parse_name($类名段, 0)`，`getControllerUrl()` `:1366` `strtolower`）。本项目的布局 = 第三种（`-c system/SystemGroup`）；运行时派生用的就是**同一个 `parse_name`**，所以算出的 jsname/lang/节点名与生成器写出的文件一一对应（只要类名是多驼峰，就必须逐段 `parse_name` 而不是整体 `strtolower`）。
  → **2026-09-29 已把生成器同步为点号形态**：`Crud::getControllerUrl()`（`app/admin/command/Crud.php:1354`）恒返回点号，删掉了 TP5 时代"父级同名控制器存在则改点号"的分支；`Menu.php:296` 的节点名由 `implode('/', $controllerNameArr)` 改为 `implode('.', ...)`（`Menu` 的 `--controller` 形参仍是斜杠做层级解析，`Crud` 调用处已 `str_replace('.','/')`）。实测口径：`system/SystemGroup` → 类名 `SystemGroup` / 文件 `SystemGroup.php` / JS `system/system_group.js` / 视图 `view/system/system_group/` / 语言包 `system/system_group.php` / **URL·节点 `system.system_group`** —— 与磁盘真实文件、库里节点名三者完全一致。`MultiLevelControllerUrl` 中间件因此已删除。
- **2026-09-29 定案（老板"项目适配内核 + 中间件多余"）**：这条缝要按内核形态补掉 —— ① `$path` 改点号（`$controllername . '/' . $action`，与库里 93 条点号节点一致，顺带修掉"非超管鉴权/面包屑失效"这个既有 bug）② 38 处模板 `$auth->check('auth/admin/edit')` 改点号 ③ JS 硬编码斜杠 URL（`require-form.js:265` 的 `general/attachment/select`、`backend/general/attachment.js` 的 `auth/admin/index`/`user/user/index`、`general/config/index.html:226` 的 `remote(general/config/check)`）改点号 ④ 12 条 `system/*` 节点改点号 → 然后 `MultiLevelControllerUrl` 即可删除。**注意 `jsname`/`loadlang` 仍必须 `str_replace('.','/')`**（那是文件目录，不是 URL/节点）。
- **2026-09-29 定案并落地（老板口径："类名是源头，视图/js 去适配类名；类名保持大驼峰"）**：
  - 类名/类文件**保持大驼峰**：`app/admin/controller/system/SystemGroup.php`（`class SystemGroup`）、`SystemGroupData.php`。**永远不要为了标识形态去改类名或类文件名**（老板明确否决两次："为什么老是想着改类名,听不懂我想要大驼峰"）。
  - 资源名由类名按**框架同一条规则 `Str::snake`** 派生：视图目录 `view/system/system_group/`（think-view 自己算）、JS `backend/system/system_group.js`、语言包 `lang/zh-cn/system/system_group.php`、权限节点 `system.system_group[/action]`（点号+下划线）。
  - **派生只在一处**（2026-09-29 定稿，照 BuildAdmin 的位置放）：`app/BaseController.php:61` 构造函数里 `$this->request->controllerPath = implode('.', array_map([Str::class,'snake'], explode('.', $this->request->controller())));`（+ `use think\helper\Str;`）；属性在 `app/Request.php` 声明 `public $controllerPath = '';`（`provider.php` 已把 `think\Request` 绑到 `app\Request`，实测 `get_class(request())` = `app\Request`）。读取方一律读属性：`Backend`/`Api`/`Frontend`/`TenantBackend`、`AdminLog`/`TenantAdminLog` 模型、`app/admin/common.php` 的 build_toolbar/build_heading；`app/tenant/common.php` 两处要斜杠形态则 `str_replace('.', '/', request()->controllerPath)`。**不要再在各处内联表达式，也不要抽全局函数/helper（老板否决 `controller_name()`）**；全仓 `controller(true)` 归零。
  - **还有 2 处容易漏的同类派生在 `app/admin/common.php`**（`build_toolbar()` `:109` 与 `build_heading()` `:183`）：原来是 `str_replace('.','/', strtolower(...))` / `parse_name(...)`（会算出 `auth/admin` 或 `auth/_rule`，都匹配不到点号节点，导致工具栏按钮权限判定与页面标题块失效）。已统一改为 `implode('.', array_map([Str::class,'snake'], explode('.', ...)))`；`build_toolbar` 里拼导入模板名那处同时把点号也映射成下划线（`str_replace(['/', '.'], '_', $controller)`）。**改完 `panel-lead` 标题块才真正渲染出来**。
  - **tp8-fastadmin 已按同一套同步**（2026-09-29）：6 个派生点（Backend/AdminLog/Api/Frontend/TenantBackend/tenant model TenantAdminLog）+ `app/admin/common.php` 两处 + `app/tenant/common.php` 两处（`build_toolbar`/`build_heading`，后者原本算出 `auth/_tenant_admin`）+ 生成器（Crud/Menu）+ 21 个模板 62 处 `$auth->check` + 3 个 JS 文件 + 删中间件 + 重打包。tp8 的 tenant 模块有**独立权限表 `im_tenant_auth_rule`**（42 条里 39 条是斜杠+旧名，如 `auth/group`、`auth/manystore`），靠 `AdminAuth::getRuleNameAliases()` 做新→旧别名映射（会把输入点号归一成斜杠，所以点号兼容）→ **该表与别名映射本身是一套待清理的陈旧数据，本次未动**。
  - 验证：组合数据两页 + `auth.admin`/`general.attachment`/`user.user`/`index` 全 200；`system_group.js` 200；`ajax/lang?controllername=system.system_group` 正常；节点断言：20 个后台控制器仅剩 6 条本来就不存在的节点（`ajax`、`index`、`system.about/index`、`system.upgrade/index`）。
- **踩过的坑（别再犯）**：① `sed -i` 在 Git Bash 下会改换行符 → 整个文件 diff，批量替换模板必须用 PHP `file_get_contents`/`file_put_contents`；② 用 `REPLACE()` 链改节点名时**必须先长后短**（`..._group_data` 在前），否则 `system.system_group` 会先吃掉前缀拼出 `system_groupdata`；③ 折叠标识（`systemgroup`）在 FastAdmin 里必须连**类文件名**一起折叠才自洽（`studly('systemgroup')='Systemgroup'` 要能命中文物），而老板要求类名/类文件保持大驼峰 → 结论：标识只能是 snake 形态。
- 依据：`im_auth_rule` 里子目录控制器的节点本来就是**点号**（`auth.admin`、`auth.admin/index`、`general.config/index`），而 `AuthService::check()`（`kernel/services/AuthService.php:89-134`）是 `strtolower` 后**精确字符串比较、没有任何点斜归一化** → 斜杠 `$path` 根本匹配不上，超管靠 `*` 短路（:85）所以一直没暴露。
- **坑：Windows 上"只改大小写"的重命名，git 索引会保留旧名（Linux 上致命）**。2026-09-29 实测：磁盘是 `app/admin/controller/system/SystemGroup.php`，`git ls-files` 却仍是 `Systemgroup.php`（`core.ignorecase=true`）→ 照此提交，Linux checkout 出来就是 `Systemgroup.php`，类加载不到直接 404。修法：`git rm --cached <旧名>` + `git add <新名>`，再用 `git ls-files` 复核。任何 case-only 重命名都要走这步。
- **参考实现对照：BuildAdmin（TP6 的 v1 / TP8 的 master）** —— 类名多驼峰（`app/admin/controller/user/MoneyLog.php`），标识用框架属性 `request->controllerPath`，语言包文件就叫 `lang/zh-cn/user/moneylog.php`（全小写、**不插下划线**），权限节点 `user/moneylog/index`。它这么干不出事，是因为 **Vue SPA 没有服务端视图、加载语言包是直接按路径 load、不反查类**；FastAdmin 有 `Ajax::lang` 的 `parseClass/class_exists` 和中间件的 `is_file(studly(...).'.php')` 两处反查 → 标识必须满足 `studly(标识) == 类名`，所以这边只能是 `system_group`。
- **BuildAdmin 的 `controllerPath` 到底是哪来的（2026-09-29 查到源头，别再猜）**：`app/BaseController.php` 的**构造函数**里赋值——
  ```php
  $this->request->controllerPath = str_replace('.', '/', $this->request->controller(true));
  ```
  即"算一次 + 挂成 request 的动态属性"，之后 `Api::initialize()` 的语言包、权限节点都读 `$this->app->request->controllerPath`。它的形态 = `controller(true)` 即**小写折叠**（`user/MoneyLog` → `user/moneylog`，所以它语言包文件就叫 `moneylog.php`）。**不是** TP 提供的属性：TP6.0.0/6.1.2/8.0 的 `think\Request` 都没有 `controllerPath`（vendor 全量 grep = 0），它自己的 `app/Request.php` 也是空壳 `extends think\Request`（本项目两个项目的 `app/Request.php` + `provider.php` 绑定结构跟它一模一样）。
  → 结论：**这一步映射谁都省不掉，差别只在"放哪、什么形态"**。要照搬它的做法：在基类构造函数算一次挂 `$request->controllerPath`（建议同时在 `app/Request.php` 里显式声明该属性，避免 PHP 8.2 动态属性弃用），读取方改成读属性 + 兜底。当前我们用的是"各派生点内联 `Str::snake` 表达式"（10 处）。



- **视图目录是硬约束（但只有它一个）**：jsname / 语言包 / 权限节点三处改成驼峰都跑得通（改名 + 改 DB 即可），但**视图目录改不了** —— `think-view/src/Think.php` 里 `Str::snake(控制器末段)` 是硬编码，`config/view.php` 的 `auto_rule` 只管 action（`view/system/systemgroup` → `system_group` 这次改名本身就是实证）。想绕开只能在每个多驼峰控制器里 `fetch('/system/system_group/index')` 显式传模板（`/` 开头才跳过控制器前缀）→ 每个 `index/add/edit` 都要覆盖，比一个全局函数贵得多。另外还有三条隐性代价：跟 `Crud.php` 生成器产出的下划线命名对着干（每次生成都要返工）、Windows 大小写不敏感 / Linux 敏感（文件名带大写本地测不出、线上才 404）、菜单 URL 变成 `/admin/system/SystemGroup`。



