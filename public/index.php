<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Graph;
use AgentAutomation\Problem;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
header('Cache-Control: no-store');
if ($path === '/' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/workbench.html');
    exit;
}
header('Content-Type: application/json; charset=utf-8');
try {
    if (!is_string($path) || !str_starts_with($path, '/api/') || !in_array($method, ['GET', 'POST'], true)) {
        throw Problem::missing();
    }
    $app = new Application(Db::connect());
    if ($path === '/api/health' && $method === 'GET') {
        if ($app->db->one('SELECT version FROM schema_migrations WHERE version=2') === null) {
            throw new Problem(503, 'unavailable', 'Workspace migrations are required.');
        }
        respond(['status' => 'ready']);
    }
    $scope = $app->access->identify($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    $input = $method === 'POST' ? input() : [];
    if ($method === 'GET') {
        switch ($path) {
            case '/api/workspace':
                respond(['tenant' => $scope->tenant, 'environment' => $scope->environment, 'actor' => $scope->actor, 'role' => $scope->role]);
            case '/api/contracts':
                respond(Graph::contracts());
            case '/api/flows':
                respond($app->flows->list($scope));
            case '/api/connections':
                respond($app->connections->list($scope));
            case '/api/runs':
                respond($app->runs->list($scope, queryText('state'), queryText('before')));
            case '/api/inbox':
                respond($app->deliveries->inbox($scope));
            case '/api/statistics':
                respond($app->statistics($scope));
            case '/api/audit':
                $after = queryText('after');
                if ($after !== '' && (!ctype_digit($after) || strlen($after) > 18)) {
                    throw Problem::invalid('Invalid audit cursor.');
                }
                respond($app->audit($scope, (int) $after));
        }
    }
    if ($path === '/api/limits' && $method === 'POST') {
        respond($app->limits($scope, intField($input, 'max_open', 1, 20000), intField($input, 'daily_tokens', 0, 10000000)));
    }
    if (preg_match('~^/api/connections/([a-z][a-z0-9_-]{0,63})$~D', $path, $parts) && $method === 'POST') {
        $secret = $input['secret'] ?? null;
        respond($app->connections->put($scope, $parts[1], stringField($input, 'capability', 32),
            intField($input, 'expected', 0), arrayField($input, 'settings'),
            $secret === null ? null : Values::text($secret, 'connection secret', 4096)));
    }
    if ($path === '/api/graph/validate' && $method === 'POST') {
        $scope->require('author');
        $graph = Graph::compile(arrayField($input, 'definition'));
        respond(['graph' => $graph, 'connections' => Graph::bindingKinds($graph), 'digest' => Values::digest($graph)]);
    }
    if (preg_match('~^/api/orders/(ORD-[0-9]{4,12})$~D', $path, $parts) && $method === 'POST') {
        respond($app->orders->put($scope, $parts[1], stringField($input, 'state', 16), intField($input, 'expected', 0)));
    }
    if (preg_match('~^/api/flows/([a-z][a-z0-9_-]{0,63})(?:/(events|activate|versions|templates)(?:/([0-9]{1,10}))?)?$~D',
        $path, $parts)) {
        $flow = $parts[1];
        $operation = $parts[2] ?? '';
        if ($method === 'GET' && $operation === 'versions' && isset($parts[3])) {
            $scope->require('author', 'operator', 'approver');
            respond($app->flows->version($scope, $flow, (int) $parts[3]));
        }
        if ($method === 'GET' && $operation === 'templates' && isset($parts[3])) {
            respond($app->flows->template($scope, $flow, (int) $parts[3]));
        }
        if ($method === 'POST' && !isset($parts[3])) {
            switch ($operation) {
                case '':
                    respond($app->flows->save($scope, $flow, arrayField($input, 'definition'), arrayField($input, 'bindings'),
                        intField($input, 'expected', 0), boolField($input, 'activate')));
                case 'activate':
                    respond($app->flows->activate($scope, $flow, intField($input, 'revision', 1), intField($input, 'expected', 0)));
                case 'events':
                    respond($app->runs->trigger($scope, $flow, stringField($input, 'source', 64), arrayField($input, 'event')));
            }
        }
    }
    if (preg_match('~^/api/runs/([a-f0-9]{32})(?:/(correction|cancel|feedback|approvals)(?:/([a-z][a-z0-9_-]{0,63}))?)?$~D',
        $path, $parts)) {
        $run = $parts[1];
        $section = $parts[2] ?? '';
        if ($section === '' && $method === 'GET') {
            respond($app->runs->detail($scope, $run) + [
                'approvals' => $app->approvals->view($scope, $run), 'deliveries' => $app->deliveries->forRun($scope, $run),
            ]);
        }
        if ($method === 'POST') {
            switch ($section) {
                case 'correction':
                    respond($app->runs->correct($scope, $run, intField($input, 'revision', 1), stringField($input, 'order_no', 32)));
                case 'cancel':
                    respond($app->runs->cancel($scope, $run, intField($input, 'revision', 1)));
                case 'feedback':
                    respond($app->feedback($scope, $run, stringField($input, 'node', 64), stringField($input, 'label', 40)));
                case 'approvals':
                    if (!isset($parts[3])) {
                        throw Problem::missing();
                    }
                    $revision = intField($input, 'revision', 1);
                    $action = stringField($input, 'action', 16);
                    if ($action === 'revise') {
                        respond($app->approvals->revise($scope, $run, $parts[3], $revision, arrayField($input, 'changes')));
                    }
                    respond($app->approvals->decide($scope, $run, $parts[3], $revision, stringField($input, 'digest', 64), $action));
            }
        }
    }
    if (preg_match('~^/api/deliveries/([a-f0-9]{64})/reconcile$~D', $path, $parts) && $method === 'POST') {
        respond($app->deliveries->reconcile($scope, $parts[1]));
    }
    throw Problem::missing();
} catch (Problem $error) {
    if ($error->status === 401) {
        header('WWW-Authenticate: Bearer');
    }
    respond(['error' => $error->kind, 'message' => $error->getMessage()], $error->status);
} catch (JsonException) {
    respond(['error' => 'invalid-json', 'message' => 'The supplied JSON could not be parsed.'], 400);
} catch (Throwable $error) {
    error_log('Workspace request failed: ' . $error::class);
    respond(['error' => 'internal', 'message' => 'The workspace operation failed.'], 500);
}

function respond(array $value, int $status = 200): never
{
    http_response_code($status);
    echo Values::json($value), PHP_EOL;
    exit;
}

function input(): array
{
    $media = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
    if ($media !== 'application/json') {
        throw new Problem(415, 'unsupported-content-type', 'Expected application/json.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    if (!is_string($raw) || strlen($raw) > 262144) {
        throw new Problem(413, 'request-too-large', 'Request body exceeds 256 KiB.');
    }
    return Values::decode($raw);
}

function stringField(array $input, string $field, int $limit): string
{
    return Values::text($input[$field] ?? null, $field, $limit);
}

function intField(array $input, string $field, int $minimum, int $maximum = 2147483647): int
{
    return Values::number($input[$field] ?? null, $field, $minimum, $maximum);
}

function arrayField(array $input, string $field): array
{
    $value = $input[$field] ?? null;
    if (!is_array($value)) {
        throw Problem::invalid("Expected $field data.");
    }
    return $value;
}

function boolField(array $input, string $field): bool
{
    if (!is_bool($input[$field] ?? null)) {
        throw Problem::invalid("Expected a Boolean $field.");
    }
    return $input[$field];
}

function queryText(string $name): string
{
    $value = $_GET[$name] ?? '';
    return is_string($value) && strlen($value) < 100 ? $value : throw Problem::invalid('Invalid query parameter.');
}
