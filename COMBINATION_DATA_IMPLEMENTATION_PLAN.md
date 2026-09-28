# 组合数据功能实施方案

## 1. 目标与边界

在当前 Rocket Admin 上新增组合数据管理，用于维护快捷入口、轮播图、友情链接、客服渠道等“多条记录共用一套字段”的内容。

例如“友情链接”组定义名称、网址、图片三个字段，管理员按该结构新增记录，每条记录可以独立编辑、删除、排序和启停。

本方案只增强现有项目，不重构系统配置：

- 现有系统配置继续负责网站名称、邮件账号、功能开关等单项参数。
- 保留 `configgroup` 和 `config.group`，不新增配置分类模块。
- 新增组合数据定义与记录管理，不把订单、支付流水等业务数据放入其中。
- 复用 ThinkPHP ORM、Backend、FastAdmin 表格和表单组件，不增加 Composer 或前端依赖。
- 首版不增加 DAO、Service、Provider、Factory、独立表单生成器或缓存层。
- 本文是实施计划。本次仅新增本文档，不创建业务文件，不修改 SQL 文件，不执行数据库操作。

## 2. 现有机制与复用方式

| 已有实现 | 本功能的使用方式 |
| --- | --- |
| `app/admin/controller/general/Config.php` | 参考常规管理模块的目录、请求处理和表单提交风格，不改变原控制器 |
| `app/common/model/Config.php` | 参考 JSON 属性处理、类型选项等写法，不把组合数据并入现有配置值 |
| `app/admin/controller/general/Attachment.php` | 参考 Backend 控制器、列表返回 `total/rows` 的约定 |
| `app/common/model/Category.php` | 参考公共模型、`createtime/updatetime`、`weigh`、状态字段的风格 |
| `app/admin/library/traits/Backend.php` | 复用常规 CRUD；涉及组归属、动态字段和删除约束时补充必要逻辑 |
| `extend/util/Form.php` 的 `fieldlist()` | 使用自定义模板编辑字段定义，避免要求管理员手写 JSON |
| `public/assets/js/require-form.js` | 复用 fieldlist、上传、附件选择、表单绑定等现有行为 |
| `public/assets/js/backend/category.js` | 参考 `Table.api`、`Form.api` 和 RequireJS 的组织方式 |
| 现有 `auth_rule` 与角色权限 | 登记新增菜单和操作权限，沿用后台统一权限检查 |

注意：现有通用 CRUD 不负责本功能的动态字段校验和组归属检查，也不能假设其自动执行 CSRF 校验。新增写操作须显式按项目方式调用 `token()`，表单带 `token_field()`。

## 3. 数据结构

只新增两张表。下面表名不含数据库前缀，实际前缀使用项目数据库配置，不硬编码为 `fa_`。

### 3.1 `system_group`：数据组定义

| 字段 | 建议类型 | 作用 |
| --- | --- | --- |
| `id` | unsigned int，主键 | 数据组 ID |
| `name` | varchar(50)，唯一 | 稳定的业务标识，例如 `friend_links` |
| `title` | varchar(100) | 管理页面显示名称 |
| `tip` | varchar(255) | 简短用途说明 |
| `fields` | text | 字段定义 JSON |
| `weigh` | int | 数据组排序 |
| `status` | varchar(30) | 使用项目常见的 `normal/hidden` |
| `createtime` | int | 创建时间 |
| `updatetime` | int | 更新时间 |

不增加父级分类、租户、版本、发布审核等字段。组名采用小写字母开头，后接小写字母、数字或下划线；校验长度并由唯一索引防止重复。

### 3.2 `system_group_data`：组内记录

| 字段 | 建议类型 | 作用 |
| --- | --- | --- |
| `id` | unsigned int，主键 | 记录 ID |
| `group_id` | unsigned int | 所属数据组 |
| `value` | text | 按组字段定义保存的 JSON 对象 |
| `weigh` | int | 记录排序 |
| `status` | varchar(30) | `normal/hidden` |
| `createtime` | int | 创建时间 |
| `updatetime` | int | 更新时间 |

建立 `(group_id, status, weigh, id)` 索引，服务于按组读取有效记录及排序。JSON 内容首版不做数据库筛选、排序和唯一约束；有这类实际需求的内容使用专用业务表。

### 3.3 字段定义与值示例

```json
[
  {"name":"title","title":"名称","type":"string","required":true},
  {"name":"url","title":"网址","type":"string","required":true},
  {"name":"image","title":"图片","type":"image","required":false}
]
```

对应的一条记录只保存值，不重复保存控件类型：

```json
{
  "title":"帮助中心",
  "url":"https://example.com/help",
  "image":"/uploads/help.png"
}
```

