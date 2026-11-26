<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';

$model = getenv('AUTOMATION_TEST_MODEL');
if (!$model || !getenv('AUTOMATION_MODEL_URL')) {
    throw new RuntimeException('AUTOMATION_MODEL_URL and AUTOMATION_TEST_MODEL must point to a running inference service.');
}
$db = Db::connect();
$db->migrate();
$app = new Application($db);
$grant = $app->access->provision('live-probe', 'test', 'fixture-admin', 'admin', 2);
$admin = $app->access->identify('Bearer ' . $grant['token']);
$sample = Values::decode(file_get_contents(dirname(__DIR__) . '/public/sample.json'));
$sample['definition']['nodes']['draft']['options']['mode'] = 'live';
$app->connections->put($admin, 'orders-demo', 'orders', 0, [], null);
$app->connections->put($admin, 'inbox-demo', 'inbox', 0, ['recipient_domain' => 'example.test'], null);
$app->connections->put($admin, 'draft-demo', 'model', 0, ['model' => $model, 'fields' => ['order_no', 'state']],
    getenv('AUTOMATION_TEST_MODEL_TOKEN') ?: null);
$app->flows->save($admin, 'customer-care', $sample['definition'], $sample['bindings'], 0, true);
$suffix = random_int(51000, 99000);
$answers = ['placed', 'shipped', 'delivered'];
$runs = [];
foreach ($answers as $index => $state) {
    $order = 'ORD-' . ($suffix + $index);
    $app->orders->put($admin, $order, $state, 0);
    $event = ['message' => "<live-$index@example.test>", 'sender' => 'synthetic-client@example.test',
        'subject' => "Return request for $order", 'body' => "Please help with a return for $order."];
    $run = $app->runs->trigger($admin, 'customer-care', 'live-probe', $event);
    $runs[] = ['run' => $run['id'], 'order' => $order, 'state' => $state];
}
for ($steps = 0; $app->engine->step(); $steps++) {
    if ($steps > 80) {
        throw new RuntimeException('Live test failed to quiesce.');
    }
}
$results = [];
foreach ($runs as ['run' => $id, 'order' => $order, 'state' => $expected]) {
    $run = $app->runs->detail($admin, $id);
    $review = $run['state'] === 'waiting-review' ? $app->approvals->latest($admin, $id, 'review') : null;
    $usage = $db->one('SELECT state,reserved_tokens,input_tokens,output_tokens,milliseconds FROM model_usage
        WHERE tenant=? AND environment=? AND run=?', [...$admin->keys(), $id]);
    $body = $review['draft']['body'] ?? '';
    $facts = $review !== null && str_contains($body, $order) && str_contains(strtolower($body), $expected);
    $results[] = ['order' => $order, 'expected' => $expected, 'run_state' => $run['state'], 'body' => $body,
        'has_expected_facts' => $facts, 'usage' => $usage];
}
$sent = count($app->deliveries->inbox($admin));
$passed = count(array_filter($results, static fn (array $result): bool => $result['has_expected_facts']));
$costs = $app->statistics($admin)['model_costs'];
echo Values::json(['model' => $model, 'passed' => $passed, 'case_count' => count($results), 'cases' => $results,
    'inbox_receipts' => $sent, 'unpriced_invocations' => $costs[0]['calls'] ?? null]), PHP_EOL;
if ($passed !== count($answers) || $sent !== 0 || $costs !== [[
    'currency' => null, 'calls' => count($answers), 'estimated_calls' => 0, 'estimate_micros' => null,
]]) {
    exit(1);
}
