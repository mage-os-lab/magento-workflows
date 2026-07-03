# Workflow CLI Commands

CLI layer for the Mage-OS Workflow Engine (`MageOS\Workflows\Console\Command`). See
`docs/04-definition-format.md` (import/export, "Workflow-as-code") and
`docs/05-triggers.md` ("Manual triggers") for the underlying spec.

- **`bin/magento workflow:export <workflow_id> [--file=<path>]`**
  Exports a workflow as a JSON envelope
  (`{format: "mageos-workflow-export/1", name, entity_type, trigger_type, trigger_ref,
  conditions_serialized, definition, loop_guard_depth}`) to stdout or to `--file`.
  Exports never contain secret values — definitions only ever reference secrets by name
  (e.g. `{{ secrets.foo }}`).

- **`bin/magento workflow:import <file> [--activate]`**
  Imports a workflow from a `workflow:export` envelope. Validates the envelope's
  `format` tag, structurally validates the definition via `Definition::fromArray()`, and
  rejects any definition referencing unregistered action codes (listing all unknown
  codes). Creates the workflow disabled unless `--activate` is passed, and prints the new
  workflow ID. **Warning:** CLI import runs with system privileges and does not
  re-authorize imported actions against an admin's ACL the way the admin-UI import path
  does — review untrusted definitions before importing.

- **`bin/magento workflow:run <workflow_id> --entity-id=<id> [--payload='{"...":...}']`**
  Manually dispatches a workflow execution (`trigger_type=manual`) via
  `DispatcherInterface`. The trigger payload is `--payload` (default `{}`) merged with
  `entity_id`. Prints the execution UUID, or `suppressed/skipped` when the dispatcher
  suppresses the run (disabled workflow, scope mismatch, debounce, loop guard, etc.).

- **`bin/magento workflow:stats [--workflow-id=<id>]`**
  Prints execution counts grouped by workflow ID and status, the same breakdown limited
  to the last 24 hours, and a count of steps currently in the `waiting` status (delay
  steps pending resume). Queries `mageos_workflow_execution` /
  `mageos_workflow_execution_step` directly via `ResourceConnection`.
