<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Framework\ObjectManagerInterface;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\Combine;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use Psr\Log\LoggerInterface;

/**
 * Condition-tree metadata for authoring UIs (docs/06-conditions.md,
 * docs/11-admin-ui.md "Condition slide-out"). The condition CLASSES remain the
 * single authority: this provider only interrogates them through the native
 * `Magento\Rule` contract they already implement — the same calls the stock
 * rule widget makes when it renders a row — and projects the answers as JSON.
 * A third-party condition class registered in the DI pools therefore appears in
 * the UI with zero client changes.
 *
 * Interrogated per node type:
 *  - `getNewChildSelectOptions()` — the "Add condition" menu. Option VALUES keep
 *    their native format: a bare `FQCN`, or the `FQCN|attribute` composite the
 *    core widget uses for attribute leaves (see Order\Combine). The format is
 *    passed through untouched; the client splits on `|`.
 *  - `loadAttributeOptions()` + `getAttributeOption()` — attribute code => label.
 *  - per attribute (set on the instance first, exactly like the widget does):
 *    `getInputType()`, `getValueElementType()`, `getValueSelectOptions()`, and
 *    the operator list, which is the core composition of
 *    `getDefaultOperatorInputByType()` over `getDefaultOperatorOptions()`
 *    published as `AbstractCondition::getOperatorSelectOptions()` — going
 *    through that method rather than re-deriving the two maps here means a
 *    condition class that narrows its operators keeps doing so in the UI.
 *  - combines additionally: `loadAggregatorOptions()` (ALL/ANY) and
 *    `loadValueOptions()` (the node's own TRUE/FALSE — or FOUND/NOT FOUND,
 *    EXISTS/NOT EXISTS for the subtree combines that override it).
 *
 * Lazy BY NODE TYPE: the client asks for one node type at a time
 * (getMetaForNode()), so a tree is never walked recursively and the cost of a
 * root request stays bounded to the root's own children.
 *
 * SECURITY — the node type arrives from a request. Two gates, in this order,
 * before anything is instantiated:
 *  1. the FQCN must be REACHABLE from the entity root, i.e. transitively
 *     discoverable through new-child options starting at
 *     ConditionCombinePool::getCombineClass() (an array-key lookup that never
 *     touches the class loader with request input);
 *  2. it must be a subclass of Magento\Rule\Model\Condition\AbstractCondition.
 * Anything else throws \InvalidArgumentException (the endpoint answers 400).
 * The reachable set is computed per entity type, cycle-guarded (the order root
 * offers the customer subtree, whose subtree may offer its way back), and
 * memoized for the life of the instance — i.e. per request.
 */
class ConditionMetaProvider
{
    /**
     * Node kinds the client renders differently (combine row vs. leaf row vs.
     * relation row vs. free dot-path row)
     */
    public const KIND_COMBINE = 'combine';
    public const KIND_LEAF = 'leaf';
    public const KIND_RELATED = 'related';
    public const KIND_TRIGGER_DATA = 'trigger_data';

    /**
     * Reachable node types per entity type: class => label under which the
     * class was OFFERED by its parent combine (memoized per request).
     *
     * @var array<string, array<string, string>>
     */
    private array $reachableByEntityType = [];

    /**
     * The ObjectManager is used directly for the same reason the condition
     * pools do (see ConditionCombinePool): node class names are dynamic data
     * from the DI map and the child-option lists, mirroring how the core rule
     * module instantiates condition classes by name
     * (\Magento\Rule\Model\ConditionFactory). Every creation here is gated by
     * createNode()'s reachability + subclass validation.
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ConditionCombinePool $combinePool,
        private readonly RelationPool $relationPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Root combine class for the entity type — the default node type of a
     * fresh tree.
     *
     * @throws \InvalidArgumentException when no domain pack registered the entity type
     */
    public function getRootType(string $entityType): string
    {
        $class = $this->combinePool->getCombineClass($entityType);
        if ($class === null) {
            throw new \InvalidArgumentException(
                sprintf('No workflow condition combine registered for entity type "%s"', $entityType)
            );
        }
        return $class;
    }

