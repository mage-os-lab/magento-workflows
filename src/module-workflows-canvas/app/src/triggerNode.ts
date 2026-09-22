/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import type { Edge, Node } from '@xyflow/react';
import { conditionAttributeLabel } from './conditionLabels';
import { t } from './i18n';
import type { Graph, TriggerMeta, WorkflowOptions } from './types';

/**
 * The trigger pseudo-node: every serious workflow canvas (n8n, Zapier,
 * Shopify Flow, Klaviyo) leads the graph with an explicit, visually distinct
 * trigger card — "what starts this?" is the first thing a reader needs. Our
 * definition format keeps the trigger OUTSIDE the step graph (it lives in the
 * workflow's own columns), so this node is purely presentational: it is
 * appended at render time, never enters the Graph model, the mapping layer,
 * or a save, and its position is derived from the entry step rather than
 * persisted.
 */

/** Ids reserved for the presentational trigger node/edge (never step keys). */
export const TRIGGER_NODE_ID = '__wf_trigger__';
export const TRIGGER_EDGE_ID = '__wf_trigger_edge__';

export interface TriggerFields {
  triggerType: string;
  triggerRef: string;
  entityType: string;
  conditionsSerialized: string | null;
}

export interface TriggerCard {
  /** "When: Order Created" / "Schedule: 0 3 * * *" / "Manual run". */
  title: string;
  /** The entity-type label the workflow runs against ('' when unset). */
  entity: string;
  /** Compact root-conditions summary, or null for "always runs". */
  conditions: string | null;
  /**
   * Every root condition spelled out (tooltip text): the face summary
   * truncates to "first +N more", and the hidden N were otherwise invisible
   * short of opening the editor.
   */
  conditionsFull: string | null;
}

/** Resolve an option label from a {value,label} list, falling back to raw. */
function optionLabel(options: Array<{ value: string; label: string }>, value: string): string {
  return options.find((o) => o.value === value)?.label ?? value;
}

export function buildTriggerCard(
  fields: TriggerFields,
  triggers: TriggerMeta[],
  options: WorkflowOptions,
): TriggerCard {
  let title: string;
  switch (fields.triggerType) {
    case 'schedule':
      title = `${t('Schedule')}: ${fields.triggerRef || t('(not set)')}`;
      break;
    case 'manual':
      title = t('Manual run');
      break;
    case 'event':
    default: {
      const catalogued = triggers.find((tr) => tr.event === fields.triggerRef);
      const label = catalogued?.label ?? fields.triggerRef;
      title = `${t('When')}: ${label !== '' ? label : t('(not set)')}`;
      break;
    }
  }
  const allLeaves = conditionLeaves(fields.conditionsSerialized);
  return {
    title,
    entity: fields.entityType === '' ? '' : optionLabel(options.entityTypes, fields.entityType),
    conditions: summarizeConditions(fields.conditionsSerialized),
    conditionsFull: allLeaves.length > 1 ? allLeaves.join('  ·  ') : null,
  };
}

/** Every leaf of a serialized tree as display strings (empty on no/bad tree). */
export function conditionLeaves(serialized: string | null | undefined): string[] {
  if (typeof serialized !== 'string' || serialized.trim() === '') {
    return [];
  }
  try {
    const root = JSON.parse(serialized) as RawConditionNode;
    const leaves: string[] = [];
    collectLeaves(root, leaves);
    return leaves;
  } catch {
    return [];
  }
}

/**
 * Where the trigger card sits: directly above the entry step, or at the
 * canvas origin for an empty graph (where it doubles as the empty-state
 * anchor a new workflow grows from).
 */
export function triggerPosition(graph: Graph): { x: number; y: number } {
  const entry = graph.nodes.find((n) => n.id === graph.entry);
  if (!entry) {
    return { x: 80, y: 40 };
  }
  return { x: entry.position.x, y: entry.position.y - 150 };
}

/** The trigger card's React Flow node payload (rendered by TriggerNode). */
export interface TriggerNodeData extends Record<string, unknown> {
  card: TriggerCard;
  /** Editor-only: clicking the conditions row opens the root-conditions editor. */
  editable: boolean;
}

