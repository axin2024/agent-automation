<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Access
{
    public function __construct(private Db $db)
    {
    }

    public function provision(string $tenant, string $environment, string $actor, string $role, int $hours): array
    {
        Values::identifier($tenant, 'tenant');
        Scope::environment($environment);
        Values::identifier($actor, 'actor');
        Values::number($hours, 'credential lifetime', 1, 744);
        if (!in_array($role, ['admin', 'author', 'approver', 'operator', 'trigger'], true)) {
            throw Problem::invalid('Invalid role.');
        }
        return $this->db->transaction(function () use ($tenant, $environment, $actor, $role, $hours): array {
            $this->db->exec('INSERT INTO tenants(tenant) VALUES(?) ON DUPLICATE KEY UPDATE tenant=tenant', [$tenant]);
            $token = bin2hex(random_bytes(32));
            $this->db->exec('INSERT INTO credentials(digest,tenant,environment,actor,role,expires)
                VALUES(UNHEX(?),?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? HOUR))',
                [hash('sha256', $token), $tenant, $environment, $actor, $role, $hours]);
            return ['tenant' => $tenant, 'environment' => $environment, 'actor' => $actor, 'role' => $role, 'token' => $token];
        });
    }

    public function identify(?string $header): Scope
    {
        if (!is_string($header) || preg_match('/^Bearer ([a-f0-9]{64})$/iD', $header, $matches) !== 1) {
            throw new Problem(401, 'authentication-required', 'A workspace credential is required.');
        }
        $value = $this->db->one('SELECT tenant,environment,actor,role FROM credentials
            WHERE digest=UNHEX(?) AND revoked IS NULL AND expires>UTC_TIMESTAMP(6)', [hash('sha256', $matches[1])]);
        if ($value === null) {
            throw new Problem(401, 'authentication-required', 'The workspace credential is invalid or expired.');
        }
        return new Scope($value['tenant'], $value['environment'], $value['actor'], $value['role']);
    }

    public function revoke(string $tenant, string $environment, string $actor): int
    {
        return $this->db->exec('UPDATE credentials SET revoked=UTC_TIMESTAMP(6)
            WHERE tenant=? AND environment=? AND actor=? AND revoked IS NULL',
            [Values::identifier($tenant, 'tenant'), Scope::environment($environment), Values::identifier($actor, 'actor')]);
    }
}
