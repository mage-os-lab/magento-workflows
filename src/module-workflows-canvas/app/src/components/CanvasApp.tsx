import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ReactFlow,
  Background,
  Controls,
  MiniMap,
  useReactFlow,
  type Edge,
  type Node,
} from '@xyflow/react';
import type { Graph, MountConfig } from '../types';
import { isReadOnly, toGraph } from '../mapping';
import { autoLayout, needsLayout } from '../layout';
import { nodeTypes, type NodeData } from './WorkflowNode';
import {
  buildDryRunOverlay,
  buildExecutionOverlay,
  emptyOverlay,
  formatDuration,
  type DryRunTraceRow,
  type ExecutionStepRow,
  type Overlay,
} from '../overlay';
import { t } from '../i18n';
import { initMeta } from '../workflowMeta';
import {
  buildTriggerCard,
  buildTriggerFlowElements,
  type TriggerCard,
  type TriggerNodeData,
} from '../triggerNode';
import { Outline } from './Outline';
import { Editor } from './Editor';
import { WorkflowSettings } from './WorkflowSettings';

interface Props {
  config: MountConfig;
}

/**
 * Dispatcher (no hooks, so the Rules of Hooks hold regardless of branch): a
 * manager editing a schema-known workflow gets the Phase B editor; everyone
 * else (::view-only, a newer-schema document, or no definition) gets the
 * read-only viewer. The write controllers re-check ACL server-side regardless
 * of which surface loaded.
 */
export function CanvasApp({ config }: Props): JSX.Element {
  const definition = config.workflow?.definition ?? null;
  const editable =
    config.grants.manage &&
    definition !== null &&
    !isReadOnly(definition.schema, config.knownSchemaVersion);

  if (editable && definition) {
    return <Editor config={config} initialGraph={toGraph(definition, config)} />;
  }
  return <Viewer config={config} />;
}

