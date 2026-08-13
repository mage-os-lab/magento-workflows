/**
 * The condition-tree model: the serialized `conditions_serialized` payload
 * <-> an editable node tree, plus the pure mutations the builder components
 * drive. Framework-free on purpose (no React, no DOM, no fetch) so every rule
 * below is pinned by vitest.
 *
 * The wire shape is EXACTLY Magento\Rule\Model\Condition\Combine::asArray() —
 * what ConditionEvaluator feeds back through loadArray():
 *   combine  {type, aggregator, value, conditions[]}
 *   leaf     {type, attribute, operator, value}
 *   related  {type, relation, value, match_mode, conditions[]}   (RelatedEntity
 *            \Combine::asArray adds relation/match_mode; see that class)
 *   trigger  {type, attribute, operator, value}                  (TriggerData —
 *            a leaf whose attribute is a free dot-path by design)
 * `type` is an FQCN with backslashes (older/short-form types exist in fixtures
 * too and are treated as opaque strings).
 *
 * PRESERVATION IS THE PRIME DIRECTIVE. A node is stored as its complete source
 * object minus `conditions` (`props`, in source key order) plus its parsed
 * children, so any key this builder does not model — a third-party condition's
 * extra field, a future core key — survives a parse/serialize round-trip
 * byte-for-byte. A node whose type has no server metadata is rendered read-only
 * by the components and still serializes verbatim; a `conditions` entry that is
 * not even an object is carried through untouched (`verbatim`).
 *
 * The metadata types below mirror the `conditionMeta` endpoint contract
 * (ConditionMetaProvider). They live here, not in the fetch layer, because the
 * node model reasons about them (kind, operator/attribute defaults).
 */

import { t } from './i18n';

/** One `{value,label}` option as the metadata endpoint serves it. */
export interface MetaOption {
  value: string;
  label: string;
  /**
   * Optgroup heading the server preserved from a grouped native source
   * (payment/shipping methods group by provider/carrier, store views by
   * website). Consecutive rows sharing a group render under one <optgroup>.
   */
  group?: string;
}

/**
 * A relation option. `cardinality` ('one'|'many', RelationInterface's own
 * constants) travels with the row because the match mode only means something
 * for a to-many relation — a one-to-one relation resolves to a single entity.
 */
export interface RelationOption extends MetaOption {
  cardinality?: string;
}

/** One grouped block of the "Add condition" menu (`new_children`). */
export interface MetaOptionGroup {
  label: string;
  options: MetaOption[];
}

/** Per-attribute render contract for a leaf condition. */
export interface AttributeMeta {
  label: string;
  /** string|numeric|date|boolean|select|multiselect */
  input_type: string;
  /** text|date|select|multiselect */
  value_element: string;
  operators: MetaOption[];
  value_options?: MetaOption[];
}

/** The four node classifications the server reports. */
export type ConditionKind = 'combine' | 'leaf' | 'related' | 'trigger_data';

/** A node type's metadata, as served per node type by `conditionMeta`. */
export interface NodeMeta {
  type: string;
  kind: ConditionKind;
  label: string;
  new_children?: MetaOptionGroup[];
  attributes?: Record<string, AttributeMeta>;
  relations?: RelationOption[];
  aggregators?: MetaOption[];
  /**
   * The related-entity match modes (MATCH_ANY|ALL|NONE) and the node's OWN
   * value select — TRUE/FALSE for a plain combine, EXISTS/NOT EXISTS for a
   * relation node, FOUND/NOT FOUND for an items subtree — read server-side from
   * the node's loadValueOptions(). Empty/absent falls back to the values pinned
   * in the PHP classes, so the builder works against either shape.
   */
  match_modes?: MetaOption[];
  value_options?: MetaOption[];
  /**
   * Leaf DEFAULTS, served at node level (null on combines). They are the only
   * operator/value-element source for a free-attribute leaf like TriggerData,
   * which by design has no attribute option list; for an attribute-bearing leaf
   * the per-attribute entry in `attributes` wins once an attribute is picked.
   */
  input_type?: string | null;
  value_element?: string | null;
  operators?: MetaOption[];
}

