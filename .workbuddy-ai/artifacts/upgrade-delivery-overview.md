# 系统升级功能 —— 交付总览

> 日期：2026-09-23 ｜ 项目：`E:\phpstudy_pro\extraproject\rocket-admin`
> 状态：**已完成并通过端到端验证**（沙箱内 21 项断言全绿）

---

## 1. 做了什么

把原本"只有一个控制器、点开就报模板不存在"的系统升级功能，补成一个**可用、闭环**的在线升级能力：支持 GitHub / Gitee Release 与自建直链三种来源，具备预检预览、流式下载、SHA256 校验、路径穿越防护、环境私有文件保护、批次备份、版本号回写、并发锁与一键回滚。

---

## 2. 文件清单

### 新增（3 个）

| 文件 | 行数 | 说明 |
|------|------|------|
| `app/admin/view/upgrade/index.html` | 126 | 升级页面（自带内联 `<style>`，未改任何皮肤/框架 CSS） |
| `public/assets/js/backend/upgrade.js` | 147 | 前端 Controller，导出 `{index, check, run, rollback}` |
| `app/admin/lang/zh-cn/upgrade.php` | 92 | 76 条语言词条（已校验：视图/JS/控制器使用的词条**零缺失**） |

### 修改（2 个）

| 文件 | 变化 | 说明 |
|------|------|------|
| `app/admin/controller/Upgrade.php` | 311 → 1233 行 | 多源解析、预检、流式下载、校验、备份覆盖、版本写回、回滚、锁、批次清理 |
| `config/rocket.php` | +`upgrade` 子配置 | 移除失效的 `upgrade_url`；新增 13 项升级配置 |

### 数据（1 行）

```sql
-- 已执行。im_auth_rule id=726
INSERT INTO `im_auth_rule` (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`extend`,`weigh`,`status`,`createtime`,`updatetime`)
VALUES ('file',0,'upgrade','系统升级','fa fa-cloud-download','','','后台系统升级',1,'addtabs','',100,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

-- 回滚
DELETE FROM im_auth_rule WHERE name='upgrade';
```

---

## 3. 架构决策（含明确"不做什么"）

| 候选模块 | 裁决 | 理由 |
|---|---|---|
| 服务层 | **不新建** | 唯一调用方就是这个控制器，抽出去只是搬家；上游同类实现（`tp8-fastadmin/app/common/service/RemoteUpgradeService.php`，355 行）全仓无调用方，是死代码。触发条件：出现第二个调用方（`php think upgrade` CLI / 安装向导复用包处理） |
| 数据模型 / ORM | **不要** | 升级无需持久实体。`im_version` 表是"移动端 App 版本发布"，与后台升级无关，禁止混用 |
| 接口 | **要** | 新增 `check` / `run` / `rollback` 三个 action |
| 持久化 | **要，用文件不用 DB** | `runtime/admin/upgrade/<批次>/{status.json, files.json, backup/, upgrade.sql, package.zip}` |
| 状态管理 | **要（两层，不引框架）** | 前端 requireJS Controller；后端 `status.json` 状态机 + `flock` 单飞 |
| SSRF 内网黑名单 | **不做** | 已有超管门控；加了会阻断本地验证。开放给非超管角色时再补 |

**版本号归属**：升级只写回 `Config::get('rocket.version')`（`config/rocket.php`，= 后台项目自身代码版本）。`site.version`（`im_config`）是前端静态资源缓存串，语义无关，不写回。

---

## 4. 关键实测结论（推翻了初版方案的两处设计）

