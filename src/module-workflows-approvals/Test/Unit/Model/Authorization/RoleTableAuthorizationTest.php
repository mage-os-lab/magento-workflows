<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model\Authorization;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use MageOS\WorkflowsApprovals\Model\Authorization\RoleTableAuthorization;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Real role enforcement (docs/discovery/approval-gate.md §5, §10.3): admin
 * matching is by role_name (case-insensitive); integration matching is a
 * resource-superset check against the named role's grants. See
 * RoleTableAuthorization's docblock for the pinned authorization_role /
 * authorization_rule schema assumptions this test's fake connection mirrors.
 */
class RoleTableAuthorizationTest extends TestCase
{
    private function authorization(FakeAuthConnection $connection): RoleTableAuthorization
    {
        return new RoleTableAuthorization(new FakeAuthResourceConnection($connection), new NullLogger());
    }

    public function testAdminHoldsRoleCaseInsensitive(): void
    {
        $connection = new FakeAuthConnection();
        $connection->addAdminRole(5, 'Sales Managers');

        // Case-insensitive, trimmed match against the exact role name content.
        $this->assertTrue($this->authorization($connection)->actorHoldsRole('admin', '5', 'SALES MANAGERS'));
        $this->assertTrue($this->authorization($connection)->actorHoldsRole('admin', '5', '  Sales Managers  '));
    }

    public function testAdminMatchingIsContentNotFormatEquivalence(): void
    {
        // "sales_managers" (underscored) is NOT treated as equivalent to the
        // stored "Sales Managers" (spaced) — only case and surrounding
        // whitespace are normalized, never separator style.
        $connection = new FakeAuthConnection();
        $connection->addAdminRole(5, 'Sales Managers');

        $this->assertFalse($this->authorization($connection)->actorHoldsRole('admin', '5', 'sales_managers'));
    }

    public function testAdminWithoutRoleAssignmentDenies(): void
    {
        $connection = new FakeAuthConnection();
        $this->assertFalse($this->authorization($connection)->actorHoldsRole('admin', '5', 'sales_managers'));
    }

    public function testAdminWithDifferentRoleDenies(): void
    {
        $connection = new FakeAuthConnection();
        $connection->addAdminRole(5, 'Support');
        $this->assertFalse($this->authorization($connection)->actorHoldsRole('admin', '5', 'Sales Managers'));
    }

    public function testNonNumericActorIdDeniesWithoutQuerying(): void
    {
        $connection = new FakeAuthConnection();
        $this->assertFalse($this->authorization($connection)->actorHoldsRole('admin', 'not-numeric', 'x'));
    }

    public function testIntegrationHoldingAllRequiredResourcesIsAllowed(): void
    {
        $connection = new FakeAuthConnection();
        $roleId = $connection->addNamedRole('Sales Managers', ['MageOS_Workflows::view', 'MageOS_Workflows::manage']);
        $connection->addIntegrationRole(9, ['MageOS_Workflows::view', 'MageOS_Workflows::manage', 'Some_Other::resource']);

        $this->assertTrue($this->authorization($connection)->actorHoldsRole('integration', '9', 'Sales Managers'));
        $this->assertTrue($roleId > 0);
    }

    public function testIntegrationMissingOneRequiredResourceIsDenied(): void
    {
        $connection = new FakeAuthConnection();
        $connection->addNamedRole('Sales Managers', ['MageOS_Workflows::view', 'MageOS_Workflows::manage']);
        $connection->addIntegrationRole(9, ['MageOS_Workflows::view']);

        $this->assertFalse($this->authorization($connection)->actorHoldsRole('integration', '9', 'Sales Managers'));
    }

    public function testIntegrationWithNoRoleRowIsDenied(): void
    {
        $connection = new FakeAuthConnection();
        $connection->addNamedRole('Sales Managers', ['MageOS_Workflows::view']);

        $this->assertFalse($this->authorization($connection)->actorHoldsRole('integration', '9', 'Sales Managers'));
    }

    public function testUnknownNamedRoleDeniesForBothActorTypes(): void
    {
        $connection = new FakeAuthConnection();
        $connection->addAdminRole(5, 'Support');
        $connection->addIntegrationRole(9, ['MageOS_Workflows::view']);

        $this->assertFalse($this->authorization($connection)->actorHoldsRole('admin', '5', 'Ghost Role'));
        $this->assertFalse($this->authorization($connection)->actorHoldsRole('integration', '9', 'Ghost Role'));
    }

    public function testUnknownActorTypeIsDenySafe(): void
    {
        $connection = new FakeAuthConnection();
        $this->assertFalse($this->authorization($connection)->actorHoldsRole('robot', '1', 'x'));
    }

    public function testWildcardRoleResourceRequiresIntegrationToAlsoHoldWildcard(): void
    {
        $connection = new FakeAuthConnection();
        $connection->addNamedRole('Everything', ['Magento_Backend::all']);
        $connection->addIntegrationRole(9, ['MageOS_Workflows::view']); // specific, not the wildcard

        $this->assertFalse($this->authorization($connection)->actorHoldsRole('integration', '9', 'Everything'));
    }

    public function testQueryFailureIsDenySafe(): void
    {
        $connection = new FakeAuthConnection();
        $connection->throwOnQuery = true;
        $this->assertFalse($this->authorization($connection)->actorHoldsRole('admin', '5', 'x'));
    }
}

