# systemgroup（组合数据）实现评审 —— 对照 CRMEB 4.1.0 原版

评审范围：当前项目 `app/admin/controller/system/Systemgroup*.php`、`app/common/model/SystemGroup*.php`、`app/admin/validate/SystemGroup*.php`、`app/admin/view/system/systemgroup*`、`public/assets/js/backend/system/systemgroup*.js`、`rocketadmin.sql` 中两张表定义；对照 `CRMEB4.1.0/crmeb` 的 `app/adminapi/controller/v1/setting/SystemGroup*.php`、`app/services/system/config/SystemGroup*Services.php`、`crmeb/services/GroupDataService.php`、dao/model 层。

## 一、当前实现结构

### 1. 数据层

| 表 | 字段 | 说明 |
| --- | --- | --- |
| `im_system_group` | id, name(唯一索引), title, tip, fields(JSON text), weigh, status(normal/hidden), createtime, updatetime | 组定义 |
| `im_system_group_data` | id, group_id, value(JSON text), weigh, status, createtime, updatetime | 组内记录，联合索引 (group_id, status, weigh, id) |

字段定义存 6 种类型：string / text / number / switch / image / select（options 为"键|值"多行文本）。

### 2. 代码分层

- 控制器：`Systemgroup`（组 CRUD + multi 白名单 status/weigh）、`Systemgroupdata`（记录 CRUD，group_id 服务端注入，编辑/删除限定组内）。
- 模型：`SystemGroup::normalizeFields()`（字段定义校验规范化）、`SystemGroupData::normalizeValue()`（动态值校验，区分 0/false 与空）、`SystemGroupData::getDataList($name)`（业务读取，隐藏组/记录不返回，损坏 JSON 抛异常）。
- 并发控制：组编辑与记录写入互相 `lock(true)` + 事务；非空组禁删；有记录时字段结构签名（md5 全量比对）不一致禁改；组标识 name 创建后不可改。
- 安全：token() CSRF、strip_tags、未知字段/未知属性拒绝、image 协议黑名单、multi 字段白名单。
- 前端：fieldlist 自定义行模板编辑字段定义；记录列表按字段定义动态生成列（operate:false，不参与搜索/排序/筛选）；"管理数据"按钮携带 group_id 进入记录页。

### 3. 菜单

auth_rule 730（组合数据）+ 745（组合数据记录）两组，index/add/edit/del/multi 均已登记。

## 二、与 CRMEB 原版对比

| 维度 | CRMEB 4.1.0 | 当前实现 | 结论 |
| --- | --- | --- | --- |
| 组标识 | config_name，编辑时**不查重**（`if (!$id)` 才查重，编辑可撞名） | name 创建后不可改 + 唯一索引 | 当前优 |
| value 存储 | `{字段title: {type, value}}`，以**中文标题做键**且冗余存 type，标题改名数据即错位 | `{字段name: 值}`，只存值 | 当前优 |
| 字段类型 | input/textarea/radio/checkbox/select/upload/uploads（7 种） | string/text/number/switch/image/select（6 种），缺多图、checkbox、日期 | CRMEB 优 |
| 动态表单 | FormBuilder 服务端生成，类型分发集中在一处 | 视图片段 + formFields + JS formatter，类型逻辑分散 4~5 处 | 各有代价 |
| 删组 | 删组后连带删数据（`delete($id,'gid')`），**无事务** | 非空组禁删 + 行锁事务 | 当前优 |
| 校验 | 只查空字符串，0 也是空；数值/选项不校验；异常全吞成 `[]` | 0/false 合法值区分、数值范围、选项白名单、未知字段拒绝，损坏数据抛异常 | 当前优 |
| 并发 | 无锁无事务（仅 saveAllData 有事务） | lock(true) + 事务 | 当前优 |
| 业务读取 | `GroupDataService::getData(config_name, limit, isCache)` + **缓存**（写后 CacheService::clear）+ 按记录 id 单条读取 + saveAll 批量保存 | `getDataList($name)`，无 limit、无单条、无缓存 | CRMEB 优 |
| 读取 URL | upload 类型读取时 `set_file_url()` 补全完整地址 | 返回原始路径，消费者自行处理 | CRMEB 略优 |
| 业务约束 | 轮播图≤4、签到≤7 天、秒杀时段不重叠等，**硬编码 config_name 散落在控制器** | 无任何机制 | 都不行，方向不同 |
| 安全 | api 无 CSRF 表单；strip 依赖全局 | token() + 过滤 + 白名单 | 当前优 |

