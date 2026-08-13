import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  addChildNode,
  applyAttributeChange,
  buildNode,
  collectNodeTypes,
  emptyTree,
  findNode,
  inferKind,
  isEmptyTree,
  nodeDefaultAttributeMeta,
  nodeType,
  optionSections,
  optionsWithCurrent,
  orFallback,
  parseConditionTree,
  propString,
  removeNode,
  serializeTree,
  setNodeProp,
  splitTypeSpec,
  type NodeMeta,
} from '../src/conditionTree';

// test -> app -> module-workflows-canvas -> src
const SRC_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const TEMPLATES_DIR = resolve(SRC_DIR, 'module-workflows-templates/templates');
const SPEC_FIXTURES_DIR = resolve(SRC_DIR, '../spec/fixtures');

/** Every non-empty conditions_serialized string anywhere in a fixture tree. */
function harvestTrees(dir: string): { file: string; path: string; tree: string }[] {
  const found: { file: string; path: string; tree: string }[] = [];
  for (const file of readdirSync(dir).filter((f) => f.endsWith('.json'))) {
    const doc = JSON.parse(readFileSync(`${dir}/${file}`, 'utf8')) as unknown;
    const walk = (value: unknown, path: string): void => {
      if (Array.isArray(value)) {
        value.forEach((v, i) => walk(v, `${path}/${i}`));
        return;
      }
      if (value === null || typeof value !== 'object') {
        return;
      }
      for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
        if (
          (key === 'conditions_serialized' || key === 'conditionsSerialized')
          && typeof child === 'string'
          && child.trim() !== ''
        ) {
          found.push({ file, path: `${path}/${key}`, tree: child });
          continue;
        }
        walk(child, `${path}/${key}`);
      }
    };
    walk(doc, '');
  }
  return found;
}

const realTrees = [...harvestTrees(TEMPLATES_DIR), ...harvestTrees(SPEC_FIXTURES_DIR)];

describe('conditionTree — round-trips every real condition tree in the repo', () => {
  it('discovers the fixture trees (guards against an empty glob)', () => {
    expect(realTrees.length).toBeGreaterThan(5);
    // FQCN-typed trees (the seed packs) AND legacy short-code trees are covered.
    expect(realTrees.some((t) => t.tree.includes('MageOS\\\\Workflows\\\\Model\\\\Rule'))).toBe(true);
    expect(realTrees.some((t) => t.tree.includes('"type":"combine"'))).toBe(true);
  });

  for (const { file, path, tree } of realTrees) {
    it(`parse -> serialize is lossless for ${file}${path}`, () => {
      const parsed = parseConditionTree(tree);
      expect(parsed.error).toBeNull();
      const out = serializeTree(parsed.root);
      expect(out).not.toBeNull();
      // Content identity...
      expect(JSON.parse(out as string)).toEqual(JSON.parse(tree));
      // ...and byte identity modulo the source's own whitespace: no key is
      // reordered, added or dropped (`conditions` stays last, as asArray emits).
      expect(out).toBe(JSON.stringify(JSON.parse(tree)));
    });
  }
});

describe('conditionTree — parsing', () => {
  it('treats an empty/blank value as the always-run tree, with no error', () => {
    for (const raw of [null, undefined, '', '   ']) {
      const parsed = parseConditionTree(raw);
      expect(parsed.root).toBeNull();
      expect(parsed.error).toBeNull();
    }
  });

  it('reports undecodable JSON without discarding anything', () => {
    const parsed = parseConditionTree('{"type":');
    expect(parsed.root).toBeNull();
    expect(parsed.error).toMatch(/valid JSON/);
  });

  it('reports a non-object tree', () => {
    expect(parseConditionTree('[1,2]').error).toMatch(/JSON object/);
    expect(parseConditionTree('"x"').error).toMatch(/JSON object/);
  });

  it('parses a bare LEAF root (templates ship these — not every root is a combine)', () => {
    const tree =
      '{"type":"MageOS\\\\Workflows\\\\Model\\\\Rule\\\\Condition\\\\TriggerData",'
      + '"attribute":"to_status","operator":"==","value":"processing"}';
    const { root } = parseConditionTree(tree);
    expect(root).not.toBeNull();
    // No `conditions` key in the source -> no children container is invented.
    expect(root?.children).toBeNull();
    expect(serializeTree(root)).toBe(JSON.stringify(JSON.parse(tree)));
  });
});

