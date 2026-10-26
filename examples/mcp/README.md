# @qutpay/mcp — Qut Pay MCP server

[Model Context Protocol](https://modelcontextprotocol.io) server for [Qut Pay](https://qut.kz): lets an AI agent (Claude Code, Claude Desktop, Cursor, Windsurf, VS Code Copilot, ChatGPT developer mode…) create Kaspi QR invoices, read their status, refund, and test the whole flow in sandbox through the Qut Pay API. Kazakhstan only, KZT only. Qut Pay is an independent service, not affiliated with Kaspi Bank.

## Install

You need an API key from the Qut Pay cabinet (https://qut.kz/app → Integrations → API keys). Start with a **sandbox key** (`qp_test_…`) — no real money moves, payments are simulated.

Claude Code:

```bash
claude mcp add qutpay -e QUTPAY_API_KEY=qp_test_… -- npx -y @qutpay/mcp
```

Cursor one-click: [Add to Cursor](https://cursor.com/en/install-mcp?name=qutpay&config=eyJjb21tYW5kIjoibnB4IiwiYXJncyI6WyIteSIsIkBxdXRwYXkvbWNwIl0sImVudiI6eyJRVVRQQVlfQVBJX0tFWSI6InFwX3Rlc3RfLi4uIn19) (then set your key). Cursor / Windsurf / Claude Desktop / VS Code (`mcp.json`, `claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "qutpay": {
      "command": "npx",
      "args": ["-y", "@qutpay/mcp"],
      "env": { "QUTPAY_API_KEY": "qp_test_…" }
    }
  }
}
```

The key is read only from the environment and is never printed by the server. Optional `QUTPAY_BASE_URL` (default `https://api.qut.kz`).

## Tools

| Tool | What it does |
|---|---|
| `qutpay_account` | Organization, mode (sandbox/live), tariff and limits, cashiers, provider status — call first |
| `qutpay_create_invoice` | Create an invoice (QR, or pushed to the customer's Kaspi.kz app with `kind: "phone"`); returns `payUrl`, `qrImageUrl`, `deepLink`, `expiresAt`. Pass `idempotencyKey` per order |
| `qutpay_get_invoice` | Status of one invoice (`live: true` asks Kaspi right now) |
| `qutpay_list_invoices` | List with filters: status, externalOrderId, from/to, limit ≤ 50 |
| `qutpay_cancel_invoice` | Close an open invoice |
| `qutpay_refund_invoice` | Full or partial refund (scope `refunds:write`); with a live key requires `confirm: true` |
| `qutpay_simulate_payment` | Sandbox only: mark an invoice paid / failed / expired, webhooks are delivered for real |
| `qutpay_list_subscriptions`, `qutpay_get_subscription` | Read recurring invoice schedules (scope `subscriptions:manage`) |

Resources: `qutpay://skill` (agent skill — the integration procedure), `qutpay://docs/integration`, `qutpay://docs/webhook-verify`, `qutpay://docs/errors`, `qutpay://docs/openapi`. Prompts: `integrate-checkout` (stack), `verify-sandbox` (amount).

API keys and webhook endpoints are deliberately **not** manageable from the MCP server: the merchant creates them in the cabinet, and the agent asks for them.

## Typical session

1. `qutpay_account` → mode is sandbox.
2. `qutpay_create_invoice { amount: 100, externalOrderId: "test-1", idempotencyKey: "test-1" }` → `payUrl`.
3. `qutpay_simulate_payment { id }` → `paid`; the merchant's webhook receives `invoice.paid`.
4. `qutpay_get_invoice { id }` → `paid`.

## Docs

- Agent skill: https://api.qut.kz/SKILL.md · integration guide: https://api.qut.kz/for-ai · OpenAPI: https://api.qut.kz/openapi.json
- Guide (ru/kk/en): https://api.qut.kz/docs/guide/mcp
- Status page: https://status.qut.kz · trust center: https://qut.kz/en/trust/

MIT.
