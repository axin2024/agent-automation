<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';

$db = Db::connect();
$app = new Application($db);
$scope = $app->access->identify('Bearer ' . getenv('AUTOMATION_SMOKE_TOKEN'));
$run = $app->runs->detail($scope, $argv[1] ?? '');
$receipts = $app->deliveries->inbox($scope);
$deliveries = $app->deliveries->forRun($scope, $run['id']);
if ($run['state'] !== 'completed' || $run['result'] !== 'sent' || ($run['ticket']['original_order'] ?? '') !== 'ORD-4001' ||
    count($receipts) !== 1 || $receipts[0]['receipt'] !== ($argv[2] ?? '') || count($deliveries) !== 1 ||
    $deliveries[0]['response_id'] !== $receipts[0]['receipt'] || $deliveries[0]['attempts'] !== 1) {
    throw new RuntimeException('Restored source order, run, ticket, delivery or receipt cannot be linked.');
}
echo Values::json(['restore' => 'passed', 'schema' => $db->one('SELECT MAX(version) AS version FROM schema_migrations')['version'],
    'receipt' => $receipts[0]['receipt'], 'attempts' => $deliveries[0]['attempts']]), PHP_EOL;
