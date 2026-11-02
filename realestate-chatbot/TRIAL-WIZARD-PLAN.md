# Wisply — Free Trial + Setup Wizard: Implementation Plan

Scope: add a self-serve **14-day / 100-conversation** free trial and a **smart setup
wizard** to `wisply-chatbot` only (this is a Wisply-product monetization feature — do
NOT mirror it to the internal `medical360-chatbot` fork). Conventions kept exactly:
`WISPLY_`/`wisply_` prefixes, `Wisply_*` classes, `wisply_*` tables, `wisply/v1` REST
namespace, `wisply-chatbot` text-domain, provider support for both OpenAI GPT-4o and Claude.

---

## 1. Data model (trial state)

Stored in the existing plugin settings table (`wisply_settings`) via
`Wisply_Database::get_setting()/set_setting()`, so it inherits the same persistence,
caching and (where relevant) encryption path as every other setting.

| Key | Meaning |
|-----|---------|
| `trial_status` | `'' \| active \| ended \| converted` — the state-machine node |
| `trial_started_at` | unix timestamp when the trial began |
| `trial_convo_count` | visitor-driven conversations consumed so far |
| `trial_model_openai` | cheap model while on trial (default `gpt-4o-mini`) |
| `trial_model_claude` | cheap model while on trial (default `claude-3-5-haiku-latest`) |

Setup lifecycle flag lives in WP options (like `wisply_db_version`):
`wisply_setup_complete` = `'0' | '1'`, plus a short-lived `wisply_activation_redirect`
transient that triggers the one-time first-run redirect.

All of this is owned by **`includes/class-trial.php`** (`Wisply_Trial`). The two counts
are cheap integer reads; no new DB table is introduced.

## 2. Where the caps are enforced

Single choke point: **`Wisply_Chatbot_API::handle_chat()`**, right after the session's
conversation row is resolved and **before** the AI call / before the user message is
stored. Mirrors the existing `max_messages` gate that already returns a
`conversation_ended` reply without calling the model.

- A "conversation" = the first USER message of a session's conversation row
  (`count_user_turns($conv_id) === 0`). This counts **visitor-driven** conversations,
  never bot replies, and is robust to retries.
- If `Wisply_Trial::is_trial_mode()` and **not** `is_active()` (14 days elapsed OR 100
  conversations consumed) → `mark_ended()` + return the localized upgrade message with
  `conversation_ended: true` (the widget already renders this and suppresses follow-ups).
- On a genuinely new conversation with credits left → `increment_conversation()`
  (count-then-serve); the conversation that hits the cap tips it to `ended`.

The gate is **inert** unless a trial was started (status `''`/`converted` skip it), so
existing and paid installs behave exactly as before.

## 3. Trial → paid state machine

```
none('')  --wizard finish--> active  --time/cap reached--> ended
                               |                              |
                               +--------- billing charge -----+--> converted
```

- `start_trial()` — idempotent; sets `active`, stamps start, zeroes the counter.
- `mark_ended()` — active → ended (graceful pause; all data retained).
- `mark_converted()` — any → converted (clears the gate); **called by the future
  billing layer once a card is charged** (not wired yet — see §6).

## 4. Cheaper-model switch during trial

`Wisply_Trial::effective_model($provider, $configured)` returns the cheap trial model
while `is_active()`, else the owner's configured premium model unchanged. Called inside
both `Wisply_AI_Handler::call_claude()` and `call_openai()` at the point the model is
read from settings — so the switch covers both providers and only affects trial installs.
Premium models stay reserved for paid.

## 5. Wizard flow + files

Triggered on activation for fresh installs (redirect via the transient; existing
installs are auto-marked `wisply_setup_complete=1` in `Wisply_Database::activate()` so
they are never nagged). Owned by **`includes/class-setup-wizard.php`**
(`Wisply_Setup_Wizard`) + view **`admin/views/setup-wizard.php`**. Single-page, 4 steps,
each posting to admin-ajax with the shared `wisply_admin_nonce`:

1. **Site scan** → `wisply_setup_scan`: `autofill_branding_from_site()` +
   `Wisply_Content_Indexer::full_reindex()` (published pages/posts/menus/footer/contact)
   + optional `index_sitemap()` top-up. Builds the knowledge base.
2. **Persona** → `wisply_setup_save`: business name, bot name, business type, tone,
   what-to-collect, lead-field modes (חובה/רשות/מוסתר). Tone + collect fold into
   `business_description` (already injected into the AI prompt).
3. **FAQ + CTA** → `wisply_setup_save`: editable `suggested_questions_he` and a primary
   CTA stored as one `action_buttons` entry (already consumed by the prompt).
4. **Finish** → `wisply_setup_finish`: `wisply_setup_complete=1` + `start_trial()` →
   bot live.

## 6. ⚠️ ONE decision NOT to make alone — the payment provider

Credit-card-at-signup is a **LOCKED product decision**, but *which billing provider*
processes the card is a business/legal choice for the owner. **No billing provider has
been integrated.** `mark_converted()` is the single hook the chosen provider's
webhook/callback will call. Tradeoffs:

| Provider | For | Against |
|----------|-----|---------|
| **Stripe** | Best API/docs, cards + SCA, easy metered/subscription, trials native | Not a merchant-of-record → you handle VAT/invoicing; Israeli card coverage okay but local acquiring is better elsewhere |
| **Paddle** | Merchant-of-record (handles global VAT + invoices), subscriptions, good for SaaS | Higher fees, stricter approval, less control over checkout |
| **Lemon Squeezy** | MoR like Paddle, fastest to launch, dev-friendly | Now Stripe-owned (roadmap uncertainty), fees, less flexible |
| **Israeli provider** (Tranzila / Cardcom / Meshulam / Grow/PayPlus) | Local acquiring, ₪ pricing, Hebrew invoices, יצוא לחשבונית/מע״מ, local support | Weaker APIs/webhooks, less polished subscription tooling, more integration work |

**Recommendation to discuss:** if buyers are mostly Israeli businesses, a local provider
(e.g. Cardcom/PayPlus) simplifies מע״מ + חשבוניות and local cards; if the market is
international SaaS, **Paddle** (or Stripe + a separate invoicing solution) is cleaner.
**Decision required from the owner before any billing code is written.**

## 7. Files created / changed

- **New:** `includes/class-trial.php`, `includes/class-setup-wizard.php`,
  `admin/views/setup-wizard.php`, this plan.
- **Changed:** `wisply-chatbot.php` (require + boot), `includes/class-database.php`
  (activation gate + trial-model defaults), `includes/class-chatbot-api.php` (trial gate
  in `handle_chat`), `includes/class-ai-handler.php` (cheaper-model switch in both
  providers).

## 8. What remains

- Billing integration (provider TBD — §6), a webhook → `mark_converted()`, and an
  in-admin "upgrade" button/link.
- Wizard polish: AI-generated FAQ suggestions in step 3 (currently seeded defaults),
  richer branding autofill, per-language FAQ, validation/UX.
- Optional: a trial dashboard widget (days/conversations left) beyond the admin notice.
