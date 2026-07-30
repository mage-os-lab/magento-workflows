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
  disconnect,
  moveNode,
  positionsOf,
} from '../graphOps';
import {
  applyTargetConditions,
  readRevalidate,
  readTargetConditions,
  supportsRevalidate,
  type ConditionTarget,
} from '../conditionTarget';
import { canUndo, canRedo, initHistory, push, redo, undo, type History } from '../history';
import { submitSave } from '../saveClient';
import { armUnloadGuard, fingerprintDefinition, isDirty } from '../unsavedGuard';
import {
  buildValidateRequest,
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

  const [selected, setSelected] = useState<string | null>(null);
  const [pinned, setPinned] = useState<PinnedMessages>({ byNode: {}, document: [], hasErrors: false });
  const [status, setStatus] = useState<string>('');
  const [conditionTarget, setConditionTarget] = useState<ConditionTarget | null>(null);

  // The workflow ROOT condition tree. It is not part of the definition (it is
  // its own workflow column), so it lives beside the graph history and is
  // folded back into the config that saveClient/validateClient read — those two
  // keep reading it from workflow meta, edited or not.
  const [rootConditions, setRootConditions] = useState<string | null>(
    config.workflow?.conditionsSerialized ?? null,
  );
  const effectiveConfig = useMemo<MountConfig>(() => {
    if (!config.workflow || config.workflow.conditionsSerialized === rootConditions) {
      return config;
    }
    return { ...config, workflow: { ...config.workflow, conditionsSerialized: rootConditions } };
  }, [config, rootConditions]);

  const readOnly = graph.readOnly;

  // Serialize a graph exactly as a save would. One helper for both the save
  // payload and the dirty comparison, so the two can never disagree about what
  // "the current definition" is. Positions are always written: a bootstrapped
  // or auto-laid-out layout must survive a save, not only a manual drag.
  const definitionOf = useCallback(
    (g: Graph) =>
      toDefinition(g, {
        positions: positionsOf(g),
        existingUi: config.workflow?.definition?.ui,
      }),
    [config],
  );
  const definition = useMemo(() => definitionOf(graph), [definitionOf, graph]);

  // ---- unsaved-changes guard (issue #18) ---------------------------------
  // Refs rather than state: submitSave navigates away in the same tick it is
  // called, so the guard must be disarmed synchronously on save — a state
  // transition (and the effect cleanup it would schedule) lands too late.
  const savedFingerprint = useRef<string | null>(null);
  const dirty = useRef(false);
  // The root condition tree is saved alongside the definition, so an edit to it
  // alone must still arm the guard.
  const fingerprintNow = useMemo(
    () => `${fingerprintDefinition(definition)}|${rootConditions ?? ''}`,
    [definition, rootConditions],
  );
  if (savedFingerprint.current === null) {
    savedFingerprint.current = fingerprintNow;
  }

  useEffect(() => {
    dirty.current = isDirty(savedFingerprint.current ?? '', fingerprintNow);
  }, [fingerprintNow]);

  useEffect(() => armUnloadGuard(() => dirty.current), []);

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
        // A machine-generated first layout is not a user edit: re-baseline it
        // (before the state lands, so the dirty effect sees it) or merely
        // OPENING an un-laid-out workflow would arm the guard.
        savedFingerprint.current = `${fingerprintDefinition(definitionOf(moveAll(graph, positions)))}|${
          config.workflow?.conditionsSerialized ?? ''
        }`;
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
      // Root conditions ride along from the bootstrap so root-condition
      // findings surface live, not only at save time (Data/Validate contract).
      postValidate(cfg, buildValidateRequest(cfg, def))
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
    // effectiveConfig, so an edited ROOT tree is what gets live-validated.
    runValidate(effectiveConfig, def);
  }, [graph, effectiveConfig, readOnly, runValidate]);

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
        rootConditionsSet={rootConditions !== null && rootConditions.trim() !== ''}
        onUndo={() => setHistory((h) => undo(h))}
        onRedo={() => setHistory((h) => redo(h))}
        onEditRootConditions={() => setConditionTarget({ scope: 'workflow' })}
        onSave={() => {
          setStatus('Saving…');
          // Disarm first: the save IS a navigation (a real hidden-form POST),
          // so a still-armed guard would prompt on the way out. The page is
          // replaced by the controller's response either way, so there is no
          // in-page state left to protect once the form is submitted.
          savedFingerprint.current = fingerprintNow;
          dirty.current = false;
          submitSave(effectiveConfig, definition);
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
          onMove={(id, position) => commit(moveNode(graph, id, position))}
        />

        {selectedNode && (
          <ConfigPanel
            // Keyed by step: the panel holds per-field UI state (a duration's
            // composite-vs-ISO mode, a search field's query, a case key draft),
            // and none of that should follow the selection to another step.
            key={selectedNode.id}
            node={selectedNode}
            config={config}
            graph={graph}
            readOnly={readOnly}
            onChange={(stepKey, step) => commit(replaceStep(graph, stepKey, step))}
            // Switch case edits arrive as a whole graph: a case key is an edge
            // handle, so the panel's case ops (switchCases) rewrite nodes AND
            // edges together and hand the result straight to the history.
            onGraphChange={commit}
            onDelete={(stepKey) => {
              commit(deleteNode(graph, stepKey));
              setSelected(null);
            }}
            onEditConditions={(target) => setConditionTarget(target)}
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

      {conditionTarget && (
        <ConditionSlideOut
          target={conditionTarget}
          config={effectiveConfig}
          readOnly={readOnly}
          value={
            conditionTarget.scope === 'workflow'
              ? rootConditions
              : readTargetConditions(stepOf(graph, conditionTarget.stepKey), conditionTarget)
          }
          revalidateEntity={
            conditionTarget.scope === 'workflow'
              ? null
              : supportsRevalidate(stepOf(graph, conditionTarget.stepKey))
                ? readRevalidate(stepOf(graph, conditionTarget.stepKey))
                : null
          }
          onApply={(target, conditionsSerialized, revalidateEntity, notice) => {
            if (target.scope === 'workflow') {
              // Not part of the definition: it rides to the server through
              // saveClient's conditions_serialized field (workflow meta).
              setRootConditions(conditionsSerialized);
            } else {
              const step = stepOf(graph, target.stepKey);
              if (step) {
                commit(
                  replaceStep(
                    graph,
                    target.stepKey,
                    applyTargetConditions(step, target, conditionsSerialized, revalidateEntity),
                  ),
                );
              }
            }
            setStatus(notice ?? '');
            setConditionTarget(null);
          }}
          onClose={() => setConditionTarget(null)}
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
        onDelete={({ nodes: deletedNodes, edges: deletedEdges }) => {
          // One combined commit for a delete gesture: a bare edge delete must
          // reach the graph too (it previously only touched React Flow's local
          // state, so a save re-posted the visually-removed edge), and a node
          // delete plus its touching edges must land as a single history entry.
          let g = graph;
          for (const e of deletedEdges) {
            g = disconnect(g, e.id);
          }
          for (const n of deletedNodes) {
            g = deleteNode(g, n.id);
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
  rootConditionsSet,
  onUndo,
  onRedo,
  onEditRootConditions,
  onSave,
}: {
  config: MountConfig;
  canUndo: boolean;
  canRedo: boolean;
  hasErrors: boolean;
  status: string;
  readOnly: boolean;
  rootConditionsSet: boolean;
  onUndo: () => void;
  onRedo: () => void;
  onEditRootConditions: () => void;
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
      {/* The workflow-level gate ("does this workflow run at all?"), edited in
          the same slide-out as a step's tree. The badge is the set/unset
          indicator — root conditions are otherwise invisible on the canvas. */}
      <button
        type="button"
        className="wf-canvas__root-conditions"
        onClick={onEditRootConditions}
        disabled={readOnly}
      >
        Workflow conditions
        <span className="wf-canvas__badge">{rootConditionsSet ? 'Set' : 'Not set'}</span>
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

function stepOf(graph: Graph, stepKey: string): StepNode | null {
  return graph.nodes.find((n) => n.id === stepKey)?.data.step ?? null;
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
