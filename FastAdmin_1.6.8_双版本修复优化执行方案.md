# FastAdmin 1.6.8 双版本修复优化执行方案

核查日期：2026-11-10。本文基于核查时的当前源码、锁文件、本地参考包及官方仓库。

## 0. 执行状态（2026-11-10 实施轮）

本节登记实际实施结果；原方案正文的比较结论以当时源码为准，实施时发现的差异已在各条目注明。

| 任务 | 状态 | 实际结果与理由 |
| --- | --- | --- |
| T00 | 已完成 | 两仓起始工作区干净（0 个未提交修改）；全程未回滚、未提交、未推送 |
| T01 | 已完成 | 两仓及 TP8 租户附件模块采用上游 safeurl/text/legacytext/search 输出修复，suffix 单参数编码，历史编码文件名显示一次；commonsearch 回填转义（operate、范围、普通值）补齐；上游专项测试两仓均 10/10 通过 |
| T02 | 已完成 | 两仓分别新增 `app/common/library/SelectPage.php`（Model/Query 双源、字段白名单、操作符白名单、FIELD 排序参数绑定、数据范围条件由控制器注入且 count/list 分别应用）；控制器委托入口覆盖全部覆写；真实 DB 直接测试两仓均 19/19 通过 |
| T03 | 已完成 | `bindTrustedPrimaryKey` 落入两仓公共 trait 并接入 12 处自定义编辑入口（含 TP8 租户）；实测确认 TP6 think-orm 2.0.62 存在客户端主键改写风险、TP8 think-orm 4.0.51 默认排除主键；修复语义测试通过；会员邮箱必填后端约束两版已存在，无需修改 |
| T04 | 已完成 | TP6/TP8 平台与 TP8 租户共 6 处 login/autologin 在写入认证态前 `Session::regenerate()` |
| T05 | 已完成 | UUID v4 改用 `random_bytes`（版本位/variant 保持）；Redis 驱动 set 原子拒绝已存在键（TTL=0 与正值均不覆盖）；Auth 签发改 `issueToken`（唯一约束冲突有限重试、其余故障上抛）；两库 `user_token` 已有主键约束，无结构变更；冲突注入测试 TP6 11/11、TP8 5/5（Redis 无认证跳过） |
| T06 | 已完成 | 两仓 API init 移除 `set_addon_config` 副作用（保留 citydata/coverdata/versiondata 与上传白名单输出）；TP6 Api 命令补 PHP 7.4 `token_get_all` 兼容路径并实测 `php think api` 构建成功；头像限制、退出流程（POST+Referer+Token）保留现有实现；dispatch_jump 本项目不存在，无需处理 |
| T07 | 无需修改 | 短信/邮箱验证码位数共用 `rocket.sms_captcha_length` 为项目配置注释显式声明的设计，保留；发送额度锁、事务校验、一次性消费逻辑完整 |
| T08 | 已完成 | 两仓 `.npmrc` 改 HTTPS 并移除 strict-ssl=false；TP6 lock 265 个 resolved 全部切 HTTPS（版本/integrity 未变）；TP8 新增 package.json/package-lock.json（与 TP6 一致，根版本 1.6.2）；dists 排除 filter-control 补丁并新增 font-awesome `less/variables.less`；移除无来源的 dists.coloris；两仓 Gruntfile 正则不匹配时明确失败；TP8 `npm run build` 串接 `tenant:min`；两仓干净 `npm ci`+两次 `npm run build` 通过，filter-control 补丁哈希不变，缺失入口非零退出（exit 3） |
| T09 | 已完成 | 导入补 POST 校验、realpath 目录分隔符边界、附件记录归属（平台按管理员数据范围、租户由 TenantAttachment 基础查询限定 shop_id），TP8 保留原有边界判断不再重复实现；分页记忆改为按表格 index_url 隔离并继承旧全局 pagesize，显式 pageSize 优先，钩子防重复包裹；pageList 无 All 与服务端 100 上限契约一致；expandOnHover/showFooter 均有项目自有实现覆盖，无需迁移 |
| T10 | 已完成 | 两仓 rocket.php 版本登记为部分适配（1.6.1.20250430.1 / 1.6.2.20260323.1）；site.php 缓存版本 1.0.2→1.0.3；两仓新增 `docs/build.md` 构建说明 |

遗留事项：组件版本未随官方 1.6.8 更新（bootstrap-table 1.11.13、Moment 2.31.0 等候选未实施，属独立决策）；浏览器端完整回归（登录/导入/分页记忆的真实浏览器验收）与并发业务验收未执行；本地参考包与官网下载日志的真实性审计仍属未完成项。

## 0.1 组件升级轮（2026-11-11）

按"组件与官方参考包保持同步（含新增/移除/版本）"的决策执行，以上游参考包 `package.json`/`package-lock.json` 为唯一基准：

| 项目 | 结果 |
| --- | --- |
| 依赖清单 | 两仓 `package.json` 声明逐字段对齐官方（grunt `^1.5.3`、moment `^2.31.0`、sortablejs `^1.12.0`、bootstraptable `^1.11.12`）；TP8 保留租户构建脚本差异 |
| lock | 两仓采用官方 `package-lock.json`（仅根版本号适配 1.6.1/1.6.2），固化 grunt 1.6.3、moment 2.31.0、sortablejs 1.15.7、bootstraptable 1.11.13、tableexport 1.33.0（jsPDF 4.2.1/DOMPurify 3.4.17 随官方） |
| dists | 恢复官方 `coloris` 条目（官方保留的死条目，deploy 空匹配跳过）；移除 font-awesome `less/variables.less` 条目（该文件由仓库历史提交维护，deploy 不删除，功能不受影响）；bootstraptable 恢复官方 `dist/**`（无排除） |
| 关键更正 1 | 上一轮报告的"filter-control 项目补丁"实为行尾符（CRLF/LF）差异误判——归一化后与 1.11.12 发行包内容一致，不存在内容级补丁；`docs/build.md` 已更正 |
| 关键更正 2 | 官方 fork 1.11.13 发布物裁剪了全部 locale/extensions，属不完整发布，但官方 lock 即固化 1.11.13（其 filter-control 为官方 escapeHTML XSS 修复版，配套主库新增 `utils.escapeHTML` 导出）；本项目与官方机制一致——deploy 仅覆盖不删除，libs 的 locale/extensions 沿用仓库历史提交文件，主库+filter-control 为 1.11.13 官方修复版，配套自洽 |
| 验证 | 两仓干净 `npm ci`（官方 lock）+ 两次 `npm run build`（TP8 含 tenant:min）通过；产物核验：主库 escapeHTML 导出 ✓、filter-control 修复 ✓、zh-CN locale 与 export extension 保留 ✓、moment 2.31.0/Sortable 1.15.7 部署 ✓、压缩包 safeurl/分页钩子修复保留 ✓ |
| 未做 | 浏览器端功能回归（表格导出、筛选下拉、日期选择、拖拽排序的 UI 验收）仍需人工执行；官方之外自行业升级的组件不存在 |

## 0.2 验收问题修复轮（2026-11-11）

依据 `docs/FastAdmin_双版本修复优化验收报告.md`（历史快照，不改写）逐项复核当前源码后修复。上两节为历史记录，本节登记该修复轮结果；filter-control 的后续用户决策见第 0.3 节。

