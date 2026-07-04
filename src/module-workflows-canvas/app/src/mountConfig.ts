import type { MountConfig } from './types';

/**
 * Reads the bootstrap config from the mount element's data-config attribute —
 * the ONLY channel from PHP to JS (CSP: no inline script). Pure and DOM-light
 * so it is unit-testable in jsdom.
 */
export function readMountConfig(el: Element | null): MountConfig | null {
  if (!el) {
    return null;
  }
  const raw = el.getAttribute('data-config');
  if (!raw) {
    return null;
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  if (!parsed || typeof parsed !== 'object') {
    return null;
  }

  const c = parsed as Partial<MountConfig>;
  return {
    workflowId: c.workflowId ?? null,
    executionId: c.executionId ?? null,
    knownSchemaVersion: Number(c.knownSchemaVersion ?? 0),
    grants: {
      manage: Boolean(c.grants?.manage),
      dryRun: Boolean(c.grants?.dryRun),
    },
    endpoints: {
      executionSteps: String(c.endpoints?.executionSteps ?? ''),
      dryRun: String(c.endpoints?.dryRun ?? ''),
    },
    formKey: String(c.formKey ?? ''),
    workflow: c.workflow ?? null,
    actions: c.actions ?? {},
  };
}

export const MOUNT_SELECTOR = '[data-role="mageos-workflows-canvas"]';