/**
 * One editable node.
 *
 * `props` is the source object minus `conditions`, in source order — the
 * lossless carrier. `children` is null when the source carried no `conditions`
 * key at all (a leaf), so a leaf never grows an empty `conditions: []`.
 */
export interface ConditionNode {
  /** Client-side identity for React keys and tree addressing. Never serialized. */
  id: string;
  props: Record<string, unknown>;
  children: ConditionNode[] | null;
  /** Set when the source entry was not a JSON object; serialized back as-is. */
  verbatim?: unknown;
  /**
   * A locally created, still-empty root (see emptyTree). It serializes to null
   * so "opened the slide-out and changed nothing" never writes a tree.
   */
  synthetic?: boolean;
}

export interface ParsedTree {
  root: ConditionNode | null;
  /** Human-readable parse failure; the raw text is never discarded by callers. */
  error: string | null;
}

// The fallback label tables below are FUNCTIONS rather than module constants
// so their labels resolve through t() after the phrase map is installed at
// mount; the option VALUES are the server's machine codes and never change.

/** Fallback aggregators when metadata is unavailable (core rule semantics). */
export function fallbackAggregators(): MetaOption[] {
  return [
    { value: 'all', label: t('ALL') },
    { value: 'any', label: t('ANY') },
  ];
}

/** TRUE/FALSE combine value select (core rule widget wording). */
export function combineValueOptions(): MetaOption[] {
  return [
    { value: '1', label: t('TRUE') },
    { value: '0', label: t('FALSE') },
  ];
}

/** RelatedEntity\Combine::EXISTS / ::NOT_EXISTS. */
export function existsOptions(): MetaOption[] {
  return [
    { value: '1', label: t('EXISTS') },
    { value: '0', label: t('NOT EXISTS') },
  ];
}

/** RelatedEntity\Combine::MATCH_ANY / MATCH_ALL / MATCH_NONE. */
export function matchModeOptions(): MetaOption[] {
  return [
    { value: 'any', label: t('ANY of them matches') },
    { value: 'all', label: t('ALL of them match') },
    { value: 'none', label: t('NONE of them matches') },
  ];
}

/**
 * Core AbstractCondition::getDefaultOperatorOptions() wording, used ONLY when
 * an attribute has no server metadata (unknown/third-party attribute) so the
 * operator still renders as a labelled select instead of a bare code.
 */
export function fallbackOperators(): MetaOption[] {
  return [
    { value: '==', label: t('is') },
    { value: '!=', label: t('is not') },
    { value: '>=', label: t('equals or greater than') },
    { value: '>', label: t('greater than') },
    { value: '<=', label: t('equals or less than') },
    { value: '<', label: t('less than') },
    { value: '{}', label: t('contains') },
    { value: '!{}', label: t('does not contain') },
    { value: '()', label: t('is one of') },
    { value: '!()', label: t('is not one of') },
  ];
}

/** Yes/No select for boolean-input leaves. */
export function booleanValueOptions(): MetaOption[] {
  return [
    { value: '1', label: t('Yes') },
    { value: '0', label: t('No') },
  ];
}

/** The relative-date hint shown on date attributes (RELATIVE_DATE_PATTERN). */
export function relativeDateHint(): string {
  return t('A date (YYYY-MM-DD) or a relative expression like "-30 days" / "+2 hours", resolved at evaluation time.');
}

let sequence = 0;

/** A fresh client-side node id. Deliberately not derived from the content. */
export function nextNodeId(): string {
  sequence += 1;
  return `c${sequence}`;
}

/** The node's `type` string (FQCN or legacy short code), '' when absent. */
export function nodeType(node: ConditionNode): string {
  return typeof node.props.type === 'string' ? node.props.type : '';
}

