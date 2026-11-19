# FastAdmin 双版本修复优化只读验收报告

验收日期：2026-11-11（Asia/Shanghai）。

后续用户决策（2026-11-11）：当前业务未启用 filter-control，两仓已决定恢复原版 JS/min.js，删除专用补丁目录及构建步骤，接受原版已知限制。F08 不再作为本轮必须修复项。以下发现及总体结论保留为原验收快照，不表示对其他后续修复重新验收。

**总体结论：不通过，验收快照不具备发布条件。**

两仓完整构建可以重现，附件输出修复、可信主键绑定和 Redis Token 保护有实际验证支持；但分页补丁直接导致表格初始化失败，故障已进入两仓及 TP8 租户的生产压缩包。另有 Session 旧 ID 未失效、退出保护遗漏、验证码消费不同步等问题，不能按执行记录认定 T00—T10 全部完成。

本报告记录已完成的只读验收，不代表报告落盘时重新执行了一轮验收。报告中的行号和结论对应验收时读取的源码快照；后续执行 Agent 必须重读当前文件，再判断问题是否仍存在。

验收过程中没有修改原工作区源码、配置、锁文件或业务数据，没有提交、推送、部署。必要验证在临时隔离副本、内存数据库及独立 Redis 实例中完成。本文件是用户随后要求补充落盘的交付文档。后续执行提示词不属于本报告。

## 一、项目、基线与审查范围

| 对象 | 路径 |
| --- | --- |
| 执行方案 | `E:\phpstudy_pro\extraproject\rocket-admin\FastAdmin_1.6.8_双版本修复优化执行方案.md` |
| TP6 | `E:\phpstudy_pro\extraproject\rocket-admin` |
| TP8 | `E:\phpstudy_pro\extraproject\tp8-fastadmin` |
| 原版参考，只读 | `E:\phpstudy_pro\WWW\fastadmin1.7` |

| 仓库 | 验收基线 / 当前 HEAD | 验收时状态 |
| --- | --- | --- |
| TP6 | `b15457805c46ba9a8a559194468f3a81cd82a8a1` | 暂存区为空；存在未暂存修改及新增文件 |
| TP8 | `abc9b71e55f4af9e5fa942bf2432dafe85369aea` | 暂存区为空；存在未暂存修改及新增文件 |

已读取两仓 AGENTS.md、执行方案，并结合 Git 日志、reflog、基线文件和当前源码核查，未发现实施期间新增提交。本轮以各仓 HEAD 检查全部工作区变更，没有将方案实施改动作为“历史遗留修改”排除，也没有直接使用 origin/master 充当执行前基线。

审查包含未暂存修改、暂存区、未跟踪文件及调用方。新增 SelectPage 类、三份专项测试、构建文档、TP8 NPM 配置和锁文件、新增 locale 均已纳入；未发现删除文件。验收结束时 git diff 列出的实质改动路径为 TP6 52 个、TP8 57 个，另有新增文件；这不是初始 git status 中包含批量资源状态的条目总数。node_modules 的忽略由用户处理，不列为问题。

### 新增文件清单

TP6：

- `app/common/library/SelectPage.php`
- `docs/build.md`
- `public/assets/libs/moment/locale/am-et.js`
- `public/assets/libs/moment/locale/ps.js`
- `tests/pk-edit.test.php`
- `tests/selectpage.test.php`
- `tests/token-conflict.test.php`

TP8：上述对应文件，以及 `.npmrc`、`package.json`、`package-lock.json`。

原工作区文件哈希在隔离验证前后进行了核对，未发现本轮验收改动。报告补充落盘时，两仓 HEAD 仍与上表一致。

## 二、按严重程度排序的可操作问题

P1 表示应优先处理的功能阻断、安全或数据保护问题；P2 表示明确的正确性、同步或验证闭环缺口；P3 表示方案遗漏或交付说明错误。

### F01 / P1：分页补丁破坏插件对象，列表和附件选择无法初始化

- **触发条件：**首次调用 `Table.api.init()`。
- **实际影响：**新函数替换 `$.fn.bootstrapTable` 后，原函数的 Constructor、defaults 等属性丢失；下一行读取 Constructor.prototype 立即抛错。分页补丁标记也留在旧函数上，没有达到防重复包装的目的。
- **位置：**TP6 `public/assets/js/require-table.js:123`；TP8 同路径第 123 行；直接报错位置均为第 130 行。
- **证据：**Node 模块调用及真实浏览器均复现 `Cannot read properties of undefined (reading 'prototype')`。两仓 frontend/backend、TP8 tenant 共五个生产压缩包中的实际 table 模块也全部复现。
- **对应任务：**T08、T09。
- **最小修复方向：**保持插件公开属性和补丁标记完整，或在已有初始化扩展点处理显式分页配置；验证首次初始化、重复初始化、插件方法调用及显式 pageSize 优先级。

