# XBoard Node Quality · 节点状态 Plus

为 XBoard 提供节点出口质量监测的个人开源项目，由 [LuorixDev](https://github.com/LuorixDev) 维护。

**前后台均嵌入原页面**：用户侧新增「节点状态 Plus 监测」，保留原节点状态列表；管理员直接在 XBoard 后台选择已有服务器或节点接入，无需另一套管理站点。

[下载安装包](https://github.com/LuorixDev/xboard-node-quality/releases) · [安装与维护](docs/OPERATIONS.md) · [开发指南](CONTRIBUTING.md) · [验证范围](docs/VALIDATION.md)

## 功能

- 节点卡片、状态筛选、IPv4/IPv6、网站延迟排序及站内详情。
- 管理员预设网站，定时检测 ICMP/TCP，用户可申请限流补测。
- 出口 IP、ASN、地区、多来源风险信息、三网回程及服务检测。
- 原始记录保留 90 天、小时汇总保留一年，记录 IP 和线路变化。
- 从现有 XBoard 服务器/旧模式节点选择接入，复用名称、地址与节点关系。
- 独立 Go Agent、一键安装、断网缓存、去重上报、任务租约与容量保护。
- 插件专用 SQLite 按日分文件，保留一致性备份和恢复工具。

检测口径是**节点机器出口到目标**，不是用户设备到节点的延迟，也不是完整代理链路测速。IP 风险分值保留各来源量表，不合成为“纯净度总分”。

## 兼容范围

| 组件 | 要求 |
| --- | --- |
| 面板 | `cedar2025/Xboard`，验证基线 `4f48e61a2cbc6db5338872b6bdb45ef954ec1256` |
| 用户主题 | 默认 Xboard 主题；第三方主题需要单独适配 |
| PHP | 8.2+，`pdo_sqlite`、`sqlite3`、`zlib`；SQLite 3.35+ |
| 存储 | 单面板实例、持久化本地磁盘，不支持 NFS/CIFS 共享写入 |
| Agent | Debian/Ubuntu、systemd、Linux amd64/arm64 |

这是初版项目。免费数据源和前端 DOM 结构可能变化，升级面板或主题前应先在测试环境验证。已有短时部署与功能验证，不代表完成了 48 小时稳定性验收。

## 快速安装

1. 从 [Releases](https://github.com/LuorixDev/xboard-node-quality/releases) 下载 `NodeQuality-0.4.0.zip`，不要把 GitHub 自动生成的源码 ZIP 当作插件安装包。
2. 备份现有设置，在 XBoard「插件管理」上传、安装并启用。
3. 刷新后台，在「节点状态 Plus 监测 → 探针接入」选择已有机器或节点，确认关联关系后生成安装命令。
4. 在对应落地机的 SSH 终端执行命令；不要公开分享含一次性注册码的命令。
5. 在「检测目标」配置公开网站，等待首轮采集。

用户入口为原站 `/#/node?plus=1`；原 `/#/node` 不被替换。后台沿用原后台的登录态、侧边栏和顶部导航。无需修改 xboard-node。

## 从源码构建

需要 Go 1.23+、Python 3，以及测试用的 Node.js 22+/PHP。

```bash
git clone https://github.com/LuorixDev/xboard-node-quality.git
cd xboard-node-quality
npm ci
npm test
(cd agent && go test -race ./...)
php tests/store.php
bash tests/quality.sh
python3 scripts/build.py dist
```

产物包括可上传插件包、完整源码包、说明及 SHA-256 清单。构建会生成运行时 PHP 类和两种架构的 Agent；这些生成文件不纳入 Git。安装包必须小于 XBoard 的 10 MiB 上传限制。

目录与额外的 Laravel/原版主题集成验证见 [开发指南](CONTRIBUTING.md)。

## 数据与权限

监测数据存放在面板的 `storage/node-quality`，Agent 凭据和暂存结果位于节点的 `/var/lib/node-quality`。不要提交这些目录、面板 `.env`、数据库、SSH 凭据或注册命令。

用户 API 按 XBoard 当前账号和节点权限检查，管理员 API 使用原后台身份认证；Agent 使用独立机器凭据，不读取或重置原 xboard-node Token。

本项目不提供任意 Shell、服务器重装、xboard-node 远程重启或带宽测速。

## 许可与致谢

[AGPL-3.0](LICENSE)。IP 质量采集器改编自 [xykt/IPQuality](https://github.com/xykt/IPQuality)，回程检测使用固定版本 [NextTrace](https://github.com/nxtrace/NTrace-core)，目标配置参考 [xykt/NetQuality](https://github.com/xykt/NetQuality)。详见 [第三方组件说明](docs/THIRD_PARTY.md)。
