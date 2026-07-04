/**
 * The canvas is a reader/writer of the workflow definition JSON — the only
 * contract between it and the engine. These types mirror the PHP
 * MageOS\Workflows\Model\Definition\Definition shape (docs/04-definition-format).
 */

export type StepType =
  | 'action'
  | 'delay'
  | 'branch'
  | 'wait'
  | 'switch'
  | 'stop';

export interface SwitchCase {
  key: string;
  conditions_serialized?: string | null;
  next?: string | null;
  [k: string]: unknown;
}

/**
 * A step node as stored in the definition. Deliberately permissive ([k]:
 * unknown) so the mapping layer round-trips every declared field losslessly —
 * NOT a general unknown-field escape hatch on save (the server strips anything
 * the schema does not declare; see toDefinition).
 */
export interface StepNode {
  type: StepType;
  action?: string;
  config?: Record<string, unknown>;
  conditions_serialized?: string | null;
  revalidate_entity?: boolean;
  cases?: SwitchCase[];
  next?: string | null;
  on_true?: string | null;
  on_false?: string | null;
  on_event?: string | null;
  on_timeout?: string | null;
  default?: string | null;
  [k: string]: unknown;
}

/**
 * The optional, non-semantic layout block. Preserved verbatim by the engine;
 * the canvas is its only writer. `nodes` maps step key -> position.
 */
export interface UiBlock {
  nodes?: Record<string, UiNode>;
  canvas?: Record<string, unknown>;
  [k: string]: unknown;
}

export interface UiNode {
  x: number;
  y: number;
  [k: string]: unknown;
}

export interface Definition {
  schema: number;
  entry: string | null;
  steps: Record<string, StepNode>;
  ui?: UiBlock;
}

/** Edge name -> target step key (or null). Mirrors Definition::getStepEdges. */
export type EdgeMap = Record<string, string | null>;

export interface GraphNode {
  id: string;
  type: StepType | 'degraded';
  position: { x: number; y: number };
  data: {
    stepKey: string;
    step: StepNode;
    isEntry: boolean;
    /** true when an action code is no longer registered (degraded install). */
    degraded: boolean;
    summary: string;
  };
}

export interface GraphEdge {
  id: string;
  source: string;
  target: string;
  /** Edge name from the edge model, e.g. on_true, case:high, on_timeout. */
  sourceHandle: string;
  label: string;
}

export interface Graph {
  nodes: GraphNode[];
  edges: GraphEdge[];
  schema: number;
  /** true when schema > known: the viewer/editor must not mutate + save. */
  readOnly: boolean;
  entry: string | null;
}

/** Bootstrap config delivered via the mount div's data-config attribute. */
export interface MountConfig {
  workflowId: number | null;
  executionId: number | null;
  knownSchemaVersion: number;
  grants: { manage: boolean; dryRun: boolean };
  endpoints: { executionSteps: string; dryRun: string };
  formKey: string;
  workflow: {
    id: number;
    name: string;
    entityType: string;
    triggerType: string;
    triggerRef: string;
    definition: Definition | null;
  } | null;
  actions: Record<string, { label: string; group: string }>;
}