| 问题 | 状态 | 实际修改与位置 |
| --- | --- | --- |
| F01 | 已修复 | 两仓 `public/assets/js/require-table.js`：替换插件入口时复制 Constructor/defaults/columnDefaults/locales/methods/utils 等公开属性，补丁标记写到新入口，重复 init 不再二次包裹；显式 pageSize、按 `index_url` 隔离的分页记忆、旧 `pagesize` 迁移保持原语义 |
| F02 | 已修复 | 六处 login/autologin 改 `Session::regenerate(true)`：TP6 admins `app/admin/library/AdminAuth.php:72、128`；TP8 平台 `:72、123`；TP8 租户 `app/tenant/library/AdminAuth.php:90、146`。旧会话存储销毁，内存身份随新 ID 重写 |
| F03 | 已修复 | 两仓平台/会员退出 GET 不再自动提交，改渲染需点击确认的 POST 表单（`token_field()`）；TP8 租户 POST 补 Referer 同源与 `$this->token()` 校验，保留租户日志/事件/认证键；会员退出原用的 `token()`（输出裸 token 而非表单字段）一并改为 `token_field()` |
| F04 | 已修复 | 两仓 `tests/pk-edit.test.php` 重写：库名必须含 test，只在隔离库创建专用 fixture 表，事务写入并回滚，调用生产 trait 的真实 `bindTrustedPrimaryKey`，覆盖伪造主键、自身唯一值、冲突值、非 id 主键、关联记录；结束校验无残留并删表 |
| F05 | 已修复（源码） | 两仓 import 归属判定与目标表 `dataLimit` 解耦：非超级管理员固定比对“本人或被管理子管理员上传”，并校验物理文件等于附件记录 URL 的 realpath；租户仍由 TenantAttachment 基础查询限定 shop_id |
| F06 | 已修复 | TP6 `app/index/controller/User.php` 注册流程 `Sms::check`/`Ems::check` 补第 4 参数 true，与 TP8、API 一致；Validate 预检查仍不消费 |
| F07 | 已修复 | 两仓 `app/common/library/SelectPage.php::buildQuery`：Query 源每次 `clone` 后构建，count/list 与连续执行不积累条件，也不修改调用方对象；Model 源保持每次新 Query |
| F08 | 按用户决定撤回补丁 | 两仓 filter-control JS/min.js 恢复原版，移除 build/patches 及 Grunt patch 步骤；当前业务未启用该扩展，接受原版已知限制，不再列为本轮必须修复项；见第 0.3 节 |
| F09 | 已修复 | 两仓 `public/assets/js/bootstrap-table-template.js`：首次 initBody 记录表格自身 showHeader/showFooter，模板视图仅临时置 false，切回恢复 |
| F10 | 已修复 | 两仓 `Gruntfile.js`：逐条校验 dists 声明的必要来源与历史复制项，缺失即 `grunt.fail.warn` 非零退出；`coloris`、`bootstrap-slider:*.css/js`、`toastr:*.css` 为明确允许空匹配；AMD 配置不匹配仍报错退出 |
| F11 | 已修复（仅 TP6） | `public/assets/less/backend.less` 皮肤入口改 `skin-white.css`，不重编译 LESS、不动现有 CSS 定制 |
| F12 | 已修复 | `docs/build.md` 两仓：补丁机制、失败闭环、隔离测试库用法、PHP/ORM 锁定版本；TP8 最低 PHP 更正为 8.0.2（easywechat 6.20.0 / symfony/http-foundation 6.0.20 要求），ORM 更正为 think-orm 4.0.51；`config/rocket.php` 版本注记与号值、`config/site.php` 缓存版本 1.0.4 同步更新 |

本轮验证（2026-11-11）：PHP 语法两仓全部通过（TP6 php7.4.3、TP8 php8.0.2）；`tests/pk-edit.test.php` 两仓 12/12（隔离库 rocketadmin_test / rocktp8_test，事务回滚、fixture 表删除）；`tests/selectpage.test.php` 两仓 20/20（新增 Query 源连续搜索、调用方条件保留、树形、两套 shop_id 范围、OR 不跨范围）；`tests/token-conflict.test.php` TP6 11/11（Redis 原子拒绝与 MySQL 唯一约束）；会话重建语义探针两仓 6/6（旧 ID 存储销毁、内存身份保留、新 ID 重写）；隔离副本删旧产物后 `npm run build` 两仓通过且二次构建哈希一致，TP8 含 `tenant:min`；浏览器（Chromium）两仓源码页各 25 项、平台生产包各 9 项、TP8 租户生产包 9 项全部通过（F01/F08/F09）。

未执行/边界：F05 没有已展开的公开导入控制器，只做源码与生成模板路径核对，未构造真实导入请求；F02/F03 未做真实 HTTP 登录—退出全链路（无测试账号与独立站点）；MySQL 行锁并发刷新、刷新与撤销竞争、验证码并发消费本轮未复测；浏览器验收为隔离页面 + 生产包，不是完整后台业务 UI。

## 0.3 filter-control 保持原版（2026-11-11 用户决策）

当前两仓业务及默认 RequireJS 入口未启用 filter-control。按用户明确要求，两仓 JS/min.js 保持与本地原版及锁定 NPM 包一致，撤回该扩展的项目定制，删除仅用于它的 `build/patches` 目录、Grunt copy.patch/patch 任务及对应构建文档步骤。

原版的特殊字符选项重复、开启 hideUnusedSelectOptions 时单引号选择器异常属于已知限制，接受其上游行为，不再把 F08 作为本轮必须修复项。此前补丁轮及测试结果只代表当时状态，不构成继续维护补丁的要求。其他独立修复、构建失败处理、依赖版本及 TP8 租户构建保持不变。

## 0.4 后续复核、原版构建约束及清理（2026-11-11）

本轮直接核对当前源码后，同步两仓修复以下遗漏：

- 无操作自动退出：原有定时器仍只访问 GET 退出页，改为先获取当前确认表单与 Token，再向实际退出地址提交 POST；保留失效会话和网络失败的回退路径。移除平台首页无人使用且会改写 Session 的 csrfToken 生成。
- Token 异常识别：不再把任意 SQLSTATE 23000 或消息中的 1062 数字判为令牌碰撞，仅识别明确的 Duplicate entry，其余故障立即抛出。
- 导入参数：file 必须是非空字符串，数组/数字等在路径处理前返回参数错误。

按用户“Grunt 跟原版保持一致，不要乱改乱加”的新要求，两仓 Gruntfile.js 恢复本地原版逻辑，仅保留 application 到 app 的压缩器路径适配；撤回此前的 VM 解析、必要来源校验及项目补丁步骤。本轮发现的历史复制路径遗漏随恢复原版实现消除，不另建复制逻辑。第 0.2 节 F10 的自定义失败校验不再代表当前实现。

实际验证：两仓各 11 项浏览器检查通过（重复初始化、分页记忆与显式页大小、footer 模板切换、自动退出 POST/新 Token/失效会话/网络回退）；导入非法输入各 5 项；Token 确定性冲突、普通故障、连续拒绝、外键状态码及错误消息含普通 1062 数字各 5 分支；SelectPage Model/Query 连续查询与范围树形通过；旧 Session 存储销毁及新 ID 身份保留通过；受影响 PHP/JS 语法通过。

构建限制：隔离 npm ci 两仓成功。原版 Grunt 在当前 CRLF 配置下把 Moment packages 解析错误，平台 frontend/backend JS 未生成，虽然 npm run build 返回 0，仍判构建未通过；TP8 租户构建及 CSS 产物生成。当前按用户要求没有自行追加 CRLF 兼容，也没有用旧产物掩盖失败或把失败产物回填原工作区。

按用户要求删除两仓本轮新增的 tests/pk-edit.test.php、tests/selectpage.test.php、tests/token-conflict.test.php 和空 tests 目录，移除 docs/build.md 中失效的测试使用说明；临时隔离副本与测试产物在结束时清理。必要业务文件、运行资源、依赖锁文件、构建说明及历史验收报告保留。此前文档中的脚本和临时路径仅为历史执行记录，不再是当前可运行入口。

## 0.5 原版 Grunt 最小 CRLF 兼容（2026-11-11 用户确认）

用户确认“只补 CRLF 换行兼容和 app 路径，其他逻辑保持原版”。两仓 Gruntfile.js 现与本地原版仅有两处差异：`application/admin/command/Min/r` 改为实际 `app/admin/command/Min/r`；urlArgs/baseUrl 移除正则的行尾由 `\n` 改为 `\r?\n`。没有恢复 VM 解析、复制来源校验、项目补丁步骤或其他自定义 Grunt 逻辑。

验证结果：LF/CRLF 共 10 个入口组合检查通过；两仓隔离 npm ci 及删除旧 min 目标后的完整 npm run build 均通过，日志无 Moment/模块路径错误，TP8 tenant 构建实际执行。frontend/backend JS、frontend/backend CSS 以及 TP8 tenant JS 共 9 个产物全部生成，且与当前工作区逐字节一致，不需回填产物；锁文件及白色皮肤保持不变。本轮环境为 Node 24.22.2、npm 11.19.1，TP8 构建使用 PHP8.2.9。

第 0.4 节的 CRLF 构建阻断已由本节解决。原版错误回调语义未改，构建验收仍须核查日志及实际产物。测试仅在隔离副本执行，临时副本及日志在验证后清理；未新增永久测试脚本，未操作业务数据库，未提交、推送或部署。

## 1. 目标、约束与采用原则

目标是把实际有价值的安全修复、组件更新和构建改进适配到两个项目，保留当前重构成果和新增功能。不得把“与官方不同”直接认定为缺陷，不以覆盖原版文件、统一文件哈希或修改版本号作为升级完成标准。

