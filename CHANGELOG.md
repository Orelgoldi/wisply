# Changelog — Wisply (AI Chat Assistant)

All notable changes to this plugin are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/); versioning is [SemVer](https://semver.org/).

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
