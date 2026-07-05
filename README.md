<div align="center">

<img src="docs/logo.svg" width="128" alt="HesabYar logo">

# HesabYar — Personal Accounting

### A full‑featured Persian (Farsi) personal‑accounting app that lives inside WordPress — and the sync hub for the [desktop](https://github.com/hrschemiker/hesabyar-desktop) and [Android](https://github.com/hrschemiker/hesabyar-android) apps.

<p>
<img alt="Platform" src="https://img.shields.io/badge/WordPress-plugin-21759B?logo=wordpress&logoColor=white">
<img alt="Version" src="https://img.shields.io/badge/version-3.16.0-4F46E5">
<img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white">
<img alt="REST" src="https://img.shields.io/badge/REST%20API-hpa%2Fv1-06B6D4">
<img alt="UI" src="https://img.shields.io/badge/UI-فارسی%20(RTL)-16A34A">
<img alt="License" src="https://img.shields.io/badge/license-MIT-black">
</p>

</div>

---

> **On language.** This README is in English, but the plugin UI is **100% Persian (RTL)** — Toman/Rial, Jalali (Shamsi) calendar, Persian numerals and live TGJU market rates.

## 🌐 What it is

HesabYar renders a **complete personal‑finance application** on any WordPress page via a single shortcode. It stores everything in its own tables (prefix `wp_hpa_`), is designed for one authorized user, and doubles as the **sync hub** for the companion desktop and Android apps.

```
[hamid_personal_accounting]
```

Open that page while logged in as the authorized user, and you get a full app: dashboard, ledger, assets, debts, reports — all inside your own site, on your own hosting, with your data in your own database.

## ✨ Features at a glance

| Area | What you get |
|---|---|
| 📊 **Dashboard** | Monthly surplus/deficit · live USD & gold rates · KPIs · due‑date reminders (installments, cheques, recurring) |
| 🏦 **Accounts** | Cash / bank / credit · multi‑currency · reconciliation · journal, ledger, monthly statements |
| 🔁 **Transactions** | **11 typed operations** (see below) · category splitting · multi‑tag · receipts · amount‑hiding · duplicate detection |
| 🏷️ **Categories** | Icon + color · **essential / non‑essential** flag driving the "needs vs wants" reports |
| 📉 **Debts & obligations** | Simple debts · **loans with auto‑generated installment schedules** · **cheques** · **recurring** payments · future‑pressure reports |
| 📈 **Receivables** | Money owed to you — full & partial collection tracking |
| 💎 **Assets** | Gold, silver, crypto, currency, property, car, valuables · **live market valuation** · realized/unrealized **P&L** · financial **goals** |
| 📄 **Reports** | Health ratios · cash‑flow & money routes · essential vs non‑essential · **per‑item spending** · per‑person breakdown · charts · financial calendar · **PDF export** · **JSON backup/restore** |
| 💱 **Rates** | Built‑in **TGJU** engine + manual entry |
| ⚙️ **Extras** | Jalali calendar everywhere · soft‑delete recycle bin · optional **PIN lock** · **Archive** (close a period) |

## 🧮 The accounting model, precisely

HesabYar is a **real double‑sided ledger**, not a transaction shoebox. Every transaction is one of **11 types**, each classified along two axes: **does it move cash?** and **is it income / expense / neither?**

| Bucket | Types in it | Effect |
|---|---|---|
| **Real expense** | `expense`, `recurring_debt` | ➖ counts against your P&L and "needs vs wants" |
| **Real income** | `income` | ➕ the only thing counted as earnings |
| **Financing — out** | `debt_settlement`, `loan_installment`, `check_settlement`, `asset_buy` | 💸 cash leaves, but it's **repayment/investment**, *not* an expense |
| **Financing — in** | debt/loan `borrow` (auto `debt_incur`), `asset_sell`, `receivable_settlement` | 💰 cash arrives, but it's **borrowing/divestment**, *not* income |
| **Transfer** | `account_transfer`, `person_transfer` | ↔️ money changes pockets; never income or expense |

**The classic double‑count, eliminated.** Borrow 10M → your balance rises but it's **never** income. Spend it on a fridge → a normal **expense**. Repay the loan → **financing‑out**, *not* a second expense. Naïve apps count that money as spent **twice**; HesabYar counts it **once**. The **«جابه‌جایی پول و بازپرداخت‌ها»** report shows these financing movements separately.

**Balances vs analytics use different sets.** Account balances and the balance trend use `cash_in/out_types` (**all** movement — every rial accounted for); the P&L and expense reports use the **true‑expense/true‑income** sets above. Your balance is always correct *and* "what I actually spent" is honest.

**Net worth** = liquid accounts **＋** asset market value (`quantity × live_rate`) **－** open debts, loan balances & cheques; the gap from cost is **unrealized P&L** until a sale makes it **realized**.

**Archive = absolute‑zero period close.** Settings → **بایگانی** snapshots the groups you tick into `wp_hpa_archives`, then **resets their figures to zero** for a new period. Settled obligations (and their linked transactions) are archived and removed; **open obligations are preserved**, so balances stay coherent. Each archive exports to **PDF**.

## 🔌 The sync hub — connect the desktop & Android apps

Version **3.13.0+** exposes a REST API (`/wp-json/hpa/v1`) so the companion apps sync **both ways** through your site:

1. **WordPress → Settings → Personal Accountant**, tick **«اتصال نرم‌افزار دسکتاپ (حساب‌یار)»** and save. The page shows your site's API base URL.
2. In the **desktop** or **Android** app: **Settings → «اتصال به سایت»**, enter the site URL and your WordPress username/password, then connect.
3. The apps then **auto‑sync in the background** — push on every change, pull on launch.

**Endpoints:** `POST /login`, `GET /pull`, `POST /push`, `POST /sync`, `GET /ping`. Requests carry a per‑app bearer token and are restricted to the authorized user's email; `app_cors_headers()` handles CORS for the desktop origin.

## 📥 Install

1. Download the latest `hesabyar-personal-accounting-x.y.z.zip` from [**Releases**](../../releases).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Install** → **Activate**.
3. Create a page containing `[hamid_personal_accounting]` and open it while logged in as the authorized user.

Or clone into `wp-content/plugins/`:

```bash
git clone https://github.com/hrschemiker/hesabyar-wordpress-plugin.git
```

## 📦 Changelog (recent)

- **3.16.0** — **Archive / close‑a‑period** with PDF export.
- **3.15.0** — Financing‑vs‑expense model (no double‑counted repayments); borrowing isn't income; per‑item prices & the **«خرج به تفکیک قلم»** report; multi‑tag entry.
- **3.13.0** — REST sync API (`hpa/v1`) + the desktop/Android connection.

## Related projects

- 🖥️ [**hesabyar-desktop**](https://github.com/hrschemiker/hesabyar-desktop) — the Windows desktop app.
- 📱 [**hesabyar-android**](https://github.com/hrschemiker/hesabyar-android) — the native Android app.

## License

[MIT](LICENSE) © hrschemiker