| 对象 | 绝对路径 | 定位 |
| --- | --- | --- |
| TP6 | `E:\phpstudy_pro\extraproject\rocket-admin` | ThinkPHP 6 项目 |
| TP8 | `E:\phpstudy_pro\extraproject\tp8-fastadmin` | ThinkPHP 8 项目，另含租户端 |
| 本地参考包 | `E:\phpstudy_pro\WWW\fastadmin1.7` | 只读参考；实际标记为 1.6.8，不因目录名判断版本 |

**用户明确约束：当前项目默认白色皮肤。两版及 TP8 租户端必须继续默认白色皮肤，保留已有样式定制。** 不改回官方默认的黑蓝皮肤，不因组件更新重做 UI。

**SelectPage 架构已由用户确定：按最新版提取独立查询类。** 两版分别新增 `app/common/library/SelectPage.php`，控制器保留兼容入口，TP8 平台与租户共用本仓查询类并注入各自数据权限。不再采用“保留控制器内完整查询实现、仅补安全校验”的方案。

逐项采用四类结论：

1. **保留当前实现**：项目实现更适合现有架构，或已经包含更完整的保护。
2. **适配上游修复**：问题确实存在，但按 TP6/TP8 及当前调用关系实施。
3. **局部合并**：保留项目功能，吸收上游有价值的部分。
4. **暂不迁移**：没有实际缺口，或属于本次无依据的框架/组件大版本升级。

功能同步不要求 PHP 实现相同。TP8 租户功能在 TP8 内完成必要适配，不为此给 TP6 新增租户系统。插件、支付、上传、权限、日志、仪表盘、动态数据等现有定制不得因本方案被原版覆盖。

## 2. 上游更新是否真实、参考包是否足够可信

### 2.1 已确认的基线

| 检查项 | 当前事实 | 含义 |
| --- | --- | --- |
| TP6 `config/rocket.php` | `1.6.1.20250430.0` | 是项目标记，不代表全部组件仍停留在该日期 |
| TP8 `config/rocket.php` | `1.6.2.20260323` | 不可把 TP8 当作与 TP6 完全相同的升级起点 |
| 两版 `config/site.php` | `1.0.2` | 是站点/静态资源缓存版本，与 FastAdmin 版本分开管理 |
| 本地参考包 `application/config.php` | `1.6.8.20261019` | 与官方对应提交的配置标记一致 |
| 本地参考包 NPM 项目版本 | `1.6.7` | 官方 1.6.8 对应提交中也如此；不是单凭此项即可断言下载包错误 |
| 官方默认分支 | `1.6.x-dev` | `master` 是旧分支，不应用它判断当前更新 |
| 1.6.1 官方基点 | `6361d9228f6fbe8955790a0cc76fb1924f82386e` | 标签 `v1.6.1.20250430` |
| 1.6.8 对应官方提交 | `148b37d9ced3a0a1d07273a55425037209a01c6a` | 2026-10-19 更新版本号的提交，作为本次正式内容基准 |
| 核查时官方开发分支头 | `5ad4f8d5fde8df4cc43b01b5b311329df953e0ce` | 2026-11-06，包含正式基准之后的 5 个提交 |

官方下载日志页 `https://www.fastadmin.net/download.html` 本轮抓取返回 502，未取得完整正文。因此不能声称已逐字核验该页面所有条目。已通过官方 GitHub 提交、版本配置和实际差异核验更新内容，官方 Gitee 发行记录辅助核对早期版本。执行 Agent 若能访问官网，应补录原日志并与下表对照，不把页面文案当作已修复的证明。

本地参考包没有 `.git`，本轮也没有取得其发布签名或整包校验值。它能作为源码参考，不能据此宣称整包来源及每个依赖均完成真实性或安全审计。

### 2.2 已由官方提交确认的更新范围

| 阶段 | 实际更新内容 | 迁移判断 |
| --- | --- | --- |
| 1.6.2 | SelectPage 排序安全、API 模板资源地址 | 按当前调用链核查，TP8 已有部分筛选保护 |
| 1.6.3—1.6.4 | SelectPage 自定义筛选、数据权限筛选修复；后续补充字段字符串空格处理 | 重点适配，不能只移植一个排序正则 |
| 1.6.5 | 公共 API 输出收敛、API 文档生成、退出流程、头像保存、模板变量输出 | 项目已有部分改进，分别判断，避免重复或降级 |
| 1.6.6 阶段 | 验证码校验、默认长度 6 位，SelectPage Query 对象及初始化兼容 | 项目验证码并发保护更完整，优先保留 |
| 2026-09—10 月、1.6.7 阶段 | 通用搜索回填转义、附件展示转义、管理员认证后重建 Session、编辑主键保护、UUID/Token 写入、前端依赖更新 | 包含当前项目确实缺少的保护，是重点 |
| 1.6.8 | 修复唯一验证时主键丢失；会员编辑邮箱必填 | 主键保护必须同时保证正常唯一验证，不能机械删除 `id` |

1.6.8 之后的以下内容单独登记为“开发分支补丁”，不冒充正式版既有内容：

| 官方提交 | 内容 | 建议 |
| --- | --- | --- |
| `f9acd4c234c5f466b2658619ebb52e882bf91480` | 后台导入校验、文件路径及上传者归属 | 纳入本项目安全修复，按本项目附件模型和租户关系重写必要部分 |
| `196e5ef0283037cf2fe754ff21c9cfd74573447f` | PHP 兼容、API 命令 PHP 7.4 tokenizer 回退 | TP6 有直接价值；其他兼容改动先核实真实入参 |
| `7873548f1be8c19b2ef5549aa4c22c3543d9f01d` | 表格分页大小记忆、All 刷新 | 按本项目后端分页契约适配，不能直接复制 |
| `70e9a5931dc7e49f34bde64f327a26c8a082e460` | 生产模式 expandOnHover 初始化时序 | 有对应功能时局部采用 |
| `5ad4f8d5fde8df4cc43b01b5b311329df953e0ce` | 模板表格 showFooter | 验证表格/模板视图切换后再采用 |

本地参考包中尚未出现上述导入归属校验、`pagesizeKey`、expandOnHover DOM-ready 修复等开发补丁，不能只比较本地包后声称已覆盖截至核查日的所有上游修复。

## 3. 相同功能的实现比较与选择

下表是按行为作出的判断。标为源码确认的事项已读取当前实现；涉及数据库、浏览器或并发效果的结论仍须执行阶段验收。

