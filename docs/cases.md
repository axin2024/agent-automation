# 售后案件

## 配置测试租户

按 [安装说明](../README.md) 完成迁移。用源码部署时执行下列命令。Compose 用户在各行命令前加 `docker compose exec web`。各账号保管自己的凭据，在工作台点击 **案件** 后办理对应步骤。

```sh
php bin/automation.php provision returns-demo test supervisor admin 8
php bin/automation.php provision returns-demo test investigator operator 8
php bin/automation.php provision returns-demo test operations operator 8
php bin/automation.php provision returns-demo test finance approver 8
php bin/automation.php provision returns-demo test decision-owner approver 8
```

1. 主管在 **模板与督办** 预演 `returns` 默认模板并发布。模板规定受理入口、阶段时限、节假日、三类必备材料和两部门审批人。修改后发布新版本，既有案件仍使用受理时的定义。
2. 调查员使用 `after-sales` 受理一份合成售后单。外部单号要在该入口唯一。登记 `customer-summary` 的 `issue_code` 和 `order` 的 `status`。来源备注和原始片段可保留在证据中。
3. 财务登记 `payment-proof` 并核实状态、金额上限和收款方内部编号。三类资料齐备后，调查员完成调查，运营和财务分别认领并完成自己的复核。转派时记录交接。临时代办在租约到期、撤销或更换后失效。
4. 拟办人认领 **拟办** 任务，登记结案决定或演示退款，例如金额 `10000` 和收款方 `demo-original`。运营和财务分别核对参数摘要与证据版本并批准。执行进程在写入本地账本后回读业务回执。该连接器写入合成账本，适用于演练。
5. 主管可按状态、关键词和到期时间搜索，回看审计与督办统计。结案后重开产生独立处置轮次，旧决定、签名和回执可继续查询和导出。

## 角色意见

配置同一台主机的 OpenAI 兼容服务，再启动 Web 进程。案件助手只连接字面环回地址。容器部署时，模型服务须与 Web 容器共享网络命名空间，或使用源码方式在宿主机部署 Web 和模型。

```sh
export AUTOMATION_CASE_MODEL_URL=http://127.0.0.1:8081/v1/chat/completions
export AUTOMATION_CASE_MODEL_NAME=qwen2.5-0.5b-instruct
```

| 助手 | 可用的核实资料 | 意见 |
| --- | --- | --- |
| 分类 | 工作区客户摘要 | 诉求分类，链接到客户摘要 |
| 调查 | 客户摘要及调查可用的订单状态 | 查单结论，链接到订单快照 |
| 拟办 | 可见订单字段和财务凭证 | 结案、演示退款或继续人工核查，链接到相应来源 |

每类资料仅提交已勾选的列、固定代码和短引用别名。客户编号、原始片段、订单标识和收款方保留在本地工作区。生成的引用逐条校验，证据缺项或引用不匹配时进入人工处置。工作人员可以从意见跳至证据、修订意见并保留前一版本。模型意见没有授权权限。

## HTTP 接口

所有入口挂在 `/api` 下，使用环境 Bearer 凭据和 JSON。案件写入使用 `Idempotency-Key`。重复同一键及同一内容返回原记录，改动内容返回 409。缺少字段或业务参数无效返回 422。跨租户、跨环境的案件访问返回 404。

| 路径 | 方法 | 用途 |
| --- | --- | --- |
| `/case-types`、`/case-types/preview` | GET / POST | 查询、发布与预演模板 |
| `/cases` | GET / POST | 查看待办、按 `state`、`mine`、`due_before`、`search` 筛选或受理 |
| `/cases/{case_id}/assignments/{task}` | POST | 认领、分派、转派、代办、撤销与完成，提交任务标识和租约修订 |
| `/cases/{case_id}/evidence` | GET / POST | 读取与登记证据 |
| `/cases/{case_id}/assistant` | GET | 读取角色意见和修订 |
| `/cases/{case_id}/assistant/{classify,investigate,recommend,revise}` | POST | 生成角色意见或提交具名修订 |
| `/cases/{case_id}/propose` | POST | 建立处理决定 |
| `/cases/{case_id}/decisions` | GET / POST | 查询决定与执行结果，或投票 |
| `/cases/{case_id}/decisions/{decision_id}/reconcile` | POST | 核对已受理的操作回执 |
| `/cases/{case_id}/timeline`、`/cases/{case_id}/export` | GET | 浏览具名记录，导出当前账号可见内容 |
| `/cases/{case_id}/link-run`、`/cases/{case_id}/reopen` | POST | 关联已冻结的历史流程运行及重开 |
| `/cases/preflight`、`/cases/metrics` | GET | 迁移预检与督办统计 |
| `/cases/intake-policy` | POST | 设置受理开关 |

提交批准时带上拟办响应中的 `proposal_id`、`evidence_rev`、`args_hash` 和当前部门 `approval_role`，允许值为 `operations` 或 `finance`。第二份有效批准返回 202 和 `Location`，可以轮询决定列表。参数修改将使旧批准失效。后台操作以独立业务摘要写入账本，查单只读取对应回执。

## 升级与恢复

1. 使用已发布环境的逻辑备份创建隔离副本。先运行 `php bin/automation.php case-preflight returns-demo test`，记录迁移号、历史运行数量、待处理标识与归属冲突。
2. 应用迁移 7。历史流程和案件表独立，历史运行按固定版本与摘要按需关联。首次发布模板只开放该工作区。可通过**督办指标**处的受理开关暂停新增，保留旧请求去重、审批处理和回执读取。
3. 恢复时还原同一份数据库及其凭据配置。在主管工作台读回决定、签名和回执，检查尚未结案的处置轮次。旧版本仍可从独立备份启动，升级后的数据库应使用匹配的运行版本。

容量与模型实测见 [验收记录](validation.md)。
