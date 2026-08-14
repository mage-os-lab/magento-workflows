<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Idempotency;

use MageOS\Workflows\Model\Idempotency\SendClaimStore;
use MageOS\Workflows\Model\Idempotency\SendClaimStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tripwire on the shipped wiring. The send-once guard only exists if the
 * ObjectManager can build it, so the di.xml preference is part of the guard,
 * not decoration: without it every action that type-hints
 * SendClaimStoreInterface fails to instantiate (or, worse, a future
 * "convenience" nullable default makes it silently absent and mail goes out
 * unguarded again). Same reasoning as the attribute-denylist di.xml pins.
 */
class SendClaimWiringContractTest extends TestCase
{
    private function diXml(): \SimpleXMLElement
    {
        $path = __DIR__ . '/../../../../etc/di.xml';
        $this->assertTrue(is_file($path), 'module-workflows etc/di.xml must exist');
        $xml = simplexml_load_file($path);
        $this->assertNotNull($xml);
        return $xml;
    }

    public function testTheClaimStoreInterfaceHasAShippedPreference(): void
    {
        $types = [];
        foreach ($this->diXml()->preference as $preference) {
            $types[(string)$preference['for']] = (string)$preference['type'];
        }

        $this->assertArrayHasKey(SendClaimStoreInterface::class, $types);
        $this->assertSame(SendClaimStore::class, $types[SendClaimStoreInterface::class]);
    }

    public function testTheStoreDependencyIsRequiredSoItCannotArriveNull(): void
    {
        // Magento's ObjectManager does not auto-inject a parameter that has a
        // default value: a nullable ResourceConnection here would mean a store
        // that silently cannot claim.
        $parameters = (new \ReflectionClass(SendClaimStore::class))->getConstructor()->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertFalse($parameters[0]->isDefaultValueAvailable());
        $this->assertFalse($parameters[0]->allowsNull());
    }

    public function testTheStoreImplementsTheContractItIsWiredFor(): void
    {
        $this->assertTrue(is_subclass_of(SendClaimStore::class, SendClaimStoreInterface::class));
    }
}
