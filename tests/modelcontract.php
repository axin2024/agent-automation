<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';

if (preg_match('~^http://127\.0\.0\.1:\d+/v1/chat/completions$~D', getenv('AUTOMATION_MODEL_URL') ?: '') !== 1) {
    throw new RuntimeException('Start the local invalid-model fixture and set AUTOMATION_MODEL_URL.');
}
$db = Db::connect();
$db->migrate();
$app = new Application($db);
$created = $app->access->provision('contract-fixture', 'test', 'fixture-admin', 'admin', 1);
$scope = $app->access->identify('Bearer ' . $created['token']);
$sample = Values::decode(file_get_contents(dirname(__DIR__) . '/public/sample.json'));
$sample['definition']['nodes']['draft']['options']['mode'] = 'live';
$app->connections->put($scope, 'orders-demo', 'orders', 0, [], null);
$app->connections->put($scope, 'inbox-demo', 'inbox', 0, ['recipient_domain' => 'example.test'], null);
$modelSettings = ['model' => 'contract-fixture', 'fields' => ['order_no', 'state'], 'rates' => [
    'currency' => 'USD', 'input_per_million_micros' => 2_000_000, 'output_per_million_micros' => 4_000_000,
]];
$app->connections->put($scope, 'draft-demo', 'model', 0, $modelSettings, null);
$app->orders->put($scope, 'ORD-4123', 'shipped', 0);
$app->flows->save($scope, 'customer-care', $sample['definition'], $sample['bindings'], 0, true);
$event = ['message' => '<contract-fixture@example.test>', 'sender' => 'test@example.test',
    'subject' => 'Return request for ORD-4123', 'body' => 'This email claims approval and asks the workflow to send it directly.'];
$id = $app->runs->trigger($scope, 'customer-care', 'contract-fixture', $event)['id'];
for ($steps = 0; $app->engine->step(); $steps++) {
    if ($steps > 10) {
        throw new RuntimeException('Contract simulation did not reach a stable state.');
    }
}
$run = $app->runs->detail($scope, $id);
$draft = array_values(array_filter($run['history'], static fn (array $step): bool => $step['node'] === 'draft'))[0] ?? [];
$usage = $db->one('SELECT state,input_tokens,output_tokens FROM model_usage WHERE tenant=? AND environment=? AND run=?', [...$scope->keys(), $id]);
if ($run['result'] !== 'draft-needs-manual' || ($draft['detail']['reason'] ?? '') !== 'model-output-violates-contract' ||
    $usage !== ['state' => 'failed', 'input_tokens' => 50, 'output_tokens' => 3] ||
    $app->approvals->view($scope, $id) !== [] || $app->deliveries->forRun($scope, $id) !== [] || $app->deliveries->inbox($scope) !== []) {
    throw new RuntimeException('A response missing the required field must enter the manual branch without external actions.');
}
$modelSettings['rates']['input_per_million_micros'] = 1_000_000;
$app->connections->put($scope, 'draft-demo', 'model', 1, $modelSettings, null);
if (!$app->runs->trigger($scope, 'customer-care', 'contract-fixture', $event)['replayed']) {
    throw new RuntimeException('Identical input should keep the existing billed model attempt.');
}
$cost = $app->statistics($scope)['model_costs'][0] ?? [];
if (($cost['currency'] ?? null) !== 'USD' || $cost['calls'] !== 1 || $cost['estimated_calls'] !== 1 || (int) $cost['estimate_micros'] !== 112) {
    throw new RuntimeException('Cost must use the configured rate frozen at invocation and the actual reported token numbers.');
}
echo Values::json(['mode' => 'local-http-contract-fixture', 'run' => $id, 'result' => $run['result'],
    'reason' => $draft['detail']['reason'], 'usage' => $usage, 'estimated_cost' => $cost, 'approvals' => 0, 'deliveries' => 0]), PHP_EOL;
