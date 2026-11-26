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
expect($db->migrate(2) === [2] && $db->migrate() === [3, 4, 5, 6], 'forward migrations to versions 2 through 6');
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
expect($app->verifyConnection($admin, 'orders-demo', 1, ['order_no' => 'ORD-4001'])['order']['state'] === 'shipped',
    'test workspace can verify an authorized sample order');
$app->connections->put($production, 'inbox-formal', 'inbox', 0, ['recipient_domain' => 'example.test'], null);
expect($app->verifyConnection($production, 'inbox-formal', 1, ['recipient' => 'buyer@example.test'])['check'] === 'recipient-allowed' &&
    $app->deliveries->inbox($production) === [], 'recipient policy check never creates a formal delivery');
reject(404, fn () => $app->verifyConnection($admin, 'inbox-formal', 1, ['recipient' => 'buyer@example.test']));
reject(403, fn () => $app->verifyConnection($author, 'draft-demo', 1, []));
$modelProbe = $app->verifyConnection($admin, 'draft-demo', 1, []);
expect($modelProbe['credential_configured'] && !str_contains(Values::json($modelProbe), 'authored-test-token'), 'verify model configuration without disclosing its token');
$app->connections->put($admin, 'probe-inbox', 'inbox', 0, ['recipient_domain' => 'example.test'], null);
$app->connections->put($admin, 'probe-inbox', 'inbox', 1, ['recipient_domain' => 'new.example.test'], null);
reject(409, fn () => $app->verifyConnection($admin, 'probe-inbox', 1, ['recipient' => 'buyer@example.test']));
reject(422, fn () => $app->verifyConnection($admin, 'probe-inbox', 2, ['recipient' => 'buyer@example.test']));
expect($app->verifyConnection($admin, 'probe-inbox', 2, ['recipient' => 'buyer@new.example.test'])['revision'] === 2,
    'verify the minimal permission on the rotated revision');

$invalid = $graph;
$invalid['nodes']['classify']['edges']['unknown'] = 'classify';
reject(422, fn () => Graph::compile($invalid));
$skipReview = $graph;
$skipReview['nodes']['draft']['edges']['error'] = 'delivery';
reject(422, fn () => Graph::compile($skipReview));
reject(422, fn () => $app->flows->save($production, 'customer-care', $graph, $bindings, 0, true));
reject(422, fn () => $app->flows->save($author, 'isolated-send', $graph, array_replace($bindings, ['reply_inbox' => 'inbox-formal']), 0, true));
reject(403, fn () => $app->connections->put($author, 'inbox-demo', 'inbox', 1, [], null));
$v1 = $app->flows->save($author, 'customer-care', $graph, $bindings, 0, true);
expect($v1['revision'] === 1 && $v1['active'] === 1, 'freeze working graph');
$template = $app->flows->template($author, 'customer-care', 1);
expect(!str_contains(Values::json($template), 'authored-test-token') && !str_contains(Values::json($template), 'orders-demo'),
    'template carries contracts, excludes local settings and secrets');
$preview = $app->flows->previewTemplate($author, $template);
expect($preview['connection_contract'] === $template['connection_contract'] && count($preview['connections']) === 5 &&
    $preview['fixtures'][0]['message'] === $template['fixtures'][0]['message'] &&
    !str_contains(Values::json($preview), 'authored-test-token'), 'template preview offers scoped connection references');
expect(array_column($app->flows->previewTemplate($production, $template)['connections'], 'connection') === ['inbox-formal'],
    'template preview cannot reuse test connections in production');
reject(403, fn () => $app->flows->previewTemplate($operator, $template));
reject(422, fn () => $app->flows->previewTemplate($author, array_replace($template, ['schema' => 2])));
reject(422, fn () => $app->flows->previewTemplate($author, array_replace($template, ['connection_contract' => ['draft_model' => 'inbox']])));
reject(422, fn () => $app->flows->save($author, 'portable-copy', $preview['definition'], [], 0, false));
$copy = $app->flows->save($author, 'portable-copy', $preview['definition'], $bindings, 0, false);
expect($copy['digest'] === $v1['digest'] && $copy['active'] === null &&
    $app->flows->active($author, 'customer-care') === 1, 'template rebind saves a draft without publishing or changing the source');

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
expect(count(array_filter($app->runs->detail($operator, $first['id'])['history'], static fn (array $step): bool => $step['node'] === 'lookup')) === 1,
    'regeneration only repeats the draft, reusing original lookup output');