总体判断：**数据正确性、安全、并发上当前实现全面好于 CRMEB 原版**（原版有三处实打实的缺陷：编辑不查重、title 做键、删组无事务）。当前的问题集中在**可用性和扩展性**，不在正确性。

## 三、设计缺陷与不合理之处

### P0-1 字段结构一旦有记录就永久锁死，没有任何演进路径

`fieldsSignature` 对字段定义做 md5 全量比对，组内有一条记录就不允许任何变更。实际运营最先撞上的需求就是"给已有组加一个可选字段"（友情链接加备注列），现在做不到——只能删组重建（非空组又禁删，得先搬数据）。CRMEB 反而随便改（代价是改完新旧数据错位没人管）。

影响：**扩展性硬伤**，是整套功能最大的问题。

### P0-2 业务读取入口无人使用，消费链路断裂

`getDataList()` 全项目零调用。没有 limit、没有单条读取（CRMEB 的 getDataNumber/getDataNumber 无对应物）、没有缓存、读取时不补全图片 URL。方案文档写"由后续业务需求接入"，但目前前台/小程序要用组合数据时没有现成的路，每个消费者都要自己写查询和 URL 处理。

影响：功能只完成了"管理"半边，"使用"半边缺失。

### P1-3 类型扩展要改 4~5 处，没有类型注册机制

新增一种字段类型需要同时改：`getFieldTypeList()`（类型表）、`normalizeFields()`（定义校验）、`normalizeValue()`（值校验 switch）、控制器 `formFields()`（表单默认值/属性）、JS `formatter.value`（列渲染）、fields.html（限制输入显隐）。任何一处漏改就是运行时不一致。

CRMEB 虽然也脏，但 FormBuilder 分发只有一处。

### P1-4 组级业务约束无扩展点

CRMEB 用 config_name 硬编码实现"轮播图最多 4 张"这类需求（实现脏，但至少解决了问题）。当前实现把这类逻辑一刀切掉了，也没有提供任何替代（组上无 max_count 之类的通用字段，无校验钩子）。将来遇到同类需求只能往控制器里塞 if。

### P1-5 类型太少且缺关键类型

没有 uploads（多图）、checkbox、日期/时间。轮播图、图集类场景一张图一个字段很难受；CRMEB 的 uploads 是高频使用的类型。

### P2-6 排序体验差

weigh 靠手填数字；新增记录默认 weigh=0，要置顶得手工改所有记录。FastAdmin 表格本身有拖拽排序（drag 现成方案），方案文档明确首版不做，但作为运营高频操作，这是日常摩擦。

### P2-7 select 的 options 格式易错

options 用 `键|值` 换行分隔（复用 Config::encode/decode）。值里含 `|` 时 decode 会截断（`$item[1]` 只取到第一个竖线后一段）；键要求 `[A-Za-z0-9_-]` 但编辑回填时全靠用户理解"键|值"这个约定，无输入校验提示。

### P2-8 菜单数据被文档污染（实锤 bug）

auth_rule 733/734/735 的 title 字段塞进了整句文档注释：

- 733：`编辑数据组标识是业务读取依据,创建后不可修改;组内已有记录时字段结构不可修改。`
- 734：`删除数据组组内存在记录时禁止删除,避免产生孤立记录。`
- 735：`批量更新只允许修改状态和排序,字段结构必须通过编辑表单变更。`

这些是生成时把 PHPDoc 首行写进了菜单 title，后台权限菜单里会显示成一句话。730 的 remark 也一样。应改为"编辑/删除/批量更新"。

### P2-9 细节

- `del()` 对每个组循环 `count()`（N+1），可一次 `groupBy` 查完；
- 编辑组时 `fieldsSignature` 内部重复 normalize 两次，可复用结果；
- 记录列表对 `value.` 前缀字段 FastAdmin 某些版本搜索参数可能透传到 SQL（当前 operate:false 已挡，但 searchFields 只留 id 是对的，保持）。

## 四、缺失功能点（对照 CRMEB）