/**
 * The React Flow node + edge for the trigger card — one constructor for the
 * editor and the viewer, so the offsets, flags, and edge styling cannot drift
 * between the two surfaces.
 */
export function buildTriggerFlowElements(
  card: TriggerCard,
  entry: string | null,
  position: { x: number; y: number },
  editable: boolean,
): { node: Node<TriggerNodeData>; edges: Edge[] } {
  return {
    node: {
      id: TRIGGER_NODE_ID,
      type: 'trigger',
      position,
      draggable: false,
      deletable: false,
      connectable: false,
      data: { card, editable },
    },
    edges:
      entry !== null
        ? [
            {
              id: TRIGGER_EDGE_ID,
              source: TRIGGER_NODE_ID,
              target: entry,
              deletable: false,
              selectable: false,
              style: { stroke: '#79a22e', strokeWidth: 1.5, strokeDasharray: '6 3' },
            },
          ]
        : [],
  };
}

// ---- compact condition summaries ------------------------------------------

const OPERATOR_GLYPHS: Record<string, string> = {
  '==': '=',
  '!=': '≠',
  '>=': '≥',
  '<=': '≤',
  '>': '>',
  '<': '<',
  '{}': '∋', // contains
  '!{}': '∌',
  '()': '∈', // is one of
  '!()': '∉',
};

interface RawConditionNode {
  type?: unknown;
  attribute?: unknown;
  operator?: unknown;
  value?: unknown;
  aggregator?: unknown;
  conditions?: unknown;
}

/**
 * A compact, face-sized summary of a serialized condition tree:
 * "grand_total ≥ 500" — plus "+N more" when the tree holds more leaves, with
 * the root aggregator spelled out when it is ANY (ALL is the visual default).
 * Attribute codes render raw (labels live server-side); this is a scanning
 * aid, and the slide-out remains the place to read a tree precisely. Returns
 * null for no/empty/unparseable trees — the caller renders its own
 * "always runs" wording.
 */
export function summarizeConditions(serialized: string | null | undefined): string | null {
  if (typeof serialized !== 'string' || serialized.trim() === '') {
    return null;
  }
  let root: RawConditionNode;
  try {
    root = JSON.parse(serialized) as RawConditionNode;
  } catch {
    return t('(unreadable condition tree)');
  }
  if (root === null || typeof root !== 'object') {
    return null;
  }
  const leaves: string[] = [];
  collectLeaves(root, leaves);
  if (leaves.length === 0) {
    return null;
  }
  const first = leaves[0];
  const rest = leaves.length - 1;
  const anyMode =
    String(root.aggregator ?? 'all') === 'any' && leaves.length > 1 ? ` (${t('ANY')})` : '';
  return rest > 0 ? `${first} +${rest} ${t('more')}${anyMode}` : first;
}

function collectLeaves(node: RawConditionNode, out: string[]): void {
  if (out.length > 12 || node === null || typeof node !== 'object') {
    return; // enough for a face summary; no need to walk a huge tree fully
  }
  const children = Array.isArray(node.conditions) ? (node.conditions as RawConditionNode[]) : null;
  if (children !== null) {
    for (const child of children) {
      collectLeaves(child, out);
    }
    return;
  }
  const attribute = typeof node.attribute === 'string' ? node.attribute : '';
  if (attribute === '') {
    return;
  }
  const operator = typeof node.operator === 'string' ? node.operator : '==';
  const glyph = OPERATOR_GLYPHS[operator] ?? operator;
  out.push(`${conditionAttributeLabel(attribute)} ${glyph} ${formatValue(node.value)}`);
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '…';
  }
  if (Array.isArray(value)) {
    const shown = value.slice(0, 2).map(String).join(', ');
    return value.length > 2 ? `[${shown}, …]` : `[${shown}]`;
  }
  const text = String(value);
  return text.length > 24 ? `${text.slice(0, 21)}…` : text;
}
