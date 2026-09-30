# 节点状态 Plus 监测 0.4.0

[打开节点质量管理界面](/plugins/node_quality/admin.html)

0.4.0：管理界面嵌入 XBoard 原后台，保留原侧边栏、顶部导航和后台登录态；从已有服务器或旧模式节点选择接入，自动带出名称、地址和可关联节点。用户侧同样注入原内容区域，保留原“节点状态”，新增“节点状态 Plus 监测”站内视图。

XBoard 插件 + 独立 Go Agent，兼容研究基线 `cedar2025/Xboard` 的 `4f48e61`（2026-08-30）。不修改 xboard-node。AGPL-3.0 开源。

## 安装面板插件

1. 备份面板数据库、当前主题设置和持久化 storage。
2. 后台「插件管理」上传 `NodeQuality-0.4.0.zip`，安装并启用。
3. PHP 需要 `pdo_sqlite`、`sqlite3`、`zlib`。监测目录自动创建于 `storage/node-quality`，该目录必须位于持久化本地磁盘，不能通过 Web 访问。单面板实例；不支持 NFS/CIFS 共享写入。
4. 确保 XBoard 的 scheduler 正常运行。维护命令每分钟执行；启用后最多一分钟修复主题入口。也可在管理页点击「修复默认主题入口」。
5. 登录 XBoard 原后台，刷新页面，在侧边栏「节点状态 Plus 监测」中进入监测概览、探针接入、节点绑定、检测目标和监测设置。无需另外登录用户主题。旧管理页地址会跳转回原后台。

在「探针接入」选择已有服务器或节点，确认名称及要关联的节点后生成安装命令。XBoard 服务器管理为空时仍可从旧模式节点选择。重复接入会被阻止，已有监测绑定不会被覆盖。默认带有北京/上海/广东三网回程参考域名，来自 NetQuality；管理员应确认适合自己的线路，目标失效会展示未知，可以修改或清空。

## 从 0.1.x 升级

1. 保留现有监测目录与主题设置，上传0.4.0更新包；已有绑定和历史数据无需迁移。
2. 0.4.0 的 HTTP 实现使用独立版本命名空间，避免常驻进程复用旧控制器；后台和用户模板通过插件视图组合器加载新入口，不改核心模板文件。已用预加载0.1.0旧类和旧SQLite结构的测试验证兼容性；上线仍需验证实际页面与调度。
3. 检查 `php artisan node-quality:maintain --check`，在新管理页「数据与设置」修复侧边栏入口。
4. 刷新用户页面，确认原节点列表仍在，且新Plus菜单在原站内容区显示监测，点击原节点状态会还原原列表。

后台内容直接挂载于原后台对应插件页，菜单包括监测概览、探针接入、节点绑定、检测目标、监测设置。支持探针重命名、出口编辑、解绑保留历史、网站编辑/启停、回程表单和来源开关；不需要手写JSON。注册命令只在当前窗口展示，关闭后清除，不写入浏览器存储。

## 安装节点 Agent

支持 Debian/Ubuntu + systemd，amd64/arm64。运行管理页生成的专属命令。安装器会：

- 安装 curl、jq、bc、ping、DNS 工具等依赖。
- 从面板下载打包好的 Agent、采集器和校验清单，核对 SHA-256。
- 从 NextTrace 官方发布地址下载固定 `v1.7.3` tiny 二进制，核对打包清单中的 SHA-256。
- 使用30分钟有效的一次性注册码注册机器，配置独立 `nodequality` 系统用户和 systemd 服务；仅授予 `CAP_NET_RAW`。

选择接入时可以同步关联所属节点。注册成功后检查绑定；同出口的其他逻辑节点可以在「节点绑定」中选择相同出口。一个落地出口可以关联多个逻辑节点；源 IP/网卡必须是这台机器真实可用的地址。不要把中转入口机器的数据当成落地出口数据。

服务检查：

```bash
systemctl status node-quality-agent
journalctl -u node-quality-agent --since '30 minutes ago'
```

安装命令包含一次性注册码，请勿公开分享。机器凭据保存在 `/var/lib/node-quality/config.json`，权限0600。重复注册到不同面板时使用新的状态目录，避免混用身份。撤销凭据后，Agent 在下一次同步收到拒绝时停止新采集。

## 用户功能

用户侧新增「节点状态 Plus 监测」菜单，站内视图地址 `/#/node?plus=1`。原「节点状态」继续显示原版列表，不被替换或隐藏。Plus 支持名称/地区/运营商搜索、状态/网站筛选、IPv4/IPv6选择、延迟和可用性排序、站内详情视图及明暗主题。详情包括：

