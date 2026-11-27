<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/case-fixtures.php';

$db = Db::connect();
$clock = new DateTimeImmutable('2026-11-27T08:30:00Z');
$app = new Application($db, static function () use (&$clock): DateTimeImmutable { return $clock; });
if (($argv[1] ?? '') === '--review') {
    $request = Values::decode(stream_get_contents(STDIN));
    $clock = new DateTimeImmutable($request['clock']);
    $scope = $app->access->identify('Bearer ' . $request['token']);
    $receipt = $app->caseAssignments->update($scope, $request['case'], $request['task'], $request['request_id'], $request['input']);
    echo Values::json($receipt);
    exit;
}

$db->migrate();
$actors = caseCredentials($app, 'case-lifecycle');
$supervisor = $actors['supervisor']['scope'];
$investigator = $actors['investigator']['scope'];
$deputy = $actors['deputy']['scope'];
$operations = $actors['operations']['scope'];
$finance = $actors['finance']['scope'];
$owner = $actors['decision-owner']['scope'];
$outside = caseCredentials($app, 'outside-case-lifecycle')['supervisor']['scope'];
$template = caseTemplate();
caseCheck($app->caseTemplates->preview($supervisor, $template, 'after-sales')['matches'], 'template source preview');
$version = $app->caseTemplates->publish($supervisor, 'returns', 0, $template);
caseCheck($version['case_type_rev'] === 1, 'first template version');
$case = caseIntake($app, $investigator, 'sale-11')['case_id'];
$separate = caseIntake($app, $investigator, 'sale-12')['case_id'];
caseCheck($case !== $separate && caseIntake($app, $investigator, 'sale-11')['case_id'] === $case, 'A1 deduplicated independent cases');
$app->cases->setIntake($supervisor, false);
caseReject(503, fn () => caseIntake($app, $investigator, 'paused-1'));
caseCheck(caseIntake($app, $investigator, 'sale-11')['case_id'] === $case, 'intake pause preserves existing idempotent receipts');
$app->cases->setIntake($supervisor, true);
caseReject(409, fn () => $app->cases->intake($investigator, 'open-updated', [
    'case_type' => 'returns', 'source' => 'after-sales', 'external_ref' => 'sale-11', 'customer_ref' => 'customer-local-100',
    'subject' => 'Different intake', 'order_no' => 'ORD-4001',
]));
caseReject(404, fn () => $app->cases->detail($outside, $case));
$next = $app->caseTemplates->publish($supervisor, 'returns', 1, array_replace($template, ['refund_limit_minor' => 5000]));
caseCheck($next['case_type_rev'] === 2 && $app->cases->detail($supervisor, $case)['case_type_rev'] === 1, 'old template pinned');
caseWriteTask($app, $investigator, $case, 'investigate', 'claim');
caseWriteTask($app, $investigator, $case, 'investigate', 'delegate', ['actor' => 'deputy', 'hours' => 2, 'handoff' => 'Check the parcel.']);
$lease = caseTask($app, $deputy, $case, 'investigate', 'complete');
caseWriteTask($app, $investigator, $case, 'investigate', 'revoke');
caseReject(409, fn () => $app->caseAssignments->update($deputy, $case, 'investigate', 'stale-lease', $lease));
caseReject(403, fn () => caseWriteTask($app, $deputy, $case, 'investigate', 'complete'));
caseCheck(count(caseWriteTask($app, $investigator, $case, 'investigate', 'complete')['missing']) === 3, 'verified evidence gate');
$summary = caseMaterial($app, $investigator, $case, 'customer-summary', 'delivery');
$order = caseMaterial($app, $investigator, $case, 'order', 'delivered');
$proof = caseMaterial($app, $finance, $case, 'payment-proof', 'captured');
caseCheck(count($app->caseEvidence->list($investigator, $case)) === 2 && count($app->caseEvidence->list($finance, $case)) === 3,
    'finance fields hidden from investigators');

$socket = stream_socket_server('tcp://127.0.0.1:0');
caseCheck(is_resource($socket), 'reserve local model fixture port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tmpfile();
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/case-model.php'],
    [['pipe', 'r'], $log, $log], $pipes);
