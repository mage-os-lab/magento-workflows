<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
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