/**
 * @internal minimal fake of the two ACL tables RoleTableAuthorization reads,
 * mirroring the pinned schema assumptions in its docblock: authorization_role
 * rows of role_type 'G' (the permission-holding role, keyed by role_id) and
 * 'U' (a user/integration assignment row whose parent_id points at its 'G'
 * role), disambiguated by user_type — seeded from the REAL
 * Magento\Authorization\Model\UserContextInterface constants (ADMIN = 2,
 * INTEGRATION = 1), never local literals, so this test only passes when the
 * class under test queries the values the real schema contains;
 * authorization_rule rows of (role_id, resource_id, permission).
 */
class FakeAuthConnection
{
    public bool $throwOnQuery = false;

    /** @var array<int, array{role_id: int, role_name: string}> */
    private array $groupRoles = [];

    /** @var array<int, array{parent_id: int, user_id: int, user_type: int}> */
    private array $userRoleAssignments = [];

    /** @var array<int, string[]> role_id => resource ids (permission=allow) */
    private array $grants = [];

    private int $nextRoleId = 1;

    public function addAdminRole(int $adminUserId, string $roleName): int
    {
        $roleId = $this->addNamedRole($roleName, []);
        $this->userRoleAssignments[] = [
            'parent_id' => $roleId,
            'user_id' => $adminUserId,
            'user_type' => UserContextInterface::USER_TYPE_ADMIN,
        ];
        return $roleId;
    }

    /**
     * @param string[] $resources
     */
    public function addIntegrationRole(int $integrationId, array $resources): int
    {
        $roleId = $this->nextRoleId++;
        $this->grants[$roleId] = $resources;
        $this->userRoleAssignments[] = [
            'parent_id' => $roleId,
            'user_id' => $integrationId,
            'user_type' => UserContextInterface::USER_TYPE_INTEGRATION,
        ];
        return $roleId;
    }

    /**
     * @param string[] $resources
     */
    public function addNamedRole(string $roleName, array $resources): int
    {
        $roleId = $this->nextRoleId++;
        $this->groupRoles[$roleId] = ['role_id' => $roleId, 'role_name' => $roleName];
        $this->grants[$roleId] = $resources;
        return $roleId;
    }

    public function findRoleIdByName(string $roleName): ?int
    {
        foreach ($this->groupRoles as $role) {
            if ($role['role_name'] === $roleName) {
                return $role['role_id'];
            }
        }
        return null;
    }

    public function findRoleName(int $roleId): ?string
    {
        return $this->groupRoles[$roleId]['role_name'] ?? null;
    }

    public function findAssignedParentId(int $userId, int $userType): ?int
    {
        foreach ($this->userRoleAssignments as $row) {
            if ($row['user_id'] === $userId && $row['user_type'] === $userType) {
                return $row['parent_id'];
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    public function findGrants(int $roleId): array
    {
        return $this->grants[$roleId] ?? [];
    }
}

/**
 * @internal ResourceConnection double dispatching to FakeAuthConnection based
 * on the queried table/where-clause shape (mirrors FakeResourceConnection's
 * "echo table name verbatim" posture in the sibling approvals test stubs).
 */
class FakeAuthResourceConnection extends ResourceConnection
{
    public function __construct(private readonly FakeAuthConnection $connection)
    {
    }

    public function getConnection($resourceName = self::DEFAULT_CONNECTION)
    {
        return new FakeAuthAdapter($this->connection);
    }

    public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION)
    {
        return (string) $modelEntity;
    }
}

/**
 * @internal
 */
class FakeAuthAdapter
{
    public function __construct(private readonly FakeAuthConnection $connection)
    {
    }

    public function select(): FakeAuthSelect
    {
        return new FakeAuthSelect();
    }

    public function fetchOne(FakeAuthSelect $select)
    {
        if ($this->connection->throwOnQuery) {
            throw new \RuntimeException('query failed');
        }

        if ($select->table === 'authorization_role' && $select->column === 'parent_id') {
            $userId = (int) $select->wheres['user_id'];
            $userType = (int) $select->wheres['user_type'];
            return $this->connection->findAssignedParentId($userId, $userType) ?? false;
        }
        if ($select->table === 'authorization_role' && $select->column === 'role_name' && isset($select->wheres['role_id'])) {
            $name = $this->connection->findRoleName((int) $select->wheres['role_id']);
            return $name ?? false;
        }
        if ($select->table === 'authorization_role' && $select->column === 'role_id') {
            $roleId = $this->connection->findRoleIdByName((string) $select->wheres['role_name']);
            return $roleId ?? false;
        }
        return false;
    }

    public function fetchCol(FakeAuthSelect $select)
    {
        if ($this->connection->throwOnQuery) {
            throw new \RuntimeException('query failed');
        }
        if ($select->table === 'authorization_rule') {
            return $this->connection->findGrants((int) $select->wheres['role_id']);
        }
        return [];
    }
}

/**
 * @internal records the from-table, the single selected column, and the
 * where-predicates keyed by column name (sufficient for this narrow query set).
 */
class FakeAuthSelect
{
    public string $table = '';
    public string $column = '';
    /** @var array<string, mixed> */
    public array $wheres = [];

    public function from($table, $columns = '*'): self
    {
        $this->table = (string) $table;
        $this->column = is_array($columns) ? (string) ($columns[0] ?? '') : (string) $columns;
        return $this;
    }

    public function where($condition, $value = null): self
    {
        $column = strtok((string) $condition, ' ');
        $this->wheres[$column] = $value;
        return $this;
    }

    public function limit($count, $offset = 0): self
    {
        return $this;
    }
}
