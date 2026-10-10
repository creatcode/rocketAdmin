# TP6 / TP8 复杂度优化审计与实施记录

> 2026-10-01 复核更正：此前“组件清理不影响功能”的结论遗漏了 CRUD 使用的 Font Awesome 图标映射，组件浏览器检查也遗漏了重复加载和格式化标签。已按文末复核记录修复；此前与原版逐字节一致的描述仅代表历史状态，不代表当前修复后的组件。2026-10-10 基准变更：前端组件基准改为官方原版 `1.6.6.20260902`，并回退本文档「追加清理」的部分结论，见文末末节；与本文档冲突处，以用户指令与文末最新记录为准。

执行日期：2026-09-30

依据：两版 AGENTS.md、实施前优化评审、当前源码与真实调用链。
本次交付：已实施具备条件的源码、生成模板优化，并按用户指定的本地 FastAdmin 原版同步两版组件、构建声明和锁文件；两份文档同步更新。没有修改 .env、本机配置或其他项目，没有提交或推送 Git。

**优化原则：以功能及其实现合理为前提。只处理已证实的冗余和功能错误，不以行数、形式统一或抽象数量衡量收益；不执行已撤回的整套 CRUD/插件模块合并。**

## 1. 当前基线与修改边界

| 项目 | 根目录 | 本轮开始 HEAD | 框架 / ORM lock | 项目 PHP 声明 |
| --- | --- | --- | --- | --- |
| TP6 | `E:/phpstudy_pro/extraproject/rocket-admin` | `40a7191` | ThinkPHP 6.1.2 / ORM 2.0.62 | >=7.4.3 |
| TP8 | `E:/phpstudy_pro/extraproject/tp8-fastadmin` | `8fb59e1` | ThinkPHP 8.1.4 / ORM 4.0.51 | >=8.0 |

- 本轮开始两版 `git status --porcelain` 均为空；旧文档所列 HEAD 及“两个文档未跟踪”的工作树状态已过时。未重置、覆盖或提交工作树。
- 仅修改本文列出的源码、CRUD stub、两版前端组件与 package.json/package-lock.json/Gruntfile.js 和两份审计文档。没有为 TP6 新增租户模块。用户追加明确要求构建体系一致，因此为 TP8 补上根级 npm/Grunt，替代最初“不新增 TP8 构建”的边界。
- PHP 行为回归使用 PHP 8.0.2；Node 22.19.0、npm 10.9.3；独立 MySQL 5.7.26 绑定 `127.0.0.1:33379`，数据目录在临时目录中，与现有数据库服务隔离。
- 验证目录：`%TEMP%/rocket-optimization-20260930`。加载临时副本的生成命令和 stub，配置在测试进程内设置，不加载项目 .env，不操作原数据库。首次平台生成测试暴露源码相对路径，所产生的新建 plain 测试语言包/视图已清除；后续完整回归均使用隔离副本，最终工作树无测试产物。
- 本轮未采用外部设计重构；前端依据用户指定的 `E:/phpstudy_pro/extraproject/fastadmin原版` 本地副本。原版只读，组件差异与真实 RequireJS 调用链逐项核对。

## 2. A01–A10 的实际处置

