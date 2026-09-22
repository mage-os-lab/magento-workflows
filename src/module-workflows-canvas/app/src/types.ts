/**
 * The canvas is a reader/writer of the workflow definition JSON — the only
 * contract between it and the engine. These types mirror the PHP
 * MageOS\Workflows\Model\Definition\Definition shape (docs/04-definition-format).
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

export type StepType =
  | 'action'
  | 'delay'
  | 'branch'
  | 'wait'
  | 'switch'
  | 'approval'
  | 'stop';

export interface SwitchCase {
  key: string;
  conditions_serialized?: string | null;
  next?: string | null;
  [k: string]: unknown;
}

/**
 * One `approval` step's payload_fields[] entry (schema 4). Mirrors
 * Definition::assertApprovalStep's field shape server-side.
 */
export interface ApprovalPayloadField {
  key: string;
  label: string;
  type: 'string' | 'number' | 'boolean';
  required?: boolean;
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
  on_approved?: string | null;
  on_rejected?: string | null;
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

/**
 * One config-panel field, mirroring an ActionMetadataInterface::getConfigForm()
 * entry (F6). `type` is declarative (text/textarea/select/multiselect/boolean/
 * integer/secret). Option-bearing selects use the F6 union: `options` for a
 * bounded inline list, `options_search` for a large/searched source resolved at
 * runtime via GET meta/options?source=&q=. The value union is never eval'd.
 */
export interface ConfigFieldOption {
  value: string;
  label: string;
}

export interface OptionsSearch {
  source: string;
  /** Minimum chars before a live search fires; 0 = resolve the whole list. */
  min_chars?: number;
}

export interface ConfigField {
  name: string;
  label: string;
  type: string;
  required?: boolean;
  notice?: string;
  default?: unknown;
  options?: ConfigFieldOption[];
  options_search?: OptionsSearch;
  [k: string]: unknown;
}

/** A palette/config action, projected from GET meta/actions. */
export interface PaletteAction {
  code: string;
  label: string;
  group: string;
  applicableEntities: string[];
  configForm: ConfigField[];
  aclResource: string | null;
}

/** A palette trigger, projected from GET meta/triggers. */
export interface TriggerMeta {
  event: string;
  entity: string;
  label: string;
  group: string | null;
}

/** One validation finding pinned to a node (Phase B validate loop). */
export interface ValidationMessage {
  severity: 'error' | 'warning' | string;
  code: string;
  message: string;
  step_key: string | null;
  edge: string | null;
}

/** The workflow's general (non-graph) fields, round-tripped through save. */
export interface WorkflowMeta {
  id: number;
  name: string;
  status: number;
  entityType: string;
  triggerType: string;
  triggerRef: string;
  conditionsSerialized: string | null;
  loopGuardDepth: number;
  websiteIds: number[];
  fanOutRelation: string;
  fanOutCap: string;
  definition: Definition | null;
}

/**
 * Option lists for the workflow-settings panel, projected server-side from the
 * SAME option sources the classic admin form's selects use (Mount.php). Values
 * are stringified (status is an int column, entity type a code).
 */
export interface WorkflowOptions {
  entityTypes: ConfigFieldOption[];
  triggerTypes: ConfigFieldOption[];
  statuses: ConfigFieldOption[];
  websites: ConfigFieldOption[];
}

/** Bootstrap config delivered via the mount div's data-config attribute. */
export interface MountConfig {
  workflowId: number | null;
  executionId: number | null;
  knownSchemaVersion: number;
  grants: { manage: boolean; dryRun: boolean };
  endpoints: {
    executionSteps: string;
    dryRun: string;
    /** Same-origin admin JSON validate proxy (canvas Data/Validate). */
    validate: string;
    /** Same-origin admin JSON option-source proxy (admin-ui Data/Options). */
    options: string;
    /**
     * Same-origin admin JSON condition-metadata provider (admin-ui
     * Data/ConditionMeta, ::view, GET ?entity_type=&node_type=). Describes ONE
     * condition node type at a time — kind, add-child menu, attributes and
     * their operators/value elements — so the condition builder is driven by
     * the server's real condition classes rather than a client-side copy.
     */
    conditionMeta: string;
    /**
     * The EXISTING shared condition apply/validate endpoint
     * (mageos_workflows/workflow/conditions, ::manage, POST + form key). It
     * shape-checks a serialized tree through the same pipeline a save runs and
     * echoes the normalized tree back.
     */
    conditions: string;
    /** The EXISTING admin Save controller (mageos_workflows/workflow/save). */
    save: string;
  };
  formKey: string;
  workflow: WorkflowMeta | null;
  /** Selects for the workflow-settings panel (always emitted by Mount.php). */
  workflowOptions: WorkflowOptions;
  /**
   * English phrase -> translated phrase (Model/I18n/PhraseCatalog via
   * Mount.php). Consumed once at mount by i18n.setTranslations.
   */
  i18n: Record<string, string>;
  /** code => {label, group}: kept for node summaries (Phase A). */
  actions: Record<string, { label: string; group: string }>;
  /** Full palette/config action metadata (Phase B). ACL-filtered display. */
  actionsMeta: PaletteAction[];
  /** Palette triggers (Phase B). */
  triggers: TriggerMeta[];
  /** Secret NAMES only (never values) for the variable picker. */
  secrets: string[];
  /**
   * Whether the MageOS_WorkflowsApprovals addon is installed on this instance
   * (docs/discovery/approval-gate.md §7: "renders only when both optional
   * packages are present" — this package is present by definition since the
   * bundle is running; this flag is the other half). Gates whether the
   * "Approval gate" palette entry is offered for NEW authoring; an existing
   * `approval` step in a loaded definition always renders (read-only viewing
   * and dry-run must work regardless — the addon only gates the runtime task
   * record and admin decision surface, not core's schema-4 step semantics).
   * Mirrors the server's own `?ApprovalTaskManagerInterface = null` seam
   * (Model/Validation/Check/ApprovalCheck.php) that produces
   * APPROVAL_MODULE_MISSING at save time when this is false.
   */
  approvalsAvailable: boolean;
}
