# MailKite examples — PHP

Runnable, copy-pasteable examples. Each file's header comment lists what to set and `composer require`.

| File | What it shows |
| --- | --- |
| [`01-send-email.php`](01-send-email.php) | Send an email over a verified domain |
| [`02-receive-webhook.php`](02-receive-webhook.php) | Receive inbound mail as a webhook and **verify the HMAC signature** |
| [`03-agent-reply.php`](03-agent-reply.php) | **AI email agent** — inbound email → Claude drafts a reply → MailKite sends it, threaded |
| [`04-agent-inbox.php`](04-agent-inbox.php) | Give your agent its own address with MailKite's **built-in inbox agent** (no server) |
| [`05-server-login.php`](05-server-login.php) | **Server-side login + register** — your own account, or your users' accounts via OAuth |

Full docs: <https://mailkite.dev/docs> · AI agents: <https://mailkite.dev/docs/ai-agents>
