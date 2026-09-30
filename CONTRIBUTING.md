# 开发与验证

## 目录

- `plugin/NodeQuality`：XBoard 插件、PHP 服务、页面桥接和静态界面。
- `agent`：Go Agent，使用标准库。
- `scripts`：运行时命名空间生成和发布包构建。
- `tests`：JS 交互、SQLite、Laravel 集成、采集器与本地预览。
- `docs`：运维、验证范围及第三方许可。

## 常规检查

```bash
npm ci
npm test
(cd agent && go test -race ./... && go vet ./...)
php tests/store.php
bash tests/quality.sh
php tests/scale.php
```

PHP 测试要求 `pdo_sqlite`、`sqlite3`、`zlib`。`quality.sh` 使用替身数据源，不会运行真实 IP 体检；需要 bash、jq。规模测试是顺序合成上报，不代表生产并发压测。

## Laravel 集成

使用固定 XBoard 基线，测试通过临时存储与内存业务数据库运行，不连接生产面板。

```bash
git clone https://github.com/cedar2025/Xboard.git .work/xboard
git -C .work/xboard checkout 4f48e61a2cbc6db5338872b6bdb45ef954ec1256
composer install --working-dir=.work/xboard --no-dev --no-scripts --ignore-platform-reqs --no-interaction
python3 scripts/runtime.py
XBOARD_PATH="$PWD/.work/xboard" php tests/laravel.php
```

`--ignore-platform-reqs` 仅用于这个隔离测试夹具，不能作为生产安装建议。测试使用真实 Router/Eloquent/权限中间件，身份由测试 guard 提供。

## 原版页面预览

```bash
XBOARD_PATH="$PWD/.work/xboard" ADMIN_ASSETS_PATH="/path/to/public-admin-fixtures" node tests/preview.mjs
```

预览只监听 `127.0.0.1:8765`，API 数据为合成数据。用户页使用 XBoard 自带主题资源；后台预览另需准备原版编译资源，目录中应有 `entry.js`、`entry.css`、`zh-CN.js`、`en-US.js`。这些上游资源不包含在本仓库中。前后台注入的自动测试不依赖这些浏览器资源。

## 构建

```bash
python3 scripts/build.py dist
```

可通过 `GO=/path/to/go` 指定编译器。运行时目录、打包的 Agent、manifest 和源码包都是生成文件，不要提交。构建源码包使用明确的源码目录列表，不包含 `.work`、依赖、数据库、缓存或部署资料。

修改 PHP 类时应考虑 Octane 常驻进程：当前运行时命名空间为 `Runtime/V040`。新增版本需要同步调整生成脚本、路由、服务提供者和资源版本；不要只修改 PHP 文件后假定旧进程已卸载旧类。

修改桥接代码必须验证：原节点视图未被替换、Plus 来回切换、移动双菜单、登录失效清理，以及原后台导航和头部保留。