| 功能 | 当前项目与上游的比较 | 选择与原因 |
| --- | --- | --- |
| 附件表格输出 | 项目后台及会员端直接拼接 URL、文件名等；新版增加安全 URL 与文本转义，已有专项测试 | **适配上游修复**。保留项目附件筛选、上传与选取功能，只替换不安全的输出边界 |
| 通用搜索回填 | 当前普通输入与范围输入仍将回填值直接写入属性；上游已转义 | **局部合并**。修复输出，保留当前搜索组件和参数协议 |
| SelectPage | 上游已提取 `app/common/library/SelectPage.php`，控制器委托其查询；项目仍将查询逻辑放在 `BackendBase` | **按最新版拆分独立类并适配**。集中维护参数校验、查询、排序与结果处理，便于直接测试；两版控制器只负责参数、权限条件和响应，不再保留第二套查询实现 |
| SelectPage 数据权限 | 项目通过每次新查询重新注入限制；TP8 `getDataLimitWhere()` 包含 shop_id | **保留项目权限语义，接入独立类**。由控制器传入服务端产生的限制，类内 count/select 每次都应用；上游单一 admin_id 接口不能代替租户隔离 |
| 编辑与唯一验证 | 上游 1.6.8 用可信记录主键支持唯一排除；项目自定义编辑入口尚无一致的主键不变约束 | **适配语义**。可信主键参与校验，客户端不能改变目标或主键；先按 ORM 实际语义验证 |
| 登录 Session | 当前平台和 TP8 租户登录/自动登录写 Session 前没有重建会话 ID | **适配上游修复**。两版框架当前均提供 `Session` Store 的 `regenerate` 能力 |
| 会员 Token 刷新 | 当前已有 MySQL 事务+行锁、Redis Lua 原子轮换；上游刷新先删旧令牌再生成新令牌 | **保留当前实现**。避免写入失败丢失会话及并发刷新/撤销竞态，不能退回上游流程 |
| Token 首次写入 | 当前 Redis 普通 set 仍可覆盖同名键；上游增加判重，但带有效期的 exists+setex 仍非原子 | **局部完善**。在现有原子脚本/唯一约束中拒绝冲突；不复制非原子判重 |
| UUID | 当前 `util\Random::uuid()` 使用 mt_rand；上游换 Ramsey UUID | **采用安全随机目标**。优先 PHP `random_bytes` 保持 UUID v4 契约，已安装合适依赖时才复用，不为几行逻辑新增包 |
| 短信/邮箱验证码 | 项目已有发送额度锁、校验事务/行锁；项目过期删除后 commit，上游相应路径 rollback | **保留当前保护**。保留调用方的一次性消费；邮箱长度配置单独纠正，不整体覆盖 |
| 公共初始化 API | 项目已白名单输出上传 URL/CDN，同时保留 citydata/coverdata；仍存在请求期间持久修改插件配置的分支 | **保留现有响应能力、移除不合理副作用**。不能仅为对齐上游删除项目正在使用的字段 |
| API 文档 | 项目已有 runtime/docs、输出名与语言校验；仍用 PhpToken | **保留项目校验**。只补缺失资源处理、必要的 PHP7.4 回退与模板安全，不覆盖整个命令 |
| Ajax 错误提示 | TP6 读取 responseJSON 的 msg/message 后转为安全文本；TP8/上游相应通用错误路径信息较少 | **以 TP6 行为为候选同步 TP8**。保留有意义且经过转义的错误反馈；非字符串/空响应要自检 |
| 弹窗 | 当前 `fast.js` 有尺寸、遮罩、不点击遮罩关闭等定制 | **保留当前交互**。不能因原版不同回退；小视口尺寸若另有问题单独复现处理 |
| 附件选择路由 | 当前表单组件使用 TP6/TP8 的 `general.attachment/select`，原版为旧框架路由 | **保留项目路由**。覆盖原版会破坏框架适配 |
| filter-control | 当前按原值去重、通过 DOM 属性设置选项；参考包先转义值后判断去重 | **保留当前去重语义，兼容新版表格核心**。A&B 等值反复加载不得重复，HTML 标签及恶意元数据需专项验证 |
| 白色皮肤 | 项目已有大量定制；参考包默认黑蓝；两版根 bootstrap.css/fastadmin.css 与参考包忽略换行后相同 | **保留项目样式**。不存在“下载包新所以所有 CSS 都要替换”的依据 |
| Grunt | 项目已改为 vm 读取本仓库 AMD 配置，并传播优化错误；上游有错误后仍 callback 成功的路径 | **保留项目实现并补失败闭环**。不要回退到上游过时解析方式 |

这些判断只决定选取方向，不等于全部业务已通过动态测试；执行时若当前代码变化，应重读相关代码并修正结论。

## 4. 组件版本清单与更新策略

### 4.1 NPM 管理的组件

下表“当前”来自 TP6 锁文件并对主要运行文件作了核验；TP8 缺根锁文件，其当前 libs 与 TP6 对应资源一致的部分已抽查/对比，不能凭此把 TP6 lock 当作 TP8 已经具备可复现构建的证明。“参考”是本地包及官方 1.6.8 对应锁文件，不是对 NPM 全网最新版或无漏洞状态的承诺。

| 组件 | 当前锁定版本 | 参考锁定版本 | 处置 |
| --- | --- | --- | --- |
| jQuery | 3.7.1 | 3.7.1 | 保留，不做 jQuery 大版本迁移 |
| `fastadmin-bootstrap`，以 bootstrap 别名安装 | 3.4.2 | 3.4.2 | 保留 FastAdmin 分支，不能替换成 Bootstrap 5 |
| `fastadmin-bootstraptable` / bootstrap-table 别名 | 1.11.12 | 1.11.13 | 候选更新，保留 filter-control 项目修复并核验扩展 |
| Moment | 2.30.1 | 2.31.0 | 随锁文件更新；验证日期、时区、中文及导出格式 |
| Sortable | 1.15.6 | 1.15.7 | 补丁更新候选，验证拖拽保存及权限 |
| bootstrap-daterangepicker | 2.1.30 | 2.1.30 | 保留，验证与更新后 Moment 兼容 |
| bootstrap-select | 1.13.18 | 1.13.18 | 保留 |
| bootstrap-slider | 11.0.2 | 11.0.2 | 保留，维持历史兼容路径 |
| eonasdan-bootstrap-datetimepicker | 4.17.49 | 4.17.49 | 保留 |
| fastadmin-addtabs | 1.0.8 | 1.0.8 | 保留，重点验收菜单/多标签定制 |
| art-template / fastadmin-arttemplate | 3.1.4 | 3.1.4 | 保留 FastAdmin 别名，不直接换当前 art-template 大版本 |
| fastadmin-citypicker | 1.3.6 | 1.3.6 | 保留 |
| fastadmin-cxselect | 1.4.2 | 1.4.2 | 已包含 1.6.1 阶段组件修复，不重复降级 |
| fastadmin-dragsort | 1.0.5 | 1.0.5 | 保留 |
| fastadmin-layer | 3.5.6 | 3.5.6 | 保留，兼容现有弹窗与白色皮肤 |
| fastadmin-selectpage | 1.1.1 | 1.1.1 | 前端包版本未变；主要修复位于 PHP 端 |
| nice-validator / fastadmin-nicevalidator | 1.1.7 | 1.1.7 | 保留 |
| Font Awesome | 4.7.0 | 4.7.0 | 保留，CRUD 仍读取 less/variables.less，不能只留 css/fonts |
| jquery-slimscroll | 1.3.8 | 1.3.8 | 保留 |
| jquery.cookie | 1.4.1 | 1.4.1 | 保留，存在历史调用，不机械删除 |
| jsTree | 3.3.17 | 3.3.17 | 保留 |
| require-css | 0.1.10 | 0.1.10 | 保留 |
| tableExport | 1.33.0 | 1.33.0 | 保留；不能将锁文件中所有传递依赖都当作页面已加载组件 |
| toastr | 2.1.4 | 2.1.4 | 保留历史 css/js 路径与项目错误提示 |

版本识别注意：bootstrap-table 运行文件头仍显示 1.11.9，SelectPage 文件头有自身 2.21 标记，Bootstrap CSS 文件头是 3.4.1。这些与 FastAdmin 分支的 NPM 包版本不一定相同。执行时同时记录包名、锁版本、实际文件、来源及项目补丁，不能仅按文件头重写版本号。

构建依赖的参考变化：Grunt 1.6.1 → 1.6.3。jsonminify、parse-config-file 等虽然还在清单，但当前 Grunt 已使用 vm，不应为了对齐上游重新接入旧解析链；本次不顺带做无收益的依赖删减。

传递依赖中可见 DOMPurify 3.2.5 → 3.4.17、jsPDF 3.0.1 → 4.2.1，xlsx 别名仍是 0.20.3。这些属于锁文件树的变化，需要核验导出实际运行路径、是否复制/加载以及审计结果，不能把“大版本变化”无条件发布到浏览器，也不能据此声称导出安全已全部解决。

### 4.2 未由根 NPM 完整管理的运行资源

| 资源 | 当前实际版本/状态 | 建议 |
| --- | --- | --- |
| RequireJS `public/assets/js/require.min.js` | 2.3.7，与参考包忽略换行后相同 | 保留 |
| r.js `app/admin/command/Min/r.js` | 2.3.7 | 保留，验收实际优化能力；TP8 租户工具保留独立能力 |
| ECharts | 4.9.0，参考包也如此 | 不借此次升级迁到 ECharts 5/6；现有仪表盘另行验证 |
| Dropzone | 5.7.0，参考包也如此 | 保留 AMD 与上传适配 |
| AdminLTE JS | 2.x，实际文件与参考包忽略换行后相同 | 保留，不替换为新版 AdminLTE |
| `public/assets/css/fastadmin.css` | AdminLTE 2.3.7 来源标记，项目/参考内容相同 | 无需为升级而覆盖 |
| `public/assets/css/bootstrap.css` | 项目 LESS 生成的 Bootstrap 3.3.6 定制入口，参考包也如此 | 与 libs/bootstrap 的分支 CSS 区分，不混用；大版本改造另立任务 |
| bootstrap-contextmenu | 项目额外组件，RequireJS 有真实路径 | 保留，登记为本地管理资源；不能因参考包没有而删掉 |
| fastadmin-tagsinput | 参考包有额外目录，项目没有；双方现有入口并不因此必然缺功能 | 先追调用，未使用则不引入 |
| skin-white.css、iconfont 等 | 项目主题/资源，不以框架版本号代表自身版本 | 保留定制，以构建发布清单及缓存版本管理 |