| ID | 状态 | 实际处置 | 两版同步 |
| --- | --- | --- | --- |
| A01 | 工具共享已撤回；保留可靠性修复 | 用户明确租户 Min 为独立功能，恢复租户 r.js 和两个 stub 及独立读取路径；保留 runtime 临时文件、失败处理及 CSS 入口修复。 | 两版平台临时文件规则同步；TP8 租户保持独立工具。 |
| A02 | 已实施明确功能修复；整套合并跳过 | 修复租户连接、递归生成、精确表名、校验、语言转义、主键/关联显示/写入失败；修复两版 Selectpage 元数据调用并适配 TP8 ORM。 | 两版平台及 TP8 租户分别核验和回归，保留租户归属差异。 |
| A03 | 待确认；合并及事件裁剪跳过 | 安装范围注释与实际列表不一致，自定义配置模板只读策略未明确；服务端写操作拒绝保持原样。 | 平台生命周期与租户查看脚本均保留。 |
| A04 | 跳过 | 资料脚本虽相同，但独立裁剪部署是否包含平台页面模块未证实；新增跨页面目录依赖收益不足。 | TP8 保留副本；TP6 无重复入口。 |
| A05 | 已实施 | 删除 10 个签名/可见性一致的父类转发；保留公开路由和租户数据限制实现。 | 两版 AdminAuth，另处理 TP8 的对应纯转发。 |
| A06 | 已实施 | 两版仅用 array_column 提取上游数组节点 ID，不改树算法。 | 两版相同改动。 |
| A07 | 已实施 | 删除 deploy 局部未使用 getAllFiles 与专用 fs 引入。 | 用户要求构建一致后，TP8 新增构建文件采用相同实现。 |
| A08 | 已实施 | 三个 CRUD 命令只在 getReplacedStub 归一化数组占位值。 | 两版平台及 TP8 租户相同改动。 |
| A09 | 保留，不执行 | 原文本域默认行列处理已清楚，替代方案增加理解成本。 | 两版原实现保留。 |
| A10 | 原整理方案撤回；按用户原版基准实施 | 恢复原版三个直接依赖、别名及表格复制映射，采用原版对应锁文件；不再以减少依赖声明为目标。 | 两版清单、lock、Grunt 同步；组件同步，租户 Min 保持独立。 |

### A05、A06、A07、A08：局部明确冗余

- A05：两版 `app/admin/library/AdminAuth.php::__construct()`；TP8 `Backend::{buildparams,getDataLimitAdminIds,selectpage,token}`、`TenantBackend::{buildparams,selectpage,token}` 和 `app/tenant/controller/Addon.php::initialize()` 改为直接继承。父类实现没有修改。Category/TenantCategory 的 public Selectpage 路由、API 的可见性差异、TenantBackend 的数据归属限制及 TP6 Backend 的实际实现继续保留。
- A06：两版 `extend/util/Tree.php::{getChildrenIds,getParentsIds}` 改为 `array_column($this->getChildren(...), 'id')` / `array_column($this->getParents(...), 'id')`。调用方仍用于权限规则删除、用户组校验、菜单及鉴权父节点读取。没有改顺序、类型、比较规则或递归。
- A07：只删 Grunt deploy 内不参与复制任务的局部递归遍历及 fs 引入；TP8 新增构建采用同一实现。copy:main、dists、路径映射和四个打包任务继续使用原构建能力。C04 中的三个开发依赖经用户追加授权后清理，见追加清理记录。
- A08：两版平台与 TP8 租户 `writeToFile()` 不再重复遍历数组占位值；保留 `getReplacedStub()` 的唯一处理及其直接调用方、stub 缓存。写入返回值/失败策略另按 A02 修复，不新增 helper。

### A02：修复已证实的生成器功能问题

保留三个命令的独立结构及不同模板，没有建立继承层、trait、公共模板回退或整文件互相覆盖。

TP8 租户生成器的具体修复：

1. `--db` 未指定时取 `database.default`，显式连接仍被保留；缺失连接先报明确错误。生成模型的连接声明也以真实默认连接判断。
2. 主表及关联表使用参数化 `SHOW TABLE STATUS WHERE Name = ?`。表名中的下划线不再作为 LIKE 通配符，避免选择错误表；没有删除平台原校验。
3. hasmany 缺失关联控制器时同进程运行本租户命令，传递当前 db/local/table/controller/model/fields；不再调用平台 crud 或拼接 shell。租户控制器、视图、JS、语言包及 shop_id 保留规则保持各自目录/职责。
4. 区分关联表实际主键 `relationPriKey` 与关联匹配键 `relationPrimaryKey`，补上业务模型和关联模型 stub 的实际 `$pk`，并向关联模型传递自定义连接。显式主键映射不再被误当成关联表主键。
5. 补上可见字段、关联类型/字段及类名路径校验，使用当前 PHP 解析器拒绝保留字。显式 fixedcolumns 选项优先，仅未指定时自动取值。
6. 语言包条目用 var_export 转义；等号对齐正则匹配整条内容，避免截断含引号/换行内容。writeToFile 失败抛异常，不再继续报告生成成功。
7. 关联 visible 与主表白名单合并；空关联保护；只有 hasmany 时不生成空 with，仍保留显式主表可见字段对应 index。
8. 将已选中的 Selectpage 展示字段写入 data-field。三命令原先调用 Query 上不存在的 getTableInfo，异常被吞掉；改成当前 `$dbconnect->getTableInfo($prefix . $selectpageTable)`，使用真实连接的受支持 API。TP6/TP8 平台同步修复。
9. 实际执行生成的显示代码发现 ORM 版本差异：TP8 ORM 4 的 getRelation 返回寄存数组，不能用来获取已加载模型。TP8 平台/租户生成代码使用 `$row->get(...)`；TP6 ORM 2 继续使用 `$row->getRelation(...)`。两版都覆盖空关联和加载关联的输出白名单。

