# Goldstein Studio — WordPress AI Chatbot Plugins

This repository holds **two** WordPress AI chat-assistant plugins that share the same
engine and stay in feature-parity:

| Folder | Plugin | Purpose |
|--------|--------|---------|
| [`wisply-chatbot/`](wisply-chatbot/) | **Wisply — AI Chat Assistant** | The **white-label** product, sold/resold for any business. Fully generic, settings-driven persona, configurable product name & branding. |
| [`medical360-chatbot/`](medical360-chatbot/) | **Medical360 Chatbot** | The **internal/source** build, tailored for the Medical Care (medical360.org) rehab hospital. Source of truth for new features. |

## How they relate
Every new feature is built first in `medical360-chatbot/`, then mirrored into
`wisply-chatbot/` in a **generic** form (no medical wording — uses persona variables and
`product_name`). The two are kept in lockstep.

Each plugin is self-contained (its own `wisply-chatbot.php` / `medical360-chatbot.php`,
classes, REST namespace and DB tables) and is installed independently by zipping its folder.

## Versioning
Wisply releases are tracked in [`wisply-chatbot/CHANGELOG.md`](wisply-chatbot/CHANGELOG.md)
and tagged (`vX.Y.Z`).

© Goldstein Studio — proprietary.
