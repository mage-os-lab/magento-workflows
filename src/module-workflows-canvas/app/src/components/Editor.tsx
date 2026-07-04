import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ReactFlow,
  Background,
  Controls,
  MiniMap,
  addEdge as _addEdge,
  useEdgesState,
  useNodesState,
  useReactFlow,
  type Connection,
  type Edge,
  type Node,
} from '@xyflow/react';
import type { Graph, MountConfig, StepNode } from '../types';
import { toDefinition } from '../mapping';
import { autoLayout, needsLayout } from '../layout';
import { nodeTypes, type NodeData } from './WorkflowNode';
import { Outline } from './Outline';
import { Palette, type PaletteDragPayload } from './Palette';
import { ConfigPanel } from './ConfigPanel';
import { ConditionSlideOut } from './ConditionSlideOut';
import {
  addNode,
  blankStep,
  connect as connectOp,
  deleteNode,
  moveNode,
  positionsOf,
} from '../graphOps';
import { canUndo, canRedo, initHistory, push, redo, undo, type History } from '../history';
import { submitSave } from '../saveClient';
import {
  debounce,
  pinMessages,
  postValidate,
  type PinnedMessages,
} from '../validateClient';
import type { StepType } from '../types';

/**
 * The Phase B editor. Assembles the pure modules — graphOps (immutable graph
 * mutations), history (undo/redo), validateClient (debounced continuous
 * validation), saveClient (save through the existing admin Save controller) —
 * around React Flow. Only reachable when grants.manage is true and the schema
 * is editable; otherwise CanvasApp renders the read-only viewer.
 */
interface Props {
  config: MountConfig;
  initialGraph: Graph;
}

