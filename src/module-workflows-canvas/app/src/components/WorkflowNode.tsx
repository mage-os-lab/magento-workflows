import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { GraphNode, StepNode } from '../types';
import { getStepEdges, edgeLabel } from '../edges';

/**
 * One typed node renderer for every step type. Handles are placed per the edge
 * model (getStepEdges): branch = on_true/on_false, switch = one per case +
 * default, wait = on_event/on_timeout, action/delay = next, stop = none. Every
 * server-provided string (summary, labels, error) renders as a React text node
 * — never dangerouslySetInnerHTML.
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

const TYPE_LABEL: Record<string, string> = {
  action: 'Action',
  delay: 'Delay',
  branch: 'Branch',
  wait: 'Wait',
  switch: 'Switch',
  stop: 'Stop',
  degraded: 'Unavailable',
};

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
      style={{
        borderColor: statusColor ?? (d.degraded ? '#c62828' : '#c4c4c4'),
      }}
    >
      {!d.isEntry && <Handle type="target" position={Position.Top} />}

      <div className="wf-node__head">
        <span className="wf-node__type">{TYPE_LABEL[type] ?? type}</span>
        {d.isEntry && <span className="wf-node__entry" title="Entry step">start</span>}
      </div>

      <div className="wf-node__summary">{d.summary}</div>

      {d.degraded && (
        <div className="wf-node__degraded">
          Action code not registered — deletable, not configurable.
        </div>
      )}

      {d.status && (
        <div className="wf-node__status" style={{ color: statusColor }}>
          {d.status}
          {d.duration ? ` · ${d.duration}` : ''}
        </div>
      )}

      {d.error && <div className="wf-node__error">{d.error}</div>}

      {renderSourceHandles(edges)}
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
  stop: WorkflowNode,
  degraded: WorkflowNode,
};

export type { GraphNode };