两版平台此前已有其余对应修复，本轮保留并通过同一数据库用例核验。语言包、目录、主键、连接、输出白名单均验证生成产物；没有承诺所有历史模板/插件/菜单路径都经过回归。

### A01：保留租户独立工具，分离运行时文件

- 用户明确租户 Min 是独立功能。此前仅凭文件相同及共同注册就改成共享，未充分确认功能边界，该判断已撤回。已按原内容恢复 `app/admin/tenantcommand/Min/{r.js,stubs/css.stub,stubs/js.stub}`；tenant:min 从自身目录读取工具和模板，不依赖平台 Min 文件。
- TP6/TP8 平台及 TP8 租户临时配置均放在 `app()->getRuntimePath()/min/temp_<唯一值>.js`，父目录由现有 writeToFile 创建。干净租户目录不依赖残留工具或临时文件。debug 保留配置，正常和失败的非 debug 流程清理自身文件。
- 租户补上 optimize 校验、缺失源文件/JS 配置异常、写入失败检查、Node 退出码检查和 finally 清理；错误输出随异常返回，不再无条件成功或追加源码目录 node.log。
- 运行发现 tenant.css 不存在；租户 `view/common/meta.html` 在开发/压缩模式均引用 backend.css。因此租户命令 CSS 入口明确使用 backend，JS 仍为 require-tenant，未创建新的样式体系。
- 此前的共享方案回归只作为历史记录。恢复独立工具后，另在不含 `app/admin/command` 的临时副本通过租户 Min 10 项检查：JS/CSS/all、none/uglify、debug、缺失源文件、Node 失败、非法选项及临时文件清理。closure/Java 成功压缩、其他外部裁剪部署未执行验证。

### A03、A04：不依据文本相似强行合并

A03 已核实平台控制器负责安装/升级/启停；TP8 租户写接口及配置 POST 进入 tenantAddonDenied。租户默认配置模板禁用控件，控制器允许插件自带 config.html。index/downloaded 实际遍历全部已安装插件，而注释写“已安装并启用”。租户脚本仍保留平台式事件，市场元数据也可能由服务端访问；这些证据不足以决定删除事件或改变插件范围。

**待用户明确**：租户应看到所有已安装插件还是仅已启用插件；插件自定义配置页是否必须强制只读。已提出具体问题，答复前保留现行为。没有移除服务端拒绝接口，也没有将整模块参数化合并。

A04 当前 profile 两个脚本 SHA-256 相同、RequireJS 可解析 backend 页面目录，但这只能证明标准仓库可加载，不能保证独立租户裁剪部署包含该模块。保留副本，不新增公共模块层；资料更新、头像、日志浏览器回归本轮未执行。

### A10：按本地原版统一前端组件和构建

用户明确前端组件及构建以 `E:/phpstudy_pro/extraproject/fastadmin原版` 为准，且两版构建体系一致。最初删除三个重复直接依赖的方案已撤回：恢复 fastadmin-arttemplate、fastadmin-bootstraptable、fastadmin-nicevalidator，保留原版 npm 别名和历史目录映射。运行组件声明和版本与原版一致；后续按用户追加授权清理无影响的构建残留，具体差异见下文。没有重新解析版本范围获取其他组件版本。

这次锁文件变更依据是用户指定的原版基准，包含 Sortable 1.15.6 → 1.15.7、bootstrap-table 1.11.12 → 1.11.13 及原版锁定的传递/开发依赖差异，不能继续称为“只删三个包、版本不变”。此前 2,328 文件构建完全等价的结果仅属于已撤回的 A10 整理方案，不作为当前版本等价证据。