function Viewer({ config }: Props): JSX.Element {
  const definition = config.workflow?.definition ?? null;

  const baseGraph = useMemo<Graph | null>(
    () => (definition ? toGraph(definition, config) : null),
    [definition, config],
  );

  const { fitView } = useReactFlow();
  const [positions, setPositions] = useState<Record<string, { x: number; y: number }> | null>(null);
  const [overlay, setOverlay] = useState<Overlay>(emptyOverlay());
  const [overlayLabel, setOverlayLabel] = useState<string>('');
  const [error, setError] = useState<string>('');
  // Optional entity id for the dry-run: empty = the server picks its default
  // sample entity; a value posts entity_id (Data/DryRun.php reads it).
  const [dryRunEntityId, setDryRunEntityId] = useState<string>('');

  // Auto-layout when there is no persisted ui layout.
  useEffect(() => {
    if (!baseGraph) {
      return;
    }
    if (needsLayout(baseGraph)) {
      let cancelled = false;
      autoLayout(baseGraph).then((pos) => {
        if (!cancelled) {
          setPositions(pos);
        }
      });
      return () => {
        cancelled = true;
      };
    }
    setPositions(null);
    return undefined;
  }, [baseGraph]);

  // Re-frame once the async auto-layout has moved the nodes: the mount-time
  // `fitView` prop framed the PRE-layout positions.
  useEffect(() => {
    if (positions !== null) {
      requestAnimationFrame(() => {
        void fitView({ padding: 0.15, maxZoom: 1 });
      });
    }
  }, [positions, fitView]);

  // Auto-load the execution overlay when arriving from the execution view.
  useEffect(() => {
    if (!config.executionId) {
      return;
    }
    loadExecution(config.executionId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [config.executionId]);

  const loadExecution = useCallback(
    async (executionId: number) => {
      setError('');
      try {
        const url = `${config.endpoints.executionSteps}?execution_id=${encodeURIComponent(String(executionId))}`;
        const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) {
          throw new Error(`${t('Execution steps request failed.')} (${res.status})`);
        }
        const body = (await res.json()) as { steps: ExecutionStepRow[] };
        setOverlay(buildExecutionOverlay(body.steps ?? []));
        setOverlayLabel(`${t('Execution')} #${executionId}`);
      } catch (e) {
        setError(e instanceof Error ? e.message : t('Failed to load execution.'));
      }
    },
    [config.endpoints.executionSteps],
  );

  const runDryRun = useCallback(async () => {
    if (!config.workflow) {
      return;
    }
    setError('');
    try {
      const form = new URLSearchParams();
      form.set('workflow_id', String(config.workflow.id));
      form.set('form_key', config.formKey);
      if (dryRunEntityId.trim() !== '') {
        form.set('entity_id', dryRunEntityId.trim());
      }
      const res = await fetch(config.endpoints.dryRun, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: form.toString(),
      });
      if (!res.ok) {
        throw new Error(`${t('Dry-run failed.')} (${res.status})`);
      }
      const body = (await res.json()) as { steps: DryRunTraceRow[] };
      setOverlay(buildDryRunOverlay(body.steps ?? []));
      setOverlayLabel(t('Dry-run preview'));
    } catch (e) {
      setError(e instanceof Error ? e.message : t('Dry-run failed.'));
    }
  }, [config, dryRunEntityId]);

  const clearOverlay = useCallback(() => {
    setOverlay(emptyOverlay());
    setOverlayLabel('');
  }, []);

  if (!definition || !baseGraph) {
    return (
      <div className="wf-canvas__empty">
        {t('No workflow definition to display. The classic form and JSON editor remain the primary authoring surface.')}
      </div>
    );
  }

  // The presentational trigger card (see triggerNode.ts), placed above the
  // entry step's EFFECTIVE position (auto-layout may have overridden it).
  const triggerCard: TriggerCard | null = config.workflow
    ? buildTriggerCard(
        {
          triggerType: config.workflow.triggerType,
          triggerRef: config.workflow.triggerRef,
          entityType: config.workflow.entityType,
          conditionsSerialized: config.workflow.conditionsSerialized,
        },
        config.triggers,
        config.workflowOptions,
      )
    : null;
  const entryNode = baseGraph.nodes.find((n) => n.id === baseGraph.entry) ?? null;
  const entryPos = entryNode ? positions?.[entryNode.id] ?? entryNode.position : null;

  const triggerElements = triggerCard
    ? buildTriggerFlowElements(
        triggerCard,
        baseGraph.entry,
        entryPos ? { x: entryPos.x, y: entryPos.y - 150 } : { x: 80, y: 40 },
        false,
      )
    : null;

  const stepNodes: Node<NodeData>[] = baseGraph.nodes.map((n) => {
    const pos = positions?.[n.id] ?? n.position;
    const status = overlay.nodeStatus[n.id];
    const dur = overlay.durationMs[n.id];
    return {
      id: n.id,
      type: n.type,
      position: pos,
      data: {
        ...n.data,
        status,
        duration: dur != null ? formatDuration(dur) : undefined,
        error: overlay.nodeError[n.id],
        onTakenPath: status !== undefined,
      },
    };
  });
  const rfNodes: Node<NodeData | TriggerNodeData>[] = [
    ...(triggerElements ? [triggerElements.node] : []),
    ...stepNodes,
  ];

  const stepEdges: Edge[] = baseGraph.edges.map((e) => {
    const taken = overlay.takenEdgeIds.has(e.id);
    return {
      id: e.id,
      source: e.source,
      target: e.target,
      sourceHandle: e.sourceHandle,
      label: e.label || undefined,
      animated: taken,
      style: taken ? { stroke: '#1565c0', strokeWidth: 2 } : undefined,
    };
  });
  const rfEdges: Edge[] = [...(triggerElements ? triggerElements.edges : []), ...stepEdges];

  return (
    <div className="wf-canvas">
      {baseGraph.readOnly && (
        <div className="wf-canvas__banner" role="alert">
          {t('This workflow declares schema')} {baseGraph.schema} ({t('this canvas understands schema')}{' '}
          {config.knownSchemaVersion}). {t('Shown read-only — edit it in the JSON editor.')}
        </div>
      )}

      <div className="wf-canvas__toolbar">
        <strong className="wf-canvas__title">{config.workflow?.name ?? t('Workflow')}</strong>
        {config.executionId && (
          <button type="button" className="action-default" onClick={() => loadExecution(config.executionId as number)}>
            {t('Reload execution')}
          </button>
        )}
        {config.grants.dryRun && config.workflow && (
          <>
            <label className="wf-canvas__dryrun-entity">
              {t('Entity ID')}
              <input
                type="number"
                min={1}
                placeholder={t('auto')}
                value={dryRunEntityId}
                onChange={(e) => setDryRunEntityId(e.target.value)}
              />
            </label>
            <button type="button" className="action-default" onClick={runDryRun}>
              {t('Run dry-run overlay')}
            </button>
          </>
        )}
        {overlayLabel && (
          <>
            <span className="wf-canvas__overlay-label">{t('Overlay:')} {overlayLabel}</span>
            <button type="button" className="action-default" onClick={clearOverlay}>
              {t('Clear overlay')}
            </button>
          </>
        )}
        {error && (
          <span className="wf-canvas__error" role="alert">
            {error}
          </span>
        )}
      </div>

      {/* The same settings panel the editor shows, read-only: a viewer-grade
          admin still needs to see what the workflow is and when it fires. */}
      {config.workflow !== null && (
        <WorkflowSettings
          meta={initMeta(config.workflow)}
          options={config.workflowOptions}
          triggers={config.triggers}
          readOnly
          onChange={() => undefined}
        />
      )}

      <div className="wf-canvas__flow">
        <ReactFlow
          nodes={rfNodes}
          edges={rfEdges}
          nodeTypes={nodeTypes}
          fitView
          nodesDraggable={false}
          nodesConnectable={false}
          elementsSelectable
          proOptions={{ hideAttribution: true }}
        >
          <Background />
          <Controls showInteractive={false} />
          <MiniMap pannable zoomable />
        </ReactFlow>
      </div>

      {/* Screen-reader / no-canvas outline (C3's legacy). */}
      <Outline graph={baseGraph} overlay={overlay} trigger={triggerCard ?? undefined} />
    </div>
  );
}