### 4.3 组件更新的最低执行要求

1. 以官方 1.6.8 对应 lock 为来源参考，保留本项目 overrides、兼容别名、dists 与有效补丁；采用的版本在两版锁文件中一致。
2. 不运行无范围的 `npm update`、`npm audit fix --force`，不使用未审查的 latest 依赖漂移。传递依赖变更必须列入报告。
3. 不整目录覆盖 `public/assets/libs`。当前每版该目录有 2014 文件、参考包 580 文件，包含历史源码/示例；差异不等于必删清单。
4. 运行组件以实际加载文件为准。只清理确认不被页面、插件、CRUD 命令或构建引用的材料；批量删除另列精确清单并按授权执行，不作为本次完成的前置条件。
5. filter-control 的 `.js` 与 `.min.js` 必须同步；升级核心时保留 1.11.13 新增的工具导出等兼容能力，不能用旧核心抵消修复。
6. 保留 Font Awesome `less/variables.less` 的部署来源。当前 dists 没有声明此文件，但两版平台/租户 CRUD 命令确实读取它；干净构建必须能恢复它。

## 5. 构建方式：保留现有技术栈，补齐可复现性

### 5.1 当前实际链路

```text
package-lock.json → npm ci → node_modules
package.json:dists → Grunt deploy/copy → public/assets/libs
require-backend.js / require-frontend.js → 内置 r.js → 对应 .min.js
backend.css / frontend.css → r.js CSS 导入合并/压缩 → 对应 .min.css
TP8 require-tenant.js → tenant:min 独立工具 → require-tenant.min.js
TP8 租户 CSS 复用 backend.css/backend.min.css
模板按调试状态选择未压缩/压缩入口，site.version 用于缓存失效
```

当前 `npm run build` **不执行 LESS 编译**。CSS 压缩不是 LESS → CSS。修改 LESS 后直接跑当前 build，不保证生成对应的新 CSS。

TP6 有 package.json、lock 和 Gruntfile；TP8 只有 Gruntfile，缺少根 package.json 和 lock，不能声称 TP8 当前可执行同一套 `npm ci`。旧 `OPTIMIZATION_AUDIT.md` 中曾记录过另一状态，应以当前文件为准。

### 5.2 推荐方案

保留 RequireJS、Grunt 和现有 r.js，补齐 TP8 构建清单及平台/租户产物。此次不迁移 Vite、Webpack、ESM、Bootstrap 大版本或 UI 框架，也不建立跨仓构建平台。

默认以现有 `public/assets/css/*.css` 和自定义皮肤 CSS 为当前可维护输入，先确保它们经过固定链路生成 min 产物。不得突然将完整 LESS 重编译加入默认任务并覆盖已有 CSS 定制。

TP6 `less/backend.less` 引入黑蓝皮肤，而实际 backend.css 引入白色；TP8 两者均为白色。对 TP6 仅纠正这一与明确默认主题不符的残留。当前没有 skin-white.less，白色皮肤直接 CSS 定制必须保留，不能虚构一个已有 LESS 来源。

若后续确定项目持续通过 LESS 开发，再单独列出确有维护来源的入口、保护 CSS 定制，并增加锁版本的 LESS 编译步骤。本次不为形式完整新增编译器依赖或强行把 CSS 定制迁入 LESS。

### 5.3 必须落实的构建改进

| 项目 | 最小改动 | 验收 |
| --- | --- | --- |
| TP8 缺 NPM 清单 | 以审核后的 TP6 清单/lock 建立 TP8 对应文件，保留租户差异 | 两版干净目录分别 `npm ci` 成功 |
| 不安全源配置 | TP6 `.npmrc` 的 HTTP registry、strict-ssl=false 改为可验证的 HTTPS 源；TP8 明确同等构建策略 | lock resolved 与源配置协调，证书失败不得靠关闭校验通过 |
| 锁文件 URL | 当前 TP6 265 个 resolved 为 HTTP 腾讯镜像；参考包对应为 HTTPS | 在受控更新中保留版本/integrity 并核验 HTTPS 可取，不能仅改 registry 就声称解决 |
| deploy 覆盖补丁 | 在 dists 排除必须保留的 filter-control 补丁，或用项目已有可复现补丁机制 | 第一次干净构建保留补丁，第二次构建不退回发行包逻辑 |
| Font Awesome 生成依赖 | dists 包含 CRUD 使用的 `less/variables.less` | 干净部署后的 CRUD 图标映射成功 |
| 复制规则残留 | `dists.coloris` 当前无对应直接依赖；按调用决定移除残留或补真实来源 | 声明的运行资源有来源，不因空目录复制假成功而掩盖缺包 |
| 错误处理 | 保留 callback(err)；AMD 配置正则不匹配、解析异常也必须明确失败；异步任务必须结束 | 注入缺失文件/错误配置后非零退出，无挂起、无假成功 |
| TP8 租户 JS | 默认发布入口明确包含 tenant 构建；可以 npm 脚本串接既有 `tenant:min` | 不能只生成 frontend/backend 而遗漏 require-tenant.min.js |
| 压缩器兼容 | 用当前 Uglify/r.js 验证新依赖实际语法；避免把 tableExport 等 ES 新语法强行内联 | 同时通过 dev/prod，保留当前 tableexport=empty 按需策略 |
| 产物新鲜度 | CSS/JS 来源变更后生成全部受影响产物，并核验关键修复确实进入压缩包 | 删除旧产物的隔离副本仍可完整生成；单纯旧文件存在不算通过 |
| 缓存 | 发布时协调 site.version、动态 lang 与页面入口缓存 | 普通刷新即获取本次资源，不靠要求用户强刷掩盖问题 |
| .gitignore | 如执行需新增 node_modules/构建验证目录，按项目规范忽略 | 不把 node_modules 或测试数据提交进仓库 |

Grunt 官方允许异步 done 接收 Error 表示失败，因此当前 callback(err) 不应被误改成无参 callback。对 callback 参数的结论以项目锁定 Grunt 的实际结果再验证一次。

构建环境快照：PowerShell 7.6.4、Node 22.20.0、npm 10.9.3。记录这些是为了复现，不代表已验证该 Node 环境下完整构建通过。

## 6. Agent 分阶段执行任务

### T00：重新确认工作区与影响范围

优先级 P0；必须先于任何源码写入。

1. 读取两仓 AGENTS.md、当前 git status、相关 diff、composer/npm 清单及本方案涉及文件。
2. 本轮核查已观察到工作区在外部继续变化；不要复用本方案中的未提交文件数量，不要回滚、覆盖、stash、切分支或擅自提交。
3. 所有修改前重新读取目标；已有改动中的首页、日志、白色皮肤、菜单、动态数据等按当前内容保留。
4. 列出拟修改/新增文件和用途；遵循当前用户授权及两仓文件安全规则。兄弟仓的沙箱写权限另按执行环境处理，不把功能同步要求当作绕过权限的理由。
5. 本地参考包只读。版本对比必须固定到第 2 节提交，不追随浮动 HEAD 自动扩大范围。

### T01：附件渲染与通用搜索输出修复

优先级 P0；先写有意义的输出断言，再局部修改。

涉及两版 `public/assets/js/backend/general/attachment.js`、`frontend/user.js`、`bootstrap-table-commonsearch.js`。同时追查 TP8 租户附件页面及其他实际复用入口，不能只改后台列表。

要求：

- 采用上游 URL 协议限制、控制字符处理、属性及文本转义的目的，保留本地/云存储 URL、缩略图参数、选择附件和上传联动。
- 不能只转义标签文本却允许 javascript/data 等活动协议进入链接；suffix 必须作为一个查询参数编码。
- 处理历史已编码文件名，显示一次，避免升级后出现双重编码；不批量改写历史数据库。
- 通用搜索覆盖普通字段、范围字段、URL 回填值及操作符属性；不把表格配置中有意提供的 formatter HTML 全部无差别转义。
- 根据方法结构适配现有测试；测试应验证最终输出，而不是只验证某个新 helper 存在。
- 将对应源码修复纳入 production bundle 后再验收。

现成测试来源：`E:\phpstudy_pro\WWW\fastadmin1.7\tests\attachment-formatters.test.js`。可在获得相应文件新增授权的执行任务中复制/适配到各仓测试目录，或只读引用运行。新增租户断言应使用实际租户模块。

### T02：按最新版拆分 SelectPage 独立类并完善安全及数据范围

优先级 P0。