### F02 / P1：Session ID 更新后，旧认证 Session 仍保留

- **触发条件：**已有认证身份的 Session 再次登录，或 TP8 平台、租户共同使用 Session 时重建 ID。
- **实际影响：**旧存储记录仍存在。默认关闭 login_unique 时，旧 ID 中原有认证身份仍可能有效，不符合方案“旧 Session ID 失效”的验收要求。
- **位置：**TP6 `app/admin/library/AdminAuth.php:72、128`；TP8 `app/admin/library/AdminAuth.php:72、123`；TP8 `app/tenant/library/AdminAuth.php:90、146`。
- **证据：**六处均调用无参数 `Session::regenerate()`；当前框架默认 `$destroy=false`。隔离存储测试两版均得到：新旧 ID 不同，但旧 ID 仍读出原来的 admin、tenant 数据。TP6 框架实现见 `vendor/topthink/framework/src/think/session/Store.php:240`。
- **对应任务：**T04。
- **最小修复方向：**使用框架支持的旧 Session 销毁语义，同时保留当前内存中的其他身份；补旧 ID 重放及平台、租户并存验证。
- **验证边界：**首次匿名登录后的 ID 更新本身有效；本轮复现的是旧认证存储保留，没有执行完整 HTTP 登录重放。

### F03 / P1：退出安全流程未完成，TP8 租户 POST 缺少 Token 校验

- **触发条件：**访问两仓平台、会员退出 GET 页面；或向 TP8 租户退出发送携带有效会话的 POST。
- **实际影响：**GET 页面仍自动提交注销表单，访问退出 URL 就会推动注销。TP8 租户 POST 直接退出，没有调用自身 Token 校验。
- **位置：**TP6 `app/admin/controller/Index.php:162、163`、`app/index/controller/User.php:187、188`；TP8 `app/admin/controller/Index.php:163、164`、`app/index/controller/User.php:188、189`、`app/tenant/controller/Index.php:133、142、143`。
- **证据：**GET 分支含 `document.forms['logout_submit'].submit()`；租户 POST 分支直接执行日志、logout、事件。相关基类及中间件没有补上该请求的 Token 校验。TP8 租户 Index 的 logout 还列在 noNeedRight 中，不能将权限检查当作 CSRF 校验。
- **对应任务：**T06。
- **最小修复方向：**GET 不自动执行注销；沿用现有交互发起经过校验的 POST。租户端补自身 Token 校验，保留租户日志、事件及独立认证键。
- **验证边界：**源码已确认；没有向实际登录站点发送攻击请求。

### F04 / P1：新增主键测试会修改现有记录，恢复过程不能还原原数据

- **触发条件：**直接按构建文档运行 `tests/pk-edit.test.php`，且数据库中已有分类数据。
- **实际影响：**测试选取已有 category 记录，修改主键和 nickname；恢复时将 nickname 写成空字符串，原值未备份。测试中途失败时也缺少完整事务回滚保障。
- **位置：**两仓 `tests/pk-edit.test.php:38` 开始选取已有数据；TP6 恢复代码第 61、98 行，TP8 第 63、100 行。两仓 `docs/build.md` 将这些脚本说明为使用本地 .env 的真实数据库测试。
- **证据：**仅保存原 name，恢复使用 `'nickname' => ''`；还会按 `pk-risk-probe-%` 搜索恢复对象。主键修复用例手工复制绑定语句，没有调用真实 trait，不能证明生产绑定入口被测试覆盖。
- **对应任务：**T03、验收安全要求。
- **最小修复方向：**只操作隔离测试库中的专用 fixture，以事务或完整快照恢复；调用真实 trait，补唯一验证、关联记录及非 id 主键场景。
- **本轮处理：**没有执行这份会写现有记录的测试，改用内存数据库调用真实 trait。

### F05 / P2：导入附件归属检查依赖目标表权限开关，默认情况下会跳过

