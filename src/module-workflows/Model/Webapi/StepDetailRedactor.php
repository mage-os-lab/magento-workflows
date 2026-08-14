<?php
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
 *      replaced with ***<name>*** — the strongest, exact guarantee. Matched
 *      both as plaintext and in their JSON-escaped form, because every field
 *      this guards is stored as a JSON document.
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

        // Layer 1: exact known secret values. strtr() with a map tries the
        // LONGEST key at each position, so a secret that is a substring of
        // another cannot leave the longer one partially exposed, and it makes a
        // single pass — a mask already written can never be re-matched.
        $value = strtr($value, self::exactReplacements($secretValues));

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

    /**
     * search => replacement map for layer 1, covering each secret BOTH as
     * plaintext and as it appears embedded in a JSON document.
     *
     * Why the second form: the fields this filter guards — step `result`, step
     * `error`, execution `context` — are stored as JSON, and json_encode
     * escapes solidus and non-ASCII, so a secret like `https://h/t` sits in the
     * blob as `https:\/\/h\/t`. Matching only the plaintext form silently missed
     * exactly the secrets most likely to be there (webhook URLs with tokens):
     * the generic layer-2 rules would usually still mask them, but as a bare
     * `***` with no name, and only when they happened to sit next to a
     * credential-shaped key. Masking the escaped form here restores the strong,
     * named guarantee for JSON blobs — and it belongs in the shared rule set,
     * not in each caller, so no surface has to remember to normalize first.
     *
     * @param array<string, string> $secretValues name => plaintext value
     * @return array<string, string>
     */
    private static function exactReplacements(array $secretValues): array
    {
        $replacements = [];
        foreach ($secretValues as $name => $secret) {
            $secret = (string) $secret;
            if ($secret === '') {
                continue;
            }
            $mask = self::MASK . $name . self::MASK;
            $replacements[$secret] = $mask;

            $encoded = json_encode($secret);
            if (!is_string($encoded)) {
                continue;
            }
            // Strip the quotes json_encode wraps the scalar in.
            $escaped = substr($encoded, 1, -1);
            if ($escaped !== '' && $escaped !== $secret) {
                $replacements[$escaped] = $mask;
            }
        }

        return $replacements;
    }
}
