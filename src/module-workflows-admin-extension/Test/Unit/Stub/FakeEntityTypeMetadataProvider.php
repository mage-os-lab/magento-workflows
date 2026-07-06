<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Stub;

use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\Workflows\Model\Webapi\EntityTypeMetadata;

/**
 * EntityTypeMetadataProviderInterface stand-in projecting a code => label map to
 * the real immutable DTO (reused from the engine module).
 */
class FakeEntityTypeMetadataProvider implements EntityTypeMetadataProviderInterface
{
    /**
     * @param array<string, string> $labelsByCode
     */
    public function __construct(
        private readonly array $labelsByCode = []
    ) {
    }

    public function getEntityTypes(): array
    {
        $items = [];
        foreach ($this->labelsByCode as $code => $label) {
            $items[] = new EntityTypeMetadata((string) $code, (string) $label);
        }
        return $items;
    }
}