$db->exec('UPDATE approvals SET expires=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE tenant=? AND environment=? AND run=? AND node=? AND revision=?',
    [...$approver->keys(), $first['id'], 'review', $review2['revision']]);
reject(409, fn () => $app->approvals->decide($approver, $first['id'], 'review', $review2['revision'], $review2['digest'], 'approve'));
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
    $app->deliveries->forRun($operator, $missing['id']) === [] &&
    array_diff(array_column($corrected['history'], 'node'), ['start', 'classify', 'ticket', 'lookup', 'draft', 'review']) === [] &&
    count($app->deliveries->inbox($operator)) === 1, 'operator correction preserves original path and blocks injected approval or extra tools');
$app->limits($admin, 1, 0);
reject(429, fn () => $app->runs->trigger($trigger, 'customer-care', 'mailbox', array_replace($event, ['message' => '<over@example.test>'])));
$app->limits($admin, 128, 10000);

$changed = $graph;
$changed['nodes']['inquiry']['options']['result'] = 'inquiry-v2';
$app->flows->save($author, 'customer-care', $changed, $bindings, 1, true);
$questions = array_replace($event, ['message' => '<info-v2@example.test>', 'subject' => 'Business hours question', 'body' => 'What are your hours?']);
$oldRun = $app->runs->trigger($author, 'customer-care', 'mailbox', $questions);
$future = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
$plannedEvent = array_replace($questions, ['message' => '<planned@example.test>']);
$planned = $app->runs->trigger($author, 'customer-care', 'mailbox', $plannedEvent, $future);
expect($planned['graph_revision'] === 2 && str_starts_with($planned['ready_at'], Values::utcSecond($future, 'future')) &&
    $app->runs->trigger($operator, 'customer-care', 'mailbox', $plannedEvent, $future)['id'] === $planned['id'],
    'scheduled trigger stores the time and replays its original event');
reject(409, fn () => $app->runs->trigger($author, 'customer-care', 'mailbox', $plannedEvent));
reject(422, fn () => $app->runs->trigger($author, 'customer-care', 'mailbox',
    array_replace($questions, ['message' => '<past@example.test>']), '2000-01-01T00:00:00Z'));
$app->flows->activate($author, 'customer-care', 1, 2);
$newRun = $app->runs->trigger($operator, 'customer-care', 'mailbox', array_replace($questions, ['message' => '<info-v1@example.test>']));
drain($app);
expect($app->runs->detail($operator, $oldRun['id'])['result'] === 'inquiry-v2' &&
    $app->runs->detail($operator, $newRun['id'])['result'] === 'general-inquiry', 'rollback preserves in-flight graph');
$pendingRun = $app->runs->detail($operator, $planned['id']);
expect($pendingRun['state'] === 'queued' && $pendingRun['history'] === [], 'worker does not claim a future run');
$db->exec('UPDATE runs SET ready_at=UTC_TIMESTAMP(6) WHERE tenant=? AND environment=? AND id=?', [...$author->keys(), $planned['id']]);
drain($app);
expect($app->runs->detail($operator, $planned['id'])['result'] === 'inquiry-v2', 'due schedule executes its frozen graph after rollback');
$replay = $graph;
$replay['nodes']['draft']['options']['mode'] = 'replay';
$app->flows->save($author, 'no-draft', $replay, array_replace($bindings, ['draft_model' => 'no-recording']), 0, true);
$unavailable = $app->runs->trigger($trigger, 'no-draft', 'mailbox', array_replace($event, ['message' => '<bad-model@example.test>']));
drain($app);
expect($app->runs->detail($operator, $unavailable['id'])['result'] === 'draft-needs-manual', 'invalid model output follows the declared error branch');
$created = substr($app->runs->detail($operator, $unavailable['id'])['created_at'], 0, 19);
$startSecond = str_replace(' ', 'T', $created) . 'Z';
$nextSecond = gmdate('Y-m-d\TH:i:s\Z', strtotime($created . ' UTC') + 1);
$filtered = $app->runs->list($operator, '', '', 'no-draft', $startSecond, $nextSecond);
expect(array_column($filtered['runs'], 'id') === [$unavailable['id']] && $filtered['next'] === null,
    'run search combines workflow with UTC range');
expect($app->runs->list($operator, '', '', 'no-draft', '', $startSecond)['runs'] === [] &&
    $app->runs->list($outsider, '', '', 'no-draft', $startSecond, $nextSecond)['runs'] === [], 'exclusive upper bound and tenant filter');