- **触发条件：**使用 CRUD 生成的公开导入入口，平台控制器保留默认 `$dataLimit=false`。
- **实际影响：**getDataLimitAdminIds() 返回 null，附件上传者比较不会执行。存在附件记录不等于属于当前导入身份，生成的新模块不能保证附件归属。
- **位置：**TP6 `app/admin/library/traits/Backend.php:458、463`、`app/common/controller/BackendBase.php:44、285`；TP8 `app/common/library/traits/Backend.php:414—425`。调用模板为两仓平台及 TP8 租户的 CRUD `stubs/mixins/import.stub`。
- **证据：**附件先按 URL 查询，只有 `is_array($adminIds)` 才比较 attachment.admin_id；默认数据权限关闭时直接跳过该比较。
- **对应任务：**T09。
- **最小修复方向：**附件归属独立于目标业务表的数据权限开关校验，明确当前上传身份及允许共享范围；租户继续保留 shop_id 约束。路径许可范围也应按当前上传配置核验，不扩大到任意 public 文件。
- **验证边界：**当前源码未找到已经展开的公开业务 import 控制器，因此这是公共实现及生成器缺口，不能表述为已经实测某个线上入口越权。POST、文件类型及 public realpath 分隔符边界检查已经存在。

### F06 / P2：TP6 会员注册未消费短信、邮件验证码，双版本不同步

- **触发条件：**将注册验证码配置为 mobile/email。
- **实际影响：**TP6 注册流程只检查验证码，不执行一次性消费；同码后续检查仍可成功，直到达到次数或过期。
- **位置：**TP6 `app/index/controller/User.php:82、84`；对照 TP8 同文件第 82、84 行已传入第四参数 true。
- **证据：**内存数据库执行真实 Sms/Ems：默认检查可重复成功；消费后再次检查被拒绝。API 的实际业务调用已使用消费参数。
- **对应任务：**T07。
- **最小修复方向：**补齐 TP6 实际注册流程的消费语义，保留 Validate 预检查不消费；覆盖前置检查后正常注册和重复消费。
- **范围：**默认 text 验证码场景不受此项影响；不能据此声称可无限重复注册，会员唯一约束仍存在。

### F07 / P2：SelectPage Query 源复用会积累旧条件，状态隔离未完成

- **触发条件：**同一 SelectPage 实例使用 Query 源连续执行不同搜索，或调用方继续使用该 Query 对象。
- **实际影响：**旧查询条件继续保留，造成错误空结果及树形结果缺失，传入的 Query 对象本身也被修改。
- **位置：**两仓 `app/common/library/SelectPage.php:374`，源码直接在 `$this->model` Query 上追加条件；count/list 调用见第 144、149 行。
- **证据：**两版实际 ORM、内存数据库中，先查 alpha 再查 beta：Model 源分别返回正确记录；Query 源第二次返回 total=0。Query 源搜索、初始化后再执行树形查询也保留旧条件。
- **对应任务：**T02。
- **最小修复方向：**保留初始 Query 状态，每次 count/list 从独立副本构建，保留已有条件、连接、字段及绑定。
- **测试缺口：**新增 `tests/selectpage.test.php` 的“同实例重复执行”仅覆盖 Model 源，未覆盖 Query 源。当前控制器每请求新建实例，未发现跨 HTTP 请求共享实例的证据。

### F08 / P2：filter-control 更新破坏去重，刷新后选项持续增加

- **触发条件：**筛选值含 &、引号或标签字符串，连续加载数据。
- **实际影响：**重复添加筛选选项，破坏原有去重行为。
- **位置：**两仓 `public/assets/libs/bootstrap-table/dist/extensions/filter-control/bootstrap-table-filter-control.js:34、37、63`；对应 min 文件及构建来源也应一并处理。
- **证据：**先把 value 编码，再与 DOM 已解码的 option.value 比较。两仓真实浏览器中，三个数据值连续加载，选项总数依次为 7、10、13，应稳定为 4。原基线采用原值比较与 DOM 属性设置，并非当前这种编码后比较。
- **对应任务：**T08。
- **最小修复方向：**按原始值去重，在输出边界安全构造 DOM；补丁必须能经过构建保留，不能回退新版核心的安全能力。
- **补充：**本次载荷未执行，但去重行为明确回归。与参考包一致不能作为此行为通过的依据。

### F09 / P2：showFooter 显式配置被覆盖，已有实现不满足方案

- **触发条件：**表格显式设置 showFooter:true。
- **实际影响：**初始化时被改成全局默认 false，汇总行不显示；切换模板视图也不能保留表格自身配置。
- **位置：**两仓 `public/assets/js/bootstrap-table-template.js:44`。
- **证据：**源码根据 templateView 将 showFooter 重新赋成全局 defaults 或 false；两仓真实浏览器均复现请求 true、实际 options 为 false。
- **对应任务：**T09。
- **最小修复方向：**保存每个表格的显式配置，模板视图仅临时隐藏，退出后恢复；补初始 true/false 及往返切换用例。

