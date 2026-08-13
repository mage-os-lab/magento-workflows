import { t } from './i18n';
import { summarizeConditions } from './triggerNode';
import type { MountConfig, StepNode } from './types';

/**
 * Client-side, labeled-fallback node summaries. The server owns the canonical
 * plain-language rendering (PlainLanguageRenderer / validate endpoint); this is
 * the deliberately-thin LABEL used on nodes so the viewer draws without a
 * per-node round-trip. Action labels come from the bootstrapped meta/actions
 * map — never invented client-side — so an unregistered code is detectable.
 *
 * (Deviation noted in docs: Phase A renders summaries client-side as a labeled
 * fallback; a server per-step rendering is a Phase-B enhancement.)
 */
export function nodeSummary(
  step: StepNode,
  actions: MountConfig['actions'],
): string {
  switch (step.type) {
    case 'action': {
      const code = String(step.action ?? '');
      const known = actions[code];
      return known ? known.label : code || t('Action');
    }
    case 'delay':
      return `${t('Wait')} ${humanizeDuration(String(step.config?.duration ?? ''))}`;
    case 'branch': {
      // The actual condition on the face (n8n/Flow convention), not the word
      // "Condition": a branch is unreadable without knowing what it tests.
      const summary = summarizeConditions(step.conditions_serialized);
      return summary !== null ? `${t('If')} ${summary}` : `${t('If')} — ${t('always')}`;
    }
    case 'wait': {
      const event = String(step.config?.event ?? '');
      const timeout = String(step.config?.timeout ?? '');
      const head = event ? `${t('Wait for')} "${event}"` : t('Wait for event');
      return timeout ? `${head} · ${t('timeout')} ${humanizeDuration(timeout)}` : head;
    }
    case 'switch': {
      const cases = step.cases ?? [];
      if (cases.length === 0) {
        return `${t('Switch')} (0 ${t('cases')})`;
      }
      // The case keys ARE the summary: they double as this node's edge labels.
      const keys = cases.slice(0, 3).map((c) => String(c.key ?? '')).filter((k) => k !== '');
      const suffix = cases.length > 3 ? `, +${cases.length - 3}` : '';
      return `${t('Cases')}: ${keys.join(', ')}${suffix}`;
    }
    case 'approval': {
      const title = String(step.config?.title ?? '');
      const timeout = String(step.config?.timeout ?? '');
      const head = title !== '' ? `${t('Approval')}: ${truncate(title, 40)}` : t('Approval gate');
      return timeout ? `${head} (${t('up to')} ${humanizeDuration(timeout)})` : head;
    }
    case 'stop':
      return t('Stop');
    default:
      return t('Unknown step');
  }
}

/** Whether an action node references a code the server did not register. */
export function isDegraded(step: StepNode, actions: MountConfig['actions']): boolean {
  if (step.type !== 'action') {
    return false;
  }
  const code = String(step.action ?? '');
  return code === '' || !(code in actions);
}

function truncate(text: string, max: number): string {
  return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

/** Approximate ISO-8601 duration humanizer (mirrors the server's coarse form). */
export function humanizeDuration(iso: string): string {
  const m = /^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/.exec(iso);
  if (!m) {
    return iso || t('a while');
  }
  const [, d, h, min, s] = m;
  const parts: string[] = [];
  const push = (n: string | undefined, singular: string, plural: string) => {
    if (n) {
      const v = Number(n);
      parts.push(`${v} ${v === 1 ? singular : plural}`);
    }
  };
  // Singular/plural as whole phrases (not an appended "s") so each is one
  // translatable catalog row.
  push(d, t('day'), t('days'));
  push(h, t('hour'), t('hours'));
  push(min, t('minute'), t('minutes'));
  push(s, t('second'), t('seconds'));
  return parts.length ? parts.join(' ') : t('a moment');
}
