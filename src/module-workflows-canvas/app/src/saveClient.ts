import type { Definition, MountConfig } from './types';

/**
 * The save loop (docs/discovery/canvas.md §4, Phase B). The canvas NEVER has
 * its own save endpoint or repository write: it posts the mapped definition
 * through the EXISTING admin Save controller (mageos_workflows/workflow/save),
 * exactly as the classic form's textarea does. That guarantees the identical
 * gauntlet — Definition::fromJson -> toJson normalization, the
 * WorkflowRepositoryInterface::save before-plugin (ValidateWorkflowOnSave:
 * structural, graph, action codes, per-action ACL re-authorization), the
 * form-key check, and the ::manage ADMIN_RESOURCE gate — with zero client-side
 * save semantics.
 *
 * The graph editor only owns the `definition` field (and its `ui` layout
 * block). Every other workflow field (name, status, trigger, conditions,
 * fan-out, website scope, loop guard) is round-tripped UNCHANGED from the
 * bootstrap so a canvas save never clobbers general config the classic form
 * owns. Save-path equivalence (buildSavePayload with an identical definition
 * yields the same stored bytes as a textarea save) is pinned by tests both
 * sides of the seam.
 */

/** A single posted form field. An array of pairs so website_ids[] can repeat. */
export type SavePair = [string, string];

/**
 * The exact POST body the admin Save controller consumes, with `definition`
 * replaced by the JSON of the current graph. Pure — the DOM submission wrapper
 * (submitSave) is separate so this is unit-testable.
 */
export function buildSavePayload(config: MountConfig, definition: Definition): SavePair[] {
  const w = config.workflow;
  const pairs: SavePair[] = [];
  const push = (k: string, v: string): void => {
    pairs.push([k, v]);
  };

  push('form_key', config.formKey);
  if (w && w.id > 0) {
    push('workflow_id', String(w.id));
  }
  push('name', w?.name ?? '');
  push('status', String(w?.status ?? 0));
  push('entity_type', w?.entityType ?? '');
  push('trigger_type', w?.triggerType ?? '');
  push('trigger_ref', w?.triggerRef ?? '');
  push('loop_guard_depth', String(w?.loopGuardDepth ?? 1));
  push('fan_out_relation', w?.fanOutRelation ?? '');
  push('fan_out_cap', w?.fanOutCap ?? '');
  // The workflow's ROOT condition tree. The editor's "Workflow conditions"
  // toolbar entry opens the shared condition slide-out bound to this field and
  // writes the applied (server-normalized) tree back into workflow meta, so
  // whatever is in the bootstrap — edited or untouched — is what posts here.
  // '' is the classic form's own spelling of "no root conditions".
  push('conditions_serialized', w?.conditionsSerialized ?? '');

  // Website scope is a multiselect: one repeated key per id, matching the
  // classic form. An empty scope posts nothing (the controller reads []).
  for (const id of w?.websiteIds ?? []) {
    push('website_ids[]', String(id));
  }

  // The one field the canvas owns. Stable key ordering + no pretty-printing:
  // the server re-normalizes via Definition::fromJson()->toJson() regardless,
  // so byte-equality of the stored value depends only on the parsed content.
  push('definition', JSON.stringify(definition));

  // Return to the CANVAS editor after the save round-trips through the Save
  // controller — for a brand-new workflow this is what lands the browser on
  // canvas/edit?workflow_id=<new id>, the id that only exists once the save
  // has run (canvas-first authoring).
  push('back', 'canvas');

  return pairs;
}

/**
 * Submit the save through the existing admin Save controller via a real form
 * POST (no fetch, no bespoke endpoint) so the browser follows the controller's
 * redirect and the admin session + form key travel exactly as the classic
 * form. CSP-safe: builds DOM nodes, never inline script or eval.
 */
export function submitSave(
  config: MountConfig,
  definition: Definition,
  doc: Document = document,
): void {
  const form = doc.createElement('form');
  form.method = 'post';
  form.action = config.endpoints.save;
  form.style.display = 'none';

  for (const [name, value] of buildSavePayload(config, definition)) {
    const input = doc.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }

  doc.body.appendChild(form);
  form.submit();
}