### F10 / P2：必要复制来源缺失时构建仍成功，旧资源掩盖失败

- **触发条件：**依赖安装目录缺少必要运行资源，但仓库内有旧 libs 文件。
- **实际影响：**deploy 空匹配跳过，整次构建仍成功，发布者无法发现实际沿用旧资源。
- **位置：**TP6 `Gruntfile.js:102—105`；TP8 `Gruntfile.js:101—106`。
- **证据：**隔离副本移走 node_modules/jquery/dist 后，两仓 npm run build 均退出 0；错误 AMD 入口测试则正确退出 3。
- **对应任务：**T08。
- **最小修复方向：**区分必要复制来源、仓库维护资源及明确允许为空的条目，对必要来源缺失返回失败；不要为维护历史资源而删除全部 libs 输入文件。

### F11 / P3：TP6 LESS 入口仍指向黑蓝皮肤，方案要求遗漏

- **触发条件：**后续按现有 LESS 入口编译后台样式。
- **实际影响：**会与明确的白色默认主题冲突。
- **位置：**TP6 `public/assets/less/backend.less:8` 仍引入 skin-black-blue.css；TP8 对应入口为 skin-white.css。
- **证据：**当前默认构建不编译 LESS，生产 backend.css 仍引入白色，skin-white.css 哈希也在构建前后保持一致。因此本项不代表当前生产默认皮肤已经变黑。
- **对应任务：**T08。
- **最小修复方向：**仅纠正入口，不重编译覆盖现有 CSS 定制，不另建主题体系。

### F12 / P3：交付说明与实际依赖、实施状态不一致

- **触发条件：**按文档选择运行环境，或根据完成记录判断升级范围。
- **实际影响：**容易使用不满足锁定依赖的 PHP 环境，并错误判断组件及 ORM 适配状态。
- **位置：**TP8 `docs/build.md:7、40`；两仓 `config/rocket.php:44`；执行方案执行状态及两仓 `tests/pk-edit.test.php` 的 ORM 版本注释。
- **证据：**TP8 文档写 PHP >=8.0，而锁定依赖要求至少 8.0.2；两仓版本注释仍写“组件未升级”，实际已升级。执行记录及测试注释将当前 TP8 ORM 描述为 3.x，实际 lock/vendor 均为 4.0.51。
- **对应任务：**T10。
- **最小修复方向：**按当前锁文件、运行环境和真实验证范围更正文档，保留项目版本与上游基准的区分。区分历史执行记录与后续修复结果，不把未验证改写为已通过。

## 三、T00—T10 验收表

| 任务 | 状态 | 实际位置、依据及两版同步情况 |
| --- | --- | --- |
| T00 | 已完成 | 两仓 HEAD、日志、reflog、暂存区及新增文件已核验；没有发现实施新提交；全部工作区变更纳入范围 |
| T01 | 已完成 | 两仓 backend/general/attachment.js、frontend/user.js、bootstrap-table-commonsearch.js 及 TP8 tenant/general/tenant_attachment.js 已核查；附件专项、租户适配及搜索 DOM 回填通过；完整业务页面仍受 F01 阻断 |
| T02 | 部分完成 | 两仓 app/common/library/SelectPage.php 为实质查询类；BackendBase 已委托，旧查询及 TP8 重复参数校验已移除；TP8 TenantBackend 通过 getDataLimitWhere 注入租户范围；Query 状态隔离 F07 未通过，真实双租户控制器流程未验证 |
| T03 | 部分完成 | 公共及自定义编辑入口已接可信主键；独立调用真实 trait 的主键与唯一验证通过；原有会员编辑的可信 ID/并发保护保留；F04 测试不安全且覆盖不完整，非 id 主键及关联权限持久化未验证 |
| T04 | 部分完成 | 六处 login/autologin 更新 ID 已落地；F02 旧 ID 存储未销毁，不满足验收要求 |
| T05 | 部分完成 | Random::uuid、Auth::issueToken 及 Redis set 已核查；保留 MySQL 事务及 Redis Lua rotate；独立 Redis 并发通过；实际 MySQL 冲突、事务及刷新/撤销竞争未复验 |
| T06 | 部分完成 | Common::init 持久写插件配置已移除，上传白名单及 citydata/coverdata/versiondata 保留；TP6 PHP7.4 文档生成通过；头像来源保护保留；F03 退出流程遗漏，TP8 租户缺 POST Token 校验；云上传完整流程未验证 |
| T07 | 部分完成 | Ems/Sms 的发送锁、次数、事务及消费实现保留；共用长度有现有配置注释依据，可接受为无需修改的配置决策；F06 TP6 注册调用遗漏，TP8 已消费 |
| T08 | 部分完成 | 两仓清单/lock、组件来源、构建及 TP8 租户产物已核验；白色 CSS、历史资源及图标输入保留；F01、F08、F10、F11 未完成 |
| T09 | 部分完成 | 公共导入 POST、类型、realpath 分隔符边界存在，但 F05 归属不完整；无 All 与服务端 100 上限一致；现有 expandOnHover 经 DOM-ready 链路初始化有源码依据；分页及 footer 分别受 F01、F09 影响 |
| T10 | 部分完成 | 项目版本保留部分适配标记，site.version 升至 1.0.3；构建说明已新增，但 F12 的环境、组件及验证结论需要更正 |

