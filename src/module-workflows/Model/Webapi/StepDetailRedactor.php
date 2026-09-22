<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

/**
 * Masks sensitive substrings out of execution-step detail fields (result/error)
 * before they leave the server via GET /V1/workflow-executions/:id/steps (07).
 *
 * Step result rows are written by the PRODUCTION executor and may embed
 * interpolated action config — real secret values, webhook URLs carrying
 * tokens. The overlay wants a detail field, so rather than dropping it we run
 * it through this filter. Two layers:
 *
 *   1. Known secret VALUES (resolved server-side from the secrets store) are
 *      replaced with ***<name>*** — the strongest, exact guarantee.
 *   2. Generic credential shapes (URL userinfo, Bearer tokens, `token=`/
 *      `password=`/`api_key=` style pairs) are masked as defence in depth for
 *      anything not stored as a workflow secret.
 *
 * Pure and shim-testable: the caller supplies the resolved secret map, so this
 * class performs no I/O.
 */
class StepDetailRedactor
{
    private const MASK = '***';

    /**
     * @param array<string, string> $secretValues name => plaintext value
     */
    public function redact(?string $value, array $secretValues): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Layer 1: exact known secret values (longest first, so a value that is
        // a substring of another does not leave a partial leak).
        uasort($secretValues, static fn ($a, $b): int => strlen((string) $b) <=> strlen((string) $a));
        foreach ($secretValues as $name => $secret) {
            $secret = (string) $secret;
            if ($secret !== '') {
                $value = str_replace($secret, self::MASK . $name . self::MASK, $value);
            }
        }

        // Layer 2: generic credential shapes.
        // URL userinfo: scheme://user:pass@host -> scheme://user:***@host
        $value = (string) preg_replace('~(://[^:@/\s]+):[^@/\s]+@~', '$1:' . self::MASK . '@', $value);
        // Authorization: Bearer <token>
        $value = (string) preg_replace('~(?i)(bearer\s+)[A-Za-z0-9._\-]{8,}~', '$1' . self::MASK, $value);
        // key=value / "key": "value" for credential-ish keys
        $value = (string) preg_replace(
            '~(?i)("?\b(?:token|secret|password|passwd|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization)\b"?\s*[:=]\s*"?)[^"\'\s,&}\]]+~',
            '$1' . self::MASK,
            $value
        );

        return $value;
    }
}