caseCheck(is_resource($process), 'launch model request for contract inspection');
fclose($pipes[0]);
$fixtureState = [];
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client("tcp://$address", $errno, $error, 0.2);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    caseCheck($ready, 'fixture ready');
    $savedURL = getenv('AUTOMATION_CASE_MODEL_URL');
    $savedName = getenv('AUTOMATION_CASE_MODEL_NAME');
    putenv("AUTOMATION_CASE_MODEL_URL=http://$address/v1/chat/completions");
    putenv('AUTOMATION_CASE_MODEL_NAME=bounded-fixture');
    $revision = ['revision' => $app->cases->detail($investigator, $case)['revision']];
    $fixtures = [];
    foreach (['classify' => $investigator, 'investigate' => $investigator, 'recommend' => $owner] as $role => $scope) {
        $fixtures[$role] = $app->caseAssistant->generate($scope, $case, $role, "$role-contract", $revision);
        caseCheck($fixtures[$role]['state'] === 'ready' && $fixtures[$role]['usage']['input_tokens'] === 17, "A5 $role model input boundary");
    }
    caseCheck(array_column($fixtures['classify']['suggestion']['citations'], 'evidence_id') === [$summary['evidence_id']] &&
        array_column($fixtures['investigate']['suggestion']['citations'], 'evidence_id') === [$order['evidence_id']] &&
        array_column($fixtures['recommend']['suggestion']['citations'], 'evidence_id') === [$order['evidence_id'], $proof['evidence_id']],
        'A7 exact evidence references by separate assistant role');
    putenv('AUTOMATION_CASE_MODEL_NAME=fabricated-fixture');
    $fabricated = $app->caseAssistant->generate($owner, $case, 'recommend', 'fabricated-citation', $revision);
    caseCheck($fabricated['state'] === 'needs-human' && $fabricated['suggestion']['reason'] === 'invalid-citation' &&
        $fabricated['usage']['output_tokens'] === 7, 'A8 fabricated evidence rejected and metered');
    $edited = $app->caseAssistant->revise($owner, $case, 'human-revision', $revision + [
        'based_on' => $fixtures['recommend']['proposal_id'], 'label' => 'refund',
        'citations' => [$order['evidence_id'], $proof['evidence_id']], 'note' => 'Verified on the fixture payment record.',
    ]);
    caseCheck($edited['state'] === 'ready', 'named human revision');
    caseMaterial($app, $investigator, $case, 'order', 'delivered');
    $stale = array_column($app->caseAssistant->list($supervisor, $case), 'state', 'proposal_id');
    caseCheck($stale[$edited['proposal_id']] === 'stale' && $stale[$fixtures['classify']['proposal_id']] === 'stale',
        'A6 newer evidence invalidates proposals');
    $fixtureState = ['fixture_calls' => 4, 'fabricated_citations_rejected' => 1, 'stale_suggestions' => 4];
} finally {
    proc_terminate($process);
    proc_close($process);
    fclose($log);
    if (isset($savedURL)) putenv($savedURL === false ? 'AUTOMATION_CASE_MODEL_URL' : 'AUTOMATION_CASE_MODEL_URL=' . $savedURL);
    if (isset($savedName)) putenv($savedName === false ? 'AUTOMATION_CASE_MODEL_NAME' : 'AUTOMATION_CASE_MODEL_NAME=' . $savedName);
}