- 两版各清理 1,436 个原版没有的组件包内文件，主要为源码、示例、测试、文档和包开发配置；并非删除业务页面或功能。删除前保留完整差异和临时备份，未清理公共 LESS、业务 CSS/JS 或租户目录。
- 各补齐 fastadmin-tagsinput/LICENSE 和 dist/bootstrap-tagsinput.min.js；更新 Sortable 两个文件及 bootstrap-table 主文件、filter-control 各两个文件，共 6 文件。其余原版组件逐字节保留/匹配。
- 用户明确保留页签右键功能。两版保留 bootstrap-contextmenu/bootstrap-contextmenu.js（包含 MIT 声明）、原有 RequireJS 路径与 backend-init 调用；仅清理该包的重复副本、示例和开发声明。另保留 jquery/external/sizzle/LICENSE.txt 与 toastr/LICENSE，不因原版缺少独立许可证文件而删除。
- 首轮原版对齐后，两版各 580 个文件：577 个原版资源逐文件 SHA-256 一致，另有上述右键脚本和两份许可证。后续仅清理其中 5 个开发文件，当前状态见下文。
- TP8 根据用户追加要求补上 package.json、package-lock.json、Gruntfile.js；两版构建文件一致，部署、前后台 JS/CSS 四个任务均可执行。Grunt 保留项目已有 app/admin/command/Min/r 路径、vm 配置解析、CRLF 适配及失败传播，不照搬原版 application 目录和旧解析方式；A07 未使用遍历仍不恢复。TP8 tenant:min 继续独立读取自己的工具及模板，不改成平台共享。
- tagsinput 业务仍加载现有 public/assets/js/tagsinput.js，不为补齐发行资源擅自替换其扩展行为。

首轮原版对齐验证：两版独立临时副本均完成 npm ci、npm run build，各复制 574 个文件并完成四个任务；首次受限缓存安装失败，随后联网安装并离线复验成功。两版浏览器各 10 项检查通过：Sortable 版本、初始顺序及重排，表格 HTML 转义、筛选特殊字符/普通选项、无注入图片，tagsinput 增删，右键菜单显示后回调。首次 tagsinput 断言因测试页未初始化而失败，按实际 API 初始化后通过，未据此修改组件。该测试是独立组件页，不是整套后台业务验收。

两版前后台 RequireJS 分别解析 38/40 个路径或包，TP8 租户解析 42 个，均存在。组件更新后，租户 Min 在不含平台 command 工具目录的临时副本再通过 10 项独立回归。原版自身目录未写入；工作树没有新增 node_modules 或压缩构建产物。

### 追加清理：用户授权移除无影响的组件/构建残留

> 本节部分结论已于 2026-10-10 按用户指令回退（组件基准改为官方 `1.6.6.20260902`），见文末「2026-10-10 组件基准切换与回退」。

- 原版组件仍为 25 个，版本与运行资源保持一致，另保留右键组件。移除两版 libs 中的 bootstrap-slider/Gruntfile.js、jquery.cookie/Gruntfile.js、toastr/gulpfile.js、fastadmin-selectpage/package.json 和 README.md。这 5 个文件属于组件自身开发/说明材料，项目不调用其构建任务；不是运行资源或许可证。当前每版 575 个组件文件：原版资源减去这 5 文件，再加右键脚本及两份许可证。
- Grunt 复制规则排除上述开发脚本/包说明，避免 npm build 再次复制回来；去掉未使用的回调参数和注释调试代码，保留原构建流程及错误处理。
- 删除没有对应 npm 依赖、资源或调用的 coloris dists 声明。删除 grunt-contrib-clean（未注册清理任务）、jsonminify 和 parse-config-file（当前采用原生 vm 解析）的开发依赖，保留实际使用的 grunt、grunt-contrib-copy。
- 对应锁文件在临时目录离线生成，仅移除 8 个包节点：以上 3 个直接依赖及 coffeescript、cson-parser、rimraf、yaml-parser、其嵌套 esprima。逐节点核对其余内容完全不变，没有升级任何保留组件或构建依赖；三个 FastAdmin 直接依赖及别名继续保留。
- 此次用户明确授权取代 C04 中对这三个构建依赖的保留结论；C04 其他对象、A09 及其他保留项不受影响。
- 清理后两版独立临时副本分别通过离线 npm ci、完整 npm run build，各复制 569 文件并完成四个任务。与清理前构建逐文件对比，路径集合仅少上述 5 个开发文件，所有其余资源 SHA-256 完全相同，包括四个 JS/CSS 压缩产物。Grunt 语法、RequireJS 前台/后台/租户映射、两版文件同步及 git diff --check 均通过。没有新增组件版本或运行功能变化，因此本次未重复此前组件浏览器检查，也未新执行业务浏览器/数据库检查。

