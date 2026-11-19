# 构建说明

更新日期：2026-11-11。本文与当前仓库实际文件一致；历史审计记录（如 `OPTIMIZATION_AUDIT.md`）仅代表其撰写时点的状态。

## 环境要求

- PHP >= 7.4.3（composer.lock 锁定依赖）
- Node.js 22.x / npm 10.x（构建环境快照：PowerShell 7.6.4、Node 22.20.0、npm 10.9.3）
- npm 源：`https://mirrors.tencent.com/npm/`（HTTPS，证书校验开启，见 `.npmrc`）

## 依赖清单与官方同步

`package.json` 声明与 `package-lock.json` 固化版本均与官方参考包保持一致（2026-11-11 对齐）：

| 组件 | lock 固化版本 | 说明 |
| --- | --- | --- |
| grunt | 1.6.3 | 官方声明 `^1.5.3` |
| moment | 2.31.0 | 官方声明 `^2.31.0` |
| sortablejs | 1.15.7 | 官方声明 `^1.12.0` |
| fastadmin-bootstraptable | 1.11.13 | 官方声明 `^1.11.12`；1.13 为官方 fork 的 escapeHTML 安全修复版 |
| tableexport.jquery.plugin | 1.33.0 | 传递依赖 jsPDF 4.2.1 / DOMPurify 3.4.17 随官方 lock |

PHP 依赖以 `composer.lock` 为准：topthink/framework 6.1.2、topthink/think-orm 2.0.62。

依赖升级原则：跟随官方清单（新增/移除/版本），不在官方之外自行升级。`dists` 配置与官方一致（含官方保留的 `coloris` 死条目——无对应依赖，deploy 时空匹配跳过；`font-awesome` 仅 css/fonts，`less/variables.less` 由仓库历史提交维护，deploy 不删除）。

## 构建流程

```text
package-lock.json → npm ci → node_modules
package.json:dists → grunt deploy/copy → public/assets/libs
require-backend.js / require-frontend.js → 内置 r.js → 对应 .min.js
backend.css / frontend.css → r.js CSS 合并压缩 → 对应 .min.css
```

命令（仓库根目录执行）：

```bash
npm ci
npm run build
```

`npm run build` 不执行 LESS 编译；`public/assets/css/*.css` 与白色皮肤 CSS 是当前可维护输入。当前默认皮肤为白色（`config/rocket.php` 的 `adminskin` 为空即使用 skin-white），不要改回官方默认黑蓝皮肤。

## 关键约定

- **组件来源**：filter-control 的 JS/min.js 保持与原版及锁定 NPM 包一致，不维护项目补丁。当前业务未启用该扩展；按用户决定接受原版的特殊字符选项重复及特定配置下的引号选择器问题。
- **bootstrap-table 1.11.13 混合文件集**：官方 fork 该版本发布物只含主库与 filter-control（locale/extensions 被裁剪）。deploy 只覆盖不删除，`public/assets/libs/bootstrap-table/dist/locale/` 与其他 extensions 沿用仓库历史提交文件，干净检出后的构建仍然存在。
- **产物新鲜度**：JS/CSS 来源变更后必须重跑 `npm run build`，并确认 `require-*.min.js` 与 `*.min.css` 时间戳更新；模板按调试状态选择未压缩/压缩入口，`config/site.php` 的 `version` 用于浏览器缓存失效。
- **Grunt 来源**：沿用本地原版逻辑，仅保留两处必要适配：压缩器路径由 `application/admin/command/Min/r` 改为 `app/admin/command/Min/r`；移除 urlArgs/baseUrl 的正则由 `\n` 改为 `\r?\n`，兼容 LF/CRLF。不增加补丁机制、VM 解析或来源校验。
- **构建验证**：2026-11-11 经用户确认补齐 CRLF 兼容后，两仓隔离 `npm ci`、删除旧 min 产物后的完整构建通过，TP8 包含租户构建。9 个目标 JS/CSS 产物与工作区逐字节一致，白色皮肤保留。原版错误回调保持不变，仍须核对日志及实际产物，不能只凭退出码判断成功。
