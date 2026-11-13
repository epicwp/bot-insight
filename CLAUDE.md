# Bot Insight (working name)

A small, efficient WordPress/WooCommerce plugin that shows site owners how much of their server's PHP
time goes to which bots. Later versions advise and apply fixes (robots.txt, Cloudflare, .htaccess,
nginx snippet, early PHP gate) and verify the effect, without hurting Google, Bing, Apple, ChatGPT,
Claude or Perplexity findability.

## Where things are

- Design (v1 = measure and dashboard only): `docs/superpowers/specs/2026-11-12-bot-insight-design.md`
  (decisions of 2026-11-13 at the end).
- Implementation plan: `docs/superpowers/plans/2026-11-13-bot-insight-v1.md`.
- Private notes (gitignored, local only): `.private/research/` (data model notes, field findings).
  Never commit or quote them in public files.
- Dashboard mockup: `docs/mockup/dashboard.dc.html` (source; sample data) and the claude.ai artifact
  https://claude.ai/artifact/GUQVLJaTNyM4dH2xBbvZC5 (read it with the Artifact tool).

## Next step

Robbert reviews the plan; then execute it task by task. Public repo:
https://github.com/epicwp/bot-insight (nothing pushed yet).

## Rules

- Code, comments, UI text, commit messages: English. Chat with Robbert: Dutch, short (max 200 words).
- GPL-2.0-or-later and WordPress.org guidelines from day one: no external calls without disclosure
  and opt-in, escape output, nonces and capabilities on every admin action.
- Distribution: first a direct download for ~300 existing customers, then WordPress.org.
- Privacy: no full IPs, no query values, no personal order data; data stays on the site.
- Performance budget: under 0.5 ms per request at p95; no database write per request.
- Never name the client shops from the field findings in code, docs or tests.

## Test data

Real access-log samples for classifier fixtures exist on shops Robbert manages. Ask Robbert for an
anonymised export; never commit real IPs.
