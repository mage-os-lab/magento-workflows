<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsActionsCore\Action\Notify\Email;
use PHPUnit\Framework\TestCase;

class EmailTest extends TestCase
{
    public function testExecuteWithNoTemplateAndNoSubjectFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->execute($ctx, ['to' => 'test@example.com']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('template_id', $result->getError());
    }

    public function testExecuteWithTemplateAndSubjectFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->execute($ctx, [
            'to' => 'test@example.com',
            'template_id' => 'my_template',
            'subject' => 'Test'
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('either', $result->getError());
    }

    public function testExecuteWithTemplateAndBodyFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->execute($ctx, [
            'to' => 'test@example.com',
            'template_id' => 'my_template',
            'body' => 'Test'
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    public function testExecuteWithSubjectButNoBodyFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->execute($ctx, ['to' => 'test@example.com', 'subject' => 'Test']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('body', $result->getError());
    }

    public function testExecuteWithMissingToFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->execute($ctx, ['template_id' => 'my_template']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('to', $result->getError());
    }

    public function testExecuteWithInvalidEmailFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->execute($ctx, [
            'to' => 'not-an-email',
            'template_id' => 'my_template'
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('Invalid recipient email', $result->getError());
    }

    public function testSimulateWithNoTemplateAndNoSubjectFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->simulate($ctx, ['to' => 'test@example.com']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('template_id', $result->getError());
    }

    public function testSimulateWithTemplateAndSubjectFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->simulate($ctx, [
            'to' => 'test@example.com',
            'template_id' => 'my_template',
            'subject' => 'Test'
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testSimulateWithSubjectButNoBodyFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->simulate($ctx, ['to' => 'test@example.com', 'subject' => 'Test']);

        $this->assertTrue($result->isFailure());
    }

    public function testSimulateWithMissingToFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->simulate($ctx, ['template_id' => 'my_template']);

        $this->assertTrue($result->isFailure());
    }

    public function testSimulateWithInvalidEmailFails(): void
    {
        $transportBuilder = $this->createTransportBuilderStub();
        $cache = $this->createCacheStub();

        $action = new Email($transportBuilder, $cache);
        $ctx = new ExecutionContext(new WorkflowExecutionStub());

        $result = $action->simulate($ctx, [
            'to' => 'invalid-email',
            'template_id' => 'my_template'
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testConfigFormTemplateFieldSearchesTheEmailTemplateSource(): void
    {
        $action = new Email($this->createTransportBuilderStub(), $this->createCacheStub());

        $field = $action->getConfigForm()[0];

        $this->assertSame('template_id', $field['name']);
        $this->assertSame('select', $field['type']);
        $this->assertSame(['source' => 'email_templates', 'min_chars' => 0], $field['options_search']);
        // The either/or notice stays: the picker does not replace the mode rule.
        $this->assertStringContainsString('not both', (string)$field['notice']);
    }

    private function createTransportBuilderStub(): TransportBuilder
    {
        return new class extends TransportBuilder {
            // Bypass the real TransportBuilder's DI constructor.
            public function __construct() {}
            public function setTemplateIdentifier($id): self { throw new \RuntimeException('Should not be called'); }
            public function setTemplateOptions($options): self { throw new \RuntimeException('Should not be called'); }
            public function setTemplateVars($vars): self { throw new \RuntimeException('Should not be called'); }
            public function setFromByScope($scope, $storeId = null): self { throw new \RuntimeException('Should not be called'); }
            public function addTo($email, $name = ''): self { throw new \RuntimeException('Should not be called'); }
        };
    }

    private function createCacheStub(): CacheInterface
    {
        return new class implements CacheInterface {
            public function load($identifier) { return false; }
            public function save($data, $identifier, $tags = [], $lifeTime = null) {}
            public function remove($identifier) {}
            public function getFrontend() { throw new \BadMethodCallException(__METHOD__); }
            public function clean($tags = []) { return true; }
        };
    }
}