## 3. 继续保留的实现

以下保留结论延续此前评审；本轮未修改这些实现，也未重新验证全部调用，不构成对整套功能正确性的认证。Excel、升级能力及外部扩展的全部运行路径未复验，不把“继续保留”解释为已通过回归。

| 对象 | 保留依据 |
| --- | --- |
| TP8 `hyperf/pimple` | 支付 SDK 的 `vendor/yansongda/artful/src/Service/ContainerServiceProvider.php:94` 在默认容器路径实际实例化 ContainerFactory；只搜索业务目录会漏掉。 |
| 两版 Carbon | Dashboard 有真实使用；composer.lock 中 think-queue 也依赖 nesbot/carbon，移除业务调用不等于能删依赖。 |
| 两版 `overtrue/pinyin` | `app/admin/model/AuthRule.php:24` 直接创建 Pinyin 并生成菜单 py/pinyin；工具包装类是否可删与底层依赖是否可删是两回事。 |
| PaymentProviderInterface | 存在 WechatPayProvider 和 AlipayProvider 两个真实实现，不能按“单实现接口”删除。 |
| Token 驱动层 | 有 Mysql、Redis 两类实际驱动，Manager 已使用框架原生管理器；保留命名连接和强制新建语义。 |
| TP8 公共 CRUD trait / 租户 trait | 已共享 CRUD 主流程，租户 trait 仍有字段归属和批量白名单差异；不能再次合并掉这些行为。 |
| 两版 `bootstrap/` 和 `bootstrap-less/` | 两者均在 LESS 导入链中，后者包含覆盖内容；“同名目录/部分相同文件”不足以删除整个目录。 |
| TP6 UpgradeService | 文件较大，但包含备份、恢复、维护锁和升级日志等真实行为；不能仅按文件长度裁剪。 |
| Excel 服务和现有导入实现 | TP6/TP8 服务能力和 API 形态不同，且有公开入口/使用文档；无内部业务调用不能直接证明整套功能无用。 |

## 4. 仅作盘点、继续保留的对象

下列对象没有足够依据表明其功能实现复杂或存在需要消除的过度设计，本次不建议删除，也不加入默认执行清单：

| ID | 对象 | 盘点结果 | 优化边界 |
| --- | --- | --- | --- |
| C01 | 两版 JsonService | 当前搜索仅命中类定义；API 另有 result/success/error 链。 | 继续保留；未找到调用不能证明公共服务没有价值，不能直接删类或缩减返回能力。 |
| C02 | 两版 util/Rsa、util/Pinyin 包装 | 本轮在 app/kernel/extend/addons 的类名引用搜索未命中包装类调用；不排除动态或外部调用。Pinyin 底层库有真实调用。 | 继续保留工具 API 和底层依赖；除非另有具体复杂度问题及功能等价替代，否则不处理。 |
| C03 | Form 的 Arr、点号数组访问和辅助函数 | Form 直接读取只需要 cols/rows，但辅助 API 还提供 ArrayAccess、点号访问和 array_except 等能力。 | A09 已撤回，现有文本域代码及其他辅助能力均保留。 |
| C04 | overtrue/easy-sms 与三个构建开发依赖 | easy-sms 继续保留；用户追加授权后已确认三个构建开发依赖未被当前构建调用。 | 两版清理这三个开发依赖并验证构建；其他对象继续保留。 |

这些条目是搜索范围和判断边界的记录，不是“后续可删清单”。后续 Agent 不能仅凭本表或静态引用数量实施删除。