reject(422, fn () => $app->runs->list($operator, '', '', 'no-draft', '2026-02-29T00:00:00Z'));
reject(422, fn () => $app->runs->list($operator, '', '', 'no-draft', $nextSecond, $startSecond));

$staleRun = $app->runs->trigger($trigger, 'customer-care', 'mailbox', array_replace($event, ['message' => '<stale-batch@example.test>']));
drain($app);
$validReview = $app->approvals->latest($approver, $missing['id'], 'review');
$staleReview = $app->approvals->latest($approver, $staleRun['id'], 'review');
$otherReviewer = makeScope($app, 'demo', 'test', 'another-reviewer', 'approver');
$due = gmdate('Y-m-d\TH:i:s\Z', strtotime($validReview['expires'] . ' UTC') + 1);
$app->approvals->assign($operator, $missing['id'], 'review', $validReview['revision'], null, $otherReviewer->actor);
expect(array_column($app->approvals->pending($operator, $otherReviewer->actor, $due)['items'], 'run') === [$missing['id']] &&
    $app->approvals->pending($production, $otherReviewer->actor, $due)['items'] === [], 'pending review filters by owner and environment');
$beforeExpiry = str_replace(' ', 'T', substr($validReview['expires'], 0, 19)) . 'Z';
expect($app->approvals->pending($operator, $otherReviewer->actor, $beforeExpiry)['items'] === [], 'pending review excludes deadline boundary');
reject(403, fn () => $app->approvals->pending($author));
reject(403, fn () => $app->approvals->decide($approver, $missing['id'], 'review', $validReview['revision'], $validReview['digest'], 'reject'));
reject(409, fn () => $app->approvals->assign($operator, $missing['id'], 'review', $validReview['revision'], null, $approver->actor));
reject(422, fn () => $app->approvals->assign($operator, $missing['id'], 'review', $validReview['revision'], $otherReviewer->actor, $production->actor));
$app->approvals->assign($otherReviewer, $missing['id'], 'review', $validReview['revision'], $otherReviewer->actor, $approver->actor);
expect($app->approvals->view($approver, $missing['id'])[0]['assignee'] === $approver->actor &&
    count(array_filter($app->audit($admin, 0), static fn (array $entry): bool => $entry['action'] === 'review-assigned' &&
        $entry['actor'] === $otherReviewer->actor && $entry['detail']['assignee'] === $approver->actor)) === 1,
    'delegation preserves the real actor and assigns the pending review');
$firstItem = ['run' => $missing['id'], 'node' => 'review', 'revision' => $validReview['revision'],
    'digest' => $validReview['digest'], 'action' => 'reject'];
$staleItem = array_replace($firstItem, ['run' => $staleRun['id'], 'revision' => $staleReview['revision'], 'digest' => $staleReview['digest']]);
$missingItem = array_replace($firstItem, ['run' => str_repeat('0', 32)]);
$selection = [$firstItem, $staleItem, $missingItem];
$batch = $app->reviewBatches->prepare($approver, 'batch-resume', $selection);
expect($batch['completed'] === 0 && $app->reviewBatches->prepare($approver, 'batch-resume', $selection) === $batch,
    'same request recovers the saved selection');
reject(409, fn () => $app->reviewBatches->prepare($approver, 'batch-resume', [$firstItem]));
reject(422, fn () => $app->reviewBatches->prepare($approver, 'duplicate', [$firstItem, $firstItem]));
reject(403, fn () => $app->reviewBatches->advance($operator, 'batch-resume'));
reject(404, fn () => $app->reviewBatches->get($otherReviewer, 'batch-resume'));
reject(404, fn () => $app->reviewBatches->get($production, 'batch-resume'));
$app->approvals->revise($approver, $staleRun['id'], 'review', $staleReview['revision'], ['recipient' => 'another@example.test']);
$partial = $app->reviewBatches->advance($approver, 'batch-resume', 1);
expect($partial['completed'] === 1 && $partial['items'][0]['receipt']['state'] === 'rejected' &&
    $partial['items'][1]['state'] === 'pending', 'process a bounded prefix');
$reconnected = new Application(Db::connect());
expect(Values::digest($reconnected->reviewBatches->get($approver, 'batch-resume')) === Values::digest($partial),
    'read the committed cursor from a separate connection');