1. **limit 截取读取**（getData 的 limit 参数）——首页轮播只取 4 条这类场景必需。
2. **按记录 id 单条读取**——详情跳转、单条渲染场景。
3. **整组批量保存 saveAll**——前端一次编辑整组后覆盖式提交（CRMEB 有事务包裹，这点是对的，可借鉴其思路而非实现）。
4. **缓存 + 写后失效**——CRMEB 的 `CacheService::remember` + `clear`（全清粗暴，但至少有机制；当前直查 DB，读取入口被业务用起来后每次请求都打库）。
5. **多图 uploads 类型**。
6. **组内记录数上限**（通用化替代 CRMEB 硬编码）。
7. **记录复制**（小功能，运营常用，可选）。

## 五、优化改进方案（按优先级）

### 方案 A：字段结构演进（解 P0-1）

把"全量签名一致才允许改"改为"**兼容性变更放行，破坏性变更拦截**"的 diff 校验：

- 允许：新增字段（required 强制为 false，旧记录读取/编辑时按缺省补 0 或空）、放宽约束（maxlength 调大、min/max 放宽）、改 title、调字段顺序。
- 禁止：删除字段、改 name、改 type、收窄约束。
- 实现：`fieldsSignature()` 换成 `diffFields($old, $new)` 返回变更分类，控制器按分类放行或报错。新增字段后旧记录 `value` 里缺键——`normalizeValue` 已按定义补缺省，编辑保存一次自然补齐；`getDataList` 不受影响。

这一条做完，90% 的实际演进需求就通了，不需要引入迁移工具。

### 方案 B：补全消费链路（解 P0-2）

- `getDataList($name, $limit = 0)` 加 limit；
- 新增 `getDataRow($name, $id)`：单条读取，组隐藏或记录隐藏返回 null；
- 新增可选参数或包装方法对 image 类型统一走 `cdnurl()`（与项目现有 URL 处理对齐），消费侧不再各自处理；
- 有真实消费后加组级缓存：`Cache::remember('system_group:' . $name, ...)`，组编辑、记录 add/edit/del/multi 后 `Cache::tag('system_group:' . $name)->clear()`（组级失效，不学 CRMEB 全清）。

### 方案 C：类型注册表（解 P1-3、P1-5）

建 `SystemGroupFieldType` 注册表（数组即可，不必上类继承）：`type => ['label', 'validate 定义', 'validate 值闭包', '表单默认值', '列 formatter 名']`。`normalizeFields` / `normalizeValue` / `formFields` / JS 四处从注册表取行为。先落 string/text/number/switch/image/select 六种迁移，再补 **uploads（多图，值存数组）** 和 **date**。uploads 值为数组，`normalizeValue` 需放开"必须标量"限制为按类型声明。

### 方案 D：组级约束字段（解 P1-4）

`system_group` 加 `max_count int default 0`（0 不限），记录 add 时校验。覆盖 CRMEB 硬编码的"轮播图≤4"类需求，且可配置、可推广。

### 方案 E：交互修补（解 P2-6/7/8/9）

- 记录列表接 FastAdmin 拖拽排序（draggable weigh 交换），手填 weigh 保留为兜底；
- options 编辑改成两列小输入（键、值各一列）或至少加"每行：键|值，值内不要使用竖线"的即时提示，`normalizeFields` 对 value 中 `|` 直接报错而不是静默截断；
- 修正 auth_rule 733/734/735 的 title（出一条 UPDATE SQL）；
- `del()` 改为一次 `GROUP BY group_id` 统计记录数。

### 不建议做的

- 不引入 CRMEB 的 FormBuilder/DAO/Service 分层——当前 FastAdmin 体系下收益低；
- 不做 JSON 虚拟列/生成列查询——有按字段筛选需求的组应该用业务表，方案文档这个决策是对的，保持；
- 不做"删除字段自动清理值"的迁移——读取侧按定义补缺省已够用。

## 七、表单设计专项（重点）

表单分两层：字段定义表单（fields.html，fieldlist 行模板）和记录动态表单（value.html + formFields()）。

### 7.1 记录表单（value.html）问题

| # | 问题 | 级别 |
| --- | --- | --- |
| 1 | **text 类型被截断到 255**：`formFields()` 对所有类型统一 `maxlength` 默认 255，string 输入框 `maxlength="{$field.maxlength}"`；text 服务端允许 65535，表单却只能输 255。定义配了 maxlength 时才对 | bug |
| 2 | 非必填 select 无法清空：`build_select` 不生成空选项，一旦选了只能换不能清 | 体验 |
| 3 | 无字段级输入提示：字段定义只有 title，没有 description/placeholder，运营不知道格式要求（CRMEB 字段有 description） | 体验 |
| 4 | 必填错误消息不带字段名：`data-rule="required"` 是通用消息，服务端报错才有 title | 体验 |
| 5 | 值统一 `(string)` 传模板：number/switch 语义丢失，能跑但脏；number 无 step 配置 | 质量 |
| 6 | 类型分发散落：value.html if/elseif、formFields()、JS formatter 三处，加类型要同步改 | 扩展性 |