## 5. 验证结果（分别记录两版）

| 检查 | TP6 | TP8 | 范围/限制 |
| --- | --- | --- | --- |
| 改动 PHP 源码 php -l，PHP 8.0.2 | 4 文件通过 | 9 文件通过 | stub 通过生成产物检查，不把 stub 当完整 PHP。 |
| TP6 改动源码 PHP 7.4.3 语法 | 4 文件通过 | 不适用 | 仅语法；不代表全依赖/继承链能在最低版本运行。 |
| Gruntfile / 更新及新增组件 JS node --check | 通过 | 通过 | 含 Sortable、表格/filter-control、tagsinput；未修改业务页面 JS。 |
| A05 初始化/反射 | auth 配置初始化、继承构造通过 | auth 初始化、参数数目/可见性/声明类、租户限制和公开路由保留通过 | 没有执行完整登录、Token HTTP、Selectpage 浏览器流程。 |
| A06 ID 提取 | 60 项严格比较通过 | 60 项严格比较通过 | 空树、多层、缺失/null ID、不存在节点、withself、字符串/整数 ID；只比较原上游节点提取结果，不声称修复树算法。 |
| A08 混合占位值和实际写文件 | 平台通过 | 平台、租户通过 | 字符串/整数/布尔/null/数组、缓存、原入参不变、实际文件内容/字节数。 |
| CRUD 独立 MySQL 回归 | 平台 16 用例通过 | 平台 16、租户 16 用例通过 | 普通/软删/枚举/日期、多级目录、三类关联、自定义连接/键、fields、fixedcolumns、语言转义、Selectpage 元数据、已有文件、非法输入、删除、写入失败。 |
| 生成的 visible 代码实际执行 | 4 项通过 | 平台 4、租户 4 项通过 | 空/加载关联，主表字段保留，关联字段白名单；使用各自 ORM。 |
| 生成 PHP/JS 语法 | 32 文件通过 | 平台 32、租户 32 文件通过 | 最终产物逐文件 php -l/node --check，不计复制的命令源码/common.php。 |
| Min 标准资源/失败回归 | 19 项通过 | 原回归 28 项通过；恢复独立后租户 10 项通过 | 实际 Node；独立租户复跑的临时副本不含平台 command 工具目录。 |
| tenant:min 并发 | 无租户入口 | JS/CSS 并发通过；两个独立 debug 配置保留且内容正确 | 只验证临时配置隔离，不承诺同一目标产物的任意并发写入安全。 |
| 当前清理后 npm ci / npm run build | 通过 | 通过 | 两版离线安装；各复制 569 文件、四个打包任务通过；临时目录执行。 |
| 当前组件/lock 基准 | 原版减去 5 个开发文件，保留 3 文件；组件共 575 文件 | 同 TP6，完整组件集合/哈希同步 | 其余运行资源匹配原版；lock 仅清理 8 包节点，其余节点不变。 |
| 组件浏览器回归 | 10 项通过 | 10 项通过 | 独立测试页，含特殊字符筛选、标签、排序及右键回调。 |
| RequireJS 路径解析 | 前台 38、后台 40 通过 | 前台 38、后台 40、租户 42 通过 | 文件存在性检查，不代表所有动态页面逐页运行。 |
| 更新组件后的独立 tenant:min | 无租户入口 | 10 项通过 | 副本不含平台 command 工具；仍独立构建。 |
| git diff --check / 文档同步 / 修改范围 | 通过 | 通过 | 两份文档内容相同，工作树仅含列出的授权改动，无测试文件、.env 或本机配置改动。 |
| 菜单创建/删除联动 | 未执行 | 未执行 | 回归使用不带 --menu 的生成命令，不将文件删除测试写成菜单回归通过。 |
| 浏览器完整业务回归 | 未执行 | 未执行 | 平台生命周期、租户配置、登录/Token/查询范围、生成表单和资料页未逐页操作。 |
| 最低 PHP 全链运行 | 未执行 | 未执行 | PHP 8.0.2 行为回归不能替代 TP6 7.4.3、TP8 8.0.0 的完整兼容验证；当前 TP8 Composer platform_check 最低为 8.0.2。 |
| closure/Java、外部插件/裁剪/构建工作流 | 未执行/未确认 | 未执行/未确认 | 保持待验证，不宣称通过。 |