- 24小时、7/30/90天历史；TCP建连耗时、Ping RTT、TCP成功率、ICMP丢包率。
- 预设网站双栈结果与限流补测。
- 脱敏出口IP、ASN、地区、网络类型、多来源风险值和历史体检。
- 三网回程跳点与ASN路径、服务检测、IP/线路变化事件。

`custom_html` 加载用户侧桥接脚本：在默认主题桌面和移动菜单增加 Plus 入口，仅在 `#/node?plus=1` 时在原内容区挂载 Shadow DOM 监测视图。原列表元素保留；回到原节点状态时销毁监测视图并恢复原列表。详情仍使用原站的查询参数，原导航、顶部栏、登录态保持原样，不使用 iframe 或另一套应用外壳。旧 `/plugins/node_quality/plus.html` 地址只转回原站内嵌视图。第三方主题不在本版兼容范围内。

## 采集口径与限制

- 机器出口探测，不等于用户端到端代理体验，也不测试代理认证或订阅协议。
- 5分钟一轮，4次ICMP、3次TCP；IPv4/IPv6单独保存。TCP计时不含DNS/TLS/HTTP。
- TCP评分使用最近24个完整UTC小时；有效数据覆盖不足80%或样本不足20次时不评分。无可用地址族不按零风险/零延迟处理。
- IP质量、服务检测和回程每天一次，出口变化可触发补测。来源故障可在管理页按名称停用；免费来源没有可用性承诺。
- 所有 IP 风险值保留原始量表，不合并成纯净度。服务检测采用保守判定，没有区域证据的正面结果降为未知；不保证付费内容或真实账号可用。
- IPQuality固定提交 `2384a67c756eb35231f5982b34731e522be3653e`，裁剪安装器、邮件、DNSBL、公开上传和使用统计。第三方数据查询本身仍需访问对应数据源。
- 不包含任意Shell、重启/升级xboard-node、带宽测速。

## 存储、恢复与备份

插件专用 SQLite，主业务数据库仅保留 XBoard 正常的插件登记和主题设置。

```text
storage/node-quality/
  metadata.sqlite       机器、绑定、任务、摘要
  raw/YYYY-MM-DD.sqlite  90天逐次采集批次（压缩JSON）
  rollups/YYYY-MM.sqlite 一年小时汇总
  backups/              一致性备份
```

上报先提交原始记录再确认，派生统计通过持久化待处理标记修复。相同记录重复提交不重复计数。Agent本地暂存72小时或256MiB，超限记录缺口；面板计划24小时未续期会停止新采集。

```bash
php artisan node-quality:maintain --check
php artisan node-quality:maintain --backup
php artisan node-quality:maintain
```

维护/备份使用排他生命周期锁；正常请求使用共享锁。暂时争用返回503，Agent稍后重传。不能直接复制活动数据库或手工删除WAL。

恢复时先禁用插件、停止访问和维护进程，保留旧目录，再把选定完整备份恢复到监测目录并恢复面板用户权限；重新启用后 Agent 会补传仍在缓存内的结果。备份存放同磁盘，应另外复制到可靠的备份介质；本版不自动删除备份。

磁盘低于10%或1GiB暂停重体检，低于512MiB拒绝新结果。分文件不能隔离物理磁盘容量，因此必须关注容量告警。

禁用/卸载插件保留历史与配置目录；卸载节点服务可运行安装器的 `--uninstall`，同样保留本地状态。仅在明确需要时清空历史：

```bash
php artisan node-quality:purge-history --confirm=DELETE-HISTORY
```

该操作保留机器、凭据、绑定、目标及备份。清理后旧缓存结果可能重新上报；如需彻底停采，应先停用或撤销 Agent。

## 开发与验证

```bash
cd agent && go test -race ./...
cd .. && npm ci && npm test
php tests/store.php
bash tests/quality.sh
php tests/scale.php
python3 scripts/build.py dist
```

`tests/laravel.php` 通过 `XBOARD_PATH` 指定带 Composer 依赖的 XBoard 测试目录，使用真实 Laravel Router/Eloquent/XBoard 中间件和内存 SQLite，仅认证身份由测试 guard 提供。`tests/preview.mjs` 使用相邻 XBoard 原始编译主题和合成 API，监听127.0.0.1:8765；不连接生产面板。

构建脚本打包两种架构的静态 Agent，校验插件ZIP小于XBoard的10MiB上传限制。完整验证结果见 `VALIDATION.md`。真实两台机器48小时试运行仍需部署后完成。
