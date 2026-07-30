import type { AttributeMeta, ConditionNode, MetaOption, NodeMeta } from '../../conditionTree';
import {
  COMBINE_VALUE_OPTIONS,
  EXISTS_OPTIONS,
  FALLBACK_AGGREGATORS,
  FALLBACK_OPERATORS,
  MATCH_MODE_OPTIONS,
  applyAttributeChange,
  kindOf,
  nodeDefaultAttributeMeta,
  nodeToPrettyJson,
  nodeType,
  optionsWithCurrent,
  orFallback,
  propString,
} from '../../conditionTree';
import { AddChildMenu } from './AddChildMenu';
import { ValueControl } from './ValueControl';

/**
 * One row of the condition tree, dispatched on the node KIND the server
 * reported for its type. Semantics mirror the native salesrule/catalogrule
 * widget so the muscle memory transfers:
 *
 *   combine      "If ALL of these conditions are TRUE:" + indented children
 *   leaf         attribute -> operator -> typed value
 *   related      relation -> EXISTS/NOT EXISTS -> match mode -> children
 *                (children are suppressed under NOT EXISTS: the server rejects
 *                that combination outright — RelationConditionsCheck
 *                RELATION_NOT_EXISTS_WITH_CHILDREN)
 *   trigger_data free dot-path -> string operator -> value (freetext by design)
 *   unknown      read-only raw JSON, preserved verbatim on serialize
 *
 * No metadata yet for a type means "unknown": the row NEVER guesses a shape for
 * data it cannot describe, it shows it and keeps it.
 */
export interface TreeHandlers {
  /** Resolved metadata for a node type; null while loading or unavailable. */
  metaFor: (type: string) => NodeMeta | null;
  readOnly: boolean;
  onSetProp: (id: string, key: string, value: unknown) => void;
  onReplace: (id: string, node: ConditionNode) => void;
  onRemove: (id: string) => void;
  onAddChild: (parentId: string, spec: string) => void;
}

interface Props {
  node: ConditionNode;
  /** The root row cannot be removed by "×" — the footer clears the whole tree. */
  isRoot: boolean;
  handlers: TreeHandlers;
}

export function ConditionNodeRow({ node, isRoot, handlers }: Props): JSX.Element {
  const type = nodeType(node);
  const meta = handlers.metaFor(type);
  const kind = 'verbatim' in node ? 'unknown' : kindOf(meta);
  const label = describeNode(node, meta);

  return (
    <li className={`wf-cond__node wf-cond__node--${kind}`}>
      <div className="wf-cond__row">
        {kind === 'combine' && <CombineRow node={node} meta={meta} handlers={handlers} />}
        {kind === 'related' && <RelatedRow node={node} meta={meta} handlers={handlers} />}
        {kind === 'leaf' && <LeafRow node={node} meta={meta} handlers={handlers} />}
        {kind === 'trigger_data' && <TriggerDataRow node={node} meta={meta} handlers={handlers} />}
        {kind === 'unknown' && <UnknownRow node={node} />}
        {!isRoot && (
          <button
            type="button"
            className="wf-cond__remove"
            aria-label={`Remove condition: ${label}`}
            disabled={handlers.readOnly}
            onClick={() => handlers.onRemove(node.id)}
          >
            ×
          </button>
        )}
      </div>

      {kind !== 'unknown' && node.children !== null && childrenVisible(node, kind) && (
        <ul className="wf-cond__children">
          {node.children.map((child) => (
            <ConditionNodeRow key={child.id} node={child} isRoot={false} handlers={handlers} />
          ))}
          <li className="wf-cond__node wf-cond__node--add">
            <AddChildMenu
              groups={meta?.new_children ?? []}
              contextLabel={label}
              readOnly={handlers.readOnly}
              onAdd={(spec) => handlers.onAddChild(node.id, spec)}
            />
          </li>
        </ul>
      )}
    </li>
  );
}

/** NOT EXISTS forbids children server-side, so the sub-tree is not offered. */
function childrenVisible(node: ConditionNode, kind: string): boolean {
  if (kind !== 'related') {
    return true;
  }
  return effective(node, 'value', '1') !== '0';
}

