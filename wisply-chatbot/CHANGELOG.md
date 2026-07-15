# Changelog — Wisply (AI Chat Assistant)

All notable changes to this plugin are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versioning is [SemVer](https://semver.org/).

## [2.5.0] — 2026-07-15
### Added
- **🛒 E-commerce module (WooCommerce).** The bot now answers about the real catalogue
  using **live** data — no REST keys needed, it reads WooCommerce's PHP API in-process,
  so prices and stock are always current.
  - **Products, live prices, stock and variations.** Matching products are injected into
    the AI context; the model is told to quote *only* that data. For variable products it
    asks which option (size/colour) and answers with that variant's price/stock; if a
    variant is out of stock it says so and offers an alternative.
  - **Rich product cards in chat** — image, price (with sale strikethrough preserved),
    stock badge and a link to the product, via a new `POST /products` route and a
    `[PRODUCTS: id,id]` marker.
  - **Similar products** — related items for the top hit are pulled in so the bot can
    offer alternatives (especially when something is out of stock).
  - **Shipping** — live WooCommerce shipping zones/rates, injected only when the visitor
    actually asks about delivery (keeps prompts cheap).
  - **Visual search (optional)** — the visitor uploads a photo, a vision model turns it
    into search terms, and the bot returns matching products (`POST /product-image-search`).
  - Settings under 📥 לידים: enable, max products (1–8), show stock, visual search.
    Degrades safely: no WooCommerce installed → module simply stays off.
  Note: this module is Wisply-only — it is intentionally not mirrored to Medical360.

## [2.4.2] — 2026-07-15
### Fixed
- **False "job-seeker" classification** — the marketing-vs-job classifier scanned the bot's
  replies too (which may quote site content), causing false job matches. Now it classifies
  from the visitor's own messages only.

## [2.4.1] — 2026-07-15
### Fixed
- **Leads CSV export came out as gibberish** — moved the export to an early `admin_init`
  handler (before any HTML output) with a clean UTF-8 BOM so Excel reads Hebrew correctly.
### Changed
- **Export columns now mirror the on-screen leads table** (תאריך, שם, סוג, טלפון, אימייל,
  התעניינות, סיכום, מחלקה, שיחה מלאה, מקור, קמפיין, Opt-In). Newlines inside a cell are
  collapsed so each lead stays on one row.

## [2.4.0] — 2026-07-14
### Removed
- **Logicare CRM integration removed entirely.** Logicare is a client-specific Israeli
  CRM (Paz Medical Care) and is not relevant to a resellable white-label product. Removed
  the enable toggle, Base URL, API key, `send_to_logicare`, System-Check CRM row, the CRM
  status column/badge in the leads table + CSV + reports, and all `logicare_*` settings.
  A future generic CRM should be a provider-agnostic webhook, not Logicare.
  (The "לידים ו-CRM" settings tab is now simply "לידים".)

## [2.3.0] — 2026-07-06
### Added
- **Desktop auto-open (call-to-action)** — optional setting that automatically opens
  the full chat window on desktop after a configurable delay, with an editable opening
  message per language (HE/EN/RU). Desktop-only (not mobile), fires once per session,
  and respects the visitor (won't reopen if they've closed it or started typing).
  Settings: enable checkbox + delay + 3 message fields, under the proactive tab.

## [2.2.1] — 2026-07-06
### Added
- **Smart job-seeker flow** — when a visitor names a *specific* role/position they're
  after (any language — HE/EN/RU), the bot does both in one reply: links to the careers
  page (`[ACTION:jobs]`) **and** offers to leave details for a call-back (`[ASK_LEAD]` →
  lead form). Active when a `jobs` action button is configured. Lead is auto-tagged `job`.

## [2.2.0] — 2026-07-05
### Added
- **Lead classification (marketing vs. job-seeker)** — every inquiry is auto-tagged
  `marketing` / `job` by scanning the conversation for career keywords (HE/EN/RU).
  Leads screen gets filter tabs (הכל / 🎯 שיווקי / 💼 דרושים) with live counts, a
  per-lead type badge, and a type-aware CSV export (adds a "סוג" column).
- **Automated leads reports** — daily (every morning) + weekly (Monday) email digests
  to configurable recipients. Each report splits marketing vs. job-seeker leads into
  HTML tables and attaches a period CSV. New settings: recipient emails + daily/weekly
  toggles (WP-Cron `wisply_daily_leads_report` / `wisply_weekly_leads_report`).
- **Tabbed settings screen** — the long settings page is now organised into tabs
  (🏷️ מיתוג · 🤖 AI · 🎙️ קול · 🔔 בועית יזומה · 📥 לידים ו-CRM · 🎨 עיצוב ותוכן · ⚙️ מתקדם),
  a single form so everything still saves together; active tab persists via localStorage.

### Fixed
- Settings save/display hardening — `wp_unslash` on all inputs, textarea fields keep
  their newlines, checkboxes normalise to 0/1, and dropdowns/values read live from the
  DB store so saved settings always render (no reverting to generic defaults).

## [2.1.0] — 2026-07-01
### Added
- **Logicare CRM integration** — every captured lead is pushed to Logicare
  (`POST /logicare/api/new_lead/`) in addition to the email + dashboard. Maps
  name/phone/email + AI summary & transcript (`details`), interest (`referrer_notes`),
  source (`referrer`), `campaign`, `landing`, `department_name`.
- Settings: enable toggle, **Base URL**, and encrypted **API Key** (company UUID).
- **System Check** validates the CRM key via `/logicare/api/auth/` and shows the company name.
- Per-lead CRM sync status (✓/✗) shown in the leads table + CSV export.

## [2.0.0] — 2026-06-29
Brought to full feature-parity with the internal Medical360 build (v4.2.0), kept fully white-label/generic.

### Added
- **Real-time voice (OpenAI Realtime API, GA endpoints)** — `/v1/realtime/client_secrets` + `/v1/realtime/calls`, model `gpt-realtime`; content-grounded via a `lookup_site_info` tool. Falls back to the chained Whisper→chat→TTS pipeline if unavailable.
- **Voice text modes** — none / save-transcript-on-hangup / live transcript.
- **Proactive page teaser** — contextual bubble that pops when the visitor scrolls to mid-page; message + 4 AI-generated, page-specific suggested questions.
- **Context Engine** — captures UTM source/medium/campaign, referrer, landing page and page/department, attached to every lead.
- **Marketing consent (Opt-In)** — required checkbox with editable text/version; stores consent + timestamp + version per lead. Default text is a generic placeholder to adapt per business/law.
- **Extended lead model + AI conversation summary** — source, channel, department, campaign, landing page, conversation length, status, and a one-line AI summary; shown in admin + email + CSV.
- **Guided lead flow** — `[OPTIONS: a | b | c]` choice buttons → `[ASK_LEAD]` yes/no → form, instead of popping a form immediately.
- **Emergency escalation** — the bot stops and shows a configurable emergency message + action buttons (`[EMERGENCY]`). Ships with Israeli defaults (101, ER"N, SAHAR), all editable.
- **Analytics dashboard** — conversations, leads, conversion rate, Opt-In rate, abandoned conversations, top questions, top departments.
- **System Check** tool + per-voice **preview** button in settings.
- **"Start a new chat"** CTA on the inactivity timeout (managed conversation end).

### Fixed
- Mobile audio playback (iOS): unlock + DOM-attached `<audio>` element.
- Language switching no longer rebuilds the whole widget (relabel-in-place; no more freezes; preserves history).
- Admin pages send `no-store` headers (settings no longer appear to "revert" from cache).
- Timestamps use the WordPress timezone (`current_time`) for day grouping.
- Settings save uses explicit UPDATE/INSERT (robust to legacy table schemas).

### White-label
- Generic persona throughout (no medical wording) via `persona()` / `available_actions()`.
- Configurable `product_name`, `bot_name`, `business_name/type/description`, `suggested_questions_he/en/ru`, and a custom **CTA buttons** repeater.
- `bot_name`/`business_name` auto-fill from the WordPress site title on activation.
- `WISPLY_PRODUCT_NAME` constant drives the admin menu + "Powered by" credit.

## [1.0.2] — 2026-06-15
Initial white-label build derived from the Medical360 chatbot: renamed namespace/classes/tables (`Wisply_*`, `wisply_*`, `wisply/v1`), settings-driven persona, configurable product name, text + voice (Whisper/TTS) chat grounded only in the site's own content. Hebrew / English / Russian, RTL, WCAG-aware.
