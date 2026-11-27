<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/case-fixtures.php';

$db = Db::connect();
$app = new Application($db);
$scope = $app->access->identify('Bearer ' . getenv('AUTOMATION_SMOKE_TOKEN'));
$case = $app->cases->detail($scope, $argv[1]);
$decisions = $app->caseDecisions->list($scope, $argv[1]);
caseCheck($case['state'] === 'closed' && count($decisions) === 1 && $decisions[0]['decision_id'] === $argv[2] &&
    $decisions[0]['receipt_id'] === $argv[3] && count($decisions[0]['votes']) === 2 && !$app->caseDispatch->pump(),
    'Case decision, signatures and receipt survive independent restore.');
echo Values::json(['case_restore' => 'passed', 'case_id' => $case['case_id'], 'receipt_id' => $decisions[0]['receipt_id'],
    'votes' => count($decisions[0]['votes']), 'new_dispatches' => 0, 'state' => $case['state']]), PHP_EOL;
