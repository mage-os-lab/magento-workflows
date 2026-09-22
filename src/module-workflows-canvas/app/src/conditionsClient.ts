/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { t } from './i18n';
import type { MountConfig, ValidationMessage } from './types';
import type { NodeMeta } from './conditionTree';

/**
 * The two condition endpoints the builder talks to. Same-origin, session-authed
 * admin JSON — exactly the posture of the options proxy and the validate proxy
 * (docs/discovery/canvas.md §3): no third-party host, no eval, form key on the
 * write.
 *
 *  - GET  `conditionMeta`  (mageos_workflows/data/conditionMeta, ::view)
 *    Lazily describes ONE node type: its kind, its "Add condition" menu, its
 *    attributes and their operators/value elements. The server interrogates the
 *    real condition classes, so third-party condition packs and DI pools show
 *    up here with no client change. Cached per entity type + node type for the
 *    page session — a node type's metadata cannot change under us.
 *
 *  - POST `conditions`     (mageos_workflows/workflow/conditions, ::manage)
 *    The pre-existing shared apply/validate endpoint (Conditions.php): it
 *    shape-checks the tree through the same F2 pipeline a save runs and ECHOES
 *    the normalized tree, which is what the editor commits. Unreachable (session
 *    expired, offline dev, no route) degrades to committing the locally
 *    serialized tree with a warning — the save path re-validates regardless, so
 *    the round-trip is an authoring aid, never the only gate.
 */

export interface ConditionMetaResult {
  ok: boolean;
  /** The entity's root combine FQCN, as reported by the endpoint. */
  root: string | null;
  node: NodeMeta | null;
  /** Server/transport error text; rendered as a text node when present. */
  error: string | null;
}

interface ConditionMetaBody {
  success?: boolean;
  root?: string;
  node?: NodeMeta;
  error?: string;
}

const metaCache = new Map<string, ConditionMetaResult>();
const inFlight = new Map<string, Promise<ConditionMetaResult>>();

/** Cache identity: entity type + node type ('' = the entity root). */
export function metaCacheKey(entityType: string, nodeType: string | null): string {
  // \u0000 separator: cannot collide with real entity/node type strings.
  return `${entityType}\u0000${nodeType ?? ''}`;
}

/** Test seam / page-session reset. */
export function resetConditionMetaCache(): void {
  metaCache.clear();
  inFlight.clear();
}

/** Already-resolved metadata for a node type, without triggering a fetch. */
export function cachedNodeMeta(
  entityType: string,
  nodeType: string | null,
): ConditionMetaResult | null {
  return metaCache.get(metaCacheKey(entityType, nodeType)) ?? null;
}

/**
 * Fetch (and cache) metadata for one node type. Concurrent requests for the
 * same key share one in-flight promise, so a tree with ten leaves of the same
 * class issues one request.
 *
 * Caching policy: definitive answers are cached for the page session — success,
 * and 4xx rejections (an unknown class or unregistered entity type will not
 * change until a reload). TRANSIENT failures (network errors, 5xx, a non-JSON
 * body) are NOT cached: caching them made one blip permanently kill every
 * "Add condition" control until a full page reload, with no way to retry.
 */
export async function loadNodeMeta(
  config: MountConfig,
  entityType: string,
  nodeType: string | null,
  fetchImpl: typeof fetch = fetch,
): Promise<ConditionMetaResult> {
  const key = metaCacheKey(entityType, nodeType);
  const cached = metaCache.get(key);
  if (cached) {
    return cached;
  }
  const pending = inFlight.get(key);
  if (pending) {
    return pending;
  }

  const request = requestNodeMeta(config, entityType, nodeType, fetchImpl)
    .then(({ result, cacheable }) => {
      if (cacheable) {
        metaCache.set(key, result);
      }
      inFlight.delete(key);
      return result;
    })
    .catch(() => {
      const result: ConditionMetaResult = {
        ok: false,
        root: null,
        node: null,
        error: t('Condition metadata could not be loaded.'),
      };
      // Network failure: transient by definition — never cached.
      inFlight.delete(key);
      return result;
    });

  inFlight.set(key, request);
  return request;
}

