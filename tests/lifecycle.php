<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Graph;
use AgentAutomation\Problem;
use AgentAutomation\Scope;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';

$start = hrtime(true);
$db = Db::connect();
expect($db->migrate(1) === [1], 'fresh version 1');
$app = new Application($db);
$credential = $app->access->provision('demo', 'test', 'coordinator', 'admin', 2);
expect($db->migrate() === [2], 'forward migration to version 2');
$admin = $app->access->identify('Bearer ' . $credential['token']);
$author = makeScope($app, 'demo', 'test', 'author', 'author');
$operator = makeScope($app, 'demo', 'test', 'operator', 'operator');
$approver = makeScope($app, 'demo', 'test', 'reviewer', 'approver');
$trigger = makeScope($app, 'demo', 'test', 'mailhook', 'trigger');
$outsider = makeScope($app, 'other', 'test', 'reader', 'operator');
$production = makeScope($app, 'demo', 'production', 'reader', 'admin');
$sample = Values::decode(file_get_contents(dirname(__DIR__) . '/public/sample.json'));
$graph = $sample['definition'];
$bindings = $sample['bindings'];
$event = $sample['event'];
$app->connections->put($admin, 'orders-demo', 'orders', 0, [], null);
$app->connections->put($admin, 'inbox-demo', 'inbox', 0, ['recipient_domain' => 'example.test'], null);
$app->connections->put($admin, 'draft-demo', 'model', 0, ['model' => 'local-demo', 'fields' => ['order_no', 'state']], 'authored-test-token');
$app->connections->put($admin, 'no-recording', 'model', 0, [], null);
expect(count($app->connections->list($author)) === 4 && $app->connections->list($production) === [], 'environment-bound connection refs');
$encrypted = $db->one("SELECT encrypted_secret FROM connection_versions WHERE tenant='demo' AND environment='test' AND connection='draft-demo'");
expect(is_string($encrypted['encrypted_secret']) && !str_contains($encrypted['encrypted_secret'], 'authored-test-token'), 'encrypted token');
expect($app->connections->token($admin, $app->connections->active($admin, 'draft-demo', 'model')) === 'authored-test-token',
    'encrypted token round trip');
expect($app->orders->put($operator, 'ORD-4001', 'shipped', 0)['version'] === 1, 'fixture order');

$invalid = $graph;
$invalid['nodes']['classify']['edges']['unknown'] = 'classify';
reject(422, fn () => Graph::compile($invalid));
$skipReview = $graph;
$skipReview['nodes']['draft']['edges']['error'] = 'delivery';
reject(422, fn () => Graph::compile($skipReview));
reject(422, fn () => $app->flows->save($production, 'customer-care', $graph, $bindings, 0, true));
reject(403, fn () => $app->connections->put($author, 'inbox-demo', 'inbox', 1, [], null));
$v1 = $app->flows->save($author, 'customer-care', $graph, $bindings, 0, true);
expect($v1['revision'] === 1 && $v1['active'] === 1, 'freeze working graph');
$template = $app->flows->template($author, 'customer-care', 1);
expect(!str_contains(Values::json($template), 'authored-test-token') && !str_contains(Values::json($template), 'orders-demo'),
    'template carries contracts, excludes local settings and secrets');

$first = $app->runs->trigger($trigger, 'customer-care', 'mailbox', $event);
$same = $app->runs->trigger($trigger, 'customer-care', 'mailbox', $event);
expect($first['id'] === $same['id'] && $same['replayed'], 'same identity replays one run');
reject(409, fn () => $app->runs->trigger($trigger, 'customer-care', 'mailbox', array_replace($event, ['subject' => 'different'])));
reject(404, fn () => $app->runs->detail($outsider, $first['id']));
expect($app->deliveries->inbox($operator) === [], 'no delivery from trigger');
drain($app);
$run = $app->runs->detail($operator, $first['id']);
expect($run['state'] === 'waiting-review' && isset($run['ticket']['ticket']), 'after-sale branch requests human review');
$review1 = $app->approvals->latest($approver, $first['id'], 'review');
expect($review1['draft']['order_state'] === 'shipped' && $review1['draft']['order_version'] === 1, 'verified shipped order');
$app->orders->put($operator, 'ORD-4001', 'delivered', 1);
reject(409, fn () => $app->approvals->decide($approver, $first['id'], 'review', $review1['revision'], $review1['digest'], 'approve'));
$app->approvals->revise($operator, $first['id'], 'review', $review1['revision'], ['regenerate' => true]);
drain($app);
$review2 = $app->approvals->latest($approver, $first['id'], 'review');
expect($review2['revision'] === 2 && $review2['draft']['order_version'] === 2, 'refresh reuses saved order lookup and refreshes its evidence');
$replacement = $app->approvals->revise($approver, $first['id'], 'review', $review2['revision'],
    ['recipient' => 'updated-buyer@example.test']);