/** A prop as a string, for the controlled form controls. */
export function propString(node: ConditionNode, key: string): string {
  const value = node.props[key];
  if (value === undefined || value === null) {
    return '';
  }
  if (Array.isArray(value)) {
    return value.map((v) => String(v)).join(',');
  }
  return String(value);
}

/** The kind the server reported for this node type; 'unknown' when unfetched. */
export function kindOf(meta: NodeMeta | null | undefined): ConditionKind | 'unknown' {
  return meta ? meta.kind : 'unknown';
}

/**
 * Parse a serialized tree. Empty/blank input is the "always run" tree (null
 * root, no error). Anything that is not decodable JSON, or decodes to something
 * other than an object, is reported as an error — the caller keeps the raw text
 * (the JSON tab still shows it) and nothing is destroyed.
 */
export function parseConditionTree(raw: string | null | undefined): ParsedTree {
  if (typeof raw !== 'string' || raw.trim() === '') {
    return { root: null, error: null };
  }
  let decoded: unknown;
  try {
    decoded = JSON.parse(raw);
  } catch {
    return { root: null, error: t('Conditions must be valid JSON (a serialized condition tree).') };
  }
  if (!isPlainObject(decoded)) {
    return { root: null, error: t('A condition tree must be a JSON object.') };
  }
  return { root: toNode(decoded), error: null };
}

/** Build an editable node from a decoded source value, losslessly. */
export function toNode(source: unknown): ConditionNode {
  if (!isPlainObject(source)) {
    // Not a condition object at all (string, number, array...). Preserve it.
    return { id: nextNodeId(), props: {}, children: null, verbatim: source };
  }
  const props: Record<string, unknown> = {};
  let children: ConditionNode[] | null = null;
  for (const [key, value] of Object.entries(source)) {
    if (key === 'conditions') {
      children = Array.isArray(value) ? value.map(toNode) : [];
      continue;
    }
    props[key] = value;
  }
  return { id: nextNodeId(), props, children };
}

/** Node -> the plain object that goes on the wire (`conditions` last). */
export function serializeNode(node: ConditionNode): unknown {
  if ('verbatim' in node) {
    return node.verbatim;
  }
  const out: Record<string, unknown> = { ...node.props };
  if (node.children !== null) {
    out.conditions = node.children.map(serializeNode);
  }
  return out;
}

/**
 * True when the tree carries nothing worth storing: no root, or a root this
 * builder created for an empty value and the user never populated.
 */
export function isEmptyTree(root: ConditionNode | null): boolean {
  if (root === null) {
    return true;
  }
  return root.synthetic === true && (root.children?.length ?? 0) === 0;
}

/**
 * Tree -> `conditions_serialized`, or null for "always run". Compact JSON:
 * the server re-encodes through json_encode(JSON_UNESCAPED_SLASHES) anyway, so
 * only the parsed content is contractual.
 */
export function serializeTree(root: ConditionNode | null): string | null {
  if (isEmptyTree(root)) {
    return null;
  }
  return JSON.stringify(serializeNode(root as ConditionNode));
}

/**
 * The starting tree for an empty value: the entity's root combine, flagged
 * synthetic so it round-trips back to null until a child is added.
 */
export function emptyTree(rootType: string): ConditionNode {
  return {
    id: nextNodeId(),
    props: { type: rootType, aggregator: 'all', value: '1' },
    children: [],
    synthetic: true,
  };
}

/** Every distinct node `type` in the tree — the metadata fetch worklist. */
export function collectNodeTypes(root: ConditionNode | null): string[] {
  const seen: string[] = [];
  const walk = (node: ConditionNode): void => {
    const type = nodeType(node);
    if (type !== '' && !seen.includes(type)) {
      seen.push(type);
    }
    for (const child of node.children ?? []) {
      walk(child);
    }
  };
  if (root) {
    walk(root);
  }
  return seen;
}