说明：“已完成”仅针对该任务明确列出的代码及专项验证，不表示全站业务、数据库和发布验收已经全部完成。一个仓或组件通过不代表另一仓、租户端或完整业务通过。

## 四、合理性评价

### 有依据，应保留的实现

1. SelectPage 确实提取了查询、校验、排序及结果处理，并非空类或转发包装；TP8 平台、租户共用本仓实现的方向合理。FIELD 排序采用绑定，权限由控制器服务端注入，优于拼接客户端表达式。
2. 使用 random_bytes 实现 UUID v4，保留 MySQL 事务和 Redis Lua 轮换机制，避免退回先删旧令牌再签发的流程。
3. 可信主键参与唯一验证的处理正确；原有会员编辑、余额并发保护没有被整体替换。原本已有可信 ID 保护的入口不必为形式统一再复制 helper。
4. 保留白色 CSS、弹窗、RequireJS 路由、上传白名单、项目功能及 TP8 租户架构，没有为了外观统一加入完整 LESS 重编译或新构建体系。
5. Font Awesome variables 和部分扩展作为 Git 中维护的输入资源，能够随干净检出保留，可以接受，不必全部由 NPM 重建。本次隔离构建中 786 个图标变量存在，但这不是完整 CRUD 生成验收。
6. 邮件、短信共用长度具有当前配置“短信/邮箱验证码位数”注释依据，可接受为项目设计调整；不能由此省略实际业务的一次性消费。
7. 没有 All 选项与后端最大 100 的边界一致，不应为了追随上游任意取消服务端限制。

### 缺乏行为证据的调整或完成判断

- 与参考包一致不能代替功能验收，filter-control 去重是实际反例。
- “已有实现覆盖 showFooter 修复”与当前源码和浏览器结果不符。
- “退出已有 POST 保护”没有覆盖自动提交 GET 流程及租户 Token 缺口。
- Model 重复执行通过不能代表 Query 状态隔离通过；现有测试没有覆盖后者。
- 构建成功和产物存在不能证明页面可用，也不能证明必要复制来源完整。
- 本地参考包可以用于内容比较，但未取得整包签名或完整真实性证明，不能宣称已经完成全依赖安全认证。

## 五、实际验证记录

### 5.1 环境与隔离边界

本轮实际环境：PowerShell 7.6.4、Node 24.22.2、npm 11.19.1。TP6 使用 PHP7.4.3 做语法及 API 文档生成，TP8 和独立运行探针使用 PHP8.2.9。此环境与执行记录中的 Node22/npm10 不同，不能混写。

隔离目录：`C:\Users\HYKJ\AppData\Local\Temp\fastadmin-readonly-accept-ns7dcycs`。

- tp6/tp8 子目录为验证副本；NPM 安装、资源生成、错误注入仅在副本执行。
- 副本 .env 使用禁用数据库地址，不复制原业务连接凭据用于测试。
- 数据语义验证使用内存 SQLite，加载各仓实际 ORM 和目标类；SQLite 不替代 MySQL 行锁、事务竞争或业务库验收。
- Redis 使用独立监听端口 18779，HTTP 使用独立回环端口 18769；验收结束均已关闭，浏览器已关闭。
- Redis 数据清理后剩余键为 0。未向真实用户发送短信或邮件，未操作原业务数据库。

### 5.2 结果矩阵

