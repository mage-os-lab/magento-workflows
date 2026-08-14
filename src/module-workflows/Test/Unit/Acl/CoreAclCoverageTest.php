<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Acl;

use MageOS\Workflows\Model\Action\AbstractAction;
use PHPUnit\Framework\TestCase;

/**
 * The packaging invariant behind src/module-workflows/etc/acl.xml.
 *
 * Magento DENIES an ACL resource it has never seen declared. So any resource
 * that core enforces — in its own webapi.xml, its own system.xml, or its own
 * validation pipeline — must be declared by core, or a headless install (core
 * without mage-os/workflows-admin-ui) 403s on every REST route. That regression
 * shipped once; these assertions are the guard.
 *
 * The mirror-image invariant matters just as much: a resource must be TITLED by
 * exactly one package. Two packages declaring the same id with a title makes
 * the merged admin role tree depend on module load order, so the sibling
 * packages graft onto core's ids without titling them.
 */
class CoreAclCoverageTest extends TestCase
{
    /** Repository src/ directory (…/src/module-workflows/Test/Unit/Acl → …/src). */
    private const SRC_DIR = __DIR__ . '/../../../../';

    private const CORE_ACL = __DIR__ . '/../../../etc/acl.xml';

    /**
     * Every ACL resource id declared by one package's acl.xml, mapped to
     * whether that declaration carries a title.
     *
     * @return array<string, bool> resource id => declares a title
     */
    private function declarations(string $aclFile): array
    {
        $this->assertTrue(is_file($aclFile), $aclFile . ' must exist');
        $document = new \DOMDocument();
        $this->assertTrue($document->load($aclFile), $aclFile . ' must be well-formed XML');

        $declared = [];
        foreach ((new \DOMXPath($document))->query('//resource') as $node) {
            /** @var \DOMElement $node */
            $declared[$node->getAttribute('id')] = $node->hasAttribute('title');
        }
        return $declared;
    }

    /**
     * Resource ids referenced by core's own webapi.xml routes.
     *
     * @return string[]
     */
    private function webapiResources(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/webapi.xml');
        $this->assertTrue($xml !== false, 'core webapi.xml must parse');

        $refs = [];
        foreach ($xml->route as $route) {
            foreach ($route->resources->resource as $resource) {
                $refs[(string) $resource['ref']] = true;
            }
        }
        return array_keys($refs);
    }

    public function testCoreDeclaresEveryResourceItsOwnRestRoutesReference(): void
    {
        $declared = $this->declarations(self::CORE_ACL);
        $refs = $this->webapiResources();
        $this->assertTrue($refs !== [], 'core webapi.xml must reference at least one ACL resource');

        foreach ($refs as $ref) {
            $this->assertArrayHasKey(
                $ref,
                $declared,
                $ref . ' is referenced by core webapi.xml but not declared by core acl.xml — '
                . 'an undeclared resource is DENIED, so the route would 403 without the admin UI installed'
            );
        }
    }

    public function testCoreDeclaresEveryPerActionGroupAuthoringGate(): void
    {
        $declared = $this->declarations(self::CORE_ACL);
        /** @var array<string, string> $groups */
        $groups = (new \ReflectionClassConstant(AbstractAction::class, 'ACL_GROUP_BY_CODE_PREFIX'))->getValue();

        $this->assertTrue($groups !== [], 'AbstractAction must map action-code prefixes to ACL groups');
        foreach ($groups as $prefix => $group) {
            $this->assertArrayHasKey(
                'MageOS_Workflows::action_' . $group,
                $declared,
                'Action-code prefix "' . $prefix . '" derives an authoring gate core does not declare; '
                . 'ActionAuthorizationCheck runs on the REST save path too'
            );
        }
    }

    public function testCoreDeclaresTheSystemConfigSectionResource(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/adminhtml/system.xml');
        $this->assertTrue($xml !== false, 'core system.xml must parse');

        $declared = $this->declarations(self::CORE_ACL);
        foreach ($xml->xpath('//section/resource') ?: [] as $resource) {
            $this->assertArrayHasKey((string) $resource, $declared);
        }
    }

    public function testCoreDeclaresTheTreeRootWithATitle(): void
    {
        $declared = $this->declarations(self::CORE_ACL);

        $this->assertArrayHasKey('MageOS_Workflows::workflows', $declared);
        $this->assertTrue(
            $declared['MageOS_Workflows::workflows'],
            'Core owns the tree root and must be the package that titles it'
        );
    }

    /**
     * ::enable and ::manual_run are enforced only by admin controllers, so they
     * stay with the admin UI. Core must not shadow them: a merchant reading the
     * role tree on a headless install would see toggles that grant nothing.
     */
    public function testCoreDoesNotDeclareAdminOnlyResources(): void
    {
        $declared = $this->declarations(self::CORE_ACL);

        foreach (['MageOS_Workflows::enable', 'MageOS_Workflows::manual_run'] as $adminOnly) {
            $this->assertFalse(
                array_key_exists($adminOnly, $declared),
                $adminOnly . ' is enforced only by the admin UI package and belongs in its acl.xml'
            );
        }
    }

    /**
     * @return string[] absolute paths of every acl.xml in the monorepo
     */
    private function allAclFiles(): array
    {
        $files = glob(self::SRC_DIR . 'module-*/etc/acl.xml') ?: [];
        $this->assertTrue($files !== [], 'expected to find the packages\' acl.xml files');

        return $files;
    }

    public function testEveryResourceIsTitledByExactlyOnePackage(): void
    {
        $titledIn = [];
        foreach ($this->allAclFiles() as $file) {
            foreach ($this->declarations($file) as $id => $hasTitle) {
                if ($hasTitle) {
                    $titledIn[$id][] = basename(dirname($file, 2));
                }
            }
        }

        $this->assertArrayHasKey('MageOS_Workflows::workflows', $titledIn);
        foreach ($titledIn as $id => $packages) {
            $this->assertCount(
                1,
                $packages,
                $id . ' is titled by more than one package (' . implode(', ', $packages)
                . '); the merged role tree would then depend on module load order'
            );
        }
    }

    public function testAdminUiKeepsOnlyItsOwnResources(): void
    {
        $declared = $this->declarations(self::SRC_DIR . 'module-workflows-admin-ui/etc/acl.xml');

        // The root is grafted onto by id, never re-titled.
        $this->assertArrayHasKey('MageOS_Workflows::workflows', $declared);
        $this->assertFalse($declared['MageOS_Workflows::workflows']);

        $this->assertTrue($declared['MageOS_Workflows::enable'] ?? false);
        $this->assertTrue($declared['MageOS_Workflows::manual_run'] ?? false);

        foreach (['MageOS_Workflows::view', 'MageOS_Workflows::manage', 'MageOS_Workflows::dry_run'] as $coreOwned) {
            $this->assertFalse(
                array_key_exists($coreOwned, $declared),
                $coreOwned . ' moved to core acl.xml; re-declaring it here re-creates the split-brain tree'
            );
        }
    }
}
