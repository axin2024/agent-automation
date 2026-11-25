# 运维手册

## 配置和身份

| 配置 | 用途 |
| --- | --- |
| `AUTOMATION_MYSQL_DSN` | 完整 PDO MySQL DSN，使用 `utf8mb4` |
| `AUTOMATION_MYSQL_USER` / `AUTOMATION_MYSQL_PASSWORD` | 数据库应用账号，限制在应用库 |
| `AUTOMATION_ENCRYPTION_KEY` | 64 位十六进制字符，加密模型凭据，独立备份 |
| `AUTOMATION_MODEL_URL` | 工作进程使用的统一模型地址，空值时规则仍可运行 |
| `AUTOMATION_BIND_PORT` | Compose 宿主机回环端口，默认 8080 |

凭据由 `provision <tenant> <environment> <actor> <role> <hours>` 创建，角色为 `admin`、`author`、`operator`、`approver` 或 `trigger`。使用 `Authorization: Bearer <token>`。支持随时用 `revoke <tenant> <environment> <actor>` 撤销某角色身份当前持有的所有凭据。正常业务把发布、触发和审批交给对应的独立账号，入门样例使用短效管理员凭据。

## 常用 API

写入使用 `application/json`。业务图、连接和订单修改应带 `expected`，填本次读取的当前修订。发送成功响应丢失后先按运行或操作标识查询。

| 路径 | 功能 |
| --- | --- |
| `GET /api/health` | 数据库与迁移就绪标记 |
| `GET /api/workspace` | 当前环境、租户、角色 |
| `POST /api/graph/validate` | 用 `definition` 校验类型、路径及绑定要求 |
| `POST /api/flows/{flow}` | `definition`、`bindings`、`expected`、`activate` 保存可复跑版本 |
| `POST /api/flows/{flow}/events` | `source` 和 `event` 触发，内容不符的重复键返回 409 |
| `GET /api/runs/{id}` | 工单、历史、审批和外发记录 |
| `POST /api/runs/{id}/approvals/{node}` | `action`、`revision`、`digest` 审批，修订时传 `changes` |
| `POST /api/deliveries/{operation}/reconcile` | 用原键检查待确认的收件回执 |
| `GET /api/inbox`、`GET /api/statistics`、`GET /api/audit` | 环境内的本地收件、模型用量与审计 |

固定数据的端到端测试从专用的新测试工作区执行。设置 `AUTOMATION_SMOKE_TOKEN` 后运行 `php scripts/smoke.php http://127.0.0.1:8080`。脚本持久创建流程和样例运行，独立的 `worker` 进程须已启动。每次验证使用新工作区。

## 升级与恢复

1. 暂停触发端，等待活跃处理结束，停止工作进程。备份应用库及独立保管的 `AUTOMATION_ENCRYPTION_KEY`。
2. 使用 `mysqldump --single-transaction --hex-blob --no-tablespaces --set-gtid-purged=OFF` 导出应用库。认证从本地安全选项文件或部署秘密注入，以免凭据进入 Shell 历史。备份含邮件正文、草稿及加密连接资料，按业务数据保护。
3. 在隔离库导入备份，使用同一密钥执行 `php bin/automation.php migrate`，检查迁移校验值及健康状态。验证原始邮件身份、工单、审批修订和投递回执，必要时用 `/api/deliveries/{operation}/reconcile` 处理未知结果。
4. 升级到新版程序后只启一个工作进程，确认正常；再逐步恢复工作进程和触发量。回退应用前按数据库版本确认兼容性，遇到不兼容时从隔离备份恢复旧程序和库。

## 版本范围

本地订单数据通过订单接口录入，工单与收件箱由本服务持久化。IMAP、SMTP、企业工单和独立订单系统需要部署方接入适配器。工作台支持内置节点的有向无环图，模型接口只负责拟稿。投递至外部系统时应保留操作键、查询与未知状态的合同。