    /**
     * Metadata for ONE node type of the entity's condition tree.
     *
     * Keys are stable: `type`, `kind`, `label`, `new_children`, `attributes`,
     * `relations`, `aggregators`, plus `value_options` (the node's own value
     * select), `match_modes` (related nodes) and the leaf defaults
     * `input_type` / `value_element` / `operators` — the only operator source
     * for a free-attribute leaf such as TriggerData, which by design has no
     * attribute option list.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when the node type is unreachable or is not a condition
     */
    public function getMetaForNode(string $entityType, string $nodeType): array
    {
        $node = $this->createNode($entityType, $nodeType);
        $kind = $this->classifyKind($node);
        $isCombine = $node instanceof Combine;
        $isLeaf = $kind === self::KIND_LEAF || $kind === self::KIND_TRIGGER_DATA;

        // Attribute-less leaf defaults; per-attribute values in `attributes`
        // take precedence once the merchant picks an attribute. Read BEFORE
        // attributes(), which walks the option list by setting `attribute` on
        // this very instance (the widget does the same and re-renders per row).
        $defaults = [
            'input_type' => $isLeaf ? (string) $node->getInputType() : null,
            'value_element' => $isLeaf ? (string) $node->getValueElementType() : null,
            'operators' => $isLeaf ? $this->operators($node) : [],
        ];

        return [
            'type' => $nodeType,
            'kind' => $kind,
            'label' => $this->labelFor($entityType, $nodeType),
            'new_children' => $isCombine ? $this->newChildren($node) : [],
            'attributes' => $isCombine ? [] : $this->attributes($node),
            'relations' => $kind === self::KIND_RELATED ? $this->relations($entityType) : [],
            'aggregators' => $isCombine ? $this->aggregators($node) : [],
            // The node's own value select: TRUE/FALSE for a plain combine,
            // FOUND/NOT FOUND for an items subtree, EXISTS/NOT EXISTS for a
            // relation node — always read from the node's own loadValueOptions().
            'value_options' => $isCombine ? $this->combineValueOptions($node) : [],
            'match_modes' => $kind === self::KIND_RELATED ? $this->matchModes() : [],
            'input_type' => $defaults['input_type'],
            'value_element' => $defaults['value_element'],
            'operators' => $defaults['operators'],
        ];
    }

    /**
     * combine / leaf / related / trigger_data. Order matters: the relation
     * combine IS a combine and the trigger-data leaf IS a leaf, so the two
     * specialized kinds are recognized first (subclasses included).
     */
    private function classifyKind(AbstractCondition $node): string
    {
        if ($node instanceof RelatedEntityCombine) {
            return self::KIND_RELATED;
        }
        if ($node instanceof TriggerData) {
            return self::KIND_TRIGGER_DATA;
        }
        return $node instanceof Combine ? self::KIND_COMBINE : self::KIND_LEAF;
    }

