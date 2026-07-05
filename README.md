<div align="center">

# HesabYar — Personal Accounting (WordPress plugin)

**A full-featured Persian (Farsi) personal-accounting plugin for WordPress**, with optional two-way sync to the [HesabYar Windows desktop app](https://github.com/hrschemiker/hesabyar-desktop).

</div>

---

> **Language note.** This README is in English, but the plugin UI is **entirely in Persian (RTL)** — Toman/Rial, Jalali (Shamsi) calendar, Persian numerals and TGJU market rates.

## What it is

HesabYar renders a complete personal-finance app on any WordPress page via a shortcode. It stores everything in its own database tables (prefix `wp_hpa_`) and is designed for a single authorized user.

Add it to a page with:

```
[hamid_personal_accounting]
```

## Features

- **Dashboard** with monthly surplus/deficit, live USD & gold rates, KPIs, and due-date reminders.
- **Accounts** (cash / bank / credit), multi-currency, balance reconciliation, journal, ledger and monthly statements.
- **Transactions** — 11 types (income, expense, loan installment, recurring debt, account & person transfers, debt/receivable/cheque settlements, asset buy/sell), category splitting, tags, receipts, amount hiding and duplicate detection.
- **Categories**, **debts**, **loans** with auto-generated installment schedules, **cheques**, **recurring payments**, **receivables**.
- **Assets** (gold, silver, crypto, currency, property, car, valuables) with live market valuation, **financial goals**, and realized/unrealized P&L.
- **Reports** — health ratios, cash-flow, money routes, essential vs. non-essential spending, **per-item spending**, per-person breakdown, charts, financial calendar, PDF export and JSON backup/restore.
- **Rates** via the built-in TGJU engine, plus manual entry.
- Jalali calendar everywhere, soft-delete recycle bin, optional PIN lock.

## What's new in 3.16.0

- **Archive (close a period).** Settings → **بایگانی**: snapshot selected data groups (transactions, accounts, assets, simple debts, other liabilities, receivables, or everything) and reset their figures to zero to start a new period. Open (unsettled) obligations are preserved; each archive can be exported to **PDF**.

## What's new in 3.15.0

- **No double-counted expenses.** Repaying a debt/loan principal, settling cheques, and buying assets are now **financing (money movement)**, not **expense** — symmetric with borrowing not being income. Borrow money, spend it (recorded as a normal expense), then repay the debt, and the repayment is no longer counted again as an expense. A new **«جابه‌جایی پول و بازپرداخت‌ها»** report shows these movements separately. Balances and net worth are unchanged.
- **Borrowing is not income.** Recording a debt or loan against an account auto-creates a «قرض/وام» transaction that raises the account balance but is **never** counted as income. Person-to-person transfers are likewise excluded from income.
- **Per-item prices.** Add named line-items with prices to any transaction (independent of the total), and see a **«خرج به تفکیک قلم»** report of monthly spend per item.
- **Multi-tag entry** (press Enter to add several) and a collapsed, newest-first journal with a «show all» button.
- Full desktop-app sync (below).

## Connecting the Windows desktop app

Version **3.13.0+** adds a REST API (`/wp-json/hpa/v1`) so the companion desktop app can sync both ways.

1. In WordPress: **Settings → Personal Accountant**, tick **«اتصال نرم‌افزار دسکتاپ (حساب‌یار)»** and save. The settings page shows your site's API base URL.
2. In the desktop app: **Settings → «اتصال به سایت»**, enter the site URL and your WordPress username/password (the same account whose email is authorized), then connect.
3. Use **Pull / Push / Full sync** from the desktop app.

Endpoints: `POST /login`, `GET /pull`, `POST /push`, `POST /sync`, `GET /ping`. Requests are authenticated with a per-app bearer token and are restricted to the authorized user's email.

## Install

1. Download the latest `hesabyar-personal-accounting-x.y.z.zip` from [**Releases**](../../releases).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Install** → **Activate**.
3. Create a page containing `[hamid_personal_accounting]` and open it while logged in as the authorized user.

Or clone into `wp-content/plugins/`:

```bash
git clone https://github.com/hrschemiker/hesabyar-wordpress-plugin.git
```

## License

[MIT](LICENSE) © hrschemiker
