# FastAdmin 双版本最终验收报告

日期：2026-11-11（Asia/Shanghai）。本报告仅依据本轮读取的工作区源码和实际执行结果；交接、根方案及旧验收报告仅用于确定范围。

## 1. 总体结论

**未满足完整验收及条件清理标准，不宣称 T00—T10 全部通过。** 已直接修复本轮复现的功能、安全边界和 ORM 兼容问题，并补齐大量真实站点、权限、持久化、回滚和多进程验证。尚有完整鼠标拖拽保存链、特殊搜索值 URL 回填专项及一次误连默认库后的元数据基线缺口，详见第 5 节。

指定根目录方案和两仓整个 docs 均保留。未提交、推送、部署；未重置、切换分支或覆盖无关修改。未恢复 filter-control 补丁、build/patches、额外 Grunt 构建机制或永久 tests 目录。

## 2. 基线、隔离与本轮操作事件

| 项目 | 实际值 |
| --- | --- |
| TP6 根目录 / HEAD | `E:/phpstudy_pro/extraproject/rocket-admin` / `b15457805c46ba9a8a559194468f3a81cd82a8a1` |
| TP8 根目录 / HEAD | `E:/phpstudy_pro/extraproject/tp8-fastadmin` / `abc9b71e55f4af9e5fa942bf2432dafe85369aea` |
| 源码边界 | 已读取两仓 AGENTS.md；以执行前 HEAD 和全部当前修改/新增为验收范围，不把它们排除为遗留修改 |
| 验证副本 | 独立临时根 `fastadmin-accept-20261111-1540` 下的 tp6 / tp8；不复制原 .env、.git、node_modules、runtime、docs、outputs |
| 数据库 | 新初始化 MySQL 8.0.12 实例，127.0.0.1:13379；`fastadmin_accept_tp6` / `fastadmin_accept_tp8` |
| Redis | 独立 Redis 7.4.7 实例，127.0.0.1:16379；Token 分别使用库 6 / 8 |
| HTTP | 127.0.0.1:18061（PHP 7.4.3）、18081（PHP 8.0.2）、18082（PHP 8.2.9，补充兼容验证） |
| 数据来源 | 只在新测试库导入根 schema 的建表语句及必要字典/权限配置，业务账号、租户、附件与业务记录均为夹具；未重导原业务库 |
| 安装/构建 | 只在副本执行 npm ci / build；Node 24.22.2，npm 11.19.1；TP8 构建 PATH 使用 PHP 8.2.9 |

### 2.1 必须披露的误连事件

本轮最初给 TP8 副本错误地使用了 TP6 的 `[DATABASE]` 环境变量格式。TP8 实际读取顶层 `DB_*`，因此第一次路由种子脚本误连默认库，在权限规则表新增了 id 9900—9904 的五条 `acceptance/index`、`selectpage`、`edit`、`import`、`weigh` 夹具；随后因查不到预期测试角色中断。此前直接 PDO 初始化的两套测试库没有误连。

发现后立即停止该脚本。已逐条核对这五条记录的 id、name、title、status，匹配后在事务中精确删除，查询确认该范围残留 **0**；未执行其他业务数据清理、角色变更、重导或重置。之后为两套副本的数据库配置加入强制主机、端口和库名断言，所有后续 CLI/HTTP 验证均经该断言。Redis 也配置为独立实例。

**限制：误写前未记录默认库权限表的 AUTO_INCREMENT 等元数据，无法证明这些元数据完全恢复。没有猜测原值、重置自增游标或宣称“从未操作业务库/完整恢复原库基线”。该流程偏差和证据缺口本身阻止无条件通过及删除资料。**

### 2.2 验证方法纠正

测试账号需要满足真实表单的 nickname、手机号等约束，遗漏时修正夹具；Query 连接测试使用明确关联字段别名；PHP 内置 HTTP 路由器修正为与实际重写入口相同的 PATH_INFO。故障注入的建触发器操作改用专用 PDO 连接，避免旧 PHP/ORM 对测试 DDL 的游标问题。这些均为隔离测试方法修正，未据此改业务逻辑。

## 3. 已复现并修复的问题

下列位置是本轮结束前当前文件中的定位；同功能已同步，版本已有正确实现的部分保留原实现。

