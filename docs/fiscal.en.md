# Fiscal receipt

Short answer: **if you accept payments via Kaspi, a fiscal receipt is mandatory. But in most cases Kaspi itself issues it, free of charge.** Below is who needs to do what.

## What the law says

The obligation to issue a receipt lies with the **seller**, not with the payment service. Kazakhstan has no legal construct of "a third party issues the receipt on your behalf". Qut Pay does not hold the money, so it cannot issue a receipt in your name.

The rules are in the new Tax Code (No. 214-VIII), in force since 1 January 2026, in Articles 110 and 111 on cash registers (ККМ). The mandatory receipt details are in Order No. 626 of the Ministry of Finance.

An important distinction:

| Payment type | Receipt required |
|---|---|
| Kaspi QR, invoice to phone, card, mobile payment | **Yes** |
| Direct transfer to a bank account (payment order) | No |

Penalty: a warning the first time; if repeated within a year, for small business from 20 to 40 MCI. The MCI (monthly calculation index, МРП) in 2026 is 4 325 ₸, i.e. roughly 86 500 to 173 000 tenge.

## The easiest way: Kaspi Касса

Your Kaspi Pay business account has a service called **Kaspi Касса** (Kaspi cash register). Both connecting it and using it are **free**. Once connected, it is automatically registered with the Kaspi fiscal data operator (ОФД) and put on record with the State Revenue Committee; no separate registration is needed.

The key point: **for remote payments (invoice to phone, link) the receipt is generated automatically** and sent to the customer in the "Уведомления" (Notifications) section of the Kaspi app. So when an invoice you issued via Qut Pay is paid, the receipt goes out by itself; nothing is required from you.

Automatic: payment via Kaspi Gold, Kaspi Red, Kaspi Kredit, from a Kaspi Pay account, remote payment, an order in the Kaspi.kz store.

Manual: cash, another bank's POS terminal, or a QR from another bank's app. In that case you press the "Отправить чек клиенту" (Send receipt to customer) button in the Kaspi app.

### How to connect

Kaspi Pay app → Kaspi Касса → connect. The full guide is in the Kaspi guide: [guide.kaspi.kz](https://guide.kaspi.kz/partner/ru/cashier/payments).

Cases where it may not be possible to connect:

- you are an additional user (only the director, manager or accountant can connect Kaspi Касса);
- you have no point of sale in Kaspi Pay;
- the sole proprietorship (ИП) was opened under a power of attorney, but you are not appointed as the director in Kaspi Pay.

If you run into one of these, write to Kaspi support; each case is reviewed individually.

## If you cannot connect Kaspi Касса

Then you need a separate cloud cash register: Webkassa, re:Kassa and others. You sign the contract yourself; the monthly fee is roughly 1 200 to 3 000 tenge.

In that case, write to us. We are planning an integration that issues the receipt automatically using your cash-register account key, but it will be off by default — see the next section for why.

## Why we do not issue receipts by default

If you have Kaspi Касса connected, Kaspi issues the receipt itself. If we issued one too, **two fiscal receipts** would go out for one payment. That artificially inflates your turnover, brings you to the VAT and special-regime thresholds earlier, and creates discrepancies in your tax return.

We have no way to find out via the API whether Kaspi Касса is connected. So the answer to this question is yours: in the cabinet, in the Settings section, you mark the Kaspi Касса status yourself.

## What to do, in short

1. Connect Kaspi Касса in the Kaspi Pay app; it is free.
2. Cabinet → Settings → in the "Fiscal receipt" section, mark that you have connected it.
3. Make one real payment and check that the customer received the receipt.
4. If you cannot connect it, write to us and we will look at the separate cash register option.

## Help

Cabinet → **Support**, WhatsApp +7 778 881 3333, or `/support text` in Telegram [@qutpaybot](https://t.me/qutpaybot).

> This page is for information only and is not tax advice. In case of doubt, check with your accountant or the State Revenue Committee.