    /**
     * Validate-then-create. See the class docblock for why the reachability
     * lookup runs BEFORE any class-loader contact with the request value.
     *
     * @throws \InvalidArgumentException
     */
    private function createNode(string $entityType, string $nodeType): AbstractCondition
    {
        if (!isset($this->getReachable($entityType)[$nodeType])) {
            throw new \InvalidArgumentException(sprintf(
                'Condition type "%s" is not reachable from the "%s" condition root',
                $nodeType,
                $entityType
            ));
        }
        if (!is_a($nodeType, AbstractCondition::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Condition type "%s" must extend %s',
                $nodeType,
                AbstractCondition::class
            ));
        }
        $node = $this->objectManager->create($nodeType);
        if (!$node instanceof AbstractCondition) {
            throw new \InvalidArgumentException(sprintf(
                'Condition type "%s" must extend %s',
                $nodeType,
                AbstractCondition::class
            ));
        }
        return $node;
    }

    /**
     * Transitive closure of the entity root's new-child options: class => the
     * label it was offered under. Cycle-guarded through the visited set (the
     * order root offers the customer subtree, the relation combine offers
     * itself, …) and memoized per entity type for the life of the request.
     *
     * Only combines are instantiated during the walk — a leaf offers no
     * children, so its class name is recorded without ever being constructed
     * (`is_a(..., true)` answers "is this a combine?" from the class name).
     *
     * @return array<string, string>
     */
    private function getReachable(string $entityType): array
    {
        if (isset($this->reachableByEntityType[$entityType])) {
            return $this->reachableByEntityType[$entityType];
        }

        $root = $this->getRootType($entityType);
        $labels = [$root => (string) __('Conditions')];
        $visited = [];
        $queue = [$root];

        while ($queue !== []) {
            $class = (string) array_shift($queue);
            if (isset($visited[$class])) {
                continue;
            }
            $visited[$class] = true;
            if (!is_a($class, Combine::class, true)) {
                continue;
            }
            $combine = $this->createForWalk($class);
            if ($combine === null) {
                continue;
            }
            foreach ($this->childOptionGroups($combine) as $group) {
                foreach ($group['options'] as $option) {
                    $childClass = $this->classPart($option['value']);
                    if ($childClass === '') {
                        continue;
                    }
                    // A real optgroup names the CLASS ("Order Attribute") while
                    // its options name attributes; an ungrouped option names the
                    // class itself ("Order Items"). First offer wins.
                    $labels[$childClass] ??= $group['grouped'] ? $group['label'] : $option['label'];
                    if (!isset($visited[$childClass])) {
                        $queue[] = $childClass;
                    }
                }
            }
        }

        return $this->reachableByEntityType[$entityType] = $labels;
    }

    /**
     * Instantiate a combine for the reachability walk. A class that cannot be
     * constructed (broken third-party DI wiring) must not take the whole
     * editor down with it: its subtree is skipped and the class stays
     * reachable, so a direct request for it surfaces the real failure.
     */
    private function createForWalk(string $class): ?Combine
    {
        try {
            $combine = $this->objectManager->create($class);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Workflow condition class "%s" could not be instantiated for condition metadata: %s',
                $class,
                $e->getMessage()
            ));
            return null;
        }
        return $combine instanceof Combine ? $combine : null;
    }

    /**
     * The "Add condition" menu as the client renders it: a list of groups.
     *
     * @return array<int, array{label: string, options: array<int, array{value: string, label: string}>}>
     */
    private function newChildren(Combine $combine): array
    {
        $groups = [];
        foreach ($this->childOptionGroups($combine) as $group) {
            $groups[] = ['label' => $group['label'], 'options' => $group['options']];
        }
        return $groups;
    }

    /**
     * Normalize the native new-child option list into groups.
     *
     * The native shape mixes two entry forms (see Order\Combine): a plain
     * option `['value' => FQCN, 'label' => …]` and an optgroup
     * `['label' => …, 'value' => [option, …]]` whose options carry the
     * `FQCN|attribute` composites. The plain options are collected into one
     * leading group so every entry the client receives has the same shape;
     * empty optgroups (a domain pack whose attribute list came back empty) and
     * the core "please choose a condition" placeholder (empty value) are
     * dropped.
     *
     * @return array<int, array{label: string, grouped: bool, options: array<int, array{value: string, label: string}>}>
     */
    private function childOptionGroups(Combine $combine): array
    {
        $raw = $combine->getNewChildSelectOptions();
        $ungrouped = [];
        $groups = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $label = (string) ($entry['label'] ?? '');
            $value = $entry['value'] ?? null;
            if (is_array($value)) {
                $options = $this->childOptions($value);
                if ($options !== []) {
                    $groups[] = ['label' => $label, 'grouped' => true, 'options' => $options];
                }
                continue;
            }
            $options = $this->childOptions([$entry]);
            if ($options !== []) {
                $ungrouped[] = $options[0];
            }
        }

        $normalized = [];
        if ($ungrouped !== []) {
            $normalized[] = ['label' => (string) __('Condition Types'), 'grouped' => false, 'options' => $ungrouped];
        }
        return array_merge($normalized, $groups);
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<int, array{value: string, label: string}>
     */
    private function childOptions(array $raw): array
    {
        $options = [];
        foreach ($raw as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = $option['value'] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $options[] = ['value' => $value, 'label' => (string) ($option['label'] ?? '')];
        }
        return $options;
    }

    /**
     * The class part of a child-option value: `FQCN` or `FQCN|attribute`.
     */
    private function classPart(string $value): string
    {
        $separator = strpos($value, '|');
        return $separator === false ? $value : substr($value, 0, $separator);
    }

    /**
     * Per-attribute metadata for a leaf, asked of the leaf exactly the way the
     * stock widget asks a rendered row: set the attribute, then read the input
     * type, value element, operators and inline value options.
     *
     * @return array<string, array<string, mixed>>
     */
    private function attributes(AbstractCondition $leaf): array
    {
        $options = $leaf->loadAttributeOptions()->getAttributeOption();
        if (!is_array($options)) {
            return [];
        }
        $attributes = [];
        foreach ($options as $code => $label) {
            $code = (string) $code;
            $leaf->setAttribute($code);
            // Leaves memoize their resolved option list per instance (see
            // Order\Attribute::getValueSelectOptions) — clear it so attribute
            // N+1 is not served attribute N's options.
            $leaf->unsetData('value_select_options');
            $valueElement = (string) $leaf->getValueElementType();
            $attributes[$code] = [
                'label' => (string) $label,
                'input_type' => (string) $leaf->getInputType(),
                'value_element' => $valueElement,
                'operators' => $this->operators($leaf),
                // Served inline when the leaf has a bounded list; an unbounded
                // source (search-typed pickers) legitimately returns nothing.
                'value_options' => $this->valueOptions($leaf, $valueElement),
            ];
        }
        return $attributes;
    }

    /**
     * Inline value options for one attribute — but only for the value elements
     * that actually render a choice list.
     *
     * Resolving them is the one genuinely expensive call on this path: the
     * catalog leaf offers every promo-rule-usable / searchable product
     * attribute, and each SELECT among them loads its EAV source. A text or
     * date element has no list to render, so asking for one would buy a query
     * per attribute and throw the answer away (the stock widget likewise only
     * resolves options for the row it is rendering).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function valueOptions(AbstractCondition $leaf, string $valueElement): array
    {
        if ($valueElement === 'text' || $valueElement === 'date') {
            return [];
        }
        return $this->options($leaf->getValueSelectOptions());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function operators(AbstractCondition $condition): array
    {
        return $this->options($condition->getOperatorSelectOptions());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function aggregators(Combine $combine): array
    {
        return $this->options($combine->loadAggregatorOptions()->getAggregatorSelectOptions());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function combineValueOptions(Combine $combine): array
    {
        return $this->options($combine->loadValueOptions()->getValueSelectOptions());
    }

    /**
     * Relations sourced FROM this entity type, keyed by the pool code the
     * RelatedEntity combine stores in `relation`. Cardinality travels with the
     * row because the match mode only means something for a to-many relation.
     *
     * @return array<int, array{value: string, label: string, cardinality: string}>
     */
    private function relations(string $entityType): array
    {
        $rows = [];
        foreach ($this->relationPool->getBySourceEntityType($entityType) as $code => $relation) {
            /** @var RelationInterface $relation */
            $rows[] = [
                'value' => (string) $code,
                'label' => (string) $relation->getLabel(),
                'cardinality' => $relation->getCardinality(),
            ];
        }
        return $rows;
    }

    /**
     * ANY / ALL / NONE over a to-many relation (docs/06-conditions.md
     * "Related-entity conditions").
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function matchModes(): array
    {
        return [
            ['value' => RelatedEntityCombine::MATCH_ANY, 'label' => (string) __('ANY')],
            ['value' => RelatedEntityCombine::MATCH_ALL, 'label' => (string) __('ALL')],
            ['value' => RelatedEntityCombine::MATCH_NONE, 'label' => (string) __('NONE')],
        ];
    }

    /**
     * Label a node was offered under by its parent combine; the raw class name
     * is the last resort so an unlabeled third-party node still renders.
     */
    private function labelFor(string $entityType, string $nodeType): string
    {
        $label = $this->getReachable($entityType)[$nodeType] ?? '';
        return $label !== '' ? $label : $nodeType;
    }

    /**
     * Flatten a native option list to `[{value, label, group?}]`. Nested
     * optgroups (payment/shipping Allmethods group by provider/carrier,
     * Store::getStoreValuesForForm() nests website / group / store) keep their
     * OUTERMOST heading as a `group` key on each selectable leaf: dropping the
     * heading loses no selectable value, but it loses meaning — "Ground" is
     * ambiguous without "UPS", and a store view means little without its
     * website. Labels are trimmed: the leading-space indentation some core
     * sources emit is a flat-<select> visual hack that HTML collapses anyway;
     * grouping replaces it.
     *
     * @param mixed $raw
     * @param string|null $group heading inherited from an enclosing optgroup
     * @return array<int, array{value: string, label: string, group?: string}>
     */
    private function options(mixed $raw, ?string $group = null): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        foreach ($raw as $key => $option) {
            if (!is_array($option)) {
                // A plain code => label hash is a legal option source too.
                $rows[] = $this->optionRow((string) $key, (string) $option, $group);
                continue;
            }
            $value = $option['value'] ?? null;
            if (is_array($value)) {
                // The outermost heading wins for deeper nesting (website >
                // store group > store view reads best as one website group).
                $heading = trim((string) ($option['label'] ?? ''));
                $rows = array_merge(
                    $rows,
                    $this->options($value, $group ?? ($heading !== '' ? $heading : null))
                );
                continue;
            }
            if ($value === null || is_object($value)) {
                continue;
            }
            $rows[] = $this->optionRow(
                is_bool($value) ? (string) (int) $value : (string) $value,
                (string) ($option['label'] ?? ''),
                $group
            );
        }
        return $rows;
    }

    /**
     * @return array{value: string, label: string, group?: string}
     */
    private function optionRow(string $value, string $label, ?string $group): array
    {
        $row = ['value' => $value, 'label' => trim($label)];
        if ($group !== null && $group !== '') {
            $row['group'] = $group;
        }
        return $row;
    }
}
