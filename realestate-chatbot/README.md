# Wisply — AI Chat Assistant (WordPress, white-label)

A white-label AI chat assistant (text **and** voice) for any WordPress site. It answers
**only** from your own site content (no hallucinations), in **Hebrew / English / Russian**,
with full RTL support — and captures qualified, consent-backed leads.

> Working code name: **Wisply**. The public product name is configurable
> (`WISPLY_PRODUCT_NAME` + `product_name` setting) and shown in the admin menu and the
> "Powered by" credit — rebrand with no code edits.

## Highlights
- **Content-grounded answers** — indexes your pages/posts/menus; replies only from real content.
- **Voice** — real-time speech-to-speech (OpenAI Realtime) with a Whisper + TTS fallback.
- **Proactive engagement** — page-aware teaser bubble + AI-generated, page-specific questions.
- **Lead capture** — guided flow (choice buttons → yes/no → form), Opt-In consent, AI summary,
  full context (UTM/referrer/department/landing), email + CSV + dashboard analytics.
- **Fully settings-driven persona** — bot name, business name/type/description, suggested
  questions, custom CTA buttons, colours.
- **Emergency escalation** — configurable stop-and-refer message + hotline buttons.

## Install
1. Zip the `wisply-chatbot` folder (or download the release zip).
2. WordPress → Plugins → Add New → Upload Plugin → activate.
3. Settings → set the OpenAI API key, branding/persona, and (optionally) voice.

## Requirements
- WordPress 6.0+, PHP 8.1+
- An OpenAI API key (chat, voice, TTS). Realtime voice needs Realtime API access on the account.

## Versioning
See [CHANGELOG.md](CHANGELOG.md). Mirrors the internal Medical360 build feature-for-feature.

© Goldstein Studio — proprietary.