首版仅支持 `string`、`text`、`number`、`switch`、`image`、`select` 六种类型。`select` 使用结构化的 `options` 键值对象；选择项的业务值统一为字符串。`number` 使用可选 `min/max`，文本使用可选 `maxlength`，不提供任意正则表达式或代码执行配置。

字段名遵循组名的命名规则，组内不得重复。禁止占用 `id/group_id/value/weigh/status/createtime/updatetime`，避免与管理字段和列表列冲突。

## 4. 后台操作流程

### 4.1 数据组管理

在“常规管理”下新增“组合数据”入口，使用原生列表展示名称、标识、状态、排序和操作。

新增、编辑表单包含名称、标识、说明、字段定义、状态、排序。字段定义采用现有 fieldlist 的自定义行模板，每行填写字段标识、标题、类型、必填及适用的选项；选项和限制只在对应类型下显示。

从每个数据组的“管理数据”按钮进入记录列表，列表明确携带组 ID。首版不动态生成每个组的菜单。

组的显示名称、说明、排序、状态可以修改。为保护已有数据及业务调用，组创建后标识不可修改；已有记录时，字段结构禁止修改。界面只读和服务端检查同时执行，不自动清空记录、不自动迁移字段。后续确有字段演进需求时，针对具体组设计兼容变更。

数据组有记录时禁止删除，返回“请先处理组内数据”；空组可以删除。组的删除、结构修改与记录写入在事务中锁定同一组记录，避免并发产生孤立数据或定义与值不一致。

### 4.2 记录管理

- 根据字段定义生成原生表单，复用输入框、textarea、switcher、selectpicker、上传和附件选择。
- 新增和编辑共用一个字段渲染片段，不分别维护两份动态表单。
- 列表根据字段定义生成列，使用现有文本、图片、状态等格式化方式。
- 只对真实表字段提供筛选和排序；动态 JSON 列关闭默认搜索、筛选和排序，避免被当作数据库列查询。
- 默认按 `weigh DESC, id DESC` 排序，保留分页、刷新、编辑、删除等标准操作。
- 首版通过编辑排序值调整顺序，不另建拖拽排序接口。
- 记录中的图片保存原始路径，展示时使用项目现有 URL 处理方式，不永久写入 CDN 完整地址。

页面使用现有 Bootstrap/FastAdmin 布局和全局样式，不增加页面专属 CSS、装饰卡片或重复说明区域。

## 5. 校验与读写约定

### 5.1 保存校验

静态字段使用 ThinkPHP Validate；动态字段校验集中在 `SystemGroupData` 模型的一个公共校验方法中，新增和编辑共用。字段定义校验集中在 `SystemGroup` 模型中，避免控制器复制判断。

必须覆盖：

1. 数据组存在；编辑、删除、批量操作涉及的记录全部属于当前组。
2. 新增记录的 `group_id` 取自已确认的数据组，编辑时禁止改变归属。
3. 提交字段和字段定义一致；未知字段拒绝，缺失的可选字段按约定补空值或 `0`。
4. 必填判断区分未提供、空文本与合法的 `0/false`，不能统一用 `empty()`。
5. 文本、图片路径、选项只接收标量；数字校验合法数值及配置范围；开关仅接收约定的 `0/1`。
6. `select` 值必须属于已定义选项；所有类型执行适用的长度、范围校验。
7. 不信任客户端传入的字段类型、选项、创建时间和记录 ID。
8. JSON 编解码失败明确报错，不能以空数组覆盖损坏数据；统一采用 ORM JSON 属性转换，避免手动重复编码。

数据库查询使用 ORM；列表的排序和过滤字段限定在允许的真实表字段内。字段标题、文本值和回填内容按输出上下文转义；链接消费者还需校验 URL 协议，不能因值来自后台就直接拼入 HTML。

### 5.2 业务读取

在公共模型提供一个读取入口，例如：

```php
SystemGroupData::getDataList('friend_links');
```

读取入口根据组标识定位数据组，组隐藏时返回空列表；只返回 `normal` 记录，按 `weigh DESC, id DESC` 排序。返回结构固定为：

```json
[
  {
    "id":1,
    "value":{
      "title":"帮助中心",
      "url":"https://example.com/help",
      "image":"/uploads/help.png"
    }
  }
]
```

保留 `value` 包装，与 ORM JSON 属性一致，不把业务字段与管理字段混在同一层。不存在的组返回空列表；数据库或 JSON 错误保留异常，不伪装成“没有数据”。读取结果不包含字段定义等后台元数据。

首版直接查询数据库，不增加 `sys_data()` 全局函数、不写入 `config/site.php`、不增加缓存。实际消费者出现重复读取或性能问题时，再针对这个入口增加缓存及组级失效。