async function requestNodeMeta(
  config: MountConfig,
  entityType: string,
  nodeType: string | null,
  fetchImpl: typeof fetch,
): Promise<{ result: ConditionMetaResult; cacheable: boolean }> {
  const params = new URLSearchParams();
  params.set('entity_type', entityType);
  if (nodeType !== null && nodeType !== '') {
    params.set('node_type', nodeType);
  }
  const url = `${config.endpoints.conditionMeta}?${params.toString()}`;
  const res = await fetchImpl(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  let body: ConditionMetaBody | null = null;
  try {
    body = (await res.json()) as ConditionMetaBody;
  } catch {
    body = null;
  }
  if (!body || body.success !== true || !body.node) {
    return {
      result: {
        ok: false,
        root: typeof body?.root === 'string' ? body.root : null,
        node: null,
        error:
          typeof body?.error === 'string' && body.error !== ''
            ? body.error
            : `${t('Condition metadata is unavailable.')} (${res.status})`,
      },
      // A 4xx verdict is definitive (unknown class, unregistered entity type);
      // everything else — 5xx, auth redirects, non-JSON — may heal on retry.
      cacheable: body !== null && res.status >= 400 && res.status < 500,
    };
  }
  return {
    result: {
      ok: true,
      root: typeof body.root === 'string' ? body.root : null,
      node: body.node,
      error: null,
    },
    cacheable: true,
  };
}

/** What the apply round-trip decided. */
export type ApplyOutcome = 'committed' | 'invalid' | 'offline';

export interface ConditionApplyResult {
  outcome: ApplyOutcome;
  /**
   * For 'committed': the server's normalized tree (what to store). For
   * 'offline': the locally serialized tree. For 'invalid': null — nothing is
   * committed.
   */
  conditionsSerialized: string | null;
  /** Server findings (errors AND warnings), rendered inline as text nodes. */
  messages: ValidationMessage[];
  /** Non-blocking notice, e.g. the offline fallback explanation. */
  warning: string | null;
  /** Blocking error text when the server refused the tree outright. */
  error: string | null;
}

interface ConditionsBody {
  success?: boolean;
  valid?: boolean;
  conditions_serialized?: string | null;
  messages?: ValidationMessage[];
  error?: string;
}

/**
 * The offline-fallback notice. A function (not a module constant) so the text
 * resolves through t() after the phrase map is installed at mount.
 */
export function offlineWarning(): string {
  return t('The condition validator could not be reached, so the tree was applied unvalidated. It is re-validated when the workflow is saved.');
}

/**
 * POST the serialized tree to the shared `conditions` endpoint and decide what
 * the editor should commit. Pure with respect to the DOM; `fetchImpl` is the
 * test seam (mirrors postValidate).
 */
export async function applyConditions(
  config: MountConfig,
  conditionsSerialized: string | null,
  fetchImpl: typeof fetch = fetch,
): Promise<ConditionApplyResult> {
  const form = new URLSearchParams();
  form.set('form_key', config.formKey);
  // '' is the endpoint's own "always run" spelling (normalize() -> null).
  form.set('conditions_serialized', conditionsSerialized ?? '');
  form.set('entity_type', config.workflow?.entityType ?? '');
  form.set('trigger_type', config.workflow?.triggerType ?? '');
  form.set('trigger_ref', config.workflow?.triggerRef ?? '');

  let body: ConditionsBody | null = null;
  try {
    const res = await fetchImpl(config.endpoints.conditions, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'application/json',
      },
      body: form.toString(),
    });
    // A 400 from this controller still carries a JSON verdict, so the body is
    // read regardless of res.ok; only an unparseable body is "unreachable".
    body = (await res.json()) as ConditionsBody;
  } catch {
    body = null;
  }

  if (!body || typeof body.success !== 'boolean') {
    return {
      outcome: 'offline',
      conditionsSerialized,
      messages: [],
      warning: offlineWarning(),
      error: null,
    };
  }

  const messages = Array.isArray(body.messages) ? body.messages : [];

  if (body.success !== true) {
    return {
      outcome: 'invalid',
      conditionsSerialized: null,
      messages,
      warning: null,
      error: typeof body.error === 'string' && body.error !== ''
        ? body.error
        : t('The conditions could not be validated.'),
    };
  }

  if (body.valid !== true) {
    return {
      outcome: 'invalid',
      conditionsSerialized: null,
      messages,
      warning: null,
      error: typeof body.error === 'string' && body.error !== '' ? body.error : null,
    };
  }

  return {
    outcome: 'committed',
    // The ECHOED normalized tree is authoritative; null means "always run".
    conditionsSerialized:
      typeof body.conditions_serialized === 'string' ? body.conditions_serialized : null,
    messages,
    warning: null,
    error: null,
  };
}