### 临时验证脚本及复跑方式

脚本保留在 `%TEMP%/rocket-optimization-20260930`，不向仓库新增测试框架或测试环境配置。必须继续使用独立环境，不把测试根目录改成原项目；crud-check.php 的测试输出目录应使用新目录，防止已有文件影响用例。

- `local-check.php <项目根> <临时测试根>`：A05/A06/A08；仅加载锁定依赖和内存 App，不初始化 .env。
- `crud-check.php <项目根> <新临时测试根> [tenant]`：独立数据库 `optimization_fixture`，仅 `127.0.0.1:33379`；命令/stub 加载前复制到临时目录。先启动本轮临时 MySQL 数据目录，禁止连接原数据库。
- `visible-check.php <项目根> <对应CRUD产物目录> [tenant]`：实际执行生成的白名单片段。
- `prepare-min.py` / `min-check.php <项目根> <临时Min副本>`：此前共享方案的历史测试，不用于独立方案复跑。当前使用 `tenant-independent-check.php <TP8根> <独立租户Min副本>`，副本包含租户自己的工具，不含平台 command 目录。
- `min-once.php <TP8根> <临时Min副本> js|css`：并发配置隔离。
- `check-lock.py` / `compare-build.py`：旧 A10 方案的历史对比，不用于当前原版版本验证。当前使用 `verify-frontend.js`、`verify-frontend-final.py` 核对路径、原版资源/lock 和两版构建结果；`prepare-component-browser.py` 生成独立浏览器检查页。不要把任何 patch/align 脚本作为可重复迁移运行。

## 6. 保留与未解决事项

- A09、C01–C03 及 C04 的 easy-sms 继续保留；C04 三个构建依赖按追加授权清理，不将其他项作为待删清单；Carbon/Pinyin/支付容器、接口/驱动、CRUD trait、LESS、Excel 和升级服务也未删除。
- A03 的插件可见范围、自定义配置页只读策略待明确；A04 因部署前提不足跳过。两项都未执行整套合并。
- 已落地项核心检查通过，但浏览器、菜单联动、最低 PHP 全链兼容、closure 和外部裁剪部署仍待验证，不能将本轮结果解释成整个系统验收通过。
- 后续继续遵守对应逻辑双版同步、最小范围、不习惯性转换内部 PHP 值、不覆盖其他修改、不提交/推送等边界。

收尾：独立 MySQL 测试实例已通过 33379 端口正常关闭。临时脚本、生成产物和构建对比副本留在临时目录供核对；原数据库及现有服务未停止。


## 2026-10-01 未提交变更复核及修复

重新检查两版所有已暂存、未暂存、新增文件，以及删除资源的业务引用、CSS/LESS 依赖和 RequireJS 前台/后台/租户入口。修复以下已确认的问题：

1. filter-control 在去重前转义选项值，导致 `A&B` 等值在表格每次 load 后重复累积。恢复对原始值的比较，并使用 `.attr()` / `.text()` 安全赋值。
2. 组件更新将 formatter 生成的 HTML 标签原样显示，丢失原有纯文本标签行为。使用 DOMParser 的独立文档提取标签文字，不将解析出来的节点插入活动页面；压缩文件用项目已有 r.js 重新生成，禁用额外 AMD 模块插入。
3. 被删除的 `font-awesome/less/variables.less` 仍被两版平台及 TP8 租户 CRUD 用于匹配控制器 `@icon`。恢复这个单文件，并加入 font-awesome 的 dists，防止部署副本遗漏。未恢复整套第三方源码、示例或文档。

两版 dists 排除项目修复后的两个 filter-control 文件，防止 npm build 再次用发行包覆盖它们；其他表格文件仍按锁定发行包复制。新增 `tests/frontend/filter-control.html`，直接用浏览器打开即可运行去重、格式化标签、安全赋值和字段筛选回归检查，页面显示“通过”表示成功。

本轮执行结果：