| 结论 | 证据 |
|------|------|
| **禁用 curl** —— 本机 `curl.cainfo` 为空，curl 访问任何 HTTPS 都失败 | `curl(默认)` 与 `curl(CURLOPT_PROXY="")` 均 `FAIL SSL certificate problem: unable to get local issuer certificate`；`file_get_contents` **OK len=2294** |
| **Guzzle 救不了** —— 默认 handler 就是 curl，且 7.4.5 不自带 `cacert.pem` | Guzzle 默认 → `FAIL cURL error 60`；强制 `StreamHandler` 才通（等于"用 stream 套壳"）。且它是 `easywechat` 的传递依赖，不在 `composer.json` 的 `require` 里 |
| **改用 PHP stream 流式下载** | `stream_copy_to_stream: OK bytes=1775 sha256=81eaadf0…`（与包一致），纯 stdlib、不占内存 |
| **GitHub 源白拿校验和** | `assets[].digest = 'sha256:a6fd66c8…'`，实测 **22/22 非空** → 直接作为期望 checksum |
| **`zipball_url` 回退是必需路径** | 实测 `vuejs/core` 的 `assets` 为**空数组** |
| **`tag_name` 带 `v` 前缀** | GitHub `v2.101.0`/`v3.5.43`、Gitee `v4.8.3` → 必须 `ltrim($tag,'vV')` 后 `version_compare` |
| **PHP stream 不读 `http_proxy`** | 访问本地 mock 与 GitHub 均直接成功 → 初版"代理导致 502"是**误诊**（真因是 mock 服务已停 + `curl -o /dev/null` 在 Git Bash 的写错误） |

---

## 5. 复核工程师产出时发现并修复的缺陷

1. **破坏性操作无 CSRF 防护**：控制器从未调用 `$this->token()`（它并非自动校验），而 `run()`/`rollback()` 会覆盖项目根目录文件。已为三个 POST 动作补上校验；前端 `Fast.api.ajax` 不会自动带 token 也不读 `__token__` 响应头（那逻辑在 `require-form.js` 的表单路径），已在 JS 中手动携带并从响应头回填。
2. **空包被当有效升级**：`resolveBasePath()` 兜底无条件返回解压根目录 → 只有 `manifest.json` 的包会把 `manifest.json` 写进项目根（实测 `updated:1`）。已改为"兜底目录也必须像项目根"，否则报错。
3. **GitHub `digest` 被丢弃**、**无 zip asset 时直接报错**（缺 `zipball_url` 回退）—— 均已补齐。
4. **网络层未按更正改造**（仍用 `Http::sendRequest` + `curl_init`）—— 已全部替换为 stream。

---

## 6. 验证结果

验证在**沙箱副本**中进行（`C:\Users\HYKJ\AppData\Local\Temp\rocket-sandbox`），沙箱内仅 2 处鉴权放行补丁，**真实项目零改动**。

### P1 可达性
升级页渲染 200（5605～56014 字节），含"系统升级 / 当前版本 / 1.6.1.20250430 / 升级批次 / `__token__`"。

### P2 多源解析与预检
| 用例 | 结果 |
|------|------|
| Gitee 源 | `latest_version=v1.7.0`、`is_newer=true` ✓ |
| GitHub 源 | 同上，且 `digest → checksum` 正确 ✓ |
| 直链 manifest | `has_manifest=true`、checksum 正确提取 ✓ |
| GitHub 空 assets | 回退 `zipball_url` 成功 ✓ |
| 仓库不存在 | `仓库不存在或无访问权限` ✓ |
| 无 Release | `该仓库暂无 Release` ✓ |
| 无 asset | `未找到可下载的升级包` ✓ |
| JSON 非法 | `升级源返回内容不是有效的 JSON` ✓ |

### P3 执行闭环（13/13）
覆盖既有 PHP/JS 文件 ✓ ｜ 创建新增文件 ✓ ｜ 版本号 `1.6.1.20250430 → 1.7.0` 回写 ✓ ｜ `.env` 未被覆盖 ✓ ｜ `runtime/` 被跳过（skipped=2）✓ ｜ **项目根不再产生 `upgrade_*.sql`** ✓ ｜ 批次 `backup/`、`files.json`、`status.json`、`upgrade.sql`、`package.zip` 齐全 ✓ ｜ `state=done` / `from_version` / `to_version` / `sql_executed=false` ✓ ｜ 备份内容为被覆盖文件的**原件** ✓

### P4 回滚与反向用例（8/8）
回滚后文件还原为原件 ✓ ｜ 版本号还原 ✓ ｜ `state=rolled_back` ✓ ｜ **升级新增的文件未被删除** ✓
坏 checksum → 中止且不落盘 ✓ ｜ 路径穿越包（`../evil.php`）→ 拒绝且未写入 ✓ ｜ 缺 `confirm` → 拒绝 ✓ ｜ 外部进程持锁 → `已有升级任务正在进行，请稍后再试` ✓ ｜ 空包 → `无法识别升级包内的项目文件` ✓