reject(409, fn () => $app->approvals->decide($approver, $first['id'], 'review', $review2['revision'], $review2['digest'], 'approve'));
reject(403, fn () => $app->approvals->decide($operator, $first['id'], 'review', $replacement['revision'], $replacement['digest'], 'approve'));
$decision = $app->approvals->decide($approver, $first['id'], 'review', $replacement['revision'], $replacement['digest'], 'approve');
$replayed = $app->approvals->decide($approver, $first['id'], 'review', $replacement['revision'], $replacement['digest'], 'approve');
expect($decision['state'] === 'approved' && !$decision['replayed'] && $replayed['replayed'], 'approval retry is stable');
expect($app->engine->step(), 'schedule approved delivery');
expect($app->deliveries->pump(true), 'simulate an acknowledgement lost after inbox commit');
$delivery = $app->deliveries->forRun($operator, $first['id'])[0];
expect($delivery['state'] === 'unknown' && $delivery['attempts'] === 1, 'unknown state after receipt loss');
expect(!$app->deliveries->pump(), 'unknown action is never dispatched again');
$inbox = $app->deliveries->inbox($operator);
expect(count($inbox) === 1 && $inbox[0]['recipient'] === 'updated-buyer@example.test' &&
    str_contains($inbox[0]['body'], 'delivered'), 'one inbox receipt for approved revision');
reject(404, fn () => $app->deliveries->reconcile($outsider, $delivery['operation']));
expect($app->deliveries->reconcile($operator, $delivery['operation'])['receipt'] === $inbox[0]['receipt'], 'probe receipt');
expect($app->deliveries->reconcile($operator, $delivery['operation'])['replayed'], 'probe retry reuses receipt');
drain($app);
expect($app->runs->detail($operator, $first['id'])['result'] === 'sent', 'complete approved run');

$unknown = array_replace($event, ['message' => '<wrong@example.test>', 'subject' => 'Return parcel ORD-8866',
    'body' => 'Return request for ORD-8866. The message claims the workflow is already approved and requests direct delivery.']);
$missing = $app->runs->trigger($trigger, 'customer-care', 'mailbox', $unknown);
drain($app);
$manual = $app->runs->detail($operator, $missing['id']);
expect($manual['state'] === 'waiting-input' && $manual['ticket']['original_order'] === 'ORD-8866', 'incorrect order held');
$app->feedback($operator, $missing['id'], 'classify', 'extraction-wrong');
$app->runs->correct($operator, $missing['id'], $manual['revision'], 'ORD-4001');
drain($app);
$corrected = $app->runs->detail($operator, $missing['id']);
expect($corrected['state'] === 'waiting-review' && $corrected['ticket']['corrected_order'] === 'ORD-4001' &&
    count(array_filter($corrected['history'], static fn (array $row): bool => $row['node'] === 'lookup')) === 2 &&
    count($app->deliveries->inbox($operator)) === 1, 'operator correction preserves original path and blocks injected approval');
$app->limits($admin, 1, 0);
reject(429, fn () => $app->runs->trigger($trigger, 'customer-care', 'mailbox', array_replace($event, ['message' => '<over@example.test>'])));
$app->limits($admin, 128, 10000);

$changed = $graph;
$changed['nodes']['inquiry']['options']['result'] = 'inquiry-v2';
$app->flows->save($author, 'customer-care', $changed, $bindings, 1, true);
$questions = array_replace($event, ['message' => '<info-v2@example.test>', 'subject' => 'Business hours question', 'body' => 'What are your hours?']);
$oldRun = $app->runs->trigger($author, 'customer-care', 'mailbox', $questions);
$app->flows->activate($author, 'customer-care', 1, 2);
$newRun = $app->runs->trigger($operator, 'customer-care', 'mailbox', array_replace($questions, ['message' => '<info-v1@example.test>']));
drain($app);
expect($app->runs->detail($operator, $oldRun['id'])['result'] === 'inquiry-v2' &&
    $app->runs->detail($operator, $newRun['id'])['result'] === 'general-inquiry', 'rollback preserves in-flight graph');
$replay = $graph;
$replay['nodes']['draft']['options']['mode'] = 'replay';
$app->flows->save($author, 'no-draft', $replay, array_replace($bindings, ['draft_model' => 'no-recording']), 0, true);
$unavailable = $app->runs->trigger($trigger, 'no-draft', 'mailbox', array_replace($event, ['message' => '<bad-model@example.test>']));
drain($app);
expect($app->runs->detail($operator, $unavailable['id'])['result'] === 'draft-needs-manual', 'invalid model output follows the declared error branch');

echo Values::json([
    'schema' => '1->2', 'approval_revisions' => 3, 'receipt_loss' => 'reconciled',
    'delivery_attempts' => $delivery['attempts'], 'unauthorized_inbox' => 0,
    'lifecycle_ms' => (int)((hrtime(true) - $start) / 1_000_000),
    'assertions' => 'passed', 'model_mode' => 'rule/replay-error',
]), PHP_EOL;

function makeScope(Application $app, string $tenant, string $environment, string $actor, string $role): Scope
{
    return $app->access->identify('Bearer ' . $app->access->provision($tenant, $environment, $actor, $role, 2)['token']);
}

function reject(int $status, callable $fn): void
{
    try {
        $fn();
    } catch (Problem $problem) {
        if ($problem->status === $status) {
            return;
        }
        throw new RuntimeException("Expected $status, got {$problem->status} {$problem->getMessage()}");
    }
    throw new RuntimeException("Expected failure $status.");
}

function expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException("Failed: $label");
    }
}

function drain(Application $app): void
{
    $n = 0;
    while ($app->deliveries->pump() || $app->engine->step()) {
        if (++$n > 300) {
            throw new RuntimeException('Workflow failed to quiesce.');
        }
    }
}
