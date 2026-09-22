<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Secrets;

use MageOS\Workflows\Model\Secrets\SecretKeyValidator;
use PHPUnit\Framework\TestCase;

class SecretKeyValidatorTest extends TestCase
{
    private SecretKeyValidator $validator;

    public function setUp(): void
    {
        $this->validator = new SecretKeyValidator();
    }

    public function testSimpleAlphanumericKeyIsValid(): void
    {
        $this->assertTrue($this->validator->isValid('fraud_hmac'));
    }

    public function testKeyWithHyphenIsValid(): void
    {
        $this->assertTrue($this->validator->isValid('stripe-key'));
    }

    public function testDotSeparatedSegmentsAreValid(): void
    {
        $this->assertTrue($this->validator->isValid('api.stripe-key'));
        $this->assertTrue($this->validator->isValid('a.b.c_d-e'));
    }

    public function testEmptyKeyIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid(''));
    }

    public function testKeyWithSpacesIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('has space'));
    }

    public function testKeyWithLeadingDotIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('.leading'));
    }

    public function testKeyWithTrailingDotIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('trailing.'));
    }

    public function testKeyWithConsecutiveDotsIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('a..b'));
    }

    public function testKeyWithTemplateSyntaxCharsIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('{{secrets.x}}'));
    }

    public function testKeyWithSlashIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('a/b'));
    }

    public function testKeyWithDollarSignIsInvalid(): void
    {
        $this->assertFalse($this->validator->isValid('$(evil)'));
    }
}