| 严重程度 / 问题 | 证据、最小修复及版本范围 |
| --- | --- |
| P0 生产模式 API 正常响应被改为 500 | 两版 API 异常处理器在非调试模式将 HttpResponseException 当普通故障。真实登录在生产模式返回 500，调试模式正常。先返回异常自带 Response，保留其他异常的生产脱敏。TP6 `app/api/library/ApiExceptionHandle.php:28`；TP8 同文件。修复后生产会员 40 项、期限 20 项通过。 |
| P1 TP8 MySQL Token 刷新非法 SQL | 真实驱动刷新在 SELECT FOR UPDATE 后将锁状态带入 DELETE，MySQL 报 FOR UPDATE 语法错误；8 进程原先全部失败。每次操作使用 newQuery，保留事务；补齐 null 使用驱动默认期限，与 TP6 一致。`app/common/library/token/driver/Mysql.php:55`、`app/common/library/token/driver/Mysql.php:72`。修复后单次/并发刷新及碰撞回滚通过。 |
| P1 TP8 管理员编辑丢失关联角色 | 实测先删关联后，ORM4 saveAll 默认按完整联合键执行 UPDATE，未新增关联行。平台及租户新增/编辑显式 saveAll($dataset, false)，TP6 同步明确新增语义。TP6 `app/admin/controller/auth/Admin.php:168`；TP8 对应 Admin.php 和 tenant/controller/auth/TenantAdmin.php。实际控制器保存、伪造主键、唯一冲突、关联表持久化和租户归属通过。 |
| P1 平台/租户同时登录互相挤掉 | 真实 HTTP 中 tenant 登录后 admin 不再认证。文件 Session 默认按应用分目录，Cookie 却共用；仅 regenerate(true) 的 Store 探针发现不了此问题。两版 `config/session.php:14` 显式使用根 runtime/session，继续保持独立 admin/tenant 键。并存、失败登录保留另一身份、租户退出保留平台身份通过。 |
| P1 TP6 普通管理员权限查询报错 | 普通管理员登录成功，进入真实后台/退出页因旧式 IN 数组条件报 Array to string conversion。`kernel/services/AuthService.php:206` 改为当前 ORM 三元组条件；TP8 已正确，无需重复修改。 |
| P1 TP6 上传和公共 API 配置调用不兼容 | 真实 Ajax 上传触发 Config::set 参数类型错误。`app/admin/controller/Ajax.php:72` 使用数组调用；`app/api/controller/Common.php:31` 在确有 HTTP_SID 时按 session 配置组写本请求设置。TP8 已使用相应接口。 |
| P1 TP8 日志/附件时间戳写库错误 | 真实登录、实际表格附件上传出现 createtime 截断，导致请求失败。ORM4 对 bigint 默认字段类型不能仅凭 autoWriteTimestamp=int 得到整数时间。AdminLog、TenantAdminLog 显式 createtime 整数；公共 Attachment 显式 createtime/updatetime 整数，租户附件继承。`app/common/model/Attachment.php:15`。未改数据库列或全仓重写模型。 |
| P1 SelectPage 特殊字符串主键初始化丢数据 | 真实控制器 keyValue 含 & 时查询不到记录；控制器在查询前进行 HTML 编码改变了值。两版 `app/common/controller/BackendBase.php:306` 只 trim，继续由独立类校验字段/操作符、绑定 SQL、编码输出。0、引号、中文及 & 的初始化均通过。 |
| P1 非默认端口无法正常退出 | TP6 平台及 TP8 租户真实本站 POST 退出被判非法请求：Referer 主机名不带端口，request->host 默认带端口。两版平台/会员及 TP8 租户统一用 host(true) 比较，保留 Token、Referer 是否可空等原有约定。TP6 `app/admin/controller/Index.php:148`、`app/index/controller/User.php:180`。 |
| P2 租户首页默认皮肤残留黑蓝类 | 浏览器在 CSS 白色但 body class 仍为 skin-black-blue。`app/tenant/view/index/index.html:7` 改为 skin-white，保留显式皮肤选择及全部样式文件。开发/生产、小屏复验通过。 |
| 经用户确认：TP8 Token 期限统一 | 已按用户“按你的建议执行”同步 TP6：默认 30 天，支持 expire/keeptime，默认上限 30 天；0/超限/负数/错误类型在默认配置下拒绝；永久令牌须由服务端显式允许。配置、Auth 签发、API 登录/验证码登录/注册、Token 刷新及响应字段同步。`config/token.php:11`；`app/common/controller/Api.php:141`。旧 Token 记录未批量修改。核对完整调用链后确认旧 TP8 常规 Auth 硬编码 30 天，而直接驱动/config 行为不一致；并非所有旧登录都永久。 |