export function Editor({ config, initialGraph }: Props): JSX.Element {
  const [history, setHistory] = useState<History<Graph>>(() => initHistory(initialGraph));
  const graph = history.present;

  const [layoutDirty, setLayoutDirty] = useState(false);
  const [selected, setSelected] = useState<string | null>(null);
  const [pinned, setPinned] = useState<PinnedMessages>({ byNode: {}, document: [], hasErrors: false });
  const [status, setStatus] = useState<string>('');
  const [conditionStep, setConditionStep] = useState<string | null>(null);

  const readOnly = graph.readOnly;

  // Auto-layout once when the definition ships no ui block.
  useEffect(() => {
    if (needsLayout(graph)) {
      let cancelled = false;
      autoLayout(graph).then((positions) => {
        if (cancelled) {
          return;
        }
        setHistory((h) => ({
          ...h,
          present: moveAll(h.present, positions),
        }));
      });
      return () => {
        cancelled = true;
      };
    }
    return undefined;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const commit = useCallback((next: Graph) => {
    setHistory((h) => push(h, next));
  }, []);

  // ---- continuous validation (debounced) --------------------------------
  const runValidate = useRef(
    debounce((cfg: MountConfig, def: string) => {
      postValidate(cfg, { definition: def })
        .then((res) => {
          if (res.success && res.messages) {
            setPinned(pinMessages(res.messages));
          }
        })
        .catch(() => undefined);
    }, 600),
  ).current;

  useEffect(() => {
    if (readOnly) {
      return;
    }
    const def = JSON.stringify(toDefinition(graph, { positions: positionsOf(graph) }));
    runValidate(config, def);
  }, [graph, config, readOnly, runValidate]);

  // ---- keyboard undo/redo ------------------------------------------------
  useEffect(() => {
    const onKey = (e: KeyboardEvent): void => {
      if (!(e.ctrlKey || e.metaKey)) {
        return;
      }
      const key = e.key.toLowerCase();
      if (key === 'z' && !e.shiftKey) {
        e.preventDefault();
        setHistory((h) => undo(h));
      } else if ((key === 'z' && e.shiftKey) || key === 'y') {
        e.preventDefault();
        setHistory((h) => redo(h));
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const selectedNode = useMemo(() => graph.nodes.find((n) => n.id === selected) ?? null, [graph, selected]);

  return (
    <div className="wf-canvas wf-canvas--editor" role="application" aria-label="Workflow visual editor">
      {readOnly && (
        <div className="wf-canvas__banner" role="alert">
          This workflow declares schema {graph.schema}, newer than this canvas understands. Shown
          read-only — edit it in the JSON editor.
        </div>
      )}

      <Toolbar
        config={config}
        canUndo={canUndo(history)}
        canRedo={canRedo(history)}
        hasErrors={pinned.hasErrors}
        status={status}
        readOnly={readOnly}
        onUndo={() => setHistory((h) => undo(h))}
        onRedo={() => setHistory((h) => redo(h))}
        onSave={() => {
          setStatus('Saving…');
          const positions = layoutDirty ? positionsOf(graph) : undefined;
          const def = toDefinition(graph, {
            positions: positions ?? positionsOf(graph),
            existingUi: config.workflow?.definition?.ui,
          });
          submitSave(config, def);
        }}
      />

      <div className="wf-canvas__stage">
        <Palette
          config={config}
          onAdd={(payload) => {
            const g = addFromPayload(graph, payload, { x: 80, y: 80 }, config);
            commit(g);
          }}
        />

        <FlowSurface
          config={config}
          graph={graph}
          pinned={pinned}
          readOnly={readOnly}
          onSelect={setSelected}
          onCommit={commit}
          onMove={(id, position) => {
            setLayoutDirty(true);
            commit(moveNode(graph, id, position));
          }}
        />

        {selectedNode && (
          <ConfigPanel
            node={selectedNode}
            config={config}
            graph={graph}
            readOnly={readOnly}
            onChange={(stepKey, step) => commit(replaceStep(graph, stepKey, step))}
            onDelete={(stepKey) => {
              commit(deleteNode(graph, stepKey));
              setSelected(null);
            }}
            onEditConditions={(stepKey) => setConditionStep(stepKey)}
          />
        )}
      </div>

      <div className="wf-canvas__doc-messages" role="status" aria-live="polite">
        {pinned.document.length > 0 && (
          <ul>
            {pinned.document.map((m, i) => (
              <li key={`${m.code}-${i}`} className={`wf-msg wf-msg--${m.severity}`}>
                {m.message}
              </li>
            ))}
          </ul>
        )}
      </div>

      <Outline
        graph={graph}
        overlay={{ nodeStatus: {}, nodeError: {}, durationMs: {}, takenEdgeIds: new Set() }}
        onSelect={setSelected}
        selected={selected}
      />

      {conditionStep && (
        <ConditionSlideOut
          step={graph.nodes.find((n) => n.id === conditionStep)?.data.step ?? null}
          stepKey={conditionStep}
          onApply={(stepKey, conditionsSerialized) => {
            const node = graph.nodes.find((n) => n.id === stepKey);
            if (node) {
              commit(replaceStep(graph, stepKey, { ...node.data.step, conditions_serialized: conditionsSerialized }));
            }
            setConditionStep(null);
          }}
          onClose={() => setConditionStep(null)}
        />
      )}
    </div>
  );
}

/** React Flow surface with drag-to-add + connect + move wiring. */
function FlowSurface({
  config,
  graph,
  pinned,
  readOnly,
  onSelect,
  onCommit,
  onMove,
}: {
  config: MountConfig;
  graph: Graph;
  pinned: PinnedMessages;
  readOnly: boolean;
  onSelect: (id: string | null) => void;
  onCommit: (graph: Graph) => void;
  onMove: (id: string, position: { x: number; y: number }) => void;
}): JSX.Element {
  const { screenToFlowPosition } = useReactFlow();
  const [rfNodes, setRfNodes, onNodesChange] = useNodesState<Node<NodeData>>([]);
  const [rfEdges, setRfEdges, onEdgesChange] = useEdgesState<Edge>([]);

  // Re-seed React Flow state whenever the source graph changes identity.
  useEffect(() => {
    setRfNodes(
      graph.nodes.map((n) => ({
        id: n.id,
        type: n.type,
        position: n.position,
        data: {
          ...n.data,
          messages: pinned.byNode[n.id] ?? [],
        } as unknown as NodeData,
      })),
    );
    setRfEdges(
      graph.edges.map((e) => ({
        id: e.id,
        source: e.source,
        target: e.target,
        sourceHandle: e.sourceHandle,
        label: e.label || undefined,
      })),
    );
  }, [graph, pinned, setRfNodes, setRfEdges]);

  const onConnect = useCallback(
    (connection: Connection) => {
      const result = connectOp(graph, {
        source: connection.source ?? '',
        sourceHandle: connection.sourceHandle ?? null,
        target: connection.target ?? '',
      });
      if (result.ok) {
        onCommit(result.graph);
      }
      // On reject: leave the graph unchanged; React Flow discards the edge.
      void _addEdge;
    },
    [graph, onCommit],
  );

  const onDrop = useCallback(
    (event: React.DragEvent) => {
      event.preventDefault();
      const raw = event.dataTransfer.getData('application/mageos-workflow-node');
      if (!raw) {
        return;
      }
      let payload: PaletteDragPayload;
      try {
        payload = JSON.parse(raw) as PaletteDragPayload;
      } catch {
        return;
      }
      const position = screenToFlowPosition({ x: event.clientX, y: event.clientY });
      onCommit(addFromPayload(graph, payload, position, config));
    },
    [graph, config, screenToFlowPosition, onCommit],
  );

  return (
    <div
      className="wf-canvas__flow"
      onDrop={onDrop}
      onDragOver={(e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
      }}
    >
      <ReactFlow
        nodes={rfNodes}
        edges={rfEdges}
        nodeTypes={nodeTypes}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onConnect={onConnect}
        onNodeClick={(_, node) => onSelect(node.id)}
        onNodeDragStop={(_, node) => onMove(node.id, node.position)}
        onPaneClick={() => onSelect(null)}
        nodesDraggable={!readOnly}
        nodesConnectable={!readOnly}
        elementsSelectable
        deleteKeyCode={readOnly ? null : ['Backspace', 'Delete']}
        onNodesDelete={(deleted) => {
          let g = graph;
          for (const d of deleted) {
            g = deleteNode(g, d.id);
          }
          onCommit(g);
        }}
        fitView
        proOptions={{ hideAttribution: true }}
      >
        <Background />
        <Controls />
        <MiniMap pannable zoomable />
      </ReactFlow>
    </div>
  );
}

function Toolbar({
  config,
  canUndo: undoable,
  canRedo: redoable,
  hasErrors,
  status,
  readOnly,
  onUndo,
  onRedo,
  onSave,
}: {
  config: MountConfig;
  canUndo: boolean;
  canRedo: boolean;
  hasErrors: boolean;
  status: string;
  readOnly: boolean;
  onUndo: () => void;
  onRedo: () => void;
  onSave: () => void;
}): JSX.Element {
  return (
    <div className="wf-canvas__toolbar" role="toolbar" aria-label="Editor actions">
      <strong className="wf-canvas__title">{config.workflow?.name ?? 'Workflow'}</strong>
      <button type="button" onClick={onUndo} disabled={!undoable || readOnly}>
        Undo
      </button>
      <button type="button" onClick={onRedo} disabled={!redoable || readOnly}>
        Redo
      </button>
      <button type="button" className="wf-canvas__save" onClick={onSave} disabled={readOnly}>
        Save
      </button>
      {hasErrors && (
        <span className="wf-canvas__error" role="status">
          Validation errors — see the badged steps.
        </span>
      )}
      {status && <span className="wf-canvas__status">{status}</span>}
    </div>
  );
}

// ---- pure helpers reused by handlers -------------------------------------

function addFromPayload(
  graph: Graph,
  payload: PaletteDragPayload,
  position: { x: number; y: number },
  config: MountConfig,
): Graph {
  const type = payload.type as StepType;
  return addNode(graph, blankStep(type, payload.action), position, config);
}

function replaceStep(graph: Graph, stepKey: string, step: StepNode): Graph {
  return {
    ...graph,
    nodes: graph.nodes.map((n) =>
      n.id === stepKey ? { ...n, data: { ...n.data, step } } : n,
    ),
  };
}

function moveAll(graph: Graph, positions: Record<string, { x: number; y: number }>): Graph {
  return {
    ...graph,
    nodes: graph.nodes.map((n) => (positions[n.id] ? { ...n, position: positions[n.id] } : n)),
  };
}
