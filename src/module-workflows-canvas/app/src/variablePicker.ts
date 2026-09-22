/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import type { Graph, MountConfig } from './types';

/**
 * Variable picker for `{{ … }}` config fields (Phase B). Offers the paths the
 * engine's VariableResolver can resolve at run time:
 *   - Trigger context: the workflow entity (`trigger.entity`, `entity`) —
 *     labeled with the workflow's entity type from trigger metadata;
 *   - Upstream step outputs: `steps.<key>.result` for every step that can run
 *     before the current one (reverse-reachable ancestors), so the picker never
 *     offers a value that does not yet exist at that point;
 *   - Secret NAMES only: `secret.<name>` — the values are write-only and never
 *     leave the server (the bootstrap carries names, never values).
 *
 * Pure and side-effect free. It suggests paths; the server is authoritative on
 * what actually resolves.
 */

export interface VariablePath {
  path: string;
  label: string;
  group: 'Trigger' | 'Steps' | 'Secrets';
}

export function buildVariablePaths(
  config: MountConfig,
  graph: Graph | null,
  currentStepKey: string | null,
): VariablePath[] {
  const paths: VariablePath[] = [];

  const entityType = config.workflow?.entityType ?? 'entity';
  paths.push({ path: 'entity', label: `Triggering ${entityType}`, group: 'Trigger' });
  paths.push({ path: 'trigger.entity', label: `Trigger payload (${entityType})`, group: 'Trigger' });

  if (graph && currentStepKey) {
    for (const key of upstreamStepKeys(graph, currentStepKey)) {
      paths.push({ path: `steps.${key}.result`, label: `Output of "${key}"`, group: 'Steps' });
    }
  }

  for (const name of config.secrets) {
    paths.push({ path: `secret.${name}`, label: `Secret: ${name}`, group: 'Secrets' });
  }

  return paths;
}

/**
 * Reverse-reachable ancestors of a step: every step from which the current one
 * can be reached by following edges. Cycle-safe (visited set). Deterministic
 * order (graph node order) so the picker list is stable.
 */
export function upstreamStepKeys(graph: Graph, target: string): string[] {
  const incoming = new Map<string, string[]>();
  for (const e of graph.edges) {
    const list = incoming.get(e.target) ?? [];
    list.push(e.source);
    incoming.set(e.target, list);
  }

  const ancestors = new Set<string>();
  const stack = [...(incoming.get(target) ?? [])];
  while (stack.length > 0) {
    const key = stack.pop() as string;
    if (ancestors.has(key) || key === target) {
      continue;
    }
    ancestors.add(key);
    for (const parent of incoming.get(key) ?? []) {
      stack.push(parent);
    }
  }

  // Return in graph node order for a stable list.
  return graph.nodes.map((n) => n.id).filter((id) => ancestors.has(id));
}
