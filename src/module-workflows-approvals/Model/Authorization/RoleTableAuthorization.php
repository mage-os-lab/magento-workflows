<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Authorization;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use MageOS\WorkflowsApprovals\Api\ApprovalAuthorizationInterface;
use Psr\Log\LoggerInterface;

/**
 * Real role enforcement (docs/discovery/approval-gate.md §5, §10.3: "assignee_role
 * = role code, matching Magento authorization roles"), replacing the deny-safe
 * DenyRoleAuthorization default now that Stage 3 wires it up.
 *
 * Schema assumptions (pinned here, adjust in one place if a target Magento
 * version differs — same posture as MageOS\WorkflowsTriggersCore\Model\WorkflowNotifier's
 * documented async-events assumptions): every admin user AND every API
 * integration gets one row in `authorization_role` with role_type='U',
 * user_id = the admin user id / integration id, and user_type disambiguating
 * which — the values are NOT this class's to pin: they come from
 * Magento\Authorization\Model\UserContextInterface (USER_TYPE_ADMIN = 2,
 * USER_TYPE_INTEGRATION = 1), referenced directly below so this class can
 * never drift from the interface. That row's parent_id points at the
 * role_type='G' row carrying the actual permission grant — role_name for
 * admin roles, and the auto-generated per-integration role for integrations.
 *
 * Matching rule:
 *  - admin: the assigned role's role_name compared to assignee_role,
 *    case-insensitively and trimmed (roles are commonly typed with different
 *    casing than a workflow author's assignee_role string; content, not case,
 *    is the contract).
 *  - integration: per §5 "the integration must be granted the role's
 *    resources" — resolve the resource ids granted (permission='allow') to the
 *    NAMED role (assignee_role, role_type='G') and require the integration's
 *    own auto-generated role to hold every one of them (a resource superset
 *    check), since integrations are not assigned merchant role names the way
 *    admin users are. A role resource of 'Magento_Backend::all' is honored only
 *    when the integration itself also carries 'Magento_Backend::all' — no
 *    wildcard-implies-wildcard inference, to stay deny-safe under uncertainty.
 *
 * Enforceable-vs-not (documented per the task): this class depends on Magento's
 * internal authorization_role / authorization_rule schema, which is stable
 * across recent Magento versions but is not re-verified here against a live
 * install (none is available in this environment). Any lookup that does not
 * resolve cleanly — missing role row, named role not found, query failure —
 * returns false. A missing/ambiguous identity must never read as "allowed".
 */
class RoleTableAuthorization implements ApprovalAuthorizationInterface
{
    private const ROLE_TABLE = 'authorization_role';
    private const RULE_TABLE = 'authorization_rule';

    private const ROLE_TYPE_GROUP = 'G';
    private const ROLE_TYPE_USER = 'U';

    private const RESOURCE_ALL = 'Magento_Backend::all';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function actorHoldsRole(string $actorType, string $actorId, string $role): bool
    {
        if (!ctype_digit($actorId)) {
            return false;
        }

        try {
            return match ($actorType) {
                'admin' => $this->adminHoldsRole((int) $actorId, $role),
                'integration' => $this->integrationHoldsRoleResources((int) $actorId, $role),
                default => false,
            };
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Approval role check failed for %s:%s against role "%s": %s',
                $actorType,
                $actorId,
                $role,
                $e->getMessage()
            ), ['exception' => $e]);
            return false;
        }
    }

    private function adminHoldsRole(int $adminUserId, string $role): bool
    {
        $roleName = $this->assignedRoleName($adminUserId, UserContextInterface::USER_TYPE_ADMIN);
        if ($roleName === null) {
            return false;
        }
        return $this->normalize($roleName) === $this->normalize($role);
    }

    private function integrationHoldsRoleResources(int $integrationId, string $role): bool
    {
        $namedRoleId = $this->roleIdByName($role);
        if ($namedRoleId === null) {
            return false;
        }
        $integrationRoleId = $this->assignedRoleId($integrationId, UserContextInterface::USER_TYPE_INTEGRATION);
        if ($integrationRoleId === null) {
            return false;
        }

        $required = $this->grantedResources($namedRoleId);
        if ($required === []) {
            // A role with no explicit grants confers nothing to require.
            return true;
        }
        $granted = $this->grantedResources($integrationRoleId);

        foreach ($required as $resourceId) {
            if ($resourceId === self::RESOURCE_ALL) {
                if (!in_array(self::RESOURCE_ALL, $granted, true)) {
                    return false;
                }
                continue;
            }
            if (!in_array($resourceId, $granted, true) && !in_array(self::RESOURCE_ALL, $granted, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The role_name of the 'G' role assigned to $userId (via its 'U' row's
     * parent_id), or null when no assignment resolves.
     */
    private function assignedRoleName(int $userId, int $userType): ?string
    {
        $roleId = $this->assignedRoleId($userId, $userType);
        if ($roleId === null) {
            return null;
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ROLE_TABLE);
        $name = $connection->fetchOne(
            $connection->select()
                ->from($table, ['role_name'])
                ->where('role_id = ?', $roleId)
                ->where('role_type = ?', self::ROLE_TYPE_GROUP)
                ->limit(1)
        );
        return $name === false || $name === null || $name === '' ? null : (string) $name;
    }

    /**
     * The 'G' role_id assigned to $userId of $userType, via its 'U' assignment row's parent_id.
     */
    private function assignedRoleId(int $userId, int $userType): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ROLE_TABLE);
        $parentId = $connection->fetchOne(
            $connection->select()
                ->from($table, ['parent_id'])
                ->where('role_type = ?', self::ROLE_TYPE_USER)
                ->where('user_id = ?', $userId)
                ->where('user_type = ?', $userType)
                ->limit(1)
        );
        if ($parentId === false || $parentId === null || (int) $parentId <= 0) {
            return null;
        }
        return (int) $parentId;
    }

    private function roleIdByName(string $role): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ROLE_TABLE);
        $roleId = $connection->fetchOne(
            $connection->select()
                ->from($table, ['role_id'])
                ->where('role_type = ?', self::ROLE_TYPE_GROUP)
                ->where('role_name = ?', $role)
                ->limit(1)
        );
        return $roleId === false || $roleId === null ? null : (int) $roleId;
    }

    /**
     * @return string[] resource ids with permission='allow' for $roleId
     */
    private function grantedResources(int $roleId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::RULE_TABLE);
        $rows = $connection->fetchCol(
            $connection->select()
                ->from($table, ['resource_id'])
                ->where('role_id = ?', $roleId)
                ->where('permission = ?', 'allow')
        );
        return array_map('strval', $rows);
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
