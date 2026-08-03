import { t } from './i18n';
import type { MountConfig, ValidationMessage } from './types';

/**
 * The continuous validation loop (Phase B). Debounced POSTs of the current
 * definition to the same-origin admin validate proxy (Data/Validate, ::manage,
 * form key), which delegates to the shared DefinitionValidationInterface — the
 * server is the sole validation authority; nothing is re-implemented here.
 *
 * Findings come back with step_key/edge; pinMessages buckets them per node so
 * the editor can badge each node. All message text is server-provided and
 * rendered as React text nodes (never innerHTML).
 */

export interface ValidateResponse {
  success: boolean;
  valid?: boolean;
  plain_language?: string;
  messages?: ValidationMessage[];
  error?: string;
}

export interface ValidateRequest {
  definition: string;
  conditionsSerialized?: string | null;
  triggerType?: string;
  triggerRef?: string;
  entityType?: string;
}

/**
 * Build the live-validation request for a definition snapshot. The workflow's
 * ROOT condition tree rides along from the bootstrap (Mount::getConfigJson
 * ships it as workflow.conditionsSerialized; the canvas never edits it and
 * saveClient round-trips it verbatim on save), so root-condition findings
 * surface during canvas editing exactly as DefinitionValidationInterface
 * reports them at save time. Null/absent stays null — Data/Validate normalizes
 * ''/absent to null before delegating.
 */
export function buildValidateRequest(config: MountConfig, definition: string): ValidateRequest {
  return {
    definition,
    conditionsSerialized: config.workflow?.conditionsSerialized ?? null,
  };
}

/** POST the definition to the validate proxy. Form-encoded, same-origin, form key. */
export async function postValidate(
  config: MountConfig,
  req: ValidateRequest,
  fetchImpl: typeof fetch = fetch,
): Promise<ValidateResponse> {
  const form = new URLSearchParams();
  form.set('form_key', config.formKey);
  form.set('definition', req.definition);
  if (req.conditionsSerialized) {
    form.set('conditions_serialized', req.conditionsSerialized);
  }
  form.set('trigger_type', req.triggerType ?? config.workflow?.triggerType ?? '');
  form.set('trigger_ref', req.triggerRef ?? config.workflow?.triggerRef ?? '');
  form.set('entity_type', req.entityType ?? config.workflow?.entityType ?? '');

  const res = await fetchImpl(config.endpoints.validate, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
    body: form.toString(),
  });
  if (!res.ok) {
    return { success: false, error: `${t('Validation request failed.')} (${res.status})` };
  }
  return (await res.json()) as ValidateResponse;
}

export interface PinnedMessages {
  /** step key -> its messages (most severe first). */
  byNode: Record<string, ValidationMessage[]>;
  /** document-level (step_key === null) messages. */
  document: ValidationMessage[];
  hasErrors: boolean;
}

/** Bucket validation messages by their target node. */
export function pinMessages(messages: ValidationMessage[]): PinnedMessages {
  const byNode: Record<string, ValidationMessage[]> = {};
  const document: ValidationMessage[] = [];
  let hasErrors = false;

  for (const m of messages) {
    if (m.severity === 'error') {
      hasErrors = true;
    }
    if (m.step_key) {
      (byNode[m.step_key] ??= []).push(m);
    } else {
      document.push(m);
    }
  }
  // Errors before warnings within a node.
  for (const key of Object.keys(byNode)) {
    byNode[key].sort((a, b) => severityRank(a.severity) - severityRank(b.severity));
  }
  return { byNode, document, hasErrors };
}

function severityRank(severity: string): number {
  return severity === 'error' ? 0 : 1;
}

/**
 * A tiny trailing-edge debouncer for the validate loop. Returns a function that
 * schedules `fn`; rapid calls collapse to one run `delayMs` after the last.
 */
export function debounce<A extends unknown[]>(
  fn: (...args: A) => void,
  delayMs: number,
): (...args: A) => void {
  let timer: ReturnType<typeof setTimeout> | null = null;
  return (...args: A): void => {
    if (timer !== null) {
      clearTimeout(timer);
    }
    timer = setTimeout(() => {
      timer = null;
      fn(...args);
    }, delayMs);
  };
}
