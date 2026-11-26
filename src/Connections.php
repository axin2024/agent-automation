<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Connections
{
    public function __construct(private Db $db)
    {
    }

    public function put(Scope $scope, string $name, string $kind, int $expected, array $settings, ?string $secret): array
    {
        $scope->require('admin');
        Values::identifier($name, 'connection name');
        Values::number($expected, 'expected connection revision', 0, 2147483646);
        $settings = $this->settings($scope, $kind, $settings);
        if ($secret !== null && ($kind !== 'model' || strlen($secret) > 4096 || preg_match('/[\x00-\x20\x7f]/', $secret))) {
            throw Problem::invalid('Only model connections accept a bounded bearer credential.');
        }
        return $this->db->transaction(function () use ($scope, $name, $kind, $expected, $settings, $secret): array {
            // Serialize creations and editions, including the first version.
            $this->db->one('SELECT tenant FROM tenants WHERE tenant=? FOR UPDATE', [$scope->tenant]);
            $current = $this->active($scope, $name, null, true);
            if (($current['revision'] ?? 0) !== $expected || ($current !== null && $current['capability'] !== $kind)) {
                throw Problem::conflict('The connection revision or capability changed.');
            }
            $revision = $expected + 1;
            if ($expected === 0) {
                $this->db->exec('INSERT INTO connections(tenant,environment,connection,capability,active_revision) VALUES(?,?,?,?,?)',
                    [...$scope->keys(), $name, $kind, $revision]);
            } else {
                $this->db->exec('UPDATE connections SET active_revision=? WHERE tenant=? AND environment=? AND connection=?',
                    [$revision, ...$scope->keys(), $name]);
            }
            $encrypted = $secret === null || $secret === '' ? null : $this->encrypt($scope, $name, $revision, $secret);
            $this->db->exec('INSERT INTO connection_versions(tenant,environment,connection,revision,settings,encrypted_secret,actor)
                VALUES(?,?,?,?,?,?,?)', [...$scope->keys(), $name, $revision, Values::json($settings), $encrypted, $scope->actor]);
            $this->db->audit($scope, 'connection-version', $name, ['revision' => $revision, 'capability' => $kind]);
            return ['connection' => $name, 'capability' => $kind, 'revision' => $revision];
        });
    }

    public function list(Scope $scope): array
    {
        $scope->require('author', 'operator', 'approver');
        return $this->db->all('SELECT connection,capability,active_revision AS revision FROM connections
            WHERE tenant=? AND environment=? ORDER BY connection LIMIT 200', $scope->keys());
    }

    public function active(Scope $scope, string $name, ?string $kind, bool $lock = false): ?array
    {
        $row = $this->db->one('SELECT c.connection,c.capability,v.revision,v.settings,v.encrypted_secret
            FROM connections c JOIN connection_versions v
                ON v.tenant=c.tenant AND v.environment=c.environment AND v.connection=c.connection AND v.revision=c.active_revision
            WHERE c.tenant=? AND c.environment=? AND c.connection=?' . ($lock ? ' FOR UPDATE' : ''), [...$scope->keys(), $name]);
        if ($row === null || ($kind !== null && $row['capability'] !== $kind)) {
            return null;
        }
        $row['settings'] = Values::decode($row['settings']);
        return $row;
    }

    public function token(Scope $scope, array $connection): string
    {
        $encrypted = $connection['encrypted_secret'];
        if ($encrypted === null) {
            return '';
        }
        $value = openssl_decrypt(substr($encrypted, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($encrypted, 0, 12),
            substr($encrypted, 12, 16), $this->aad($scope, $connection['connection'], $connection['revision']));
        if ($value === false) {
            throw new \RuntimeException('Connection credential could not be decrypted.');
        }
        return $value;
    }

    public function ensureRecipient(array $connection, string $recipient): void
    {
        $domain = strtolower(substr($recipient, strrpos($recipient, '@') + 1));
        if ($domain !== $connection['settings']['recipient_domain']) {
            throw Problem::invalid('Recipient domain is outside this inbox connection.');
        }
    }

    private function settings(Scope $scope, string $kind, array $settings): array
    {
        $allowed = match ($kind) {
            'orders' => [],
            'inbox' => ['recipient_domain'],
            'model' => ['model', 'fields', 'replay_body', 'rates'],
            default => throw Problem::invalid('Unknown connection capability.'),
        };
        if (array_diff(array_keys($settings), $allowed)) {
            throw Problem::invalid('Unknown connection settings.');
        }
        if ($kind === 'orders') {
            return [];
        }
        if ($kind === 'inbox') {
            $domain = strtolower(Values::text($settings['recipient_domain'] ?? 'example.test', 'recipient domain', 200));
            if (!filter_var('x@' . $domain, FILTER_VALIDATE_EMAIL) || str_contains($domain, '@')) {
                throw Problem::invalid('Invalid inbox recipient domain.');
            }
            return ['recipient_domain' => $domain];
        }
        $fields = $settings['fields'] ?? ['order_no', 'state'];
        if (!is_array($fields) || !array_is_list($fields) || count($fields) > 4 ||
            array_filter($fields, static fn (mixed $field) => !is_string($field)) ||
            array_diff($fields, ['body', 'subject', 'order_no', 'state']) || !in_array('state', $fields, true)) {
            throw Problem::invalid('Model fields must be approved, including order state.');
        }
        $result = ['model' => Values::text($settings['model'] ?? 'local', 'model name', 90), 'fields' => array_values(array_unique($fields))];
        if (array_key_exists('rates', $settings)) {
            $rates = $settings['rates'];
            if (!is_array($rates) || array_diff(array_keys($rates), ['currency', 'input_per_million_micros', 'output_per_million_micros']) ||
                !is_string($rates['currency'] ?? null) || preg_match('/^[A-Z]{3}$/D', $rates['currency']) !== 1) {
                throw Problem::invalid('Model billing rates require a three-letter currency and input and output prices.');
            }
            $result['rates'] = ['currency' => $rates['currency'],
                'input_per_million_micros' => Values::number($rates['input_per_million_micros'] ?? null, 'input rate', 0, 1_000_000_000_000),
                'output_per_million_micros' => Values::number($rates['output_per_million_micros'] ?? null, 'output rate', 0, 1_000_000_000_000)];
        }
        if (array_key_exists('replay_body', $settings)) {
            if ($scope->environment !== 'test') {
                throw Problem::denied();
            }
            $result['replay_body'] = Values::text($settings['replay_body'], 'recorded draft', 4000);
        }
        return $result;
    }

    private function encrypt(Scope $scope, string $name, int $revision, string $secret): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $body = openssl_encrypt($secret, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag,
            $this->aad($scope, $name, $revision));
        if ($body === false) {
            throw new \RuntimeException('Connection credential could not be encrypted.');
        }
        return $iv . $tag . $body;
    }

    private function key(): string
    {
        $key = getenv('AUTOMATION_ENCRYPTION_KEY');
        if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/iD', $key) !== 1) {
            throw new \RuntimeException('Connection credential encryption is unavailable.');
        }
        return hex2bin($key);
    }

    private function aad(Scope $scope, string $name, int $revision): string
    {
        return Values::json([...$scope->keys(), $name, $revision]);
    }
}
