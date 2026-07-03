# 14 — Risks & Open Questions

| Risk | Mitigation |
|---|---|
| EAV hydration at scale | Two-phase evaluation ([Conditions](06-conditions.md#two-phase-evaluation-the-eav-at-scale-answer)); static attribute classification at save time; workflow index cache; batch caps on scheduler |
| DB-queue installs (no RabbitMQ) lack delayed delivery | Cron sweeper path ([Execution Model](08-execution-model.md#resumption-after-delays)); document RabbitMQ as recommended, required for sub-minute delay precision — identical stance to async-events' retry backoff |
| Event loops from action side effects | Chain-depth guard + debounce ([Actions §Guards](07-actions.md#loop-prevention-storms-and-circuit-breaking)) |
| `Magento\Rule` widget UX debt | Accept for v1; canvas replaces the *layout*, rule widget remains the condition editor even in v2 (it's the only EAV-aware editor that exists) |
| Adobe pushes App Builder as the answer | Different market: on-prem/OS merchants and agencies who won't take a SaaS dependency; engine also runs on Adobe Commerce PaaS untouched |
| Order-status action vs. custom order-state extensions | Validate transitions via core guards; document that exotic state machines need custom actions |
| SSRF via webhook action | Hardened by default ([Security](10-security.md#ssrf-hardening-the-webhook-action)): private-range denial, DNS-pin, redirect re-validation, response caps |
| Deferred privilege escalation (workflows run as system) | Authoring ACL + attribute denylists + execution-time scope re-check + import re-authorization ([Security](10-security.md#deferred-privilege-escalation-the-core-threat-model)) |
| Event storm from imports/mass-actions | Suppression API + config-flagged bulk paths; aggregate triggers in Phase 2 ([Actions §Guards](07-actions.md#loop-prevention-storms-and-circuit-breaking)) |
| Runaway/misconfigured workflow | Circuit breaker auto-suspend + digest notification ([Actions §Guards](07-actions.md#loop-prevention-storms-and-circuit-breaking)) |
| Duplicate side effects on at-least-once redelivery | Step claim timestamps + per-step dedupe key (execution UUID + step key) checked by non-idempotent actions (email send logs the key before SMTP) |
| Entity deleted during a delay | Resume path treats missing-entity as `skipped` with explicit log status, never as error retry |
| Timezone ambiguity (delays, schedules) | Delays are absolute durations (UTC arithmetic); schedules evaluate in *store* timezone with the store recorded on the execution — document loudly, it's the #1 support-ticket generator in every scheduler ever shipped |
| PII sprawl into ES / retained contexts | Redaction-by-default indexing, TTL pruning, GDPR erasure hook ([Security §PII](10-security.md#pii-containment)) — **GA blockers, not fast-follows** |
| **Open:** multi-source inventory semantics for stock actions | v1 restricts to default source + salability check; MSI-aware config in Phase 2 |