### 静态与模板检查
`php -l` 三个 PHP 文件全通过 ✓ ｜ `node --check upgrade.js` 通过 ✓ ｜ 语言词条 76 条零缺失 ✓ ｜ **模板离线渲染未解析标签 = 0**，条件分支正确（仅 `done` 批次出回滚按钮）✓ ｜ 项目根无游离 `.php` 污染 ✓

### 补充验证：`protectedPaths` 保护机制（5/5）
首轮 P3 的 `skipped:2` 全部来自 `shouldSkipFile`（`.env` + `runtime/`），`protectedPaths` 机制**当时从未被触发**。加入 `config/rocket.php` 后补做专项验证（包内同时含 `config/rocket.php` 与一个普通文件）：

```
code=1 msg=升级完成
data={"updated": 1, "skipped": 1, ..., "version": "1.7.0"}
```

| 断言 | 结果 |
|------|------|
| 普通文件被正常覆盖（保护未误伤） | ✓ |
| `config/rocket.php` 未被覆盖（保护生效） | ✓ |
| 原有键值（`watermark_text`）仍在 | ✓ |
| `upgrade` 源配置仍在 | ✓ |
| 版本号仍由 `writeVersion()` 定点回写为 1.7.0（保护未阻断回写） | ✓ |
| `updated=1 / skipped=1` 与预期完全一致 | ✓ |

---

## 7. 需要你决定的默认值

| 项 | 当前默认 | 说明 |
|---|---|---|
| `upgrade.source` | `gitee` | 默认源类型 |
| `upgrade.gitee_repo` / `github_repo` | 空 | **需填你自己的 `owner/repo`**，否则页面无法开箱即用 |
| `upgrade.execute_sql` | `false` | SQL 只落批次目录，不自动执行 |
| `upgrade.verify_tls` | `true` | 不静默降级。如需指定 CA 填 `ca_file` |
| `upgrade.keep_batches` | `5` | 保留最近 5 个批次，超出的自动清理 |
| `upgrade.proxy` | 空 | 生产环境若需经代理访问 GitHub 时填写 |
| 回滚是否删新增文件 | 否 | 避免误删用户数据 |
| **`config/rocket.php` 是否可被包覆盖** | **不可（已保护）** | 已按你的决定加入 `protectedPaths`。该文件混有实例个性化设置（水印文案/皮肤/`auto_logout`）与 `upgrade` 源配置，被覆盖会静默重置。代价：升级包无法通过覆盖该文件下发新配置项，需看更新日志后人工合并。版本号不受影响（由 `writeVersion()` 定点回写） |

### 升级包不得覆盖的路径（`protectedPaths`）
`config/site.php`、`config/database.php`、`config/token.php`、`config/rocket.php`
另加 `blockedPaths`：`.env`、`.git/`、`runtime/`、`public/uploads/`

---

## 8. 使用方式

1. 在 `config/rocket.php` 的 `upgrade` 中填好仓库地址（或直接在页面手填）。
2. 后台侧边栏 →「系统升级」→ 选来源类型 → 点「检查更新」看版本号/更新日志/包大小。
3. 点「立即升级」→ 二次确认 → 执行。升级后页面自动刷新，版本号随之更新。
4. 出问题在下方「升级批次」列表点「回滚」。

**升级包约定**：推荐在包里放 `manifest.json`，声明 `version` / `files`（文件基准目录）/ `sql` / `changelog`；不放则按整包覆盖处理，并强制二次确认。GitHub 源无需声明 checksum（API 自带 `digest`）；Gitee 与直链源建议在 manifest 里给 `checksum`。

---

## 9. 已知边界

- **回滚不删除升级新增的文件**（有意为之，避免误删用户数据）——升级引入的新文件需人工清理。
- **升级包不能覆盖 `config/rocket.php`**（见 §7）。若某次升级需要新增该文件的配置项，请人工合并。
- 升级包的 SQL 默认**只落盘不执行**，需人工确认后执行。
- `runtime/admin/upgrade/<批次>/` 会随升级累积，由 `keep_batches` 控制上限。
- 页面适配后台桌面端，未做移动端适配。
