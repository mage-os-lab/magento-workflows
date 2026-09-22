import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { GraphNode, StepNode } from '../types';
import { getStepEdges, edgeLabel } from '../edges';
import { t } from '../i18n';
import type { TriggerNodeData } from '../triggerNode';

export type { TriggerNodeData };

/**
 * One typed node renderer for every step type. Handles are placed per the edge
 * model (getStepEdges): branch = on_true/on_false, switch = one per case +
 * default, wait = on_event/on_timeout, approval = on_approved/on_rejected/
 * on_timeout, action/delay = next, stop = none. Every server-provided string
 * (summary, labels, error) renders as a React text node — never
 * dangerouslySetInnerHTML.
 */

export interface NodeData extends Record<string, unknown> {
  stepKey: string;
  step: StepNode;
  isEntry: boolean;
  degraded: boolean;
  summary: string;
  status?: string;
  duration?: string;
  error?: string;
  onTakenPath?: boolean;
  /** Pinned validation findings for this node (Phase B editor). */
  messages?: import('../types').ValidationMessage[];
}

const STATUS_COLORS: Record<string, string> = {
  complete: '#2e7d32',
  failed: '#c62828',
  running: '#1565c0',
  waiting: '#f9a825',
  skipped: '#9e9e9e',
  pending: '#bdbdbd',
  would_run: '#2e7d32',
  would_fail: '#c62828',
};

/**
 * The step-type badge label, resolved through t() at render time (a module
 * constant would be baked before the phrase map is installed). Unknown types
 * fall through to the raw machine value.
 */
function typeLabel(type: string): string {
  switch (type) {
    case 'action':
      return t('Action');
    case 'delay':
      return t('Delay');
    case 'branch':
      return t('Branch');
    case 'wait':
      return t('Wait');
    case 'switch':
      return t('Switch');
    case 'approval':
      return t('Approval');
    case 'stop':
      return t('Stop');
    case 'degraded':
      return t('Unavailable');
    default:
      return type;
  }
}

export function WorkflowNode({ data }: NodeProps): JSX.Element {
  const d = data as NodeData;
  const type = d.degraded ? 'degraded' : d.step.type;
  const edges = Object.keys(getStepEdges(d.step));
  const statusColor = d.status ? STATUS_COLORS[d.status] ?? '#616161' : undefined;

  return (
    <div
      className={`wf-node wf-node--${type}${d.degraded ? ' wf-node--degraded' : ''}${
        d.onTakenPath ? ' wf-node--taken' : ''
      }`}
      // Inline border-color only for the DYNAMIC cases (overlay status,
      // degraded): an unconditional inline value expands to all four sides
      // and would override the per-type border-top-color accents in the
      // stylesheet.
      style={
        statusColor !== undefined || d.degraded
          ? { borderColor: statusColor ?? '#c62828' }
          : undefined
      }
    >
      {/* Always present: without a target handle React Flow silently drops
          every edge INTO this node — including the trigger card's edge to the
          entry step (and any loop-back a definition may legally contain). The
          entry's handle is non-connectable so users still cannot wire into it. */}
      <Handle type="target" position={Position.Top} isConnectable={!d.isEntry} />

      <div className="wf-node__head">
        <span className="wf-node__type">{typeLabel(type)}</span>
        {d.isEntry && <span className="wf-node__entry" title={t('Entry step')}>{t('start')}</span>}
      </div>

      <div className="wf-node__summary">{d.summary}</div>

      {d.degraded && (
        <div className="wf-node__degraded">
          {t('Action code not registered — deletable, not configurable.')}
        </div>
      )}

      {d.status && (
        <div className="wf-node__status" style={{ color: statusColor }}>
          {d.status}
          {d.duration ? ` · ${d.duration}` : ''}
        </div>
      )}

      {d.error && <div className="wf-node__error">{d.error}</div>}

      {d.messages && d.messages.length > 0 && (
        <ul className="wf-node__messages">
          {d.messages.map((m, i) => (
            <li key={`${m.code}-${i}`} className={`wf-node__message wf-node__message--${m.severity}`}>
              {m.message}
            </li>
          ))}
        </ul>
      )}

      {renderSourceHandles(edges)}
    </div>
  );
}

/**
 * The trigger card at the top of every graph (purely presentational — see
 * triggerNode.ts). Distinct visual weight from step nodes: the reader's eye
 * needs one unambiguous "this is where it starts".
 */
export function TriggerNode({ data }: NodeProps): JSX.Element {
  const d = data as TriggerNodeData;
  return (
    <div className="wf-node wf-node--trigger" data-testid="wf-trigger-node">
      <div className="wf-node__head wf-node__head--trigger">
        <span className="wf-node__type">
          <svg className="wf-node__icon" viewBox="0 0 12 14" aria-hidden="true">
            <path d="M7.5 0 1 8h3.5L4 14l6.5-8H7z" fill="currentColor" />
          </svg>
          {t('Trigger')}
        </span>
        {d.card.entity !== '' && <span className="wf-node__chip">{d.card.entity}</span>}
      </div>

      <div className="wf-node__summary">{d.card.title}</div>

      <div
        className={`wf-node__conditions${d.card.conditions === null ? ' wf-node__conditions--none' : ''}`}
        title={
          d.card.conditionsFull ??
          (d.editable ? t('Click to edit the workflow conditions') : undefined)
        }
      >
        {d.card.conditions !== null
          ? `${t('Only if')}: ${d.card.conditions}`
          : t('No conditions — this always runs.')}
      </div>

      <Handle type="source" position={Position.Bottom} isConnectable={false} />
    </div>
  );
}

function renderSourceHandles(edges: string[]): JSX.Element[] {
  if (edges.length === 0) {
    return [];
  }
  const step = 100 / (edges.length + 1);
  return edges.map((name, i) => {
    const left = `${step * (i + 1)}%`;
    const label = edgeLabel(name);
    return (
      <Handle
        key={name}
        id={name}
        type="source"
        position={Position.Bottom}
        style={{ left }}
        data-edge={name}
      >
        {label && <span className="wf-node__handle-label">{label}</span>}
      </Handle>
    );
  });
}

/** Node-type map for ReactFlow: every step type routes to WorkflowNode. */
export const nodeTypes = {
  action: WorkflowNode,
  delay: WorkflowNode,
  branch: WorkflowNode,
  wait: WorkflowNode,
  switch: WorkflowNode,
  approval: WorkflowNode,
  stop: WorkflowNode,
  degraded: WorkflowNode,
  trigger: TriggerNode,
};

export type { GraphNode };
