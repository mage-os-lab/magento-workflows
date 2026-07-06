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
      return known ? known.label : code || 'Action';
    }
    case 'delay':
      return `Wait ${humanizeDuration(String(step.config?.duration ?? ''))}`;
    case 'branch':
      return 'Condition';
    case 'wait': {
      const event = String(step.config?.event ?? '');
      return event ? `Wait for "${event}"` : 'Wait for event';
    }
    case 'switch': {
      const count = (step.cases ?? []).length;
      return `Switch (${count} ${count === 1 ? 'case' : 'cases'})`;
    }
    case 'approval': {
      const timeout = String(step.config?.timeout ?? '');
      return timeout ? `Approval gate (up to ${humanizeDuration(timeout)})` : 'Approval gate';
    }
    case 'stop':
      return 'Stop';
    default:
      return 'Unknown step';
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
    return iso || 'a while';
  }
  const [, d, h, min, s] = m;
  const parts: string[] = [];
  const push = (n: string | undefined, unit: string) => {
    if (n) {
      const v = Number(n);
      parts.push(`${v} ${unit}${v === 1 ? '' : 's'}`);
    }
  };
  push(d, 'day');
  push(h, 'hour');
  push(min, 'minute');
  push(s, 'second');
  return parts.length ? parts.join(' ') : 'a moment';
}
