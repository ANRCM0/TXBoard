# Telegram Plugin

TXBoard 内置 Telegram Bot 插件，为用户提供账号绑定、自助查询和事件通知能力。

## Features

- 用户账号绑定 / 解绑
- 流量查询
- 获取最新订阅链接
- 工单通知
- 支付通知
- 可配置欢迎信息与帮助文案

## Commands

```text
/start
/bind <subscription-url>
/traffic
/getlatesturl
/unbind
```

## Configuration

插件配置由 TXBoard Plugin Runtime 管理，常用字段包括：

- `auto_reply`
- `help_text`
- `start_welcome_title`
- `start_bot_description`
- `start_bind_guide`
- `start_unbind_guide`
- `enable_ticket_notify`
- `enable_payment_notify`

具体可用字段以插件当前 `config.json` 和实现为准，避免 README 与运行时 Schema 漂移。
