# Repository Guidelines

## Paired Repositories & Synchronization

- `tp6版` = `E:\phpstudy_pro\extraproject\rocket-admin` (ThinkPHP 6.1.2; PHP >=7.4.3).
- `tp8版` = `E:\phpstudy_pro\extraproject\tp8-fastadmin` (locked ThinkPHP 8.1.4; PHP >=8.0).

Both versions remain unfinished refactors of one system. Synchronize every change unless the user explicitly declares a feature exclusive to one version. Version names alone never imply exclusivity. Preserve identical functionality through necessary version-specific implementations.

## Project Structure & Module Organization

- `app/{admin,api,index}`: applications; `app/common`: shared code; module `view/`: templates; TP8 additionally has `app/{tenant,addonstore}`.
- `kernel/`: services; `extend/`: utilities; `addons/`: plugins; `config/`/`route/`: configuration/routes.
- `public/`: web root; `public/assets/{js,css,less,libs}`: assets; `runtime/`: generated files.
- Schemas: `rocketadmin.sql` (TP6), `数据库.sql` (TP8).

## Build, Test, and Development Commands

Run from the relevant repository:

- `composer install`: install locked dependencies, discover services, and publish vendor resources.
- TP6: `npm ci`, then `npm run build`: copy dependencies and bundle/minify JS/CSS with Grunt. TP8 has no root npm/Grunt setup.
- `php think run --host 127.0.0.1 --port 8000`: start a development server.
- `php -l app/admin/controller/auth/Admin.php`: PHP syntax check.
- `node --check public/assets/js/backend/auth/admin.js`: JavaScript syntax check.

## Coding Style & Naming Conventions

Use four-space indentation, PascalCase classes, camelCase methods, namespace-matching paths, and snake_case database/frontend names. Use RequireJS modules and `__()` translations.

Prefer modern, supported PHP/ThinkPHP features/APIs to simplify maintenance. Verify runtime, compatibility, and locked dependencies first. Keep Chinese UTF-8 comments professional, clean, concise, and understandable. Preserve useful comments; update/remove obsolete ones. No project formatter is configured.

## Testing Guidelines

Test every feature/logic change: main flow, key branches, and invalid input; confirm logical consistency. Verify both versions. Separate syntax/browser/database checks; report passed, failed, and unexecuted checks. No project test runner/coverage threshold is declared; document execution for new `*Test.php` tests.

## Design & Feature References

Study [BuildAdmin](https://gitee.com/wonderful-code/buildadmin) designs/implementations and adapt suitable ideas to supported versions.

Check [original FastAdmin](https://gitee.com/fastadminnet/fastadmin) for missing functionality; its legacy implementation style has limited value.

## Commit & Pull Request Guidelines

Only when explicitly asked to submit to Git, commit each repository separately and push to its configured remote. Write professional, concise, change-specific messages using `type(scope): summary`. PRs describe behavior, issues, UI screenshots, and both versions' synchronization/testing status.

## Security & Configuration

Examples: `.env.sample` (TP6), `.example.env` (TP8). Keep credentials/environment-specific values local; adapt dependency changes per version.

## Agent-Specific Instructions

Edit files directly by default. When explicitly asked “不修改文件”, provide exact locations and complete code without editing. Confirm ambiguous targets, requirements, or scope with the user before dependent edits; never guess.