有限有效期是安全原则，30 天是本项目兼容决策，不是声称所有后台系统的通用安全标准；参见 [OWASP 会话生命周期与过期原则](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html#session-expiration)。

## 4. T00—T10 验收矩阵

| 任务 | 当前结论 | 当前证据 / 保留理由 |
| --- | --- | --- |
| T00 状态与范围 | 部分完成，流程偏差已披露 | 两仓规范、HEAD、全部当前改动、依赖及实际调用链核对；本轮误连事件及基线缺口见 2.1 |
| T01 附件/搜索输出 | 部分动态通过 | 平台/会员/租户附件格式化专项各 10/10；三端真实附件恶意 URL/历史实体文件名 DOM 安全通过；通用搜索转义源码确认，恶意 URL 回填的完整业务筛选专项仍缺 |
| T02 SelectPage | 本轮范围通过 | 真实 MySQL 类直接测试每版 17 项；Model/Query 连续查询、连接/别名、空权限、范围、树、OR、初始化顺序和非法字段/操作符。实际 auth.Admin、Category、双租户 TenantAdmin 覆写入口另 12 项通过；隔离业务控制器 count/list/keyValue/树/OR 也通过 |
| T03 主键/唯一/生成代码 | 本轮范围通过 | 真实通用编辑、字符串非 id 主键、自身唯一值、冲突拒绝、实际平台/租户管理员及关联表；普通及租户 CRUD 生成成功，继承可信公共处理。邮件必填保留现有规则，不擅改会员约束 |
| T04 会话重建 | 本轮范围通过 | 平台/租户真实登录及旧 ID 拒绝、记住登录自动恢复、GET/POST 退出、错误 Token/异站 Referer、并存及单端退出；修复跨应用文件存储；平台无操作真实自动退出两版通过 |
| T05 UUID/Token | 本轮范围通过 | 保留 random_bytes UUID、MySQL 唯一键与事务、Redis Lua；确定性冲突、故障注入、TTL=0/默认、8 进程单赢家、48 轮刷新/撤销竞争及有限期限参数。不是生产压力容量认证 |
| T06 API/文档/退出 | 本轮范围通过 | 公共初始化输出四项与上传两字段白名单、隔离插件配置哈希未变；API 文档命令两版成功且输出 runtime/docs；生产 API 正常响应修复；头像合法/外链/活动协议及真实退出通过 |
| T07 验证码 | 本轮范围通过，保留共用长度 | 共用 sms_captcha_length 是既有明确设计；真实发送锁/配额、错误次数、过期删除、前导 0、预检查、并发消费；API 注册/改邮箱/重置密码及真实 Web 邮箱/手机注册消费/重放通过；发送仅事件测试通道 |
| T08 组件与构建 | 大部通过，仍有 UI 验证缺口 | 两仓 npm ci、清除旧目标后 build，9 个目标新生成且等于工作区；三端 dev/prod 表格/导出/日期/Sortable 组合 90 项、移动/弹窗/存储型 XSS 12 项通过；完整鼠标拖拽保存链未通过验收 |
| T09 导入/分页/后续补丁 | 部分动态通过 | csv/xls/xlsx 真实上传→附件记录→导入、跨管理员/跨租户、非法参数、GBK、部分失败事务回滚、持久化及恢复；分页 0→20、上限 100；实际排序 HTTP 写库和越权回滚通过，但不能替代完整鼠标拖拽链 |
| T10 记录与条件清理 | 报告已保存，清理条件不满足 | 保留部分适配版本号；更新版本注释指向本报告；Grunt/原版资源/白色主题和锁文件保留；指定方案与两仓 docs 不删除 |

### 4.1 实际执行结果（分层，不把重复轮次相加成独立覆盖率）

| 层次 | 执行结果 | 证据边界 |
| --- | --- | --- |
| 语法 | TP6 当前改动 PHP/项目 JS 31 项；TP8 47 项，均通过 | TP6 PHP 7.4.3，TP8 PHP 8.0.2；含 SelectPage 新类，不把第三方全量 min 文件逐项语法当业务测试 |
| Composer 平台要求 | 两版隔离副本 check-platform-reqs --no-dev 返回 0 | 使用锁定安装依赖；不是 composer update 或全依赖漏洞认证 |
| 附件 formatter | TP6 10/10；TP8 10/10；实际租户模块适配载入 10/10 | VM 执行真实 AMD 模块的输出断言，另有真实浏览器存储型恶意值检查 |
| 核心 HTTP 工作流 | 124/124 | TP6、TP8 平台、TP8 两个独立租户；含上传附件、三格式导入、数据权限、主键、唯一、失败分支 |
| 扩展真实流程 | 24/24；实际租户编辑另 2/2；原有 SelectPage 路由另 12/12 | 平台/租户并存、实际关联表、非 id 主键、GBK、混合行失败回滚；不只检查 helper |
| SelectPage 实际 ORM | 每版 17/17 | 独立类 + 实际 MySQL，Query 初始条件和连接保留，调用方对象不污染 |
| MySQL/Redis Token 最终复验 | 28/28 | 每版每驱动 8 个进程争同一旧令牌，仅 1 成功；每组合 12 轮刷新/clear 竞争，总 48 轮，无成功撤销后复活；最终轮无死锁异常 |
| 验证码并发/配额 | 每版 Sms/Ems 各 7 项，共 28 项通过 | 每组 8 消费者只 1 成功；12 错误请求累计上限 10；8 发送竞争同地址只 1，8 地址同 IP 额度 5；预检查/过期删除均实测 |
| Token 确定性签发/故障 | 每版 5/5 | 确定 UUID 候选 + 真实唯一约束；3 次耗尽；真实触发器普通故障、外键状态码及普通 1062 文本只执行 1 次并上抛；无残留触发器/令牌 |
| 生产模式会员 API / Web 登录退出 | 40/40 | 注册、校验、改邮箱、头像保存/拒绝、Token 刷新/检查/退出、Web 登录退出、重置密码、重复消费拒绝 |
| Token 期限生产模式 | 20/20 | 默认 30 天、指定 300/120/60 秒、错误/负数/永久/超限拒绝、失败刷新保留旧令牌、服务端显式放开永久配置 |
| Web 注册验证码 | 两版邮箱/手机注册与重放共 8/8 | 真实 index/user/register，满足现有手机号等表单约束；不把失败夹具当生产问题 |
| PHP 8.2 补充运行 | TP8 会员生产流程 20/20，SelectPage 17/17 | 使用 PHP 8.2.9 实际 HTTP / ORM；不是所有第三方插件 PHP8.2 全量认证 |
| 浏览器组件 | 三端 dev/prod 合并 90/90；小屏/弹窗/存储型 XSS 12/12 | 真站点附件表格、实际 tableExport CSV、日期输入值、分页隔离/显式配置、模板 footer 恢复、Sortable 顺序、白色皮肤、资源状态；完整鼠标拖拽未完成 |
| 分页/排序/恢复 | 两版共 10/10 | 110 条额外记录实测默认 20/上限 100；真实 weigh 请求改写 DB；混入外部记录拒绝且无部分改写；原业务 fixture 行精确恢复 |
| 无操作自动退出 | 两版各 1，通过 | 实际站点定时 GET 当前表单、POST 退出后，同浏览器 Cookie 请求受保护接口被拒绝；未把只打开 GET 确认页记为退出 |
| 生成器 / API 文档 | TP6 CRUD、TP8 平台 CRUD、TP8 tenant:crud 均 Build Successed；两版 API 文档 Build Successed | 另外以实际 star 表生成，三种控制器均产生 fa fa-star，证明 Font Awesome variables.less 映射可用 |

最初并发轮曾出现 TP6 一次刷新/撤销竞争异常，已保留为先前轮失败事实；后续针对失败的诊断轮和最终 12 轮/组合无复现。数据库并发并非对任意负载下无死锁的保证，普通数据库故障仍须向上抛出，未为此新增无依据的全局重试层。

### 4.2 构建、资源与依赖

两仓 Gruntfile.js 与本地原版按内容比对，严格仅有压缩器 application→app 和移除 urlArgs/baseUrl 正则支持 CRLF 两处差异。所有构建都在隔离副本，先删除四/五个旧目标再执行 npm run build；检查日志未发现 Error/ENOENT，TP8 tenant:min 实际执行。未回填旧产物掩盖失败，也未为 PHP 修复无意义地改写 JS/CSS。

原版错误回调可能返回退出 0、coloris 空匹配、deploy 不删除 Git 历史扩展、原版 filter-control 特殊字符已知限制，均按用户决定保留；它们不是本轮新缺陷或恢复额外 Grunt 机制的理由。白色皮肤文件未改写，TP6 LESS 和 TP8 租户 body 的默认白色入口保留/纠正。

| 产物 | 字节 | SHA256 | 与工作区一致 |
| --- | ---: | --- | --- |
| tp6 `public/assets/js/require-backend.min.js` | 534629 | `d1a5664a5d77d898f2bb8e09a5e90f70423922e4718cd000546586f952a57793` | 是 |
| tp6 `public/assets/js/require-frontend.min.js` | 486292 | `2905be36e69e457beedeaad74b9c81e12f4d947f964c0855a98606cee76f2ad3` | 是 |
| tp6 `public/assets/css/backend.min.css` | 450282 | `78708e804a9c793c3b820c38bf749cad988d0cd3a27a580260cfe27a851ba82d` | 是 |
| tp6 `public/assets/css/frontend.min.css` | 399510 | `0da5bad4b05ffabb0bbf46aa9a57e48382e67020fc9470178c246d4353d5fbda` | 是 |
| tp8 `public/assets/js/require-backend.min.js` | 534495 | `9566601309dbe7e437c4bff786bf069f028b6e50aef9ff530de1cbab0d483771` | 是 |
| tp8 `public/assets/js/require-frontend.min.js` | 486158 | `7ea9bb86f34c00760a0e0e7182d7b8c05b2e7bbe5e2c3516f28de8b925a55c7c` | 是 |
| tp8 `public/assets/css/backend.min.css` | 450282 | `78708e804a9c793c3b820c38bf749cad988d0cd3a27a580260cfe27a851ba82d` | 是 |
| tp8 `public/assets/css/frontend.min.css` | 399510 | `0da5bad4b05ffabb0bbf46aa9a57e48382e67020fc9470178c246d4353d5fbda` | 是 |
| tp8 `public/assets/js/require-tenant.min.js` | 587938 | `9d254818d33c737d25ec24562bfa1006f4ddff2835af6d7f902d103a6e335ff0` | 是 |

两仓 package.json 的 dependencies、devDependencies、overrides、dists 均与指定参考包逐项一致，仅保留项目版本及 TP8 租户脚本差异；npm ci 未漂移锁文件。实际内容变更的第三方资源与本轮隔离 deploy 输出逐项归一化换行比较，无内容差异。

| 依赖/别名 | 当前锁版本 |
| --- | --- |
| `art-template` | `3.1.4` |
| `bootstrap` | `3.4.2` |
| `bootstrap-daterangepicker` | `2.1.30` |
| `bootstrap-select` | `1.13.18` |
| `bootstrap-slider` | `11.0.2` |
| `bootstrap-table` | `1.11.13` |
| `eonasdan-bootstrap-datetimepicker` | `4.17.49` |
| `fastadmin-addtabs` | `1.0.8` |
| `fastadmin-arttemplate` | `3.1.4` |
| `fastadmin-bootstraptable` | `1.11.13` |
| `fastadmin-citypicker` | `1.3.6` |
| `fastadmin-cxselect` | `1.4.2` |
| `fastadmin-dragsort` | `1.0.5` |
| `fastadmin-layer` | `3.5.6` |
| `fastadmin-nicevalidator` | `1.1.7` |
| `fastadmin-selectpage` | `1.1.1` |
| `font-awesome` | `4.7.0` |
| `jquery` | `3.7.1` |
| `jquery-slimscroll` | `1.3.8` |
| `jquery.cookie` | `1.4.1` |
| `jstree` | `3.3.17` |
| `moment` | `2.31.0` |
| `nice-validator` | `1.1.7` |
| `require-css` | `0.1.10` |
| `sortablejs` | `1.15.7` |
| `tableexport.jquery.plugin` | `1.33.0` |
| `toastr` | `2.1.4` |
| `grunt` | `1.6.3` |
| `grunt-contrib-clean` | `2.0.1` |
| `grunt-contrib-copy` | `1.0.0` |
| `jsonminify` | `0.4.2` |
| `parse-config-file` | `1.0.4` |

运行路径以各 require-*.js 的 paths/packages 为准：如 bootstrap-table 主库及 Git 历史 locale/extensions、libs/moment、libs/Sortable、libs/tableExport.jquery.plugin；锁里存在包不等于该包已内联进入页面。RequireJS、ECharts、Dropzone、AdminLTE、contextmenu、皮肤/图标等继续沿用项目跟踪资源，不借验收升级。tableExport 的 jsPDF/DOMPurify/xlsx 传递树按参考 lock 保留，不宣称所有导出类型或全部第三方依赖均完成漏洞认证。

## 5. 尚未解决的事项及边界

| 类型 | 未完成项 | 影响及下一步 |
| --- | --- | --- |
| 必需验证缺口 | 完整鼠标拖拽→项目事件→保存请求→DB 的浏览器链 | 浏览器补测在登录后等待导航阶段出现连接读取超时（os error 10060），重启本轮浏览器后重试仍未取得结果，已停止滞留的本轮探针；没有凭此认定生产根因。Sortable 实际排序、真实 weigh 请求及权限/回滚已通过，但不替代完整鼠标链。恢复可用浏览器后补测 |
| 必需验证缺口 | 通用搜索恶意 URL 回填与实际业务筛选专项 | 当前源码确认普通/范围/操作符输出转义，真页面组件加载和附件恶意值通过，但本轮未完成这一完整专项；旧报告的通过记录不替代当前动态验收 |
| 隔离流程与恢复证据 | 默认库权限表误写前的自增等元数据基线 | 五条精确夹具已删除、无该范围残留；原游标未事前记录，无法凭当前最大 ID 推测复原。需可信事前快照/数据库审计证据核对，不能擅自重置 |
| 交付风险 | 文件 Session 存储路径调整 | 新配置统一 runtime/session；原应用目录的登录态不会自动迁移，应用变更后现有用户需要重新登录。未批量删除原运行目录 |
| 范围外且未执行 | 真实短信邮件、真实云存储服务商链路、支付、部署、升级恢复、整包真实性及全依赖漏洞认证 | 未调用真实发送通道；不把这些写成通过，也不将无关认证扩大为本轮必须改造 |

本轮不能给出“全部必要工作完成”“无风险发布”或据此删除资料的结论。对保留原版组件/Grunt、固定白色皮肤、共用验证码长度、服务端分页边界、历史资源来源的取舍有明确业务/兼容依据；不会为追求文件与上游完全相同而覆盖项目功能。

## 6. 清理与保留

最终报告已先保存在 docs 外。清理只针对本轮临时根、测试服务、浏览器会话及测试实例；不删除原项目 runtime、既有 node_modules、业务测试、运行资源或原版参考包。

清理状态：独立 MySQL、Redis、三个 PHP HTTP 服务均已停止；本轮 fa-accept 浏览器会话已关闭，滞留的拖拽探针及 doctor 检测创建的子进程已精确停止。测试会员、Token、验证码、故障触发器在停库前均查询为 0。未停止其他用户服务。

临时目录清理未完成：对 `C:/Users/HYKJ/AppData/Local/Temp/fastadmin-accept-20261111-1540` 的递归删除被平台自动审批拒绝，仅返回 `blocked by policy`，未提供具体原因。删除前已核对准确绝对路径、无目录链接、报告已保存、端口和任务进程均退出；拒绝后未执行该删除。该目录目前仍含两套隔离副本、独立数据库文件、依赖、测试脚本、附件、截图及日志，需要后续清理，不得记为“全部测试产物已清除”。

以下用户指定目标因必要验收缺口继续保留：

- `E:/phpstudy_pro/extraproject/rocket-admin/FastAdmin_1.6.8_双版本修复优化执行方案.md`
- `E:/phpstudy_pro/extraproject/rocket-admin/docs` 整个目录
- `E:/phpstudy_pro/extraproject/tp8-fastadmin/docs` 整个目录

两仓 OPTIMIZATION_AUDIT.md、AGENTS.md、依赖锁、必要资源均保留。测试脚本、生成控制器/附件/截图/日志在本轮临时根中，清理后不再作为可运行入口；本报告中的结果、输入规模、失败边界、产物哈希是长期记录。

## 附录：验收结束时当前源码快照

下列哈希用于识别后续外部变化，不作为功能正确性的替代。仅列本方案当前内容差异中的 PHP/项目 JS、配置与构建输入，第三方及 min 产物已在正文按来源/产物核对。

| 仓库 / 文件 | SHA256 |
| --- | --- |
| tp6 `Gruntfile.js` | `8ac0d36de217737c88a66b506bf4baf25acc75b9d100dbfcb789f0091adb1efc` |
| tp6 `app/admin/command/Api.php` | `21bcccb823f3ce6b23a0bccfe4e8fc4beedfbb18b9e255b903950b227d3858ac` |
| tp6 `app/admin/controller/Ajax.php` | `531258e23ab16fec9578e18447c1512510629efed699b77a8b86400910ec785a` |
| tp6 `app/admin/controller/Category.php` | `8c5ff20f7a82d11459b0b7af8effd9d3c285fb3a206cf186557f910f956de8fb` |
| tp6 `app/admin/controller/Index.php` | `e78b0125c8fa8b8208b25e15fd103281093e1a111e5d8039f1d2bc2baa32e7d5` |
| tp6 `app/admin/controller/auth/Admin.php` | `243817ca595cb66a6468ea70d85bc8b7ca969cb71fba5a466182fc00cb32ec00` |
| tp6 `app/admin/controller/auth/Group.php` | `c8565f086cac3d37aee1a1418ceeb400cf19c41c54bad0d41104b8daa2e6bdb0` |
| tp6 `app/admin/controller/auth/Rule.php` | `38fbf758d0858c6965dd81b86dbae8fb16720b8555c8e5df349758e4e8e569ed` |
| tp6 `app/admin/controller/general/Config.php` | `16e7825b80603e5ff40d5d04ad01df5a569576e8bfe1dcc16c7cd6feb43dc8c0` |
| tp6 `app/admin/library/AdminAuth.php` | `5181a8d70f5f093176714d7b69763401c682bd55f76763134d6bc33d4faadd69` |
| tp6 `app/admin/library/traits/Backend.php` | `55f55a0a514c0881fe910b6d1f479e75dada1206cdbc2cf28abb72f4d3be111b` |
| tp6 `app/api/controller/Common.php` | `4d9049d7638af5fd20a1b49a2fd7cd12848e067c49afd641e5800d638bcd07e2` |
| tp6 `app/api/library/ApiExceptionHandle.php` | `e076c14c92dcb63828c1595663d8577c489c33eb4306a1f923a1d88ee808ca5b` |
| tp6 `app/common/controller/BackendBase.php` | `ed1cd41e6c6d73f453bf4e2824d75c5874fa29f1334f3cb9ee4f738aebce89a6` |
| tp6 `app/common/library/Auth.php` | `c38fc018e68141c5233ebc1dc691f021895efdd3f095d7acfb4184e57051e492` |
| tp6 `app/common/library/token/driver/Redis.php` | `f58f6aa4e14231b3e958702da44c7885e9c79c7bdf9a76f934810689a64ac225` |
| tp6 `app/index/controller/User.php` | `3a78cccc9801d3cd429637b73af553d32361bc1821731e27f62971a671e33859` |
| tp6 `config/rocket.php` | `f2c2243a0d5e16536041a88441b82a2032f8f82e8756497f4df64b80bb35b0d7` |
| tp6 `config/session.php` | `ab7cbba8c9f40ed9ca5ab09d811b9898a75fc4ec0dc53cf5e84d9cc1cf58ecd6` |
| tp6 `config/site.php` | `ff818cba0b2e6310bc43e138cd653e052c94ebe471fe2f15697bb993ebe22eea` |
| tp6 `extend/util/Random.php` | `cc55698412b0761fde5f113703ec40a174946c68e1f1336946357f9d2bf70fc0` |
| tp6 `kernel/services/AuthService.php` | `6b46d8394c3f49cee7706efac56f87eab51776613e4bf571e1b0b25236a1a359` |
| tp6 `package-lock.json` | `c5b710981ce6b02f8b80895dd9966437daf93606fcd6a7ba2c4879f9f6b8bcab` |
| tp6 `package.json` | `c50c8dc795aecd3b4685c67baca0763bee26bc7bcfdbb6ec0b3060c6ed25c682` |
| tp6 `public/assets/js/backend/general/attachment.js` | `91e6deaabf789fbff1762578316b72bf6b6195cbcf1f978d2080778e4a4a7668` |
| tp6 `public/assets/js/backend/index.js` | `00936a12efb5a446c1fd7420ae774474ee340070109830e89569dc3a5c88b1a3` |
| tp6 `public/assets/js/bootstrap-table-commonsearch.js` | `43223bb90a0ea507eb8e9acb7ac98555f9e60d533aeeed19be3f4c4e72e91431` |
| tp6 `public/assets/js/bootstrap-table-template.js` | `74b7f7086d17a6ec89d279e3df5f9a68b49d27d679e8b88e1f4cf141defdc02f` |
| tp6 `public/assets/js/frontend/user.js` | `00f4c29f941fa6f285c0634d29b3b0f5292afba2896a1d0e378133fa58ffb7e3` |
| tp6 `public/assets/js/require-table.js` | `79b3024a56f1038184ae1901b94c258a90d7f3c522b2cebb4e9332bba7946c19` |
| tp6 `public/assets/less/backend.less` | `eecee2fbc518ec4771661f3119b002357fc80e4e8d85650aa337eb8faebec71d` |
| tp6 `app/common/library/SelectPage.php` | `afed7eb12fa3ea599ee896bcd568b60099a1385cec5911e7e901c64a84a75cf9` |
| tp8 `Gruntfile.js` | `8ac0d36de217737c88a66b506bf4baf25acc75b9d100dbfcb789f0091adb1efc` |
| tp8 `app/admin/controller/Category.php` | `6b80964674d1831e08c45611ce8063377ee6b736fe30cbddee24f322acb303d9` |
| tp8 `app/admin/controller/Index.php` | `e65ddf55cc82e03611ecd0aa7125c7b47b9c952bf654195a34dc1df07a0d3a3c` |
| tp8 `app/admin/controller/auth/Admin.php` | `151df2e65da545548a564af7b3f399151b14498ff56eec2935d7472691dbaa5b` |
| tp8 `app/admin/controller/auth/Group.php` | `2b9104bc657d2364a6da7897dff36babff9e589167d203fd7bbf61c0ed2708e6` |
| tp8 `app/admin/controller/auth/Rule.php` | `b0170881380fa4dcd1361c8a294acc065b6192d2b6e0d136e01adfa5c991469c` |
| tp8 `app/admin/controller/general/Config.php` | `2c952aa3fc0b92cc440db94cf62a323c06a04e73c75a9ee9513fcb5676ffa045` |
| tp8 `app/admin/controller/tenant/Package.php` | `b01722bca5db8b30595d7f49073116ec47a3868b95c66f723f643a8997c1641e` |
| tp8 `app/admin/controller/tenant/Rule.php` | `f1ee1a16999b5e860bf9b8ca47acb81badd05cd8045b655e7046402d4974c12a` |
| tp8 `app/admin/library/AdminAuth.php` | `2a8383e22b0a41a6814b1199bbff7cbc4ba4e48e0683da4429bf252cafddae8b` |
| tp8 `app/admin/model/AdminLog.php` | `836f964653f1df2681bf8c307d408c034e5f4baf1ec9dbf8e76e3e80beac1f4f` |
| tp8 `app/api/controller/Common.php` | `1987169b990197ef1ac472f6361e1ef2467b844b5d88543dd91f81ce4f08dadd` |
| tp8 `app/api/controller/Token.php` | `81cfd22465a5297596fc4ea12fe1ee968b419c0fcbbf22eb1b63b9f962e5615b` |
| tp8 `app/api/controller/User.php` | `0992922ffea53ee382827bac7e0623c7b70e9b92658acbd016668ed95793df9c` |
| tp8 `app/api/library/ApiExceptionHandle.php` | `e076c14c92dcb63828c1595663d8577c489c33eb4306a1f923a1d88ee808ca5b` |
| tp8 `app/common/controller/Api.php` | `475c01d2e122f82f81c53666cf87126250e1c98fb97a6fd988fdb5dbab0bed84` |
| tp8 `app/common/controller/BackendBase.php` | `8fa330656eef9389937a8cdd18893d4123d5eae21989b00ab245e8269c57ba06` |
| tp8 `app/common/library/Auth.php` | `62c95d60f8c1fcd40dd8f8cca781c93f0794c3980f4ca3c60e1b458553c22719` |
| tp8 `app/common/library/token/driver/Mysql.php` | `2bef0d9a7c4d356ac0f6794c9e0872737fc27989cb545c8035be6f90d7edd634` |
| tp8 `app/common/library/token/driver/Redis.php` | `69b8923c1750ed7f4c9811688dfc1123e1aa24f617123bdbcf980d266be5f2b1` |
| tp8 `app/common/library/traits/Backend.php` | `76421ff7d9238a58e9304da5f8f7c9ac81f16dbecbeb317356275be2ae9ad909` |
| tp8 `app/common/model/Attachment.php` | `985238110afdcfa85295c3c11072f4884d279fa17d0872170be4fce573b99992` |
| tp8 `app/index/controller/User.php` | `83953dd054deee359c83df0c7198b7ed22c1ee44c215c01121aa77a488d0c0ad` |
| tp8 `app/tenant/controller/Index.php` | `91a953e8e8b6cd1167abbfaa87f947d65490acdec8f68320c7035b9afd10c127` |
| tp8 `app/tenant/controller/TenantCategory.php` | `b22f695e23a458e35031c02c36407af859d022b0af16007bb1bede8926d3912f` |
| tp8 `app/tenant/controller/auth/TenantAdmin.php` | `f0c2a72b27bd4e9279e165f51e844746aa239142a05e15d3cfbd9ab9cb07d297` |
| tp8 `app/tenant/controller/auth/TenantAuthGroup.php` | `bc0ee0f84633f049923eb0e21ddebc2b216d996b6ce4e9487e319179e454d9b1` |
| tp8 `app/tenant/controller/auth/TenantAuthRule.php` | `05a4e07193c10d5b21a0126bd0edeede665e54e4ce35785e9f1ed903b2d8d6c7` |
| tp8 `app/tenant/controller/general/TenantConfig.php` | `49fc21cbe93c2de91045fb4c751bb6e2e1f711afa9935c88d5f12543965a6948` |
| tp8 `app/tenant/library/AdminAuth.php` | `2b1526403754ba67f1e31367e0ff3d7b3b215825004de9f266ef14992f906b39` |
| tp8 `app/tenant/model/TenantAdminLog.php` | `705ed79370ad17274e2c2563e56e75f01c09de15cde51bfa4dec063699df71c5` |
| tp8 `config/rocket.php` | `c17ffff4c1edd7eb4fb96a6048801d61121672f602bee7302500b59be81160ab` |
| tp8 `config/session.php` | `ab7cbba8c9f40ed9ca5ab09d811b9898a75fc4ec0dc53cf5e84d9cc1cf58ecd6` |
| tp8 `config/site.php` | `ff818cba0b2e6310bc43e138cd653e052c94ebe471fe2f15697bb993ebe22eea` |
| tp8 `config/token.php` | `64c396ca697332b34552112b09f85d4441bf05f9831c2e489fce499d94340bef` |
| tp8 `extend/util/Random.php` | `cc55698412b0761fde5f113703ec40a174946c68e1f1336946357f9d2bf70fc0` |
| tp8 `public/assets/js/backend/general/attachment.js` | `91e6deaabf789fbff1762578316b72bf6b6195cbcf1f978d2080778e4a4a7668` |
| tp8 `public/assets/js/backend/index.js` | `09713fb9f05447a9990e603ba22d83a41a2d9cd0d2bbb8eaac6f834f0eda32d4` |
| tp8 `public/assets/js/bootstrap-table-commonsearch.js` | `43223bb90a0ea507eb8e9acb7ac98555f9e60d533aeeed19be3f4c4e72e91431` |
| tp8 `public/assets/js/bootstrap-table-template.js` | `74b7f7086d17a6ec89d279e3df5f9a68b49d27d679e8b88e1f4cf141defdc02f` |
| tp8 `public/assets/js/frontend/user.js` | `00f4c29f941fa6f285c0634d29b3b0f5292afba2896a1d0e378133fa58ffb7e3` |
| tp8 `public/assets/js/require-table.js` | `e77e4bea4f9e7c7741cc59dcbd62a06a295047b97988012109dd74a2337a205e` |
| tp8 `public/assets/js/tenant/general/tenant_attachment.js` | `0c6690a16947b86d368527c2e7ca51308b88341b51f2025293346a330885ea81` |
| tp8 `app/common/library/SelectPage.php` | `afed7eb12fa3ea599ee896bcd568b60099a1385cec5911e7e901c64a84a75cf9` |