$runReviews = static function () use ($app, $investigator, $operations, $finance, $actors, $case, &$clock): void {
    $current = $app->cases->detail($investigator, $case);
    if (in_array($current['state'], ['investigating', 'needs-evidence'], true)) {
        caseWriteTask($app, $investigator, $case, 'investigate', 'complete');
    }
    $commands = [];
    foreach (['operations' => $operations, 'finance' => $finance] as $name => $scope) {
        $task = 'review-' . $name;
        caseWriteTask($app, $scope, $case, $task, 'claim');
        $commands[] = ['token' => $actors[$name]['token'], 'case' => $case, 'task' => $task,
            'request_id' => bin2hex(random_bytes(10)), 'input' => caseTask($app, $scope, $case, $task, 'complete'),
            'clock' => $clock->format(DATE_ATOM)];
    }
    $jobs = [];
    foreach ($commands as $command) {
        $php = [PHP_BINARY];
        if (getenv('AUTOMATION_TEST_PDO_MYSQL_EXTENSION')) {
            array_push($php, '-d', 'extension=pdo_mysql');
        }
        array_push($php, __FILE__, '--review');
        $process = proc_open($php, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        caseCheck(is_resource($process), 'start a parallel reviewer');
        fwrite($pipes[0], Values::json($command));
        fclose($pipes[0]);
        $jobs[] = [$process, $pipes[1], $pipes[2]];
    }
    foreach ($jobs as [$process, $out, $err]) {
        $text = stream_get_contents($out);
        $errors = stream_get_contents($err);
        fclose($out);
        fclose($err);
        caseCheck(proc_close($process) === 0 && Values::decode($text)['state'] === 'complete', 'parallel reviewer: ' . $errors);
    }
    caseCheck($app->cases->detail($investigator, $case)['state'] === 'decision', 'both reviews reach decision stage');
};

$runReviews();
caseCheck(count(array_filter($app->cases->timeline($supervisor, $case), static fn ($event) => ($event['detail']['to'] ?? '') === 'decision')) === 1,
    'A3 one stage transition after two simultaneous reviewers');
caseWriteTask($app, $owner, $case, 'decision', 'claim');
$propose = static function (string $payee, string $key, int $amount = 10000) use ($app, $owner, $case): array {
    return $app->caseDecisions->propose($owner, $case, $key, ['revision' => $app->cases->detail($owner, $case)['revision'],
        'kind' => 'refund', 'payee' => $payee, 'amount_minor' => $amount, 'reason' => 'The verified case qualifies for a refund.']);
};
$vote = static fn (array $proposal, string $role): array => array_intersect_key($proposal, array_flip(['proposal_id', 'evidence_rev', 'args_hash']))
    + ['approval_role' => $role];
caseReject(422, fn () => $propose('demo-original', 'limit-check', 26000));
caseReject(422, fn () => $propose('unverified-payee', 'payee-check'));
$draft = $propose('demo-original', 'draft-first');
$financeVote = $vote($draft, 'finance');
$first = $app->caseDecisions->vote($finance, $case, 'finance-first', $financeVote);
caseCheck($first['dispatch_state'] === 'awaiting_second_vote' && !$app->caseDispatch->pump(), 'a single vote has no pending execution');
caseCheck(Values::digest($app->caseDecisions->vote($finance, $case, 'finance-first', $financeVote)) === Values::digest($first),
    'same key retrieves first vote');
caseReject(409, fn () => $app->caseDecisions->vote($finance, $case, 'finance-first', array_replace($financeVote, ['args_hash' => str_repeat('0', 64)])));
caseReject(403, fn () => $app->caseDecisions->vote($finance, $case, 'finance-impersonation', $vote($draft, 'operations')));
$changed = $propose('demo-alternate', 'replace-payee');
caseCheck($changed['args_hash'] !== $draft['args_hash'], 'A10 payee change changes the signed digest');
caseReject(409, fn () => $app->caseDecisions->vote($operations, $case, 'operation-old-payee', $vote($draft, 'operations')));
caseCheck($app->caseDecisions->vote($operations, $case, 'operation-current', $vote($changed, 'operations'))['dispatch_state'] === 'awaiting_second_vote',
    'replacement has no inherited approvals');
$queued = $app->caseDecisions->vote($finance, $case, 'finance-current', $vote($changed, 'finance'));
caseCheck($queued['dispatch_state'] === 'queued', 'two distinct approvals queue an exact operation');
$app->caseDispatch->accept($supervisor, $case, $queued['decision_id']);
caseCheck($app->cases->detail($supervisor, $case)['state'] === 'reconcile', 'acceptance survives loss of original worker');
$resumed = new Application(Db::connect(), static function () use (&$clock): DateTimeImmutable { return $clock; });
caseCheck($resumed->caseDispatch->pump() && !$resumed->caseDispatch->pump(), 'connector readback retries preserve one receipt');
$executed = $app->caseDecisions->list($supervisor, $case)[0];
caseCheck($executed['receipt_id'] !== null && $executed['outcome']['amount_minor'] === 10000 &&
    $executed['outcome']['payee'] === 'demo-alternate' && count($executed['votes']) === 2, 'A9 exact execution and readback');
caseCheck($app->cases->detail($supervisor, $separate)['deadline'] === '2026-11-30 01:30:00.000000', 'A11 Friday business-time deadline');
$clock = new DateTimeImmutable('2026-11-28T01:30:01Z');
caseCheck(!$app->cases->escalate(), 'no alert on weekend');
$clock = new DateTimeImmutable('2026-11-30T01:30:01Z');
caseCheck($app->cases->escalate() && !$app->cases->escalate(), 'Monday exactly one escalation');
caseCheck(count($app->cases->detail($supervisor, $separate)['escalations']) === 1, 'escalation is readable');
$closed = $app->cases->detail($supervisor, $case);
$reopen = $app->cases->reopen($supervisor, $case, 'reopen-first', ['revision' => $closed['revision'], 'reason' => 'Second issue after delivery.']);
caseCheck($reopen['case_round'] === 2, 'A12 new round');
caseWriteTask($app, $investigator, $case, 'investigate', 'claim');
caseMaterial($app, $investigator, $case, 'customer-summary', 'quality');
caseMaterial($app, $investigator, $case, 'order', 'delivered');
caseMaterial($app, $finance, $case, 'payment-proof', 'captured');
$runReviews();
caseWriteTask($app, $owner, $case, 'decision', 'claim');
$again = $propose('demo-original', 'new-round');
$app->caseDecisions->vote($operations, $case, 'ops-again', $vote($again, 'operations'));
$app->caseDecisions->vote($finance, $case, 'fin-again', $vote($again, 'finance'));
caseCheck($app->caseDispatch->pump() && !$app->caseDispatch->pump(), 'second round requires its own authorization');
$history = array_values(array_filter($app->caseDecisions->list($supervisor, $case), static fn ($row) => $row['state'] === 'executed'));
caseCheck(count($history) === 2 && $history[0]['case_round'] === 2 && $history[1]['receipt_id'] === $executed['receipt_id'] &&
    $history[0]['receipt_id'] !== $history[1]['receipt_id'], 'two preserved decision and receipt chains');
echo Values::json(['schema' => 7, 'parallel_reviews' => 2, 'weekend_notifications' => 0, 'monday_notifications' => 1,
    'receipts' => count($history), 'amount_minor' => $executed['outcome']['amount_minor'], 'final_state' => 'closed',
    'tested' => ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10', 'A11', 'A12']] + $fixtureState), PHP_EOL;