- 两版隔离副本 npm ci 和完整 npm run build 通过，各安装 257 个锁定包、复制 568 文件并完成前后台四个 JS/CSS 任务；安装使用 --ignore-scripts。首次离线安装因缓存缺少 yargs 失败，联网按原锁文件补齐后通过，没有升级依赖。
- 构建后两个 filter-control 修复文件的 SHA-256 未改变，Font Awesome 图标映射存在；两版上述修复、清单、lock、Grunt 和回归页面逐字节同步。
- 两版浏览器回归页面通过：连续三次 load、空值、中文、A&B、带引号和 HTML 的值、格式化标签文本、无注入元素/事件执行、按原始字段值筛选。测试列使用 searchFormatter:false，未声称覆盖所有 formatter 搜索策略。
- TP6 PHP 7.4.3、TP8 PHP 8.0.2：鉴权配置初始化、每版 40 项树节点 ID 严格比较、CRUD 混合占位符实际写入、平台 Min JS/CSS/all、none/uglify、非法 optimize 和临时文件清理通过。TP8 独立 tenant:min JS/CSS/all 及清理通过。
- 两版 PHP 语法、修复 JS/Grunt 语法、git diff --check、RequireJS 路径及图标映射读取检查通过。
- 两版 Min 缺失 JS 配置时正确报错且无临时文件残留；TP8 ORM 实例验证已加载关联、主表/关联输出白名单合并和空关联保护通过，模型 schema 由测试夹具显式提供，不连接数据库。
- 本轮未访问业务数据库，未执行真实数据库 CRUD 生成、完整登录/菜单/插件业务、closure/Java 压缩验证。本文此前数据库或组件验证记录均为历史结果，不作为本轮已执行结果。

其余纯父类转发删除、Tree 的 array_column 简化、CRUD 占位符去重、Min 临时文件位置、受支持的连接元数据 API、TP8 ORM 读取适配和租户生成器修复，经调用链复核没有发现应回退的新增问题，保留。仍不把静态检查或局部功能检查表述为完整业务验收。

## 2026-10-10 组件基准切换与回退（用户指令）

用户指定前端组件以官方原版 `1.6.6.20260902` 为基准，并明确「以用户说法为准、本文档记录排后」。据此回退上文「追加清理」中的部分结论：

- 恢复 5 个组件文件：`bootstrap-slider/Gruntfile.js`、`jquery.cookie/Gruntfile.js`、`toastr/gulpfile.js`、`fastadmin-selectpage/package.json`、`fastadmin-selectpage/README.md`，与官方逐字节一致。
- `Gruntfile.js` 撤销 4 条排除规则（`!**/Gruntfile.js`、`!**/gulpfile.js`、`!**/package.json`、`!**/README.md`），deploy 复制规则回到官方写法。
- `package.json` 补回 `grunt-contrib-clean`、`jsonminify`、`parse-config-file` 三个开发依赖与 `coloris` 的 dists 声明，依赖顺序对齐官方。
- `package-lock.json` 采用官方文件，逐字节一致（此前仅差 8 个包节点与 devDependencies 段）。
- 清理 `public/assets/libs/` 下 136 个残留空目录。

**不随官方回退的差异**（均为项目必需）：

- `package.json`：`fastadmin-bootstraptable` 的 `dists` 排除 filter-control 两个文件（否则 build 覆盖项目修复）；`font-awesome` 的 `dists` 含 `less/variables.less`（CRUD `@icon` 映射）。
- `Gruntfile.js`：Min/r 路径 `./app/admin/...`（官方为 `application/`，TP6 必须）；require 配置用 `require('vm').runInNewContext()` 解析；`callback(err)` 传播失败。
- `libs` 中官方没有的 4 个文件：`bootstrap-contextmenu/bootstrap-contextmenu.js`、`font-awesome/less/variables.less`、`jquery/external/sizzle/LICENSE.txt`、`toastr/LICENSE`。

对齐结果：`libs` 581 文件 = 官方 577 + 保留 4；官方有而本项目缺 0；内容不同 2（filter-control 修复）；`package-lock.json` 与官方差异 0。校验通过 `node --check`（Gruntfile 与恢复的 3 个 JS）与 JSON 解析；未执行 `npm ci` / `npm run build`。

本节结论取代上文「追加清理」中的对应条目，上文作为历史记录保留。
