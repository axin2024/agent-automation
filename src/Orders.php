<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Orders
{
    public function __construct(private Db $db)
    {
    }

    public static function orderNumber(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^ORD-[0-9]{4,12}$/D', $value) !== 1) {
            throw Problem::invalid('Expected an order like ORD-4001.');
        }
        return $value;
    }

    public function get(Scope $scope, string $number, bool $lock = false): ?array
    {
        return $this->db->one('SELECT order_no,state,version FROM orders
            WHERE tenant=? AND environment=? AND order_no=?' . ($lock ? ' FOR UPDATE' : ''), [...$scope->keys(), $number]);
    }

    public function put(Scope $scope, string $number, string $state, int $expected): array
    {
        $scope->require('operator');
        self::orderNumber($number);
        if (!in_array($state, ['placed', 'shipped', 'delivered', 'returned', 'cancelled'], true) || $expected < 0) {
            throw Problem::invalid('Invalid order state or version.');
        }
        return $this->db->transaction(function () use ($scope, $number, $state, $expected): array {
            $this->db->one('SELECT tenant FROM tenants WHERE tenant=? FOR UPDATE', [$scope->tenant]);
            $row = $this->get($scope, $number, true);
            if (($row['version'] ?? 0) !== $expected) {
                throw Problem::conflict('The order version changed.');
            }
            if ($row !== null && $row['state'] === $state) {
                return $row;
            }
            $version = $expected + 1;
            $this->db->exec('INSERT INTO orders(tenant,environment,order_no,state,version) VALUES(?,?,?,?,?)
                ON DUPLICATE KEY UPDATE state=?, version=?', [...$scope->keys(), $number, $state, $version, $state, $version]);
            $this->db->audit($scope, 'order-state', $number, ['version' => $version, 'state' => $state]);
            return ['order_no' => $number, 'state' => $state, 'version' => $version];
        });
    }
}
