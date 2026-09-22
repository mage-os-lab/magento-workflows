<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Import;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Workflows\Model\Import\WorkflowImporter;
use PHPUnit\Framework\TestCase;

/**
 * The structural envelope contract of the shared import path (F3).
 * Persistence-side behavior rides the repository plugin (F2) and the
 * gated integration suite.
 */
class WorkflowImporterEnvelopeTest extends TestCase
{
    private function validEnvelope(): array
    {
        return [
            'format' => WorkflowImporter::FORMAT,
            'name' => 'Fixture workflow',
            'entity_type' => 'sales_order',
            'trigger_type' => 'event',
            'trigger_ref' => 'sales.order.created',
            'conditions_serialized' => null,
            'definition' => ['schema' => 1, 'steps' => [], 'entry' => null],
            'loop_guard_depth' => 1,
        ];
    }

    public function testValidEnvelopePasses(): void
    {
        WorkflowImporter::assertEnvelope($this->validEnvelope());
        $this->assertTrue(true);
    }

    public function testWrongFormatTagRejected(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['format'] = 'mageos-workflow-export/999';

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unsupported export format');

        WorkflowImporter::assertEnvelope($envelope);
    }

    public function testMissingFormatRejected(): void
    {
        $envelope = $this->validEnvelope();
        unset($envelope['format']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unsupported export format');

        WorkflowImporter::assertEnvelope($envelope);
    }

    public function testNonObjectDefinitionRejected(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['definition'] = '{"schema":1}';

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"definition" must be an object');

        WorkflowImporter::assertEnvelope($envelope);
    }

    public function testMissingRequiredFieldRejected(): void
    {
        foreach (['name', 'entity_type', 'trigger_type', 'trigger_ref'] as $field) {
            $envelope = $this->validEnvelope();
            $envelope[$field] = '';
            try {
                WorkflowImporter::assertEnvelope($envelope);
                $this->fail(sprintf('Empty "%s" should have been rejected', $field));
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('missing required field', $e->getMessage());
            }
        }
    }

    public function testNonStringConditionsRejected(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['conditions_serialized'] = ['type' => 'combine'];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"conditions_serialized" must be a string or null');

        WorkflowImporter::assertEnvelope($envelope);
    }

    public function testBothSpecFixturesPassTheEnvelopeContract(): void
    {
        foreach ([
            'multi-region-order-routing',
            'canvas-ui-round-trip',
            'high-value-order-fraud-check',
            'guest-nudge-register-invite',
        ] as $name) {
            $envelope = json_decode(
                (string) file_get_contents(
                    __DIR__ . '/../../../../../../spec/fixtures/' . $name . '.json'
                ),
                true
            );
            WorkflowImporter::assertEnvelope($envelope);
        }
        $this->assertTrue(true);
    }
}
