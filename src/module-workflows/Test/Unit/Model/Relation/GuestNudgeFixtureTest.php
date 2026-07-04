<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Relation;

use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\Check\RelationConditionsCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use PHPUnit\Framework\TestCase;

/**
 * Conformance for the flagship guest-nudge workflow
 * (spec/fixtures/guest-nudge-register-invite.json): a guest order whose email
 * has NO customer account, waits a grace period, then (revalidated) sends a
 * registration invite only if the email STILL has no account. It exercises the
 * RelatedEntity combine in conditions_serialized without changing the
 * definition format — no schema bump.
 */
class GuestNudgeFixtureTest extends TestCase
{
    private function envelope(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 6) . '/spec/fixtures/guest-nudge-register-invite.json'),
            true
        );
    }

    public function testDefinitionParsesAndIsUnchangedSchema(): void
    {
        $envelope = $this->envelope();
        $definition = Definition::fromArray($envelope['definition']);

        // No schema bump — the relation lives entirely in conditions_serialized.
        $this->assertSame(1, $definition->getSchemaVersion());
        $this->assertSame('wait_grace', $definition->getEntryKey());
    }

    public function testRootAndBranchRelationConditionsPassTheSaveRule(): void
    {
        $envelope = $this->envelope();
        $subject = new ValidationSubject(
            (string) json_encode($envelope['definition']),
            $envelope['conditions_serialized']
        );

        $messages = (new RelationConditionsCheck())->check(
            $subject,
            new ValidationContext(ValidationContext::MODE_ADMIN_CONTEXT, ValidationContext::KIND_STANDARD, false)
        );

        // Both NOT-EXISTS nodes (root guest check + revalidated branch) are
        // childless, so the hard save rule is satisfied.
        $this->assertSame([], $messages);
    }

    public function testRootConditionCarriesTheGuestRelationNode(): void
    {
        $tree = json_decode($this->envelope()['conditions_serialized'], true);
        $relationNode = $tree['conditions'][1];

        $this->assertSame(
            'MageOS\\Workflows\\Model\\Rule\\Condition\\RelatedEntity\\Combine',
            $relationNode['type']
        );
        $this->assertSame('order.customer_by_email', $relationNode['relation']);
        $this->assertSame('0', $relationNode['value'], 'NOT EXISTS');
    }
}