describe('conditionTree — preserves what it does not understand', () => {
  const thirdParty = JSON.stringify({
    type: 'Vendor\\Pack\\Model\\Rule\\Condition\\Combine',
    aggregator: 'any',
    value: '1',
    vendor_extra: { weight: 3, flags: ['a', 'b'] },
    conditions: [
      {
        type: 'Vendor\\Pack\\Model\\Rule\\Condition\\Weird',
        attribute: 'thing',
        operator: '{}',
        value: 'x',
        unknown_key: 'keep me',
      },
      'a bare string that is not a condition object',
    ],
  });

  it('round-trips an unknown node type, its unknown keys and non-object entries', () => {
    const { root, error } = parseConditionTree(thirdParty);
    expect(error).toBeNull();
    expect(serializeTree(root)).toBe(JSON.stringify(JSON.parse(thirdParty)));
  });

  it('keeps unknown siblings intact while a known sibling is edited', () => {
    const { root } = parseConditionTree(thirdParty);
    const leafId = root!.children![0].id;
    const edited = setNodeProp(root!, leafId, 'value', 'y');
    const out = JSON.parse(serializeTree(edited) as string) as Record<string, unknown>;
    const children = out.conditions as unknown[];
    expect((children[0] as Record<string, unknown>).unknown_key).toBe('keep me');
    expect((children[0] as Record<string, unknown>).value).toBe('y');
    expect(children[1]).toBe('a bare string that is not a condition object');
    expect(out.vendor_extra).toEqual({ weight: 3, flags: ['a', 'b'] });
  });

  it('reports no kind for an unfetched type, so the row renders read-only', () => {
    const { root } = parseConditionTree(thirdParty);
    expect(collectNodeTypes(root)).toEqual([
      'Vendor\\Pack\\Model\\Rule\\Condition\\Combine',
      'Vendor\\Pack\\Model\\Rule\\Condition\\Weird',
    ]);
  });
});

describe('conditionTree — empty tree serializes to null ("always run")', () => {
  it('null root -> null', () => {
    expect(serializeTree(null)).toBeNull();
    expect(isEmptyTree(null)).toBe(true);
  });

  it('an untouched synthetic root -> null (opening the editor writes nothing)', () => {
    const root = emptyTree('MageOS\\Workflows\\Model\\Rule\\Condition\\Order\\Combine');
    expect(isEmptyTree(root)).toBe(true);
    expect(serializeTree(root)).toBeNull();
  });

  it('a synthetic root with a child becomes real', () => {
    const root = emptyTree('MageOS\\Workflows\\Model\\Rule\\Condition\\Order\\Combine');
    const withChild = addChildNode(
      root,
      root.id,
      buildNode('MageOS\\Workflows\\Model\\Rule\\Condition\\Order\\Attribute|status', null),
    );
    expect(isEmptyTree(withChild)).toBe(false);
    const out = JSON.parse(serializeTree(withChild) as string) as Record<string, unknown>;
    expect(out.type).toBe('MageOS\\Workflows\\Model\\Rule\\Condition\\Order\\Combine');
    expect(out.aggregator).toBe('all');
    expect((out.conditions as unknown[]).length).toBe(1);
  });

  it('a PARSED empty combine is NOT dropped (a stored tree is never invented away)', () => {
    const stored = '{"type":"combine","aggregator":"all","value":"1","conditions":[]}';
    const { root } = parseConditionTree(stored);
    expect(isEmptyTree(root)).toBe(false);
    expect(serializeTree(root)).toBe(stored);
  });

  it('removing the root clears the tree', () => {
    const { root } = parseConditionTree('{"type":"combine","conditions":[]}');
    expect(serializeTree(removeNode(root, root!.id))).toBeNull();
  });
});