两版分别新增 `app/common/library/SelectPage.php`，以本地参考包 `application/common/library/SelectPage.php` 为实现参考。当前两版均尚无此独立类，查询实现位于 `app/common/controller/BackendBase.php`；TP8 再结合 `TenantBackend.php` 的 `getDataLimitWhere()`。先搜索所有 selectpage 覆盖、调用、data-source、参数校验方法与生成模板，再实施拆分。

确定的职责划分：

| 位置 | 拆分后职责 |
| --- | --- |
| `app/common/library/SelectPage.php` | 字段与操作符校验、参数规范化、查询构建、排序、分页、字段输出及树形结果；返回 `['list' => ..., 'total' => ...]` |
| `BackendBase::selectpage()` | 读取请求与控制器配置、计算服务端数据权限、构造查询类并调用、转换为当前项目的错误/JSON 响应；不再内嵌完整查询算法 |
| TP8 `TenantBackend` | 保留 `getDataLimitWhere()` 等租户权限计算；通过现有继承入口把 shop_id/admin_id 限制传给同一个查询类，不另建租户 SelectPage 副本 |

迁移步骤：

1. 按上游构造模型/字段配置、执行参数并返回结果的组织方式提取类；请求对象、Auth、Session 和 HTTP 响应留在控制器，查询类只接收其需要的数据。
2. 在上游接口基础上增加项目必需的最小服务端数据范围注入能力，使 TP6 管理员限制与 TP8 `getDataLimitWhere()` 都能完整传入。权限条件不得来自客户端 custom 参数，空的授权 ID 集合必须保持“无可访问数据”的语义。
3. 适配两版锁定的 ORM、`util\Tree`、数据库 Facade、字段元数据及参数绑定 API；不得原样复制 TP5 的 `think\Db`、`fast\Tree` 或不存在的 `Db::quote()`。按现有调用支持 Model/Query，保留 Query 已有的服务端过滤、关联及连接条件，不把它退回无条件模型查询。
4. 将当前 SelectPage 专属校验、查询和结果构建迁入类，控制器保留原 `selectpage()` 可见性、参数协议、返回结构及必要的前置行为。逐个核对覆写入口，避免某个控制器仍走旧路径或失去其权限过滤。
5. 接入后移除不再调用的旧查询代码、重复操作符表和专属校验实现；确有调用方的扩展点先迁移或保留必要兼容。不保留两套长期实现，也不新增通用查询框架、服务容器层或多余包装类。
6. 类实例按当前调用创建，count/list 的查询状态分别正确构造；不能因 ORM 消费查询条件、Query 对象复用或排序状态泄漏，丢失原有条件或影响下一次执行。

要求：

- 使用真实表字段或由服务端明确声明的关联字段集合限制 key/show/search/order/custom。关联别名和展示访问器必须按现有用法支持，不能用“全禁关联字段”破坏功能。
- 排序方向只允许 ASC/DESC。TP8 目前把 v[1] 直接传给 order，现有参数检查未覆盖它；TP6 的 custom 操作符缺少白名单。
- custom 操作符限定为项目实际支持的比较操作，不接受客户端 raw/exp/任意 SQL 表达式；明确异常类型与响应，不泄露 SQL。
- 对数组层级、字段类型和初始化 keyValue 做输入验证；保留合法的字符串主键、0、引号、中文以及既定顺序。不要用 addslashes 拼接 FIELD()，优先当前 ORM 可用的参数绑定方式。
- count 和 select 分别构建时都保留权限条件；初始化 keyValue、树形模式、搜索 OR 条件不得绕过 admin/shop 限制。数据范围条件必须在业务 OR 条件外以 AND 施加。
- TP8 保留 `getDataLimitWhere()` 的权限计算与条件含义；将条件注入独立类，在 count/list 查询中分别完整应用，不降级为只识别 admin_id 的上游 API。
- trim `$selectpageFields` 的逗号分隔项，避免 `'id, username,nickname'` 丢字段；数组/null 类型按明确的返回契约编码，不对复杂值盲调 htmlentities。
- 分页大小按现有组件/树需求制定明确界限，不能为了安全随意改变已使用的树形和初始化行为。

验证分为独立类直接测试与控制器接入测试：前者覆盖字段/操作符、正常搜索/多词/初始化/树形、字段字符串空格、Model/Query 及查询状态；后者覆盖现有路由、控制器覆写、错误/JSON 协议、跨管理员和跨 shop_id 的 list/total。需要两版真实 ORM/数据库检查，不能用“输入经过正则”或类文件已创建代替验收。

### T03：编辑主键、唯一验证与生成代码

优先级 P0。

TP6 公共 CRUD 位于 `app/admin/library/traits/Backend.php`；TP8 实际共用实现位于 `app/common/library/traits/Backend.php`，admin/tenant trait 是适配入口。不要在 TP8 只修改空壳 trait。

要求：

- 先以当前 ORM 的 getWhere/updateData 行为复现风险；TP6/TP8 与上游 TP5 行为不同，不能未经验证就声称旧版本的同一越权漏洞已可利用。
- 不信任 row[id] 或自定义主键作为更新目标/唯一排除依据。可信主键来自已通过权限校验读取的记录。
- 验证参数需要主键时补入可信值；持久化时不能改变主键。不能只 unset 主键导致“编辑自己未改邮箱”被判重复。
- 覆盖自定义编辑入口：Category、auth/Admin、auth/Group、auth/Rule，以及 TP8 的 TenantCategory、TenantAdmin、TenantAuthGroup、TenantAuthRule 等实际存在的相应控制器。
- 保留 TP8 已有 shop_id 不可编辑和所有数据范围保护；批量修改/导入与普通编辑的主键语义分开，不误删导入用于匹配记录的主键。
- 核对 CRUD stubs，生成的新控制器不能重新引入不安全的编辑写法。复用现有可信处理点，不新增无必要的 trait/服务层。
- 会员邮箱“必填”先核对当前验证器与业务约束；若已有必填后端约束，同步页面提示即可。若项目允许无邮箱会员，先确认兼容要求，不机械收紧已有业务。

验证：提交另一个记录的主键后，两条记录及关联权限均不被意外改变；自身唯一值未改可保存，冲突值被拒绝；非 id 主键及租户分支正常。

### T04：认证成功后的 Session 更新

优先级 P0。

涉及两版 `app/admin/library/AdminAuth.php`，另含 TP8 `app/tenant/library/AdminAuth.php`。

在 login/autologin 成功、写入认证态之前，按当前框架正确重建 Session ID。保留登录失败计数事务、自动登录签名/IP 检查及平台/租户独立键名。不在每个请求重建，不让登录失败清掉另一个已登录身份。

验证旧 Session ID 失效、新 ID 正常；自动登录同样生效；平台和租户同时打开、记住登录、多标签页及退出行为符合现有约定。

### T05：UUID 与 Token 写入可靠性

优先级 P0；这部分不得退回上游较弱实现。

涉及两版 `extend/util/Random.php`、`app/common/library/Auth.php`、`Token.php`、`token/driver/Mysql.php`、`Redis.php`、`app/api/controller/Token.php` 及调用方。

要求：

- UUID v4 改用密码学安全随机源，保持格式、版本位和 variant；保留其他工具方法和业务随机抽奖逻辑，不扩大到全部 Random 重构。
- 首次签发失败必须失败返回，不能忽略 Token::set 的 false 或异常后仍标为已登录。
- MySQL 以数据库唯一约束兜底；只在可识别的 token 冲突时有限重试，数据库故障不能伪装成随机碰撞。
- Redis 在现有 Lua 中原子拒绝已存在 Token，TTL=0 和正值均不能覆盖其他用户令牌；拒绝冲突时不能先污染用户索引。
- 完整保留当前 rotate 的事务/Lua 原子性，签发失败时旧 Token 保留，并发刷新及撤销不能让旧会话复活。
- 保留项目 token_type、expires_in、expiretime 及有效期配置契约，不用上游固定 2592000 覆盖。
- 不增加数据库结构，除非核验当前数据库确实缺唯一约束；如需迁移，先提供重复检查、增量 SQL、恢复步骤，禁止直接重导 schema。

使用确定性冲突注入检查，不能只“生成很多 UUID 没重复”就声称验证了并发与错误处理。

### T06：公共 API、文档与退出流程局部完善

优先级 P1，安全输出缺口提升为 P0。

