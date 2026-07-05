# 01 — Overview & Positioning

A merchant-facing, admin-native **trigger → condition → action** workflow engine, entirely on-prem, composed from existing Magento primitives. The target user is the merchant admin (not the ops engineer), the target deployment is any Magento Open Source / Mage-OS / Adobe Commerce ≥ 2.4.4 install, and the target authoring experience is the Magento admin itself.

## Locked decisions

These decisions were carried in from prior analysis and are treated as constraints, not open questions:

| Decision | Rationale |
|---|---|
| **Build native; do not embed n8n** | Licensing (Sustainable Use / embed license), payload-JSON impedance vs. EAV/scopes/B2B, wrong user (ops vs. merchant) |
| **`mageos-async-events` is the event bus** | Inherits queue transport, quadratic-backoff retry, UUID trace logging, ES/Lucene search, subscription model |
| **Conditions extend `Magento\Rule\Model`** | Free EAV introspection, merchant-familiar UI widget, battle-tested evaluation |
| **Actions are a DI-registered pool** | Standard Magento pattern (payment methods, totals collectors); third-party extensible by `di.xml` |
| **v1 UI is adminhtml forms, not a canvas** | ~20% of the cost of React Flow; AutomateWoo proves the model. Canvas is v2 (since implemented as the optional `workflows-canvas` module — pending live-install verification) |
| **External connectors via webhook action → iPaaS** | Don't compete with 400-connector ecosystems; own the data model instead |

## Non-goals for v1

- Storefront-facing anything
- Adobe I/O Events interop
- Loops/iterators over collections
- Approval-chain UI (B2B native approvals remain in Commerce core)

## Strategy: open spec, commercial layers

The engine is only "merchant-facing" if a non-developer can trust and understand it, and it only wins the ecosystem if third parties can build on it:

- **Open spec as strategy:** publish and semver the definition JSON Schema, the `workflow_triggers.xml` XSD, and a conformance fixture set. Third parties (canvas alternatives, CI linters, AI tools, competing UIs) building on the format grow the moat rather than eroding it — the format wins, and the reference engine is the default implementation.
- **Licensing:** core engine under OSL-3.0/MIT via Mage-OS maximizes install base. The template gallery *UI* ships open (only the bundled content pack, `workflows-templates`, is trimmable); the commercial layer is the [B2B pack](12-b2b.md), curated/premium template packs, and support.
- **Agencies are the actual buyers:** workflow-as-code (import/export CLI, data-patch installability, git-versionable definitions — see [Definition Format](04-definition-format.md)) matters more to agencies than the canvas does.
- **Positioning vs. Adobe App Builder:** different market — on-prem/OS merchants and agencies who won't take a SaaS dependency; the engine also runs on Adobe Commerce PaaS untouched.

## Reading order

For a first pass, read [Domain Model](03-domain-model.md) → [Triggers](05-triggers.md) → [Conditions](06-conditions.md) → [Actions](07-actions.md) → [Execution Model](08-execution-model.md). Security-minded reviewers should start with the [Security Model](10-security.md).