describe('conditionTree — mutations', () => {
  const tree = JSON.stringify({
    type: 'A\\Combine',
    aggregator: 'all',
    value: '1',
    conditions: [
      { type: 'A\\Attribute', attribute: 'status', operator: '==', value: 'pending' },
      {
        type: 'A\\Combine',
        aggregator: 'any',
        value: '1',
        conditions: [{ type: 'A\\Attribute', attribute: 'total', operator: '>=', value: '5' }],
      },
    ],
  });

  it('sets a prop on a nested node without touching its siblings', () => {
    const { root } = parseConditionTree(tree);
    const nested = root!.children![1].children![0];
    const next = setNodeProp(root!, nested.id, 'value', '10');
    expect(propString(findNode(next, nested.id)!, 'value')).toBe('10');
    // Sibling identity is preserved (no needless re-render / remount).
    expect(next.children![0]).toBe(root!.children![0]);
  });

  it('removes a nested node and leaves the rest alone', () => {
    const { root } = parseConditionTree(tree);
    const target = root!.children![0].id;
    const next = removeNode(root!, target)!;
    expect(next.children).toHaveLength(1);
    expect(nodeType(next.children![0])).toBe('A\\Combine');
  });

  it('appends a child to the addressed combine only', () => {
    const { root } = parseConditionTree(tree);
    const nestedCombine = root!.children![1];
    const next = addChildNode(root!, nestedCombine.id, buildNode('A\\Attribute|sku', null));
    expect(next.children![1].children).toHaveLength(2);
    expect(next.children).toHaveLength(2);
  });
});

describe('conditionTree — new-child options (the FQCN|attribute composite)', () => {
  it('splits on the first pipe only', () => {
    expect(splitTypeSpec('A\\B\\C')).toEqual({ type: 'A\\B\\C', attribute: null });
    expect(splitTypeSpec('A\\B\\C|grand_total')).toEqual({ type: 'A\\B\\C', attribute: 'grand_total' });
  });

  it('builds each kind from the server metadata', () => {
    const combineMeta: NodeMeta = {
      type: 'A\\Combine',
      kind: 'combine',
      label: 'Conditions Combination',
      aggregators: [{ value: 'all', label: 'ALL' }, { value: 'any', label: 'ANY' }],
    };
    expect(buildNode('A\\Combine', combineMeta).children).toEqual([]);
    expect(buildNode('A\\Combine', combineMeta).props).toEqual({
      type: 'A\\Combine',
      aggregator: 'all',
      value: '1',
    });

    const relatedMeta: NodeMeta = {
      type: 'A\\RelatedEntity\\Combine',
      kind: 'related',
      label: 'Related Entity',
      relations: [{ value: 'order.customer_by_email', label: 'Customer by email' }],
    };
    expect(buildNode('A\\RelatedEntity\\Combine', relatedMeta).props).toEqual({
      type: 'A\\RelatedEntity\\Combine',
      relation: 'order.customer_by_email',
      value: '1',
      match_mode: 'any',
    });

    const leafMeta: NodeMeta = {
      type: 'A\\Attribute',
      kind: 'leaf',
      label: 'Order Attribute',
      attributes: {
        status: { label: 'Status', input_type: 'select', value_element: 'select', operators: [{ value: '()', label: 'is one of' }] },
      },
    };
    const leaf = buildNode('A\\Attribute|status', leafMeta);
    expect(leaf.children).toBeNull();
    expect(leaf.props).toEqual({ type: 'A\\Attribute', attribute: 'status', operator: '()', value: '' });
  });

  it('falls back to an inferred kind when the metadata fetch failed', () => {
    expect(inferKind('X\\Attribute|code')).toBe('leaf');
    expect(inferKind('X\\Condition\\RelatedEntity\\Combine')).toBe('related');
    expect(inferKind('X\\Condition\\TriggerData')).toBe('trigger_data');
    expect(inferKind('X\\Condition\\Order\\Combine')).toBe('combine');
    expect(buildNode('X\\Condition\\Order\\Combine', null).children).toEqual([]);
    expect(buildNode('X\\Attribute|code', null).props.operator).toBe('==');
  });
});