$finished = $reconnected->reviewBatches->advance($approver, 'batch-resume');
expect($finished['completed'] === 3 && array_column($finished['items'], 'state') === ['applied', 'rejected', 'rejected'] &&
    $finished['items'][1]['error'] === 'state-conflict' && $finished['items'][2]['error'] === 'not-found' &&
    Values::digest($app->reviewBatches->advance($approver, 'batch-resume')) === Values::digest($finished),
    'stable per-item outcomes after resume and retry');
expect(count(array_filter($app->audit($admin, 0), static fn (array $entry): bool =>
    $entry['action'] === 'review-rejected' && $entry['object'] === $missing['id'])) === 1 &&
    count($app->deliveries->inbox($operator)) === 1, 'retry has no duplicate decision or delivery');
$metrics = $app->statistics($operator);
$timings = array_column($metrics['operation_durations'], 'count', 'kind');
expect($timings['human-review'] === 2 && $timings['human-correction'] === 1 && $timings['local-inbox'] === 1 &&
    $timings['local-orders'] >= 3 && count($metrics['models']) >= 2 && $app->statistics($production)['operation_durations'] === [],
    'human wait, model and local adapter duration have separate scoped totals');
expect(array_column($metrics['evaluations'], 'count', 'label') === ['extraction-wrong' => 1] &&
    $app->evaluations($operator, $missing['id'])[0]['actor'] === $operator->actor, 'feedback is linked to the run and reviewer');

$db->exec('UPDATE runs SET updated_at=UTC_TIMESTAMP(6)-INTERVAL 10 DAY WHERE tenant=? AND environment=? AND id IN (?,?)',
    [...$admin->keys(), $first['id'], $staleRun['id']]);
$db->exec('UPDATE model_usage SET started_at=UTC_TIMESTAMP(6)-INTERVAL 10 DAY WHERE tenant=? AND environment=? AND run IN (?,?)',
    [...$admin->keys(), $first['id'], $staleRun['id']]);
$db->exec("UPDATE audit SET happened_at=UTC_TIMESTAMP(6)-INTERVAL 10 DAY WHERE tenant=? AND environment=? AND object=? AND action='run-triggered'",
    [...$admin->keys(), $first['id']]);
reject(403, fn () => $app->retention->apply($operator, 10));
expect(array_sum($app->retention->apply($admin, 10)) === 0, 'zero settings protect old data by default');
$app->retention->configure($admin, 5, 0, 0);
$redaction = $app->retention->apply($admin, 1);
$archived = $app->runs->detail($operator, $first['id']);
$afterRedaction = $app->deliveries->inbox($operator)[0];
expect($redaction === ['runs_redacted' => 1, 'metering_deleted' => 0, 'audit_deleted' => 0] &&
    !$archived['body_retained'] && !isset($archived['event']['body']) && !isset($archived['outputs']['start']['body']) &&
    !isset($archived['outputs']['draft']['draft']['body']) && !isset($app->approvals->view($operator, $first['id'])[0]['draft']['body']) &&
    !$afterRedaction['body_retained'] && $afterRedaction['receipt'] === $inbox[0]['receipt'], 'body policy redacts persisted copies and preserves receipts');
expect($app->runs->detail($operator, $staleRun['id'])['body_retained'] &&
    $app->runs->trigger($trigger, 'customer-care', 'mailbox', $event)['id'] === $first['id'] &&
    $app->deliveries->reconcile($operator, $delivery['operation'])['receipt'] === $afterRedaction['receipt'],
    'active payload, event identity and receipt verification survive redaction');
$app->retention->configure($admin, 0, 5, 5);
$expiry = $app->retention->apply($admin, 10);
expect($expiry['runs_redacted'] === 0 && $expiry['metering_deleted'] === 2 && $expiry['audit_deleted'] === 1 &&
    $app->retention->policy($production) === ['body_days' => 0, 'metering_days' => 0, 'audit_days' => 0] &&
    $db->one('SELECT COUNT(*) AS n FROM model_usage WHERE tenant=? AND environment=? AND run=?', [...$admin->keys(), $staleRun['id']])['n'] === 1 &&
    array_sum($app->retention->apply($admin, 10)) === 0, 'separate expiry windows preserve active metering and repeat safely');

echo Values::json([
    'schema' => '1->2->3->4->5->6', 'approval_revisions' => 3, 'receipt_loss' => 'reconciled',
    'batch_resume' => array_column($finished['items'], 'state'),
    'scheduled_trigger' => 'executed-frozen-graph',
    'retention' => ['redacted' => $redaction['runs_redacted'], 'metering' => $expiry['metering_deleted'], 'audit' => $expiry['audit_deleted']],
    'duration_samples' => $timings,
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