本轮实现不自动替换仪表盘快捷入口，也不新增公开 API；由后续明确的业务需求接入读取入口。

## 6. 权限与文件组织

沿用现有角色与菜单权限，分别登记数据组定义管理和记录管理的 `index/add/edit/del/multi` 等实际操作。字段定义、删除数据组可以授予开发维护角色，记录编辑授予运营角色。

首版权限按模块操作划分，不新增按组授权体系。所有获记录管理权限的管理员可以管理各组记录，此范围须在角色配置时明确。隐藏状态表示业务不展示，不代表管理员失去编辑权限。

接口继续经过 Backend 权限检查；不要将管理接口放入 `noNeedRight`。批量操作只允许修改 `status/weigh`，校验枚举和数值；数据组的 `multi` 不得绕过字段结构限制。

建议文件位置如下，按实际必要职责创建，不先铺设空类：

```text
app/admin/controller/general/Systemgroup.php
app/admin/controller/general/Systemgroupdata.php
app/common/model/SystemGroup.php
app/common/model/SystemGroupData.php
app/admin/validate/SystemGroup.php
app/admin/validate/SystemGroupData.php
app/admin/view/general/systemgroup/       # 列表、新增、编辑和共用表单片段
app/admin/view/general/systemgroupdata/  # 列表、新增、编辑和共用表单片段
app/admin/lang/zh-cn/general/systemgroup.php
app/admin/lang/zh-cn/general/systemgroupdata.php
public/assets/js/backend/general/systemgroup.js
public/assets/js/backend/general/systemgroupdata.js
```

控制器负责请求、权限、组归属和提交编排；模型负责定义校验、动态值校验及业务读取；模板负责控件展示；JS 负责原生表格和表单绑定。只有出现多个业务入口复用复杂编排时，再考虑服务层。

类、方法和新增函数使用中文 UTF-8 文档块，说明功能、参数、返回值；保留既有注释，复杂校验解释约束意图。

## 7. 实施步骤与影响范围

| 步骤 | 内容 | 原因 | 影响范围 |
| --- | --- | --- | --- |
| 1 | 核对数据库约定，确定两张表及菜单规则 | 保持前缀、状态、时间与权限风格一致 | 数据库新增表、菜单权限；具体 SQL 交付或执行方式在实施时确定 |
| 2 | 实现模型、JSON 属性和校验 | 统一数据约定，避免控制器重复逻辑 | 新增公共模型和验证器 |
| 3 | 实现组定义管理 | 提供稳定标识和可维护的字段结构 | 新增后台控制器、模板、语言文件、JS |
| 4 | 实现记录列表与动态表单 | 复用字段定义完成单条 CRUD | 新增记录管理页面及操作 |
| 5 | 提供统一读取方法 | 使业务不依赖数据库存储细节 | 新增模型方法，不修改已有消费者 |
| 6 | 验证权限、校验、并发边界和展示 | 确认功能完整及兼容原后台 | 必要检查和测试数据，不影响现有系统配置 |

数据库结构和菜单规则应形成可复核的变更清单，不能把新增菜单等同于角色自动获得权限。本次文档创建不授权后续自动创建其他文件、执行建表或变更角色。

## 8. 验收标准

- 可以定义“友情链接”组，并通过表单维护字段，无需手写 JSON。
- 可以新增、编辑、删除多条记录，修改排序和状态；隐藏组或隐藏记录不进入业务读取结果。
- 动态表单控件与定义一致，图片上传、选择和展示沿用现有附件机制。
- 缺少必填项、错误数字、非法选项、未知字段、跨组记录操作均被服务端拒绝；合法 `0` 可以保存。
- 已有记录时禁止改字段结构，禁止修改组标识；非空组不能删除；批量操作不能绕过这些约束。
- 并发修改结构与新增记录不会产生不一致或孤立记录。
- 权限不足时直接请求接口同样被拒绝，非法 CSRF 提交不能写入。
- 业务读取结构和排序稳定；异常不会静默变成空列表。
- 页面保持原生后台风格，桌面和窄屏下表单可用，动态列不会产生无效 SQL 查询。
- 现有系统配置、配置文件和仪表盘行为保持兼容。

实施时执行相关 PHP 语法检查，保留一个可运行的最小动态校验检查，覆盖零值、必填、选项和未知字段；配合实际数据库 CRUD、浏览器和非超级管理员权限验证。语法检查不能替代数据库和浏览器验收。

## 9. 暂不实施的扩展

暂不增加数据组分类树、逐组权限、缓存、导入导出、嵌套字段、可视化拖拽表单、字段自动迁移和通用业务规则引擎。

首版以“字段定义驱动原生 CRUD”为完成标准。只有具体业务需求证明现有能力不足时，再增加对应功能。