| 类别 | 通过 | 失败 / 未执行及边界 |
| --- | --- | --- |
| 静态语法 | 实质变更及新增文件：TP6 PHP 19、JS 33；TP8 PHP 26、JS 35，全部通过 | 语法通过不代表业务通过；不是对全 vendor 或所有仅状态变化资源逐文件运行语法检查 |
| 组件来源 | 两仓 lock 除根版本外与本地参考一致；各 25 个有内容变化的组件文件与隔离锁定安装来源一致；运行包为 bootstrap-table 1.11.13、Moment 2.31.0、Sortable 1.15.7、tableExport 1.33.0 | 未认证参考包签名及整包来源，也没有做全网最新版本或全依赖漏洞认证 |
| 附件格式化 | 两仓原版专项各 10/10；TP8 实际租户模块适配测试 10/10，其中 7 项覆盖租户附件模块、3 项仍为共用会员模块 | 未执行实际上传、选择、跨租户附件业务及云存储流程；格式化测试不能替代业务权限验收 |
| 通用搜索 | 普通、范围输入包含中文、引号及标签字符串时，DOM 回填保留文本，无额外 autofocus 属性及注入 img 节点 | 为隔离组件页验证，没有执行真实业务筛选请求及所有操作符分支 |
| SelectPage | 实际 ORM/SQLite 下 Model 搜索、初始化、范围及树形通过；限定范围的 OR 搜索未发现泄漏 | Query 连续执行串用条件，见 F07；真实控制器、关联配置及两个测试租户未验 |
| 主键/唯一验证 | 调用真实 trait 后，可信 ID 为原记录 ID；自身唯一值允许、重复值拒绝，原有 ID 集合保留，两版通过 | 非 id 主键、完整控制器及关联权限持久化未验；没有运行会改已有数据的 pk-edit.test.php |
| Session | 两版框架 Store 均确认 ID 改变 | 旧认证存储仍可读取；完整登录/自动登录、多标签及并存身份未验 |
| Token 签发 | 两版确定性注入均满足：可识别冲突第三次成功、普通存储故障一次即上抛、连续拒绝三次后失败，每次候选不同 | 替身注入不代替真实 MySQL 唯一约束或故障注入 |
| Redis 并发 | 独立实例每版 13 项通过：TTL 正值/0 冲突拒绝、原归属不变、不污染错误用户索引、轮换冲突保留旧令牌、正常轮换、旧令牌不能再刷新、撤销、8 进程刷新仅一次成功、撤销后不能顺序复活 | 未验证刷新与撤销同时竞争；不代表 MySQL 驱动并发通过 |
| 验证码 | 真实 Ems/Sms 在内存库验证前导零、错误次数累计、预检查可重复及消费后拒绝 | 未执行发送频率、真实 MySQL 行锁并发、完整注册/改绑/重置密码；未发送真实短信或邮件 |
| 完整构建 | 每仓正常构建及删除旧 min 产物后重建通过；TP8 tenant 包生成；重建产物与原工作区一致；白色皮肤文件哈希保留；Font Awesome 变量存在 | 必要复制来源缺失仍退出 0，见 F10；不能把产物相同视为功能正确 |
| 失败构建 | 错误 AMD 入口两仓均退出 3，无静默挂起 | 失败后残留旧产物不能直接作为成功交付 |
| 浏览器 | 通过隔离页面加载真实源码、组件以及五个生产包中的实际模块 | 表格初始化、filter-control 去重、显式 footer 均失败；没有完成实际站点登录和完整业务 UI 验收 |
| 数据库业务 | 只使用隔离内存数据库 | 未操作原业务库；未建立隔离 MySQL 双租户业务 fixture，因此未执行真实导入、回滚、持久化及行锁并发 |

### 5.3 命令与证据适用范围

附件专项的只读引用命令：

```powershell
node 'E:\phpstudy_pro\WWW\fastadmin1.7\tests\attachment-formatters.test.js' 'E:\phpstudy_pro\extraproject\rocket-admin\public\assets\js'
node 'E:\phpstudy_pro\WWW\fastadmin1.7\tests\attachment-formatters.test.js' 'E:\phpstudy_pro\extraproject\tp8-fastadmin\public\assets\js'
```

租户测试在临时目录复制测试载入器，仅将 backend/general/attachment.js 目标改为实际 tenant/general/tenant_attachment.js，未修改参考包或项目测试。

两仓隔离副本执行：

```text
npm ci --no-audit --no-fund
npm run build
删除隔离副本 require-*.min.js、backend.min.css、frontend.min.css 等目标产物
npm run build
```

