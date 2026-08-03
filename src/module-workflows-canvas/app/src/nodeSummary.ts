import { t } from './i18n';
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
    case 'branch':
      return t('Condition');
    case 'wait': {
      const event = String(step.config?.event ?? '');
      return event ? `${t('Wait for')} "${event}"` : t('Wait for event');
    }
    case 'switch': {
      const count = (step.cases ?? []).length;
      return `${t('Switch')} (${count} ${count === 1 ? t('case') : t('cases')})`;
    }
    case 'approval': {
      const timeout = String(step.config?.timeout ?? '');
      return timeout
        ? `${t('Approval gate')} (${t('up to')} ${humanizeDuration(timeout)})`
        : t('Approval gate');
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
