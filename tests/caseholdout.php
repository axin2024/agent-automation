<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/case-fixtures.php';

if (!getenv('AUTOMATION_CASE_MODEL_URL') || !getenv('AUTOMATION_CASE_MODEL_NAME')) {
    throw new RuntimeException('Configure the local model URL and name for this live test.');
}
$db = Db::connect();
$db->migrate();
$app = new Application($db);
$actor = caseCredentials($app, 'case-holdout');
$admin = $actor['supervisor']['scope'];
$operator = $actor['investigator']['scope'];
$finance = $actor['finance']['scope'];
$owner = $actor['decision-owner']['scope'];
$app->caseTemplates->publish($admin, 'returns', 0, caseTemplate());
$cases = [];
$inputTokens = $outputTokens = $grounded = $correct = $manual = $metered = $shortfalls = $reviewAccepted = 0;
$recommendAccepted = $recommendCorrected = $reviewedCorrect = 0;
$byRole = [];
$labels = ['delivery', 'billing', 'quality', 'other'];
$orders = ['placed', 'shipped', 'delivered', 'cancelled'];
$payments = ['captured', 'refunded', 'uncertain'];
for ($index = 0; $index < 30; $index++) {
    $case = caseIntake($app, $operator, sprintf('holdout-%02d', $index + 1))['case_id'];
    $category = $labels[$index % count($labels)];
    $order = $orders[intdiv($index + 1, 2) % count($orders)];
    $payment = $payments[intdiv($index + 1, 2) % count($payments)];
    $missing = $index % 3 === 0 ? ['customer-summary', 'order', 'payment-proof'][intdiv($index, 3) % 3] : null;
    $evidence = [];
    foreach (['customer-summary' => $category, 'order' => $order, 'payment-proof' => $payment] as $kind => $value) {
        if ($kind !== $missing) {
            $evidence[$kind] = caseMaterial($app, $kind === 'payment-proof' ? $finance : $operator, $case, $kind, $value)['evidence_id'];
        }
    }
    $row = $app->cases->detail($admin, $case);
    $actions = match ($payment) { 'captured' => 'refund', 'refunded' => 'close', default => 'manual' };
    $record = ['sample' => $index + 1, 'case_id' => $case, 'missing' => $missing, 'evidence' => $evidence,
        'inputs' => ['category' => $category, 'order_status' => $order, 'payment_status' => $payment], 'suggestions' => []];
    foreach (['classify' => [$operator, $category, ['customer-summary']], 'investigate' => [$operator, $order, ['order']],
        'recommend' => [$owner, $actions, ['order', 'payment-proof']]] as $role => [$scope, $label, $required]) {
        $proposal = $app->caseAssistant->generate($scope, $case, $role, "assist-$index-$role", ['revision' => $row['revision']]);
        $state = $proposal['state'];
        $expected = $missing === null ? $label : 'insufficient';
        $usage = $proposal['usage'];
        $suggestion = $proposal['suggestion'];
        $reference = array_column($suggestion['citations'] ?? [], 'evidence_id');
        sort($reference);
        $expectedReferences = $missing === null ? array_values(array_intersect_key($evidence, array_flip($required))) : [];
        sort($expectedReferences);
        $isCorrect = ($suggestion['label'] ?? null) === $expected && $reference === $expectedReferences &&
            ($missing === null || ($suggestion['reason'] ?? null) === 'insufficient-evidence');
        $correct += (int) $isCorrect;
        $grounded += (int) ($missing === null && $reference === $expectedReferences && $state === 'ready');
        $manual += (int) ($state === 'needs-human');
        $inputTokens += $usage['input_tokens'] ?? 0;
        $outputTokens += $usage['output_tokens'] ?? 0;
        $metered += (int) $usage['metered'];
        $byRole[$role] ??= ['correct' => 0, 'grounded' => 0, 'manual' => 0];
        $byRole[$role]['correct'] += (int) $isCorrect;
        $byRole[$role]['grounded'] += (int) ($missing === null && $state === 'ready');
        $byRole[$role]['manual'] += (int) ($state === 'needs-human');
        $record['suggestions'][$role] = ['proposal_id' => $proposal['proposal_id'], 'state' => $state, 'expected' => $expected,
            'expected_citations' => $expectedReferences, 'suggestion' => $suggestion, 'usage' => $usage,
            'input_hash' => $proposal['input_hash'], 'matched_label_and_citations' => $isCorrect];
    }
    caseWriteTask($app, $operator, $case, 'investigate', 'claim');
    $task = caseWriteTask($app, $operator, $case, 'investigate', 'complete');
    $final = $app->cases->detail($admin, $case)['state'];
    $shortfalls += (int) ($missing !== null && $final === 'needs-evidence' && $task['missing'] === [$missing]);
    $record['final_state'] = $final;
    if ($missing === null && $record['suggestions']['classify']['matched_label_and_citations']) {
        $human = $app->caseAssistant->revise($operator, $case, "accept-$index", [
            'revision' => $app->cases->detail($admin, $case)['revision'],
            'based_on' => $record['suggestions']['classify']['proposal_id'], 'label' => $category,
            'citations' => [$evidence['customer-summary']], 'note' => 'Accepted by the acceptance operator using the labeled fixture.',
        ]);
        $record['fixture_review'] = $human;
        $reviewAccepted++;
    }
    if ($missing === null) {
        $recommendation = $record['suggestions']['recommend'];
        $matches = $recommendation['matched_label_and_citations'];
        $revision = $app->caseAssistant->revise($owner, $case, "recommend-review-$index", [
            'revision' => $app->cases->detail($admin, $case)['revision'],
            'based_on' => $recommendation['proposal_id'], 'label' => $actions,
            'citations' => [$evidence['order'], $evidence['payment-proof']],
            'note' => $matches ? 'Accepted against the fixture evidence.' : 'Corrected against the order and payment fixture evidence.',
        ]);
        $revised = array_column($app->caseAssistant->list($owner, $case), null, 'proposal_id')[$revision['proposal_id']];
        $reviewedReferences = array_column($revised['suggestion']['citations'], 'evidence_id');
        sort($reviewedReferences);
        $reviewedCorrect += (int) ($revised['suggestion']['label'] === $actions &&
            $reviewedReferences === $recommendation['expected_citations'] && $revised['state'] === 'ready');
        $recommendAccepted += (int) $matches;
        $recommendCorrected += (int) !$matches;
        $record['recommendation_fixture_review'] = ['adopted' => $matches, 'revision' => $revision,
            'actor' => $revised['actor'], 'based_on' => $revised['based_on'], 'suggestion' => $revised['suggestion']];
    }
    $cases[] = $record;
    fwrite(STDERR, sprintf("sample %02d · %s\n", $index + 1, $final));
}
caseCheck($metered === 90 && $shortfalls === 10, 'All 90 calls must be metered and all 10 incomplete cases must stop.');
caseCheck($reviewedCorrect === 20, 'The fixed-label recommendation review must preserve audit and approved evidence.');
$dispatches = $db->one("SELECT COUNT(*) AS count FROM case_dispatch WHERE tenant='case-holdout' AND environment='test'")['count'];
caseCheck($dispatches === 0 && !$app->caseDispatch->pump(), 'zero actions without separate approvals');
$report = ['mode' => 'live-on-loopback', 'fixture_count' => 30, 'calls' => 90, 'model' => getenv('AUTOMATION_CASE_MODEL_NAME'),
    'mysql' => $db->one('SELECT VERSION() AS version')['version'], 'php' => PHP_VERSION, 'evidence_shortfalls_stopped' => $shortfalls,
    'grounded_complete' => $grounded, 'complete_outputs' => 60, 'exact_label_and_citation' => $correct,
    'manual_outputs' => $manual, 'fixture_review_accepted' => $reviewAccepted, 'unapproved_dispatches' => $dispatches,
    'prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens, 'by_role' => $byRole,
    'recommendation_fixture_review' => ['adopted' => $recommendAccepted, 'corrected' => $recommendCorrected,
        'correct_after_review' => $reviewedCorrect], 'samples' => $cases];
echo Values::json($report), PHP_EOL;
