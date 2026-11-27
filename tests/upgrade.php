<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

$previous = realpath(getenv('AUTOMATION_TEST_UPGRADE_SOURCE') ?: '');
$previousVersion = $previous !== false && is_file($previous . '/VERSION') ? trim(file_get_contents($previous . '/VERSION')) : '';
$previousSchema = ['1.0.1' => 2, '1.1.0' => 6][$previousVersion] ?? null;
if ($previousSchema === null) {
    throw new RuntimeException('Set AUTOMATION_TEST_UPGRADE_SOURCE to an extracted v1.0.1 or v1.1.0 source tree.');
}

if (($argv[1] ?? '') === 'seed') {
    require $previous . '/bootstrap.php';
    seedPrevious($previous, $previousSchema);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/case-fixtures.php';
$args = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'zend.exception_ignore_args=1'];
if (getenv('AUTOMATION_TEST_PDO_MYSQL_EXTENSION') === '1') {
    array_push($args, '-d', 'extension=pdo_mysql');
}
$child = proc_open([...$args, __FILE__, 'seed'], [1 => ['pipe', 'w'], 2 => STDERR], $pipes, $previous);
if (!is_resource($child)) {
    throw new RuntimeException('The previous application process did not start.');
}
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
check(proc_close($child) === 0 && is_string($output), 'run previous release');
$old = Values::decode($output);
$db = Db::connect();
$before = (new Application($db))->cases->preflight(new AgentAutomation\Scope('upgrade-fixture', 'test', 'cli', 'admin'));
check($before['ownership_conflicts'] === 0 && count($before['legacy_pending']) === 1 && $before['legacy_pending'][0]['id'] === $old['run'],
    'preflight detects pending work and checks its tenant-scoped workflow');
$migration = $db->migrate();
check($migration === range($previousSchema + 1, 7) && $db->migrate() === [], 'upgrade old schema without rewriting previous checksums');
$app = new Application($db);
$scope = $app->access->identify('Bearer ' . $old['token']);
$id = $old['run'];
$restored = $app->runs->detail($scope, $id);
check($restored['graph_revision'] === 1 && $restored['state'] === 'waiting-receipt' && $restored['body_retained'] &&
    $restored['ticket']['ticket'] === $old['ticket'] && $restored['ticket']['original_order'] === 'ORD-4001', 'read original run and ticket');
check($app->flows->version($scope, $restored['flow'], 1)['digest'] === $old['graph_digest'], 'read exact previous graph');
$delivery = $app->deliveries->operation($scope, $old['operation']);
check($delivery['state'] === 'unknown' && $delivery['attempts'] === 1 && !$app->deliveries->pump(), 'unconfirmed delivery is not re-sent');
$replay = $app->runs->trigger($scope, 'customer-care', 'upgrade-fixture', $restored['event']);
check($replay['id'] === $id && $replay['replayed'], 'read original event deduplication key');
check($app->deliveries->reconcile($scope, $delivery['operation'])['receipt'] === $old['receipt'], 'recover exact previously committed receipt');
drainUpgrade($app);
$inbox = $app->deliveries->inbox($scope);
check(count($inbox) === 1 && $inbox[0]['receipt'] === $old['receipt'] && $app->runs->detail($scope, $id)['result'] === 'sent' &&
    $app->deliveries->operation($scope, $delivery['operation'])['attempts'] === 1, 'finish historical run without a second receipt');
$template = caseTemplate() + ['workflow' => ['name' => $restored['flow'], 'revision' => $restored['graph_revision']]];
$pinned = $app->caseTemplates->publish($scope, 'returns', 0, $template);
$newCase = caseIntake($app, $scope, 'legacy-link')['case_id'];
$app->cases->linkRun($scope, $newCase, 'legacy-link-first', ['run' => $id]);
$app->cases->linkRun($scope, $newCase, 'legacy-link-repeat', ['run' => $id]);
check(count($app->cases->detail($scope, $newCase)['runs']) === 1 && $pinned['definition']['workflow']['digest'] === $old['graph_digest'],
    'Case pins exact legacy workflow revision and deduplicates the run link.');
echo Values::json(['from' => $previousVersion, 'migration' => $migration, 'previous_schema' => $previousSchema, 'run' => $id, 'ticket' => $old['ticket'],
    'operation' => $old['operation'], 'receipt' => $old['receipt'], 'attempts' => 1, 'duplicate_replayed' => true,
    'legacy_graph_digest' => $old['graph_digest'], 'case_id' => $newCase, 'run_links' => 1, 'preflight' => $before]), PHP_EOL;

function seedPrevious(string $previous, int $schema): void
{
    $db = Db::connect();
    check($db->migrate() === range(1, $schema), 'install old migrations');
    $app = new Application($db);
    $token = $app->access->provision('upgrade-fixture', 'test', 'legacy-admin', 'admin', 1)['token'];
    $scope = $app->access->identify('Bearer ' . $token);
    $sample = Values::decode(file_get_contents($previous . '/public/sample.json'));
    $app->connections->put($scope, 'orders-demo', 'orders', 0, [], null);
    $app->connections->put($scope, 'inbox-demo', 'inbox', 0, ['recipient_domain' => 'example.test'], null);
    $app->connections->put($scope, 'draft-demo', 'model', 0, [], null);
    $app->orders->put($scope, 'ORD-4001', 'delivered', 0);
    $flow = $app->flows->save($scope, 'customer-care', $sample['definition'], $sample['bindings'], 0, true);
    $run = $app->runs->trigger($scope, 'customer-care', 'upgrade-fixture', $sample['event'])['id'];
    drainUpgrade($app);
    $pending = $app->runs->detail($scope, $run);
    check($pending['state'] === 'waiting-review' && $pending['ticket']['original_order'] === 'ORD-4001', 'previous release created its sample');
    $review = $app->approvals->latest($scope, $run, 'review');
    $app->approvals->decide($scope, $run, 'review', $review['revision'], $review['digest'], 'approve');
    check($app->engine->step() && $app->deliveries->pump(true), 'old release committed delivery before losing the acknowledgement');
    $delivery = $app->deliveries->forRun($scope, $run)[0];
    $inbox = $app->deliveries->inbox($scope);
    check($delivery['state'] === 'unknown' && $delivery['attempts'] === 1 && count($inbox) === 1, 'old release persisted one unresolved delivery');
    echo Values::json(['token' => $token, 'run' => $run, 'ticket' => $pending['ticket']['ticket'], 'graph_digest' => $flow['digest'],
        'operation' => $delivery['operation'], 'receipt' => $inbox[0]['receipt']]);
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Upgrade failed: ' . $label);
    }
}

function drainUpgrade(Application $app): void
{
    for ($steps = 0; $app->engine->step(); $steps++) {
        check($steps < 20, 'fixture reached a stable state');
    }
}