function CombineRow({
  node,
  meta,
  handlers,
}: {
  node: ConditionNode;
  meta: NodeMeta | null;
  handlers: TreeHandlers;
}): JSX.Element {
  const aggregator = effective(node, 'aggregator', 'all');
  const value = effective(node, 'value', '1');
  return (
    <span className="wf-cond__sentence">
      <span className="wf-cond__text">If</span>
      <OptionSelect
        label="Aggregator"
        options={orFallback(meta?.aggregators, FALLBACK_AGGREGATORS)}
        value={aggregator}
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onSetProp(node.id, 'aggregator', v)}
      />
      <span className="wf-cond__text">of these conditions are</span>
      <OptionSelect
        label="Expected result"
        // The node's OWN value select: TRUE/FALSE for a plain combine, but e.g.
        // FOUND/NOT FOUND for an items subtree — always the server's wording.
        options={orFallback(meta?.value_options, COMBINE_VALUE_OPTIONS)}
        value={value}
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onSetProp(node.id, 'value', v)}
      />
      <span className="wf-cond__text">:</span>
    </span>
  );
}

function RelatedRow({
  node,
  meta,
  handlers,
}: {
  node: ConditionNode;
  meta: NodeMeta | null;
  handlers: TreeHandlers;
}): JSX.Element {
  const relation = propString(node, 'relation');
  const exists = effective(node, 'value', '1');
  const matchMode = effective(node, 'match_mode', 'any');
  const hasChildren = (node.children?.length ?? 0) > 0;
  return (
    <span className="wf-cond__sentence">
      <span className="wf-cond__text">Related</span>
      <OptionSelect
        label="Relation"
        options={meta?.relations ?? []}
        value={relation}
        placeholder="— select relation —"
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onSetProp(node.id, 'relation', v)}
      />
      <OptionSelect
        label="Existence"
        options={orFallback(meta?.value_options, EXISTS_OPTIONS)}
        value={exists}
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onSetProp(node.id, 'value', v)}
      />
      {exists !== '0' && (
        <>
          {/* The match mode only means something for a to-many relation; the
              server ships each relation's cardinality for exactly this. */}
          {isToMany(meta, relation) && (
            <>
              <span className="wf-cond__text">where</span>
              <OptionSelect
                label="Match mode"
                options={orFallback(meta?.match_modes, MATCH_MODE_OPTIONS)}
                value={matchMode}
                readOnly={handlers.readOnly}
                onChange={(v) => handlers.onSetProp(node.id, 'match_mode', v)}
              />
            </>
          )}
          {hasChildren && (
            <>
              <span className="wf-cond__text">against</span>
              <OptionSelect
                label="Related child aggregator"
                options={orFallback(meta?.aggregators, FALLBACK_AGGREGATORS)}
                value={effective(node, 'aggregator', 'all')}
                readOnly={handlers.readOnly}
                onChange={(v) => handlers.onSetProp(node.id, 'aggregator', v)}
              />
              <span className="wf-cond__text">of:</span>
            </>
          )}
        </>
      )}
      {exists === '0' && (
        <span className="wf-cond__hint">
          NOT EXISTS is a bare existence check — child conditions are not allowed.
        </span>
      )}
    </span>
  );
}

function LeafRow({
  node,
  meta,
  handlers,
}: {
  node: ConditionNode;
  meta: NodeMeta | null;
  handlers: TreeHandlers;
}): JSX.Element {
  const attribute = propString(node, 'attribute');
  const attributes = meta?.attributes ?? {};
  // An attribute the server did not describe (third-party, or renamed) still
  // renders through the node-level leaf defaults instead of losing its row.
  const attributeMeta: AttributeMeta | undefined =
    attributes[attribute] ?? nodeDefaultAttributeMeta(meta);
  const attributeOptions: MetaOption[] = Object.entries(attributes).map(([code, m]) => ({
    value: code,
    label: m.label,
  }));
  const operators = orFallback(attributeMeta?.operators, FALLBACK_OPERATORS);

  return (
    <span className="wf-cond__sentence">
      <OptionSelect
        label="Attribute"
        options={attributeOptions}
        value={attribute}
        placeholder="— select attribute —"
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onReplace(node.id, applyAttributeChange(node, v, attributes[v]))}
      />
      <OptionSelect
        label={`Operator for ${attribute || 'the attribute'}`}
        options={operators}
        value={propString(node, 'operator')}
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onSetProp(node.id, 'operator', v)}
      />
      <ValueControl
        label={`Value for ${attribute || 'the attribute'}`}
        value={node.props.value}
        attributeMeta={attributeMeta}
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onSetProp(node.id, 'value', v)}
      />
    </span>
  );
}

