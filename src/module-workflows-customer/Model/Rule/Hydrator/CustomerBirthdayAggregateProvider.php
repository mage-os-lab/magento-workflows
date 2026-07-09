<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model\Rule\Hydrator;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;
use MageOS\WorkflowsCustomer\Model\BirthdayCalculator;

/**
 * Birthday aggregates for the customer condition root (CUS-C4), contributed
 * through AggregateProviderPool under entity type 'customer' — registered
 * ALONGSIDE the sales pack's order-history provider (the pool accepts multiple
 * providers per entity type; verified against AggregateProviderPool, which
 * keys entity_type => provider[] and merges every provider's aggregates).
 *
 *  - days_until_birthday: whole days to the customer's next birthday, 0 = today
 *    (computed from dob's next anniversary via the shared BirthdayCalculator,
 *    same math the detector uses).
 *  - birthday_month: the customer's birth month, 1-12.
 *
 * Both are ABSENT (not null-set) when the customer has no dob: absent
 * attributes only match the negative operators (fail-toward-false,
 * AbstractWorkflowCondition::validateAttribute()), consistent with the
 * order-history aggregates.
 *
 * Like the order-history provider, these are hydration-time only — never part
 * of a trigger snapshot — so conditions on them always classify as
 * needs_hydration and resolve in phase 2.
 */
class CustomerBirthdayAggregateProvider implements AggregateProviderInterface
{
    /**
     * Attribute code => [label, workflow input type]. Labels are raw strings
     * (__()-wrapped by the consuming condition root).
     */
    private const ATTRIBUTE_METADATA = [
        'days_until_birthday' => ['label' => 'Days Until Birthday', 'input_type' => 'numeric'],
        'birthday_month' => ['label' => 'Birthday Month', 'input_type' => 'select'],
    ];

    private const CUSTOMER_TABLE = 'customer_entity';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly BirthdayCalculator $birthdayCalculator
    ) {
    }

    /**
     * @return array<string, array{label: string, input_type: string}>
     */
    public function getAttributeMetadata(): array
    {
        return self::ATTRIBUTE_METADATA;
    }

    /**
     * @return array{days_until_birthday?: int, birthday_month?: int}
     */
    public function getAggregates(int $entityId): array
    {
        $dob = $this->fetchDob($entityId);
        if ($dob === null) {
            // No date of birth: both attributes absent (fail-toward-false).
            return [];
        }

        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return [
            'days_until_birthday' => $this->birthdayCalculator->daysUntilNextBirthday($dob, $today),
            'birthday_month' => $this->birthdayCalculator->birthdayMonth($dob),
        ];
    }

    private function fetchDob(int $entityId): ?\DateTimeImmutable
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::CUSTOMER_TABLE), ['dob'])
            ->where('entity_id = ?', $entityId)
            ->limit(1);

        $dobRaw = $connection->fetchOne($select);
        if (!is_string($dobRaw) || trim($dobRaw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($dobRaw, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
