<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One `{value, label}` option of GET /V1/workflows/meta/options (F6).
 *
 * @api
 */
interface OptionItemInterface
{
    public function getValue(): string;

    public function getLabel(): string;
}
