# Agent skills

Claude Code skills that teach an agent this engine's authoring and review discipline. They
ship inside the `mage-os/workflows` package so the skills a store gets are **version-pinned
to the module it has installed** — these files assert exact endpoint paths, field names, ACL
resources and CLI flags, and a stale copy against a newer engine would mislead an agent
confidently.

- `skills/workflow-authoring` — generate → validate → dry-run → install-disabled, plus the
  hard rules (never enable, never touch secrets, never invent codes).
- `skills/workflow-review` — read-only explanation and audit of an existing workflow.

## Installing them into a project

Claude Code discovers skills at the **project** root, not inside `vendor/`, so one copy or
link step is always needed. From your Magento root:

```bash
mkdir -p .claude/skills
cp -R vendor/mage-os/workflows/.agents/skills/* .claude/skills/
```

Re-run that after `composer update` to stay in sync. To have them track the installed module
automatically instead, symlink:

```bash
mkdir -p .claude/skills
ln -sfn ../../vendor/mage-os/workflows/.agents/skills/workflow-authoring .claude/skills/workflow-authoring
ln -sfn ../../vendor/mage-os/workflows/.agents/skills/workflow-review .claude/skills/workflow-review
```

The skills are instructions, not enforcement — see
[docs/21 — LLM-Assisted Authoring](https://github.com/mage-os-lab/magento-workflows/blob/main/docs/21-ai-assisted-authoring.md).
The ACL is the enforcement.