TP8 在命令 PATH 前置 PHP8.2.9，默认 build 实际执行 build:tenant。两次正常构建后，frontend/backend 及租户 JS、CSS 与原工作区对应产物哈希一致。

失败注入也只在隔离副本执行：

- 临时移走 node_modules/jquery/dist 后运行 npm run build：两仓均退出 0，不符合必要复制来源失败要求。
- 临时把 require-backend.js 替换为不含 AMD 配置的输入后执行 grunt backend:js：两仓均退出 3。
- 注入结束恢复隔离输入；没有将任何探针改动复制回原源码。

TP6 API 命令在隔离副本使用 PHP7.4.3 执行 `php think api --controller=Demo --output=acceptance_api.html`，输出 Build Successed；该结果证明本次 tokenizer 回退在此路径可运行，不代表所有控制器文档和模板输入均已验证。

保留证据包括：

- `tp6-npm-ci.log`、`tp8-npm-ci.log`
- `tp6-build.log`、`tp8-build.log`
- `tp6-clean-build.log`、`tp8-clean-build.log`
- `tp6-missing-copy.log`、`tp8-missing-copy.log`
- `tp6-bad-entry.log`、`tp8-bad-entry.log`
- `tp6-syntax.json`、`tp8-syntax.json`
- `orm-probe.php`、`session-probe.php`、`pk-captcha-probe.php`、`redis-probe.php`、`issuance-probe.php`
- 租户附件专项及隔离 browser/search/prod 页面
- 两仓原工作区文件哈希清单

临时目录不是长期归档位置，可能被系统清理。若后续复验，应重新建立隔离环境，不能将旧探针输出当作修复后结果。

### 5.4 对执行 Agent 记录的核对

- 附件专项通过、两仓 npm ci/build 通过、TP8 租户构建入口存在，本轮能够独立重现。
- 原测试的 19/19 SelectPage 不能证明 Query 状态隔离，也不能证明平台/租户实际权限全部通过。
- 原 pk-edit 测试手工复制绑定逻辑并修改现有记录，不宜作为安全验收脚本继续使用。
- 原 token-conflict 测试不等于完整并发刷新/撤销验收，Redis 不可用时跳过也不等于通过。
- 原执行记录的“已有 showFooter 实现可覆盖”“退出保护完整”“T00—T10 全部完成”等结论与本轮源码及动态结果不一致，应按实际结果纠正。

## 六、剩余事项与发布边界

### 必须修复

- F01—F10 均为有代码或运行依据的功能、安全、数据保护及验证闭环问题；F01 是最直接的发布阻断项。
- F11、F12 属于明确方案遗漏和交付说明纠正，应在本轮收尾前完成，但不应借此重构主题或升级其他依赖。
- 修复后只复验受影响范围及发布必需场景，不自动进入无边界的“修复—全仓审查”循环。

### 发布前必须补做的验证

1. 实际登录、自动登录、旧 Session ID 重放、失败登录、平台/租户并存、多标签及退出。
2. 两个独立租户及非超级管理员的 SelectPage list、total、初始化、树形、OR 搜索、关联和编辑权限。
3. 隔离 MySQL 中 Token 碰撞、写入故障、事务回滚、并发刷新及刷新/撤销竞争。
4. 验证码发送频率、并发消费、预检查后实际注册/改绑/重置密码；仅使用事件替身或测试通道。
5. 生成后的实际导入入口：csv/xls/xlsx、编码、路径边界、附件归属、租户范围、失败回滚及准确清理。
6. 开发/生产模式下真实页面的筛选、分页记忆、表格视图切换、导出、日期组件、拖拽保存、AMD 错误及资源 404。
7. 白色皮肤在首页、iframe、弹窗、登录、菜单折叠、多标签及小屏下的关键视觉验收。
8. 普通 CRUD、图标映射及 TP8 tenant CRUD 的实际生成结果。

### 可以接受的限制

- 保留 RequireJS/Grunt、Git 中维护的历史资源及项目版本，不增加与本轮无关的抽象、构建平台或依赖升级。
- TP8 租户能力只在 TP8 保留和验证，不为形式统一给 TP6 新增租户系统。
- 本地参考包用于内容比较，整包真实性、全依赖安全认证不在本轮通过范围内。
- 无法完成的验证必须明确标记，不得用语法通过、旧产物存在、单仓结果或执行报告代替。

**发布判断：在已复现问题修复且相应验证完成前，不通过，不发布。**


