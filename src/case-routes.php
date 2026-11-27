<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Problem;
use AgentAutomation\Scope;
use AgentAutomation\Values;

function caseRoutes(Application $app, Scope $scope, string $method, string $path, array $input): never
{
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    if ($path === '/api/case-types') {
        if ($method === 'GET') {
            respond($app->caseTemplates->list($scope));
        }
        respond($app->caseTemplates->publish($scope, Values::identifier($input['case_type'] ?? null, 'case_type'),
            intField($input, 'expected', 0), arrayField($input, 'definition')), 201);
    }
    if ($path === '/api/case-types/preview' && $method === 'POST') {
        respond($app->caseTemplates->preview($scope, arrayField($input, 'definition'), $input['source'] ?? null));
    }
    if (preg_match('~^/api/case-types/([a-z][a-z0-9_-]{0,63})$~D', $path, $parts) && $method === 'GET') {
        $scope->require('operator', 'approver', 'author');
        respond($app->caseTemplates->get($scope, $parts[1]));
    }
    if ($path === '/api/cases/metrics' && $method === 'GET') {
        respond($app->cases->metrics($scope));
    }
    if ($path === '/api/cases/intake-policy' && $method === 'POST') {
        respond($app->cases->setIntake($scope, boolField($input, 'enabled')));
    }
    if ($path === '/api/cases/preflight' && $method === 'GET') {
        respond($app->cases->preflight($scope));
    }
    if ($path === '/api/cases') {
        if ($method === 'POST') {
            $value = $app->cases->intake($scope, $key, $input);
            header('Location: /api/cases/' . $value['case_id']);
            respond($value, 201);
        }
        $limit = queryText('limit');
        respond($app->cases->queue($scope, queryText('state'), queryText('mine'), queryText('due_before'),
            $limit === '' ? 50 : (ctype_digit($limit) ? (int) $limit : 0), queryText('search')));
    }
    if ($method === 'POST' && preg_match('~^/api/cases/([a-f0-9]{32})/decisions/([a-f0-9]{32})/reconcile$~D', $path, $parts)) {
        respond($app->caseDispatch->reconcile($scope, $parts[1], $parts[2]));
    }
    if (!preg_match('~^/api/cases/([a-f0-9]{32})(?:/([a-z-]+)(?:/([a-z-]+))?)?$~D', $path, $parts)) {
        throw Problem::missing();
    }
    $id = $parts[1];
    $section = $parts[2] ?? '';
    $action = $parts[3] ?? '';
    if ($method === 'GET' && $action === '') {
        switch ($section) {
            case '':
                respond($app->cases->detail($scope, $id));
            case 'timeline':
                $cursor = queryText('after');
                if ($cursor !== '' && (!ctype_digit($cursor) || strlen($cursor) > 18)) {
                    throw Problem::invalid('Invalid event cursor.');
                }
                respond($app->cases->timeline($scope, $id, (int) $cursor));
            case 'evidence':
                respond($app->caseEvidence->list($scope, $id));
            case 'assistant':
                respond($app->caseAssistant->list($scope, $id));
            case 'decisions':
                respond($app->caseDecisions->list($scope, $id));
            case 'export':
                header('Content-Disposition: attachment; filename="case-' . $id . '.json"');
                respond($app->db->transaction(fn() => ['case' => $app->cases->detail($scope, $id), 'timeline' => $app->cases->timeline($scope, $id, null),
                    'decisions' => $app->caseDecisions->list($scope, $id), 'evidence' => $app->caseEvidence->list($scope, $id),
                    'suggestions' => $app->caseAssistant->list($scope, $id)]));
        }
    }
    if ($method === 'POST') {
        switch ($section . ($action === '' ? '' : '/' . $action)) {
            case 'evidence':
                respond($app->caseEvidence->put($scope, $id, $key, $input), 201);
            case 'assignments/investigate':
            case 'assignments/review-operations':
            case 'assignments/review-finance':
            case 'assignments/decision':
                respond($app->caseAssignments->update($scope, $id, $action, $key, $input));
            case 'assistant/classify':
            case 'assistant/investigate':
            case 'assistant/recommend':
                respond($app->caseAssistant->generate($scope, $id, $action, $key, $input));
            case 'assistant/revise':
                respond($app->caseAssistant->revise($scope, $id, $key, $input), 201);
            case 'propose':
                respond($app->caseDecisions->propose($scope, $id, $key, $input), 201);
            case 'decisions':
                $value = $app->caseDecisions->vote($scope, $id, $key, $input);
                header('Location: /api/cases/' . $id . '/decisions');
                respond($value, $value['dispatch_state'] === 'queued' ? 202 : 200);
            case 'link-run':
                respond($app->cases->linkRun($scope, $id, $key, $input));
            case 'reopen':
                respond($app->cases->reopen($scope, $id, $key, $input));
        }
    }
    throw Problem::missing();
}
