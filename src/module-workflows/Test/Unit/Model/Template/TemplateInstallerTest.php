<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Template;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Import\ImportResult;
use MageOS\Workflows\Model\Import\WorkflowImporter;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\CompatibilityReason;
use MageOS\Workflows\Model\Template\CompatibilityResult;
use MageOS\Workflows\Model\Template\InstallProvenance;
use MageOS\Workflows\Model\Template\ParameterEngine;
use MageOS\Workflows\Model\Template\TemplateInstaller;
use MageOS\Workflows\Model\Template\TemplateInstallRequest;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;
use MageOS\Workflows\Model\Template\TemplateSummary;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationResult;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Installer orchestration + ordering (06 stage 3 done-when): the envelope is
 * lifted with the export format tag; provenance and secrets are written ONLY
 * after a successful save, so a failed save leaves zero writes (secrets
 * deferred); an incompatible template aborts before the importer is touched.
 */
class TemplateInstallerTest extends TestCase
{
    private const RAW = '{
        "format": "mageos-workflow-template/1",
        "template": {
            "code": "demo", "title": "Demo", "description": "d", "category": "C", "version": "1.2.0",
            "parameters": [{"key": "subject", "type": "string", "default": "Hi"}]
        },
        "workflow": {
            "name": "Demo %param.subject%",
            "entity_type": "sales_order",
            "trigger_type": "event",
            "trigger_ref": "sales.order.created",
            "definition": {"schema": 1, "entry": null, "steps": {}}
        }
    }';

    /** @var array<int, string> shared temporal log across the fakes */
    private array $sequence = [];

    private function source(string $raw = self::RAW): TemplateSourceInterface
    {
        return new class ($raw) implements TemplateSourceInterface {
            public function __construct(private readonly string $raw)
            {
            }

            public function list(): array
            {
                return [];
            }

            public function get(string $code): string
            {
                return $this->raw;
            }

            public function has(string $code): bool
            {
                return true;
            }
        };
    }

    private function compat(bool $compatible = true): CompatibilityChecker
    {
        return new class ($compatible) extends CompatibilityChecker {
            public function __construct(private readonly bool $compatible)
            {
            }

            public function check(TemplateSummary $summary, string $locale = 'en_US'): CompatibilityResult
            {
                return $this->compatible
                    ? new CompatibilityResult([])
                    : new CompatibilityResult([
                        new CompatibilityReason(CompatibilityReason::MISSING_ACTION, __('nope')),
                    ]);
            }
        };
    }

    private function importer(bool $fail = false): WorkflowImporter
    {
        $seq = &$this->sequence;
        return new class ($seq, $fail) extends WorkflowImporter {
            /** @var array<int, array{envelope: array, authMode: string, status: int}> */
            public array $calls = [];

            public function __construct(private array &$seq, private readonly bool $fail)
            {
            }

            public function import(array $envelope, string $authMode, int $status = 0): ImportResult
            {
                $this->calls[] = ['envelope' => $envelope, 'authMode' => $authMode, 'status' => $status];
                $this->seq[] = 'import';
                if ($this->fail) {
                    throw new LocalizedException(__('save exploded'));
                }
                return new ImportResult((new WorkflowStub())->setWorkflowId(42), new ValidationResult([]));
            }
        };
    }

    private function provenance(): InstallProvenance
    {
        $seq = &$this->sequence;
        return new class ($seq) extends InstallProvenance {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function __construct(private array &$seq)
            {
            }

            public function record(
                int $workflowId,
                string $templateCode,
                string $templateVersion,
                array $parameters,
                string $installedBy
            ): int {
                $this->records[] = compact('workflowId', 'templateCode', 'templateVersion', 'parameters', 'installedBy');
                $this->seq[] = 'provenance';
                return 100;
            }
        };
    }

    private function secrets(): SecretsProviderInterface
    {
        $seq = &$this->sequence;
        return new class ($seq) implements SecretsProviderInterface {
            /** @var array<string, string> */
            public array $created = [];

            public function __construct(private array &$seq)
            {
            }

            public function get(string $key): ?string
            {
                return null;
            }

            public function set(string $key, string $value): void
            {
                $this->created[$key] = $value;
                $this->seq[] = 'secret';
            }

            public function delete(string $key): void
            {
            }

            public function listKeys(): array
            {
                return array_keys($this->created);
            }
        };
    }

    private function installer(
        WorkflowImporter $importer,
        InstallProvenance $provenance,
        SecretsProviderInterface $secrets,
        bool $compatible = true,
        ?TemplateSourceInterface $source = null
    ): TemplateInstaller {
        return new TemplateInstaller(
            $source ?? $this->source(),
            $this->compat($compatible),
            new ParameterEngine(),
            $importer,
            $provenance,
            $secrets,
            new NullLogger()
        );
    }

    public function testSuccessfulInstallLiftsEnvelopeAndOrdersWrites(): void
    {
        $importer = $this->importer();
        $provenance = $this->provenance();
        $secrets = $this->secrets();

        $result = $this->installer($importer, $provenance, $secrets)->install(new TemplateInstallRequest(
            'demo',
            ['subject' => 'Sale'],
            WorkflowInterface::STATUS_SHADOW,
            ValidationContext::MODE_ADMIN_CONTEXT,
            'admin.jane',
            ['fraud_hmac' => 's3cr3t']
        ));

        // Envelope lifted with the export format tag + substituted body.
        $envelope = $importer->calls[0]['envelope'];
        $this->assertSame(WorkflowImporter::FORMAT, $envelope['format']);
        $this->assertSame('Demo Sale', $envelope['name']);
        $this->assertSame(WorkflowInterface::STATUS_SHADOW, $importer->calls[0]['status']);
        $this->assertSame(ValidationContext::MODE_ADMIN_CONTEXT, $importer->calls[0]['authMode']);

        // Provenance recorded once, snapshotting the params, crediting the installer.
        $this->assertCount(1, $provenance->records);
        $this->assertSame('demo', $provenance->records[0]['templateCode']);
        $this->assertSame('1.2.0', $provenance->records[0]['templateVersion']);
        $this->assertSame('admin.jane', $provenance->records[0]['installedBy']);

        // Secret created; and strictly AFTER the save + provenance.
        $this->assertSame(['fraud_hmac' => 's3cr3t'], $secrets->created);
        $this->assertSame(['import', 'provenance', 'secret'], $this->sequence);
        $this->assertSame(['fraud_hmac'], $result->getCreatedSecrets());
        $this->assertSame(42, (int) $result->getWorkflow()->getWorkflowId());
    }

    public function testFailedSaveLeavesZeroWrites(): void
    {
        $importer = $this->importer(fail: true);
        $provenance = $this->provenance();
        $secrets = $this->secrets();

        try {
            $this->installer($importer, $provenance, $secrets)->install(new TemplateInstallRequest(
                'demo',
                ['subject' => 'Sale'],
                WorkflowInterface::STATUS_DISABLED,
                ValidationContext::MODE_SYSTEM,
                'system',
                ['fraud_hmac' => 's3cr3t']
            ));
            $this->fail('A failed save must propagate.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('save exploded', $e->getMessage());
        }

        // No provenance, no secrets — the failed save left nothing behind.
        $this->assertSame([], $provenance->records);
        $this->assertSame([], $secrets->created);
        $this->assertSame(['import'], $this->sequence);
    }

    public function testIncompatibleTemplateAbortsBeforeImport(): void
    {
        $importer = $this->importer();
        $provenance = $this->provenance();
        $secrets = $this->secrets();

        try {
            $this->installer($importer, $provenance, $secrets, compatible: false)->install(
                new TemplateInstallRequest('demo', ['subject' => 'Sale'])
            );
            $this->fail('An incompatible template must not install.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('cannot be installed', $e->getMessage());
        }

        $this->assertSame([], $importer->calls);
        $this->assertSame([], $this->sequence);
    }

    public function testWrongFormatTagRejected(): void
    {
        $raw = str_replace('mageos-workflow-template/1', 'mageos-workflow-export/1', self::RAW);
        $installer = $this->installer($this->importer(), $this->provenance(), $this->secrets(), source: $this->source($raw));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unsupported template format');
        $installer->install(new TemplateInstallRequest('demo', ['subject' => 'Sale']));
    }
}