## 七、后续修复与清理记录（2026-11-11）

本节补充本轮当前源码复核结果，不改写前文历史验收快照，也不将未执行项目改成通过。

### 本轮新增修复

| 项目 | 两仓修改 | 验证 |
| --- | --- | --- |
| 自动退出调用方遗漏 | backend/index.js 的无操作退出先 GET 获取当前表单及 Token，再向 logout 地址 POST；移除平台 Index::index 中无人使用的 csrfToken 生成 | 浏览器真实 jQuery/DOM 验证 POST、新 Token、会话失效及网络回退 |
| Token 异常误分类 | Auth::isTokenDuplicateError 不再凭任意 23000 或消息中的 1062 数字重试 | 明确碰撞仍有限重试；外键/普通错误只执行一次并保留异常 |
| 导入错误类型输入 | 公共 import 在 realpath/ltrim 之前要求非空字符串 | 每仓 5 个数组、数字、null、空值用例均被可控拒绝 |

上一轮修复复核：两仓浏览器各 11 项通过，包含 Table.api.init 重复调用、插件公开属性、分页记忆、显式 pageSize、footer 初始/模板隐藏/恢复及自动退出四项；内存 ORM 验证 Model/Query 连续搜索、权限范围及树形结果正常；Session 存储探针确认旧 ID 数据销毁、新 ID 保留身份。Token 签发替身每仓覆盖 5 分支。PHP7.4/8.0 对相应版本受影响 PHP 文件语法检查通过，JS 语法检查通过。

### 用户确认的组件和构建边界

- filter-control 保持原版，不恢复项目补丁或 build/patches。
- Gruntfile.js 沿用指定本地原版，仅适配 application 到 app 的压缩器路径。不保留项目自定义 VM 解析、补丁步骤及复制来源校验。
- 原版内部的历史 CSS 复制路径完整；此前修复轮遗漏 node_modules 前缀的问题随恢复原版消除。
- 隔离 npm ci 两仓通过；完整 npm run build 返回 0，但在 CRLF 配置下平台 frontend/backend JS 因 Moment packages 解析错误没有生成，因此本轮完整构建仍未通过。CSS 生成、TP8 租户 JS 生成不代表整仓通过。当前未自行添加 CRLF 兼容逻辑，未回填任何失败构建产物。

### 清理范围与保留项

按用户要求删除两仓本轮新增的三份测试脚本及空 tests 目录，移除构建文档中失效的测试运行说明；关闭临时浏览器和 HTTP 服务，并清理本会话创建的隔离副本、探针和构建测试产物。保留业务源码、必要运行资源、NPM/Composer 锁文件、构建说明及本报告。前文列出的旧临时证据路径清理后不再可访问，不得继续当作现存测试入口。

本轮没有连接业务数据库，没有发送短信/邮件，没有提交、推送或部署。真实 MySQL 并发及完整站点登录/导入验收仍属于未执行范围。构建限制解决前不能认定发布验收通过。


## 八、原版 Grunt CRLF 兼容复验（2026-11-11）

用户确认只保留 CRLF 换行兼容和 app 路径适配，其他 Grunt 逻辑保持原版。两仓实际改动与指定参考 Gruntfile.js 精确对比，仅为上述两处：压缩器 application 路径适配到 app，以及 urlArgs/baseUrl 正则的行尾由 `\n` 改为 `\r?\n`。

| 验证 | 结果 |
| --- | --- |
| LF/CRLF 入口组合 | 10 项通过，Moment packages/path 正确保留 |
| TP6 隔离 npm ci / 完整构建 | 通过；先删除 frontend/backend JS、CSS 四个旧 min 目标，再完整生成 |
| TP8 隔离 npm ci / 完整构建 | 通过；平台四个目标及 tenant JS 均生成，租户命令实际执行 |
| 错误及新鲜度 | 构建日志无 Error/ENOENT，9 个目标产物全部存在且非空，与工作区逐字节一致 |
| 依赖和皮肤 | 锁文件未变化，白色皮肤未变化，不需要覆盖当前产物 |
| 环境与边界 | Node 24.22.2、npm 11.19.1；TP8 构建 PHP8.2.9；未操作业务库、未部署 |

第七节记录的 CRLF 构建阻断已经解决。本节只更新构建结果，不代表完成此前未执行的真实数据库、完整站点或并发验收。原版错误回调保持不变，不能将未来任意构建的退出 0 单独视为通过。验证结束后清理本轮临时副本、依赖及日志，不保留永久测试脚本。