- `app/api/controller/Common.php`：保留当前上传白名单响应及实际需要的 citydata/coverdata；先追客户端与插件调用。移除 init 请求持久写插件 uploadmode 的副作用，构造当前请求所需配置，继续支持已有本地/云上传模式。不要直接删除项目功能。
- `app/api/controller/User.php` 的头像已有限制来源的实现，保留并验证本地路径、CDN、同站 http/https；不把当前改进覆盖成上游前缀匹配代码，不为升级重复重写头像逻辑。
- `app/admin/command/Api.php`：保留 runtime/docs、输出名和语言校验、禁止 admin/common 等现有保护；资源 CDN 参数按确有使用需求补齐。TP6 用 token_get_all 等兼容路径支持 PHP7.4，不能直接依赖 PhpToken。不得额外放开模块或把文档重新输出到 public。
- API 模板及 dispatch_jump：对照当前模板转义规则核对原始输出、属性 URL 和 JS 内插值；仅修复实际不安全边界，保留允许的业务消息格式，避免盲目加 htmlentities 产生双重编码。
- 后台与会员退出已有 Referer/Token 校验，保留。若 GET 返回自动提交表单，结合现有菜单/Ajax 退出改为不在 GET 阶段直接执行注销；采用当前交互可承受的安全 POST 流程，不机械引入整套官方退出页面。
- TP8 租户退出采用自身 token、事件、日志及路由；现有同时登录状态不能互相撤销。检查生产模式资源时序。

### T07：验证码保留优点、修正实际差异

优先级 P1；认证回归属于必须验收项。

当前两版 `Ems::send()` 使用 `rocket.sms_captcha_length`，config/rocket.php 也仅有短信长度配置。为邮件长度提供独立配置并保持现有默认 6 位，除非项目明确要求共用；相应表单长度、翻译、API 及生成验证码的调用方一并核对。

保留已有发送额度锁、事务校验、错误次数累计、一次性消费及过期记录删除提交逻辑。前置 Validate 接口可只检查，真正注册/登录/改邮箱/改手机/重置密码必须在实际业务执行处消费，不能因提前预校验使正常表单流程失效。

测试通过事件替身/测试邮箱和短信通道执行，未经明确指令不向真实用户发送短信或邮件。

### T08：组件与构建修复

优先级 P1，但 T01 等安全修复进入生产包属于发布前置条件。

按第 4、5 节实施。保留当前白色皮肤、RequireJS 路由、Ajax 文本错误提示、弹窗参数、bootstrap-contextmenu、filter-control 和 CRUD 图标来源。

最少形成两版完整清单/lock/Grunt 对应关系，以及 TP8 tenant 独立构建的发布入口。平台同功能可以同步经过验证的 JS，PHP 则保留版本适配差异。

### T09：正式版之后的补丁适配

导入安全补丁按 P0 处理，其余按 P1；在报告中单列来源。

1. 导入：仅 POST，限制表格类型，realpath 必须位于带目录分隔符边界的允许上传目录，校验附件记录属于当前身份/租户；禁止跨用户、跨 shop_id 导入。TP8 已有 public realpath 边界判断，应保留并补归属，不再重复写一套。TP6 应补完整限制。复用本项目 reader/CSV 编码和导入验证，不拷贝上游旧 PhpSpreadsheet 调用。
2. 分页：上游针对 All 发送 limit=0，而本项目 `BackendBase::buildparams()` 把 0 转为默认 20、并限制最多 100。必须先确定现有 All 业务定义，再改前后端共同契约；不照搬上游 JS 后假定生效，不为了 All 任意取消服务端边界。若当前 All 实际不受支持，应明确 UI 与服务端行为一致。
3. 分页大小记忆：按实际表格身份隔离，避免一个页面改大小影响另一页。继承旧 pagesize 的迁移策略须有测试；不能仅用“等于默认值”判断页面是否显式指定 pageSize，也要防止 Table.api.init 多次调用重复包裹原型。
4. expandOnHover：确认项目保留的设置后，仅补 DOM-ready 初始化时序；不要覆盖正在修改的 backend/index.js 菜单逻辑。
5. showFooter：保持显式配置，模板视图只临时隐藏；从模板切回列表后原配置必须恢复。官方补丁自身也需要这项行为验收。
6. PHP8.2：对照实际调用修正 null/日期输入边界，不把文档中所有强制转换原样抄入，不把本就无错的工具函数重新重构。

### T10：版本、文档与发布记录

只在已选范围完成后更新 `config/rocket.php` 的兼容基准，并分别登记正式基准、开发补丁和项目自有版本。部分迁移未完成时不得写“完整升级 1.6.8”。NPM 项目 version 不等于依赖版本，也不负责浏览器缓存刷新。

更新两版必要的构建说明，使文档中关于 TP8 构建、白色默认皮肤、生成命令与实际文件一致。历史审计记录可以保留，但应注明旧结论的适用时间，不大段覆盖历史。

## 7. 文件范围与新增文件提案

实施前仍需按当前调用链缩减/补齐，不机械修改表内每个文件。

| 范围 | TP6 | TP8 |
| --- | --- | --- |
| SelectPage/查询 | 新增 `app/common/library/SelectPage.php`；`BackendBase.php` 改为委托入口，核对所有覆写/调用与生成模板 | 同样新增本仓 `SelectPage.php`；平台/租户共用，接入 `TenantBackend.php` 的完整数据范围 |
| CRUD 公共逻辑 | `app/admin/library/traits/Backend.php` | `app/common/library/traits/Backend.php`，admin/tenant 适配 trait |
| 自定义编辑 | Category、auth 相关控制器、必要 validate | 同类平台控制器及 Tenant 对应控制器 |
| 管理认证 | `app/admin/library/AdminAuth.php` | 同位置及 `app/tenant/library/AdminAuth.php` |
| Token/验证码 | common library、token drivers、API 真实调用方、Random | 同功能按当前实现适配 |
| 文档生成 | `app/admin/command/Api.php` 及实际模板/Builder | 同位置；PHP8 不必为形式统一增加无用分支 |
| JS 输出 | attachment、frontend/user、commonsearch 及相关压缩包 | 同功能加实际租户附件模块 |
| 表格与提示 | require-table、fast.js、backend/index、必要模板视图组件 | 同功能加 tenant 入口 |
| 默认主题 | 保留 skin-white.css；纠正 LESS 残留；生成受影响 CSS | 保留 skin-white.css 和现有白色入口；租户共用后台 CSS |
| 构建 | package.json、lock、Gruntfile、.npmrc、必要 Min/stub | 同类配置，tenantcommand/Min 及 RequireJS tenant 入口 |
| 文档 | 实际构建说明与本方案执行状态 | 对应说明，不照抄不适用命令 |

预计必要新增项：两版各自的 `app/common/library/SelectPage.php`、TP8 package.json、package-lock.json，以及按执行方案落实范围需要的专项测试（包含 SelectPage 类直接测试与平台/租户接入回归）。TP8 .npmrc 是否新增以最终 registry 策略决定。新建测试、构建补丁源或 fixture 前，遵循用户文件授权规则；不能借方案擅自批量创建目录。临时隔离目录也纳入执行授权与清理说明。

数据库默认无新增结构；只在核验发现 Token 唯一约束等实际缺失时提出增量迁移。业务数据不自动修复、不导入整份 rocketadmin.sql/数据库.sql、不清空现有表。

## 8. 验证矩阵与通过标准

### 8.1 本轮已经实际执行的只读检查

| 检查 | TP6 | TP8 | 参考包 |
| --- | --- | --- | --- |
| 当前配置、composer/npm、Grunt、相关源码核对 | 已执行 | 已执行 | 已执行 |
| 核对 RequireJS paths 对应文件存在 | frontend 38、backend 40 条路径，未发现缺失 | 同前，加 tenant 42 条，未发现缺失 | 本轮未单列其 paths 验收 |
| 现有配置按当前 Grunt 的 vm 方式解析 | 成功 | 成功，含 tenant | 未作为其完整构建通过证明 |
| 上游 attachment-formatters 专项测试 | 0 通过 / 10 失败 | 0 通过 / 10 失败 | 10 通过 / 0 失败 |
| 组件运行文件与参考包对比 | 已核对关键版本与差异 | 已核对关键版本与差异 | 不等于发布包真实性完整认证 |
| 完整 npm ci / npm run build | 未执行 | 未执行，当前缺清单/lock | 未执行 |
| 浏览器/数据库/Redis/并发业务 | 未执行 | 未执行 | 未执行 |

附件测试的失败包括未转义输出行为及新 formatter 方法缺失造成的结构不兼容；不能表述为“发现了 10 个独立安全漏洞”。本轮没有运行浏览器攻击载荷，也没有修改附件数据。

可复现命令，在 PowerShell 中执行：