function TriggerDataRow({
  node,
  meta,
  handlers,
}: {
  node: ConditionNode;
  meta: NodeMeta | null;
  handlers: TreeHandlers;
}): JSX.Element {
  const path = propString(node, 'attribute');
  // TriggerData has no attribute option list by design, so its operator set and
  // value element are the node-level leaf defaults.
  const defaults = nodeDefaultAttributeMeta(meta);
  return (
    <span className="wf-cond__sentence">
      <span className="wf-cond__text">Trigger data</span>
      <input
        type="text"
        className="wf-cond__path"
        aria-label="Trigger payload path (dot notation)"
        placeholder="e.g. to_status or items.0.sku"
        value={path}
        disabled={handlers.readOnly}
        spellCheck={false}
        onChange={(e) => handlers.onSetProp(node.id, 'attribute', e.target.value)}
      />
      <OptionSelect
        label="Operator for the trigger payload path"
        options={orFallback(defaults?.operators, FALLBACK_OPERATORS)}
        value={propString(node, 'operator')}
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onSetProp(node.id, 'operator', v)}
      />
      <ValueControl
        label="Value for the trigger payload path"
        value={node.props.value}
        attributeMeta={defaults}
        readOnly={handlers.readOnly}
        onChange={(v) => handlers.onSetProp(node.id, 'value', v)}
      />
    </span>
  );
}

/**
 * A node type this build has no metadata for — a third-party condition class,
 * or a metadata fetch the server refused. Shown read-only with its raw JSON so
 * the author can see (and keep) it; serialization preserves it byte-for-byte.
 */
function UnknownRow({ node }: { node: ConditionNode }): JSX.Element {
  return (
    <span className="wf-cond__sentence wf-cond__sentence--unknown">
      <span className="wf-cond__text">
        Unrecognized condition{nodeType(node) ? `: ${nodeType(node)}` : ''} — shown read-only and
        preserved exactly as stored.
      </span>
      <pre className="wf-cond__raw">{nodeToPrettyJson(node)}</pre>
    </span>
  );
}

/** A labelled select whose persisted value always remains selectable. */
function OptionSelect({
  label,
  options,
  value,
  placeholder,
  readOnly,
  onChange,
}: {
  label: string;
  options: MetaOption[];
  value: string;
  placeholder?: string;
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element {
  return (
    <select
      className="wf-cond__select"
      aria-label={label}
      value={value}
      disabled={readOnly}
      onChange={(e) => onChange(e.target.value)}
    >
      {(placeholder !== undefined || value === '') && (
        <option value="">{placeholder ?? '— select —'}</option>
      )}
      {optionsWithCurrent(options, value).map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

/**
 * Whether the chosen relation resolves to MANY target entities. Unknown /
 * unserved cardinality keeps the match mode visible: hiding a control whose
 * value is already stored would strand it.
 */
function isToMany(meta: NodeMeta | null, relation: string): boolean {
  const row = meta?.relations?.find((r) => r.value === relation);
  return row?.cardinality !== 'one';
}

/** The effective value of a prop, honouring the core rule defaults when absent. */
function effective(node: ConditionNode, key: string, fallback: string): string {
  const value = propString(node, key);
  return value === '' ? fallback : value;
}

/** Short human name for aria-labels: the server label, else the class name. */
function describeNode(node: ConditionNode, meta: NodeMeta | null): string {
  if (meta?.label) {
    return meta.label;
  }
  const type = nodeType(node);
  const short = type.split('\\').pop() ?? type;
  return short === '' ? 'condition' : short;
}
