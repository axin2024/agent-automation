# Agent Automation

自托管的 PHP 业务流程与审批工作台。以售后邮件为例，把分类、建单、订单查询、拟稿、审批和收件回执串成可回读的流程。推理可使用规则、测试录制或 OpenAI 兼容接口。执行进度、审批版本、任务去重和本地收件箱由 MySQL 8.4 持久保存。

## 独立运行

需要 PHP 8.2 及以上、`pdo_mysql`、`curl`、`mbstring`、`openssl` 和 MySQL 8.4。使用 Docker Compose 时，复制 `.env.example` 为 `.env`，为 MySQL 配置两个不同的随机密码，并填入 `openssl rand -hex 32` 生成的加密密钥。

```sh
docker compose up -d --build
docker compose exec web php bin/automation.php provision demo test demo-admin admin 1
```

浏览器打开 `http://127.0.0.1:8080`，把命令返回的 `token` 填入环境凭据。依次点**载入样例**、**初始化样例连接**、**发布**和**提交试运行**。在运行详情里批准草稿，刷新后在本地收件箱查阅回执。示例地址使用 `example.test`，投递目标是项目自带的 SQL 收件箱。

已有 PHP 和 MySQL 的环境可以直接从源码运行。创建名为 `automation` 的空库并给应用账号授权，然后在两个进程中配置同一组环境变量。

```sh
export AUTOMATION_MYSQL_DSN='mysql:host=127.0.0.1;dbname=automation;charset=utf8mb4'
export AUTOMATION_MYSQL_USER='automation'
# 从部署环境注入 AUTOMATION_MYSQL_PASSWORD 和 AUTOMATION_ENCRYPTION_KEY。
php bin/automation.php migrate
php bin/automation.php provision demo test demo-admin admin 1
php -S 127.0.0.1:8080 -t public public/router.php
```

另开终端，以相同配置运行 `php bin/automation.php worker`。PHP 内置服务器用于本地验证，长期运行使用提供的 Apache 容器并在入口配置 TLS。

## 工作区和集成

| 能力 | 边界 |
| --- | --- |
| 可视化流程 | 类型化输入和分支、发布前校验、版本回滚、冻结在途运行、模板导出 |
| 业务连接 | 同租户的测试与生产凭据独立，按能力绑定订单、模型和本地收件箱 |
| 事件处理 | 邮件身份与内容摘要去重、待处理额度、订单纠正、节点时间线 |
| 生成和审核 | 字段白名单、模型调用预算、草稿修订、审批期限、订单与连接变更校验 |
| 投递与恢复 | 稳定操作标识、只查询未知回执、回读审批撤销原因及发送尝试 |
| 运营 | 作用域凭据、审计、运行及用量统计、分类和提取反馈 |

接入真实模型时，配置 `AUTOMATION_MODEL_URL`，使用受信 HTTPS 服务或字面地址 `127.0.0.1`、`[::1]` 上的 `/v1/chat/completions`。管理员保存模型名、允许提交的字段及可选令牌，作者将 `draft.options.mode` 改为 `live` 后发布。模型通过 JSON Schema 从预先审核的回复结尾中选择一项。系统从订单库填入订单号和状态，草稿仍需人工审批。测试录制仅能在 `test` 环境使用。

Webhook 输入见 [事件示例](public/sample.json)，HTTP 路径和恢复步骤见 [运维手册](docs/operations.md)，逐项验证见 [验收记录](docs/validation.md)。完整设计存于 [需求文档](docs/requirements/01-产品总览-prd.md)。

## 开发与许可

服务直接使用 PHP，Composer 仅验证平台依赖。提交变更前跑 PHP 语法检查和 MySQL 生命周期用例，涉及界面时检查 `node --check public/app.js`。协作说明见 [CONTRIBUTING](CONTRIBUTING.md)，许可证为 [Apache-2.0](LICENSE)。
