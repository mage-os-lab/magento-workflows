<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use MageOS\Workflows\Model\Webapi\StepDetailRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Secret masking for execution-step detail fields (07 security control).
 */
class StepDetailRedactorTest extends TestCase
{
    private StepDetailRedactor $redactor;

    public function setUp(): void
    {
        $this->redactor = new StepDetailRedactor();
    }

    public function testMasksExactKnownSecretValuesWithName(): void
    {
        // Bare occurrence (no credential-shaped context): layer 1 names it.
        $out = (string) $this->redactor->redact(
            'the resolved value sk_live_ABC123 was used',
            ['stripe' => 'sk_live_ABC123']
        );
        $this->assertStringContainsString('***stripe***', $out);
        $this->assertFalse(str_contains($out, 'sk_live_ABC123'));
    }

    public function testMasksKnownSecretInCredentialContext(): void
    {
        // In a token= context both layers fire; the guarantee is only that the
        // raw secret is gone (the friendly name may be clobbered by layer 2).
        $out = (string) $this->redactor->redact(
            'calling https://api.test/x?token=sk_live_ABC123 now',
            ['stripe' => 'sk_live_ABC123']
        );
        $this->assertFalse(str_contains($out, 'sk_live_ABC123'));
    }

    public function testMasksOverlappingSecretsLongestFirst(): void
    {
        // A short secret that is a substring of a longer one must not leave the
        // longer one partially exposed.
        $out = (string) $this->redactor->redact(
            'value=SUPERSECRETTOKEN',
            ['short' => 'SECRET', 'long' => 'SUPERSECRETTOKEN']
        );
        $this->assertFalse(str_contains($out, 'SUPERSECRETTOKEN'));
    }

    /**
     * The fields this filter guards are stored as JSON, where json_encode has
     * escaped the solidus — so the bytes in the blob are not the bytes of the
     * plaintext secret. Matching only the plaintext form let webhook URLs (the
     * single most likely place for a real token) fall through to the weaker
     * generic layer, which masks them as an anonymous ***.
     */
    public function testMasksSecretsInTheirJsonEscapedFormToo(): void
    {
        $secret = 'https://hooks.test/services/T00/B11/xoxb-9f8e7d6c';
        $blob = (string) json_encode(['steps' => ['notify' => ['url' => $secret]]]);

        // Sanity: the stored bytes really are escaped, not the plaintext.
        $this->assertFalse(str_contains($blob, $secret));

        $out = (string) $this->redactor->redact($blob, ['slack_hook' => $secret]);

        $this->assertStringContainsString('***slack_hook***', $out);
        $this->assertFalse(str_contains($out, 'xoxb-9f8e7d6c'));
    }

    public function testMasksUrlUserinfo(): void
    {
        $out = (string) $this->redactor->redact('https://user:hunter2@host/path', []);
        $this->assertFalse(str_contains($out, 'hunter2'));
        $this->assertStringContainsString('user:***@host', $out);
    }

    public function testMasksBearerAndKeyValuePairs(): void
    {
        $out = (string) $this->redactor->redact('Authorization: Bearer abcdef123456', []);
        $this->assertFalse(str_contains($out, 'abcdef123456'));

        $out2 = (string) $this->redactor->redact('{"api_key":"zzzKEYzzz","x":1}', []);
        $this->assertFalse(str_contains($out2, 'zzzKEYzzz'));

        $out3 = (string) $this->redactor->redact('password=letmein&user=bob', []);
        $this->assertFalse(str_contains($out3, 'letmein'));
    }

    public function testLeavesNullAndPlainTextAlone(): void
    {
        $this->assertNull($this->redactor->redact(null, ['a' => 'b']));
        $this->assertSame('nothing sensitive here', $this->redactor->redact('nothing sensitive here', []));
    }
}