/** Depth-first lookup by client id. */
export function findNode(root: ConditionNode | null, id: string): ConditionNode | null {
  if (!root) {
    return null;
  }
  if (root.id === id) {
    return root;
  }
  for (const child of root.children ?? []) {
    const hit = findNode(child, id);
    if (hit) {
      return hit;
    }
  }
  return null;
}

/** Immutably replace one node via an updater. Untouched branches keep identity. */
export function updateNode(
  root: ConditionNode,
  id: string,
  updater: (node: ConditionNode) => ConditionNode,
): ConditionNode {
  if (root.id === id) {
    return updater(root);
  }
  if (root.children === null) {
    return root;
  }
  let changed = false;
  const children = root.children.map((child) => {
    const next = updateNode(child, id, updater);
    if (next !== child) {
      changed = true;
    }
    return next;
  });
  return changed ? { ...root, children } : root;
}

/** Set one prop on one node (the controlled-input write path). */
export function setNodeProp(
  root: ConditionNode,
  id: string,
  key: string,
  value: unknown,
): ConditionNode {
  return updateNode(root, id, (node) => ({ ...node, props: { ...node.props, [key]: value } }));
}

/** Append a child to a combine-ish node. A leaf (children === null) is left alone. */
export function addChildNode(
  root: ConditionNode,
  parentId: string,
  child: ConditionNode,
): ConditionNode {
  return updateNode(root, parentId, (node) => ({
    ...node,
    children: [...(node.children ?? []), child],
    // Adding a child makes a synthetic root real.
    synthetic: undefined,
  }));
}

/** Remove a node by id. Removing the root yields null ("always run"). */
export function removeNode(root: ConditionNode | null, id: string): ConditionNode | null {
  if (!root) {
    return null;
  }
  if (root.id === id) {
    return null;
  }
  const prune = (node: ConditionNode): ConditionNode => {
    if (node.children === null) {
      return node;
    }
    const kept = node.children.filter((c) => c.id !== id).map(prune);
    return { ...node, children: kept };
  };
  return prune(root);
}

/**
 * Split a `new_children` option value: `FQCN` or `FQCN|attribute` (core's
 * composite format — preserved end to end, split on the first pipe only since
 * an FQCN never contains one).
 */
export function splitTypeSpec(spec: string): { type: string; attribute: string | null } {
  const at = spec.indexOf('|');
  if (at === -1) {
    return { type: spec, attribute: null };
  }
  return { type: spec.slice(0, at), attribute: spec.slice(at + 1) };
}

/**
 * Kind guess used ONLY when a just-picked child type's metadata could not be
 * fetched (offline dev / third-party class the provider rejects). Rendering
 * never guesses: a node with no metadata renders read-only.
 */
export function inferKind(spec: string): ConditionKind {
  const { type, attribute } = splitTypeSpec(spec);
  if (attribute !== null) {
    return 'leaf';
  }
  if (type.includes('RelatedEntity')) {
    return 'related';
  }
  if (type.endsWith('TriggerData')) {
    return 'trigger_data';
  }
  if (type.endsWith('Combine') || type === 'combine') {
    return 'combine';
  }
  return 'leaf';
}

/** First option value, or a fallback when the list is absent/empty. */
export function firstOptionValue(options: MetaOption[] | undefined, fallback: string): string {
  return options && options.length > 0 ? options[0].value : fallback;
}

/**
 * A served option list, or the built-in fallback when the server sent nothing
 * for it — the endpoint serves `[]` (not absent) for keys that do not apply to
 * a node kind, so emptiness is the signal, not undefined.
 */
export function orFallback(
  options: MetaOption[] | undefined | null,
  fallback: MetaOption[],
): MetaOption[] {
  return options && options.length > 0 ? options : fallback;
}

/**
 * The value-render contract for a leaf whose attribute the server did not
 * describe (unknown attribute, or a free-attribute leaf like TriggerData):
 * the node-level leaf defaults stand in for a per-attribute descriptor.
 */
