# Changelog — Medical360 AI Chatbot

All notable changes to this plugin are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versioning is [SemVer](https://semver.org/).

## [4.6.4] — 2026-07-15
### Fixed
- **False "job-seeker" classification.** The marketing-vs-job classifier scanned the whole
  transcript including the bot's replies, which sometimes quote site content mentioning
  קריירה/דרושים — so a genuine patient inquiry (e.g. שיקום גריאטרי) could be misrouted to the
  recruitment department. Now it classifies from the **visitor's own messages only**. Verified
  end-to-end against the live site (all departments route correctly).

## [4.6.3] — 2026-07-15
### Added
- **Routing self-check** — the `/lead` endpoint returns the resolved branch/department +
  Logicare status in its response when a `_routecheck` param is present (used only by the
  QA test harness; the normal widget never sends it). Lets an automated test confirm each
  department routes correctly end-to-end without logging into Logicare.

## [4.6.2] — 2026-07-15
### Fixed
- **Leads CSV export came out as gibberish.** The export ran inside the admin page
  render (after WordPress already printed HTML), so the download headers failed and the
  CSV was mixed into the page. Moved it to an early `admin_init` handler with a clean
  UTF-8 BOM so Excel reads Hebrew correctly.
### Changed
- **Export columns now mirror the on-screen leads table** (תאריך, שם, סוג, טלפון, אימייל,
  התעניינות, סיכום, מחלקה, שיחה מלאה, מקור, קמפיין, Opt-In) instead of the old 20-column
  technical dump. Newlines inside a cell are collapsed so each lead stays on one row.

## [4.6.1] — 2026-07-15
### Changed
- **Routing now uses real Logicare department IDs** (from the CRM export "סניפים ומחלקות")
  instead of free-text names. Each `department_id` also fixes the branch (סניף), so routing
  is unambiguous. Embedded department catalog (id → name + branch) drives the lead push,
  which now sends `department_id` (primary) + name + `home_id`/branch under several key
  variants. Rules format is now `keyword | department_id | (name for reference)`; settings
  are `logicare_default_department_id` (216) + `logicare_job_department_id` (263 — דרושים).
  The settings screen shows a collapsible reference table of the department IDs.

## [4.6.0] — 2026-07-14
### Added
- **Logicare branch + department routing** — each lead is now routed to the correct
  Logicare סניף (branch) + מחלקה (department) based on the page it came from, via an
  admin-editable table (`מילת מפתח בדף | סניף | מחלקה`, first match wins). Job-seekers
  route to a dedicated recruitment (דרושים) branch/department regardless of page.
  New settings under "🧭 ניתוב לידים לסניף ומחלקה": default branch, job branch/department,
  and the rules table (pre-filled with the Paz Medical Care hospital/workshops/clinics map).
  The lead push sends `branch_name`/`branch` + `department_name`/`department` (Logicare is a
  Zapier-style endpoint that routes by name; the API exposes no branch/department ID listing).

## [4.5.0] — 2026-07-06
### Added
- **Desktop auto-open (call-to-action)** — optional setting that automatically opens
  the full chat window on desktop after a configurable delay, with an editable opening
  message per language (HE/EN/RU). Desktop-only (not mobile), fires once per session,
  and respects the visitor (won't reopen if they've closed it or started typing).
  Settings: enable checkbox + delay (seconds) + 3 message fields, under the
  "בועית יזומה" tab.

## [4.4.1] — 2026-07-06
### Added
- **Smart job-seeker flow** — when a visitor names a *specific* role/position they're
  after (any language — HE/EN/RU), the bot now does both in one reply: links to the
  careers page (`[ACTION:jobs]`) **and** offers to leave details so recruiting can call
  back (`[ASK_LEAD]` → lead form). A generic "are there openings?" still links to the
  page and asks which role interests them. The captured lead is auto-tagged `job`.

## [4.4.0] — 2026-07-05
### Added
- **Lead classification (marketing vs. job-seeker)** — every inquiry is auto-tagged
  `marketing` / `job` by scanning the conversation for career keywords (HE/EN/RU).
  Leads screen gets filter tabs (הכל / 🎯 שיווקי / 💼 דרושים) with live counts, a
  per-lead type badge, and a type-aware CSV export (adds a "סוג" column).
- **Automated leads reports** — daily (every morning) + weekly (Monday) email digests
  to configurable recipients. Each report splits marketing vs. job-seeker leads into
  HTML tables and attaches a period CSV. New settings: recipient emails + daily/weekly
  toggles (WP-Cron `m360_daily_leads_report` / `m360_weekly_leads_report`).
- **Tabbed settings screen** — the long settings page is now organised into tabs
  (🤖 AI · 🎙️ קול · 🔔 בועית יזומה · 📥 לידים ו-CRM · 🎨 עיצוב ותוכן · ⚙️ מתקדם),
  a single form so everything still saves together; active tab persists via localStorage.

### Fixed
- **Settings display bug** — dropdowns/values now read live from the DB store, so all
  saved settings render correctly instead of reverting to the plugin's generic defaults.
- Settings save hardening — `wp_unslash` on all inputs, textarea fields keep their
  newlines, checkboxes normalise to 0/1.

## [4.3.0] — 2026-07-01
### Added
- **Logicare CRM integration** — every captured lead is pushed to Logicare
  (`POST /logicare/api/new_lead/`) alongside the email + dashboard. Settings: enable
  toggle, Base URL, encrypted API Key; System Check validates the key via
  `/logicare/api/auth/`; per-lead CRM sync status (✓/✗) in the leads table + CSV.
- Voice options (TTS/STT + realtime) and proactive page teaser groundwork.