```powershell
node 'E:\phpstudy_pro\WWW\fastadmin1.7\tests\attachment-formatters.test.js'
node 'E:\phpstudy_pro\WWW\fastadmin1.7\tests\attachment-formatters.test.js' 'E:\phpstudy_pro\extraproject\rocket-admin\public\assets\js'
node 'E:\phpstudy_pro\WWW\fastadmin1.7\tests\attachment-formatters.test.js' 'E:\phpstudy_pro\extraproject\tp8-fastadmin\public\assets\js'
```

### 8.2 实施后的必验项目

| 类别 | 场景 | 通过标准 |
| --- | --- | --- |
| 语法 | 所有受影响 PHP/JS；TP6 最低 PHP 语法 | 相应 PHP `-l`、Node `--check` 通过，且必要兼容路径实际执行 |
| 运行平台 | 两版 composer 锁定依赖 | 使用明确 PHP 二进制和扩展；TP8 不能用 PATH 上 PHP7.4 执行 |
| 附件 | 后台、会员、租户列表/选取/搜索/云 URL/历史编码文件名 | 无活动协议或属性注入，正常 URL 与缩略图保持语义 |
| 搜索 | 文本、范围、URL 回填、中文、引号、标签字符串 | 无属性注入，搜索参数与界面一致 |
| SelectPage 独立类 | 两版类直接调用、Model/Query、已有条件/连接、重复执行及字段配置 | 查询类不依赖 Request/Auth/HTTP 响应；结果与原有有效行为一致，查询状态不串用，旧重复实现已移除 |
| SelectPage 控制器接入 | 搜索/初始化/树形/排序/关联/空结果、覆写入口与生成模板 | 数据和 total 一致；原请求/响应协议保持，非法输入可控拒绝，不泄露 SQL/敏感字段 |
| 数据权限 | 非超级管理员及两个独立租户 | list、total、初始化、编辑、导入均不能跨范围 |
| 编辑 | 自身唯一值、重复值、伪造主键、非 id 主键 | 正常保存；伪造主键不能改变其他记录及关联关系 |
| 登录 | login/autologin/失败/平台租户同时登录 | 成功更新会话 ID，失败不破坏已有身份，旧 ID 不能恢复认证 |
| Token | 写入失败、碰撞、过期、TTL=0、重复刷新、刷新并发撤销 | 原子性及有效期正确，失败不丢失有效旧令牌，不覆盖他人令牌 |
| 验证码 | 前导 0、错误次数、过期、并发消费、发送频率 | 同码最多一次成功；前置检查不破坏最终业务消费 |
| 导入 | csv/xls/xlsx、编码、非法类型、越界、非本人/非本租户文件 | 正常业务成功，非法路径/归属拒绝，失败无部分数据残留 |
| 组件 | 表格核心与固定列/移动/导出/filter-control、日期、树、下拉、拖拽 | 不只验证组件能加载，要验证保存/筛选/导出的实际结果 |
| filter-control | A&B、引号、formatter 标签，连续加载 3 次 | 值不被改变、不重复累积、不产生执行性 DOM |
| 分页 | 大小记忆、多表格、All、All 后新增刷新 | 前后端契约一致，不缺行、不跨表互相污染、不无界放大查询 |
| 白色主题 | 首页、iframe 内页、弹窗、登录、菜单折叠、多标签、小屏 | 默认持续白色，既有标题、颜色、遮罩、滚动条等定制保持 |
| 构建 | 干净隔离副本执行两版 npm ci/build | 产物全部生成，TP8 tenant 不遗漏，锁文件无非预期漂移 |
| 构建失败 | 缺失 AMD 模块/错误入口/缺少复制来源 | 非零退出、不挂起，不把残留旧产物当成功 |
| 生产模式 | debug 开关两种状态，首次进入、缓存后刷新 | 关键修复在压缩包中，无 AMD 错误、404、资源时序异常 |
| 生成器 | 普通 CRUD、图标映射、TP8 tenant CRUD | 生成代码具有相同安全约束，路径与权限正确 |

TP8 根 composer 声明 PHP>=8.0，但当前锁中的 EasyWeChat 6.20.0、Symfony HTTP Foundation 6.0.20 已要求 PHP>=8.0.2。构建/验收说明应反映锁定依赖的实际要求；是否提升根声明在核验全依赖后决定。本轮可见本机 PHP7.4.3、8.0.2、8.2.9 等安装，但未执行完整 platform requirements 验收。

测试优先用隔离数据库、两个测试租户、测试文件与事件替身。涉及真实库写入、真实外部通知、发布等行为按执行任务已有授权处理；没有授权的生产写入不能用“验收需要”代替授权。

### 8.3 执行顺序与停止条件

```text
T00 当前状态 → T01/T02/T03/T04/T05 安全修复
→ T06/T07 保留现有功能并局部完善
→ T08 组件与构建 + T09 明确来源的补丁
→ 两版及租户验证 → T10 版本/发布记录
```

以上按单代理顺序推进；独立只读检查可并行。每项失败先定位，再重跑受影响范围，不为形式执行全仓循环审计。

停止依赖工作并明确报告的情况：无法界定原有改动、关键业务契约（如 All、邮箱必填）无法确认、需要未授权文件/生产写入、实际环境权限不足。其余无依赖任务继续推进，不把局部阻碍当作全部完成。

## 9. 交付、发布与回退

执行 Agent 必须交付：

1. 按任务 ID 列出“已改、无需改、保留项目实现、未验证/阻塞”，每项附理由和实际文件。
2. 两版同步表：同功能、版本特有实现、TP8 租户覆盖情况；单列 SelectPage 独立类、控制器委托、权限注入及旧逻辑清理结果，不以复制成功代替行为一致。
3. 前端依赖清单：包名、锁定版本、实际运行路径、项目补丁、构建来源、采用/保留理由；区分根包、传递依赖和手工管理资源。
4. 构建命令、环境、退出状态、产物清单，以及干净构建能否保留 filter-control/白色皮肤/CRUD 图标证明。
5. 静态、组件、浏览器、数据库、并发结果分别记录；标出没做的场景，不能只写“测试通过”。
6. 本次变更文件 diff，以及用户原有修改保留情况；默认不 commit、不 push、不部署。

发布时备份确切将替换的源码、静态产物和配置，并保存对应版本清单。先预发布验收，再将同一批已验收产物交付上线；站点版本/缓存与资源包协调更新。回退按该批清单恢复，不能恢复成旧包同时丢掉本次之前的用户修改；需要数据迁移时单独制定数据回退。

**本方案完成标准：有依据地保留更好的项目实现，迁入确实缺失的更新，组件来源和版本可追溯，两仓及租户可重复构建，关键场景实际验收。版本字符串相同、文件数量相同、与原版哈希一致都不是单独的完成标准。**

## 10. 可核验来源

- 官方下载日志页（本轮抓取 502，正文未取得）：<https://www.fastadmin.net/download.html>
- 官方仓库及默认分支：<https://github.com/fastadminnet/fastadmin>
- 1.6.1 到核查时开发分支的完整差异：<https://github.com/fastadminnet/fastadmin/compare/v1.6.1.20250430...5ad4f8d5fde8df4cc43b01b5b311329df953e0ce>
- 1.6.8 正式基准提交：<https://github.com/fastadminnet/fastadmin/commit/148b37d9ced3a0a1d07273a55425037209a01c6a>
- 正式基准的版本配置：<https://github.com/fastadminnet/fastadmin/blob/148b37d9ced3a0a1d07273a55425037209a01c6a/application/config.php>
- 正式基准 NPM 清单与锁文件：<https://github.com/fastadminnet/fastadmin/blob/148b37d9ced3a0a1d07273a55425037209a01c6a/package.json>、<https://github.com/fastadminnet/fastadmin/blob/148b37d9ced3a0a1d07273a55425037209a01c6a/package-lock.json>
- 正式基准之后 5 个提交：<https://github.com/fastadminnet/fastadmin/compare/148b37d9ced3a0a1d07273a55425037209a01c6a...5ad4f8d5fde8df4cc43b01b5b311329df953e0ce>
- 官方早期发行记录：<https://gitee.com/fastadminnet/fastadmin/releases>
- Grunt 异步任务失败语义：<https://gruntjs.com/api/inside-tasks>
- npm ci 的锁文件约束：<https://docs.npmjs.com/cli/v10/commands/npm-ci/>

本地证据均为本文日期读取的实际文件。历史审计只用于识别可能的定制，不作为当前代码或已通过测试的证明。