export function nodeDefaultAttributeMeta(meta: NodeMeta | null): AttributeMeta | undefined {
  if (!meta) {
    return undefined;
  }
  const inputType = meta.input_type ?? null;
  const valueElement = meta.value_element ?? null;
  if (inputType === null && valueElement === null && (meta.operators?.length ?? 0) === 0) {
    return undefined;
  }
  return {
    label: meta.label,
    input_type: inputType ?? 'string',
    value_element: valueElement ?? 'text',
    operators: meta.operators ?? [],
  };
}

/**
 * Build a new node for a chosen "Add condition" option. `meta` is the metadata
 * for the chosen TYPE (not the parent); when it is null the kind is inferred
 * and safe defaults are used.
 */
export function buildNode(spec: string, meta: NodeMeta | null): ConditionNode {
  const { type, attribute } = splitTypeSpec(spec);
  const kind = meta ? meta.kind : inferKind(spec);

  if (kind === 'combine') {
    return {
      id: nextNodeId(),
      props: { type, aggregator: firstOptionValue(meta?.aggregators, 'all'), value: '1' },
      children: [],
    };
  }
  if (kind === 'related') {
    return {
      id: nextNodeId(),
      props: {
        type,
        relation: firstOptionValue(meta?.relations, ''),
        value: '1',
        match_mode: 'any',
      },
      children: [],
    };
  }

  const code = attribute ?? '';
  const attributeMeta = code !== '' ? meta?.attributes?.[code] : undefined;
  // Per-attribute operators win; the node-level leaf defaults cover a
  // free-attribute leaf (TriggerData) and an undescribed attribute.
  const operators = attributeMeta?.operators ?? meta?.operators;
  return {
    id: nextNodeId(),
    props: {
      type,
      attribute: code,
      operator: firstOptionValue(operators, '=='),
      value: '',
    },
    children: null,
  };
}

/**
 * Switch a leaf to another attribute: the operator falls back to the new
 * attribute's first offered operator when the current one is not in its set,
 * and the value resets (a select value from the old attribute is meaningless
 * under the new one). Mirrors what the stock widget does on attribute change.
 */
export function applyAttributeChange(
  node: ConditionNode,
  attribute: string,
  attributeMeta: AttributeMeta | undefined,
): ConditionNode {
  const operators = attributeMeta?.operators ?? [];
  const current = propString(node, 'operator');
  const keepOperator = operators.length === 0 || operators.some((o) => o.value === current);
  return {
    ...node,
    props: {
      ...node.props,
      attribute,
      operator: keepOperator ? current : firstOptionValue(operators, current),
      value: '',
    },
  };
}

/**
 * An option list that always contains the persisted value, so a value the
 * server did not offer (third-party attribute, stale relation code, a value
 * from a template) can never be silently dropped by rendering a select.
 */
export function optionsWithCurrent(options: MetaOption[], current: string): MetaOption[] {
  if (current === '' || options.some((o) => o.value === current)) {
    return options;
  }
  return [...options, { value: current, label: `${current} ${t('(not offered)')}` }];
}

/**
 * Partition an option list into render sections: consecutive rows sharing a
 * `group` become one <optgroup>, ungrouped rows render bare. Order is
 * preserved — the server already emits groups contiguously, and an appended
 * "(not offered)" row simply lands in its own bare tail section.
 */
export function optionSections(
  options: MetaOption[],
): Array<{ group: string | null; options: MetaOption[] }> {
  const sections: Array<{ group: string | null; options: MetaOption[] }> = [];
  for (const option of options) {
    const group = option.group ?? null;
    const tail = sections[sections.length - 1];
    if (tail && tail.group === group) {
      tail.options.push(option);
    } else {
      sections.push({ group, options: [option] });
    }
  }
  return sections;
}

/** Pretty-print a node for the read-only unknown-node view (a text node only). */
export function nodeToPrettyJson(node: ConditionNode): string {
  return JSON.stringify(serializeNode(node), null, 2);
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