### 7.2 字段定义表单（fields.html）问题

1. 限制列把 maxlength/min/max/options 四个输入全部渲染，靠 JS toggle 显隐；窄屏下挤爆，且 placeholder 是"Field maxlength tips"这类无信息文案。
2. required 用"是/否"下拉而不是 switcher，操作重。
3. options 是裸"键|值"多行文本：无格式说明、无校验反馈、值含 `|` 被静默截断。
4. 缺 description 属性（同 7.1-3，定义端没这个配置项）。

### 7.3 表单重构方案（FastAdmin 体系内，不引入 FormBuilder）

> **实施修订（2026-09-29）**：字段定义编辑已按 CRMEB 交互重做——主表单只留组信息（数据组标识/数据组名称/数据简介/权重/状态），字段通过"添加字段"按钮**弹窗逐个添加/编辑**（fields.html 表格只读展示 + systemgroup.js 弹窗），有记录时锁定字段编辑。同时修正了标签误导：`name` 字段 label 原显示"名称"导致被当显示名填入数字，已覆盖 lang 为"数据组标识/数据组名称/数据简介"。下列原方案保留记录，部分被弹窗方案取代。

**定义端（fields.html）**：

- 加"输入提示"列（description），随字段定义入库（FIELD_KEYS 增加 `description`，normalizeFields 校验 mb_strlen≤100）。
- required 改 `Form::switcher`（模板里手写 checkbox + hidden 亦可）。
- 限制列改为单一容器，placeholder 按类型给示例（如 number："最小值,最大值"），options textarea 上方加灰字"每行一条：键|值，键为字母数字下划线"。
- normalizeFields 对 select 的 value 含 `|` 直接报错，不再静默截断。

**记录端（value.html）**：

- 修 7.1-1：`formFields()` 按 `string=255 / text=65535` 分类型给 maxlength 默认（与 normalizeValue 一致），text 未配置时不出 maxlength 属性。
- 每个控件拼 `placeholder="{$field.description}"`；必填消息带字段名（`data-rule="required"` 搭配 `data-msg-required="{$field.title}不能为空"`，nice-validator 支持）。
- select：`required_rule` 为空时在最前插入 `'' => 请选择` 空选项，允许清空回缺省。
- number：definition 可选 `step`（FIELD_KEYS 加 `step`），输入框带 step 属性，值保持原样不 (string)（模板输出无影响，保存 normalizeValue 已按类型处理）。
- 类型分发收敛：value.html 的 if/elseif 拆成 `value_{$type}.html` 片段 include（value_string/value_text/value_number/value_switch/value_image/value_select 六个片段），后续加类型只加片段 + 注册表，不再动主模板。JS formatter 已按 type 闭包分发，维持。

**不改的**：weigh/status 独立 form-group 保持标准 FastAdmin 风格；token、服务端 normalizeValue 校验逻辑不动；不引入 FormBuilder。

### 7.4 实施顺序

1. 修 text maxlength 截断（一行级改动）+ select 空选项 + 必填消息带字段名——小改立即可做；
2. 定义端加 description 属性贯通（FIELD_KEYS → fields.html → value.html placeholder）；
3. value.html 拆 per-type 片段；
4. fields.html 限制列重排 + options 报错 + required switcher。

## 八、落地顺序建议

1. 表单专项 7.4 第 1 批（含 auth_rule 菜单 title 修正，均为小改）；
2. 方案 A 字段演进 diff（核心解锁，与表单 description 属性天然配套——新增字段必须是非必填）；
3. 方案 B 读取链路（getDataList limit + getDataRow + cdnurl 约定，缓存等有消费者再加）；
4. 表单专项 7.4 第 2~4 批 + 方案 D max_count；
5. 方案 C 类型注册表 + uploads/date（工作量最大，可拆两批，届时 value 片段与注册表合并落地）；
6. 方案 E 其余交互项随手带。
