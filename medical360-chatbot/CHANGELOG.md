# Changelog — Medical360 AI Chatbot

All notable changes to this plugin are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versioning is [SemVer](https://semver.org/).

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
