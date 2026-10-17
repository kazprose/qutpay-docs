# Qut Pay Python SDK

Бір модуль — `qutpay.py` (Python 3.8+, стандартты кітапхана ғана). `pip install .` немесе файлды жобаға көшіріңіз.

```python
import os
from qutpay import QutPay, QutPayError, verify_webhook

qp = QutPay(api_key=os.environ["QUTPAY_API_KEY"])  # qp_test_… (sandbox) / qp_live_…
inv = qp.create_invoice(amount=5000, description="Премиум жазылым", external_order_id="inv-1001",
                        customer={"email": "a@b.kz"}, success_url="https://site.kz/ok", idempotency_key="inv-1001")
redirect(inv["payUrl"])
```

Webhook (Flask):
```python
@app.post("/qutpay-webhook")
def hook():
    raw = request.get_data()
    if not verify_webhook(WEBHOOK_SECRET, raw, request.headers):
        abort(401)
    e = json.loads(raw)
    if e["event"] == "invoice.paid":
        activate(e["invoice"]["externalOrderId"])
    return "", 200
```

Django: `verify_webhook(secret, request.body, request.headers)`.

Әдістер: `create_invoice`, `get_invoice(id, live=False)`, `list_invoices(**filters)`, `cancel_invoice`, `refund_invoice(id, amount=None, reason=None)`, `simulate_invoice` (sandbox), `account`. Қателер — `QutPayError` (`.status`, `.code`).
Толық API: https://api.qut.kz/docs