describe('conditionTree — attribute switch and select safety', () => {
  it('resets an operator the new attribute does not offer, and clears the value', () => {
    const { root } = parseConditionTree(
      '{"type":"A\\\\Attribute","attribute":"status","operator":"()","value":"a,b"}',
    );
    const next = applyAttributeChange(root!, 'created_at', {
      label: 'Created',
      input_type: 'date',
      value_element: 'date',
      operators: [{ value: '<=', label: 'equals or less than' }],
    });
    expect(next.props).toEqual({
      type: 'A\\Attribute',
      attribute: 'created_at',
      operator: '<=',
      value: '',
    });
  });

  it('keeps a still-valid operator', () => {
    const { root } = parseConditionTree(
      '{"type":"A\\\\Attribute","attribute":"status","operator":"==","value":"x"}',
    );
    const next = applyAttributeChange(root!, 'sku', {
      label: 'SKU',
      input_type: 'string',
      value_element: 'text',
      operators: [{ value: '==', label: 'is' }, { value: '{}', label: 'contains' }],
    });
    expect(next.props.operator).toBe('==');
  });

  it('uses the node-level leaf defaults for a free-attribute leaf (TriggerData)', () => {
    // The endpoint serves input_type/value_element/operators at NODE level for
    // leaves; TriggerData has no attribute option list at all.
    const triggerMeta: NodeMeta = {
      type: 'MageOS\\Workflows\\Model\\Rule\\Condition\\TriggerData',
      kind: 'trigger_data',
      label: 'Trigger Data (advanced)',
      attributes: {},
      input_type: 'string',
      value_element: 'text',
      operators: [{ value: '==', label: 'is' }, { value: '{}', label: 'contains' }],
    };
    expect(nodeDefaultAttributeMeta(triggerMeta)).toEqual({
      label: 'Trigger Data (advanced)',
      input_type: 'string',
      value_element: 'text',
      operators: triggerMeta.operators,
    });
    // ...and a new node picks its operator from them.
    expect(buildNode(triggerMeta.type, triggerMeta).props.operator).toBe('==');
  });

  it('reports no leaf defaults for a combine (the endpoint nulls them there)', () => {
    const combineMeta: NodeMeta = {
      type: 'A\\Combine',
      kind: 'combine',
      label: 'Conditions Combination',
      input_type: null,
      value_element: null,
      operators: [],
    };
    expect(nodeDefaultAttributeMeta(combineMeta)).toBeUndefined();
    expect(nodeDefaultAttributeMeta(null)).toBeUndefined();
  });

  it('falls back only when the server sent an EMPTY list (it sends [] not absent)', () => {
    const fallback = [{ value: 'all', label: 'ALL' }];
    const served = [{ value: 'any', label: 'ANY' }];
    expect(orFallback([], fallback)).toBe(fallback);
    expect(orFallback(undefined, fallback)).toBe(fallback);
    expect(orFallback(served, fallback)).toBe(served);
  });

  it('keeps an unoffered persisted value selectable so rendering cannot drop it', () => {
    const options = [{ value: 'a', label: 'A' }];
    expect(optionsWithCurrent(options, 'a')).toBe(options);
    expect(optionsWithCurrent(options, '')).toBe(options);
    expect(optionsWithCurrent(options, '%param.vip_group_id%')).toEqual([
      { value: 'a', label: 'A' },
      { value: '%param.vip_group_id%', label: '%param.vip_group_id% (not offered)' },
    ]);
  });
});

describe('optionSections — optgroup partitioning for grouped value options', () => {
  it('groups consecutive rows by their group key and keeps bare rows bare', () => {
    const sections = optionSections([
      { value: 'ups_GND', label: 'Ground', group: 'UPS' },
      { value: 'ups_1DA', label: 'Next Day Air', group: 'UPS' },
      { value: 'flatrate_flatrate', label: 'Fixed', group: 'Flat Rate' },
      { value: 'pickup', label: 'Store Pickup' },
    ]);
    expect(sections).toEqual([
      { group: 'UPS', options: [
        { value: 'ups_GND', label: 'Ground', group: 'UPS' },
        { value: 'ups_1DA', label: 'Next Day Air', group: 'UPS' },
      ] },
      { group: 'Flat Rate', options: [{ value: 'flatrate_flatrate', label: 'Fixed', group: 'Flat Rate' }] },
      { group: null, options: [{ value: 'pickup', label: 'Store Pickup' }] },
    ]);
  });

  it('an ungrouped list yields one bare section (no optgroup rendered)', () => {
    const sections = optionSections([
      { value: 'pending', label: 'Pending' },
      { value: 'complete', label: 'Complete' },
    ]);
    expect(sections).toHaveLength(1);
    expect(sections[0].group).toBeNull();
  });
});
