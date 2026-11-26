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
| `POST /api/graph/template-preview` | 校验导出模板，返回当前环境的连接引用和试运行样例 |
| `POST /api/connections/{name}/verify` | 管理员用 `revision` 和 `fixture` 验证当前连接的最小权限 |
| `POST /api/flows/{flow}` | `definition`、`bindings`、`expected`、`activate` 保存可复跑版本 |
| `POST /api/flows/{flow}/events` | `source` 和 `event` 触发，`scheduled_at` 可指定 UTC 执行时刻，同键参数不一致返回 409 |
| `GET /api/runs` | 以 `state`、`flow`、`from`、`until` 过滤，`before` 读取下一页 |
| `GET /api/runs/{id}` | 工单、历史、审批、外发记录及模型质量标注 |
| `GET /api/approvals` | 以 `reviewer`、`due_before` 筛选待办，用 `after` 继续翻页 |
| `POST /api/runs/{id}/approvals/{node}` | `action`、`revision`、`digest` 审批，修订时传 `changes`，分配时传 `expected` 与 `assignee` |
| `POST /api/runs/{id}/feedback` | 用 `node` 和 `label` 记录质量抽检 |
| `POST /api/review-batches` | 保存 `request_id` 和最多 20 条 `items`，每条包含 `run`、`node`、`revision`、`digest`、`action` |
| `GET /api/review-batches`、`GET /api/review-batches/{id}` | 查询当前审批人最近的批次，或回读已处理游标及逐项结果 |
| `POST /api/review-batches/{id}/run` | 处理未完成项，`limit` 缺省为 20 |
| `POST /api/deliveries/{operation}/reconcile` | 用原键检查待确认的收件回执 |
| `GET /api/inbox`、`GET /api/statistics`、`GET /api/audit` | 环境内的本地收件、用量耗时和审计 |
| `GET /api/retention`、`POST /api/retention` | 查询或配置正文、计量与审计保留天数 |
| `POST /api/retention/apply` | 按当前策略清理，每类最多处理 `limit` 条记录 |

固定数据的端到端测试从专用的新测试工作区执行。设置 `AUTOMATION_SMOKE_TOKEN` 后运行 `php scripts/smoke.php http://127.0.0.1:8080`。脚本持久创建流程和样例运行，独立的 `worker` 进程须已启动。每次验证使用新工作区。

流程作者可以在工作台导出或导入版本 1 模板。导出包含节点、连接能力和合成样例。导入时，每个别名都要选择当前环境中对应能力的连接。加载模板后，可在画布修改，再用现有流程保存草稿或显式发布。测试环境会将首个合成样例载入试运行表单。

连接探测使用当前修订。订单探测传入 `fixture.order_no`，回读当前环境的单笔订单。收件箱探测传入 `fixture.recipient`，检查域限制。模型探测传空对象，回读字段白名单与凭据配置状态。模型推理由独立试运行验证。

运行列表按创建时间倒序。时间参数使用 `2026-01-01T00:00:00Z` 格式，包含 `from` 指定的时刻，截止到 `until` 之前。翻页沿用同一组筛选条件。

计划触发会先登记事件和当前活动版本，到指定时刻后才可被工作进程领取。事件标识和计划时刻一起参与去重，在执行前可按运行修订取消。计划运行占未完结运行的额度。

运营人员可在待办详情分配或取消分配。当前审批人也可委托给同环境具有有效审批权限的账号，未分配时可领取给自己。分配会校验页面读取的原审批人和草稿版本，实际分配者及决定者写入各自的审计记录。已到期的草稿仍可从待办找回并刷新。

审批人可从运行详情将待审草稿加入批次，保存后再执行。一次请求的结果按项提交，草稿已改版或运行不可访问时记录该项失败，继续处理其余项目。浏览器断线时通过原标识查询进度，重连后也可以从最近批次打开并执行剩余项。相同标识和选择可重试，选择内容变化时使用新批次标识。

工作台支持为执行过的节点标注分类、字段提取和人工介入情况。统计接口返回标注计数、人工等待和本地适配器耗时，单位为微秒。模型统计按 UTC 日及模式汇总，提供调用耗时和已报告令牌量。模型调用耗时包含连接和响应时间。本地订单查询及收件箱写入各自计量。

模型连接可配置 `settings.rates`，字段为三字母大写 `currency`、`input_per_million_micros` 及 `output_per_million_micros`。后两项分别是一百万输入和输出令牌对应的微货币单位，例如 `2000000` 表示 2 个货币单位。费率在请求发送前冻结，更新连接费用会影响新请求。按货币汇总估算时保留配置费率和模型返回的用量。缺少费率或实际用量的调用仍进入总次数，但不会增加有估算的次数。实际账单由服务商提供。

## 数据保留

管理员按租户和环境独立配置 `body_days`、`metering_days` 和 `audit_days`，范围为 0 到 3650 天，0 表示保留。配置保存后，由管理员或获授权的定时作业调用清理接口。正文仅从达到期限的终态运行中清除，同时清除其草稿和收件箱副本。运行详情和收件箱会返回 `body_retained`。消息标识、摘要、工单和投递回执仍可按原标识查验。计量仅清理过期终态运行的样本，审计按各记录时间单独清理。

## 升级与恢复

1. 暂停触发端，等待活跃处理结束，停止工作进程。备份应用库及独立保管的 `AUTOMATION_ENCRYPTION_KEY`。
2. 使用 `mysqldump --single-transaction --hex-blob --no-tablespaces --set-gtid-purged=OFF` 导出应用库。认证从本地安全选项文件或部署秘密注入，以免凭据进入 Shell 历史。备份含邮件正文、草稿及加密连接资料，按业务数据保护。
3. 在隔离库导入备份，使用同一密钥执行 `php bin/automation.php migrate`，检查迁移校验值及健康状态。验证原始邮件身份、工单、审批修订和投递回执，必要时用 `/api/deliveries/{operation}/reconcile` 处理未知结果。
4. 升级到新版程序后只启一个工作进程，确认正常；再逐步恢复工作进程和触发量。回退应用前按数据库版本确认兼容性，遇到不兼容时从隔离备份恢复旧程序和库。

## 版本范围

本地订单数据通过订单接口录入，工单与收件箱由本服务持久化。IMAP、SMTP、企业工单和独立订单系统需要部署方接入适配器。工作台支持内置节点的有向无环图，模型接口只负责拟稿。投递至外部系统时应保留操作键、查询与未知状态的合同。
