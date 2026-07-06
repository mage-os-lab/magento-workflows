<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Template;

use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use MageOS\Workflows\Model\Template\BundledTemplateSource;
use MageOS\Workflows\Model\Template\TemplateNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Bundled source scanning: pack-module templates indexed by code, defensive
 * skipping of files with no template node, and raw-JSON retrieval by code.
 */
class BundledTemplateSourceTest extends TestCase
{
    private function source(): BundledTemplateSource
    {
        $base = __DIR__ . '/_files/pack-a';
        $reader = new class ($base) extends ModuleDirReader {
            public function __construct(private readonly string $base)
            {
            }

            public function getModuleDir($type, $moduleName)
            {
                return $moduleName === 'Test_PackA' ? $this->base : '';
            }
        };

        return new BundledTemplateSource($reader, ['Test_PackA']);
    }

    public function testListsEveryValidTemplateSkippingMalformed(): void
    {
        $summaries = $this->source()->list();
        $codes = array_map(static fn ($s) => $s->getCode(), $summaries);
        sort($codes);

        // notemplate.json has no `template` node and is skipped.
        $this->assertSame(['alpha-template', 'beta-template'], $codes);
    }

    public function testSummaryCarriesCategoryVersionAndLocalizedTitle(): void
    {
        $byCode = [];
        foreach ($this->source()->list() as $summary) {
            $byCode[$summary->getCode()] = $summary;
        }

        $this->assertSame('Testing', $byCode['alpha-template']->getCategory());
        $this->assertSame('1.0.0', $byCode['alpha-template']->getVersion());
        $this->assertSame('Beta DE', $byCode['beta-template']->getTitle('de_DE'));
        $this->assertSame('Beta', $byCode['beta-template']->getTitle('en_US'));
        $this->assertSame('notify.email', $byCode['alpha-template']->getRequires()['actions'][0]);
    }

    public function testHasAndGetByCode(): void
    {
        $source = $this->source();
        $this->assertTrue($source->has('alpha-template'));
        $this->assertFalse($source->has('missing'));

        $raw = json_decode($source->get('alpha-template'), true);
        $this->assertSame('mageos-workflow-template/1', $raw['format']);
        $this->assertSame('Alpha workflow', $raw['workflow']['name']);
    }

    public function testGetUnknownCodeThrows(): void
    {
        $this->expectException(TemplateNotFoundException::class);
        $this->source()->get('does-not-exist');
    }
}
