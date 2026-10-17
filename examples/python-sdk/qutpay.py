"""Qut Pay — Python клиенті (3.8+, тек стандартты кітапхана).

    from qutpay import QutPay, verify_webhook
    qp = QutPay(api_key=os.environ["QUTPAY_API_KEY"])  # base_url әдепкі https://api.qut.kz
    inv = qp.create_invoice(amount=5000, description="Премиум", external_order_id="inv-1001",
                            customer={"email": "a@b.kz"}, success_url="https://site.kz/ok")
    return redirect(inv["payUrl"])

Webhook (Flask):
    raw = request.get_data()
    if not verify_webhook(secret, raw, request.headers):
        abort(401)
    event = json.loads(raw)
    if event["event"] == "invoice.paid":
        activate(event["invoice"]["externalOrderId"])
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, Mapping, Optional, Union

__all__ = ["QutPay", "QutPayError", "verify_webhook"]
__version__ = "0.1.0"


class QutPayError(Exception):
    """API қатесі: .status (HTTP, 0 — желі), .code (invalid_amount, invoice_not_found …)."""

    def __init__(self, message: str, status: int = 0, code: Optional[str] = None):
        super().__init__(message)
        self.status = status
        self.code = code


def _clean(d: Dict[str, Any]) -> Dict[str, Any]:
    return {k: v for k, v in d.items() if v is not None}


class QutPay:
    def __init__(self, api_key: str, base_url: str = "https://api.qut.kz", timeout: float = 20.0):
        if not api_key:
            raise ValueError("api_key is required")
        self._api_key = api_key
        self._base = base_url.rstrip("/")
        self._timeout = timeout

    # ── invoices ──

    def create_invoice(
        self,
        amount: Union[int, float, str],
        *,
        kind: str = "qr",
        description: Optional[str] = None,
        external_order_id: Optional[str] = None,
        customer: Optional[Dict[str, str]] = None,
        success_url: Optional[str] = None,
        fail_url: Optional[str] = None,
        metadata: Optional[Dict[str, Any]] = None,
        idempotency_key: Optional[str] = None,
    ) -> Dict[str, Any]:
        """→ {id, status, payUrl, qrUrl, deepLink, qrImageUrl, expiresAt, …}. kind: 'qr' | 'phone' (customer.phone керек)."""
        body = _clean(
            {
                "amount": amount,
                "kind": kind,
                "description": description,
                "externalOrderId": external_order_id,
                "customer": customer,
                "successUrl": success_url,
                "failUrl": fail_url,
                "metadata": metadata,
            }
        )
        headers = {"Idempotency-Key": idempotency_key} if idempotency_key else {}
        return self._call("POST", "/api/v1/invoices", body, headers)

    def get_invoice(self, invoice_id: str, live: bool = False) -> Dict[str, Any]:
        return self._call("GET", f"/api/v1/invoices/{urllib.parse.quote(invoice_id, safe='')}" + ("?live=1" if live else ""))

    def list_invoices(self, **filters: Any) -> Dict[str, Any]:
        """filters: status, externalOrderId, search, from, to, limit, offset (camelCase немесе snake_case)."""
        params = {k.replace("_id", "Id").replace("from_", "from"): v for k, v in _clean(filters).items()}
        qs = urllib.parse.urlencode(params)
        return self._call("GET", "/api/v1/invoices" + (f"?{qs}" if qs else ""))

    def cancel_invoice(self, invoice_id: str) -> Dict[str, Any]:
        return self._call("POST", f"/api/v1/invoices/{urllib.parse.quote(invoice_id, safe='')}/cancel", {})

    def refund_invoice(self, invoice_id: str, amount: Optional[Union[int, float]] = None, reason: Optional[str] = None) -> Dict[str, Any]:
        """amount=None → толық қайтару."""
        return self._call("POST", f"/api/v1/invoices/{urllib.parse.quote(invoice_id, safe='')}/refund", _clean({"amount": amount, "reason": reason}))

    def simulate_invoice(self, invoice_id: str, status: str = "paid") -> Dict[str, Any]:
        """Тек sandbox."""
        return self._call("POST", f"/api/v1/invoices/{urllib.parse.quote(invoice_id, safe='')}/simulate", {"status": status})

    def account(self) -> Dict[str, Any]:
        return self._call("GET", "/api/v1/account")

    # ── transport ──

    def _call(self, method: str, path: str, body: Optional[Dict[str, Any]] = None, headers: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
        data = None
        h = {"X-API-Key": self._api_key, "Accept": "application/json", "User-Agent": f"qutpay-python/{__version__}"}
        if headers:
            h.update(headers)
        if method != "GET":
            data = json.dumps(body or {}, ensure_ascii=False).encode("utf-8")
            h["Content-Type"] = "application/json"
        req = urllib.request.Request(self._base + path, data=data, method=method, headers=h)
        try:
            with urllib.request.urlopen(req, timeout=self._timeout) as resp:
                return self._parse(resp.status, resp.read())
        except urllib.error.HTTPError as e:
            return self._parse(e.code, e.read())
        except urllib.error.URLError as e:
            raise QutPayError(f"Network error: {e.reason}", 0, "network_error") from e
        except TimeoutError as e:  # pragma: no cover
            raise QutPayError("Timeout", 0, "timeout") from e

    @staticmethod
    def _parse(status: int, raw: bytes) -> Dict[str, Any]:
        try:
            payload = json.loads(raw.decode("utf-8")) if raw else {}
        except ValueError:
            payload = {}
        if not 200 <= status < 300:
            raise QutPayError(payload.get("message") or payload.get("error") or f"HTTP {status}", status, payload.get("error"))
        return payload


def verify_webhook(secret: str, raw_body: Union[bytes, str], headers: Mapping[str, str], tolerance_sec: int = 300) -> bool:
    """X-Webhook-Signature = sha256=HMAC-SHA256(secret, f"{timestamp}.{raw_body}") тексеру, replay-қорғанысымен."""
    if not secret:
        return False
    lower = {str(k).lower(): v for k, v in headers.items()}
    signature = str(lower.get("x-webhook-signature", ""))
    timestamp = str(lower.get("x-webhook-timestamp", ""))
    if not signature.startswith("sha256=") or not timestamp.isdigit() or len(timestamp) > 12:
        return False
    if abs(time.time() - int(timestamp)) > tolerance_sec:
        return False
    body = raw_body.decode("utf-8") if isinstance(raw_body, bytes) else str(raw_body)
    expected = hmac.new(secret.encode("utf-8"), f"{timestamp}.{body}".encode("utf-8"), hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature[7:])
