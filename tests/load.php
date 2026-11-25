<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';

$db = Db::connect();
$db->migrate();
$app = new Application($db);
$grant = $app->access->provision('capacity', 'test', 'fixture-admin', 'admin', 2);
$admin = $app->access->identify('Bearer ' . $grant['token']);
$sample = Values::decode(file_get_contents(dirname(__DIR__) . '/public/sample.json'));
$app->connections->put($admin, 'orders-demo', 'orders', 0, [], null);
$app->connections->put($admin, 'inbox-demo', 'inbox', 0, ['recipient_domain' => 'example.test'], null);
$app->connections->put($admin, 'draft-demo', 'model', 0, [], null);
$app->orders->put($admin, 'ORD-4001', 'delivered', 0);
$app->flows->save($admin, 'customer-care', $sample['definition'], $sample['bindings'], 0, true);
$app->limits($admin, 1024, 0);

for ($i = 0; $i < 24; $i++) {
    $event = array_replace($sample['event'], ['message' => "<warm-$i@example.test>", 'subject' => 'Business hours question',
        'body' => 'What are your hours?']);
    $app->runs->trigger($admin, 'customer-care', 'warmup', $event);
}
while ($app->engine->step()) {
}

$events = [];
for ($i = 0; $i < 900; $i++) {
    $events[] = array_replace($sample['event'], ['message' => "<capacity-$i@example.test>"]);
}
$sequence = [...range(0, 899), ...range(0, 99)];
$random = new Random\Randomizer(new Random\Engine\Mt19937(411));
$sequence = $random->shuffleArray($sequence);
$ids = [];
$latencies = [];
$replays = 0;
$started = hrtime(true);
foreach ($sequence as $index) {
    $begin = hrtime(true);
    $result = $app->runs->trigger($admin, 'customer-care', 'capacity-feed', $events[$index]);
    $latencies[] = (hrtime(true) - $begin) / 1_000_000;
    if (isset($ids[$index])) {
        if ($result['id'] !== $ids[$index] || !$result['replayed']) {
            throw new RuntimeException('Repeated event created a different run.');
        }
        $replays++;
    } else {
        $ids[$index] = $result['id'];
    }
}
$ingress = (hrtime(true) - $started) / 1_000_000;
$steps = 0;
while ($app->engine->step()) {
    if (++$steps > 9000) {
        throw new RuntimeException('Worker failed to quiesce.');
    }
}
$elapsed = (hrtime(true) - $started) / 1_000_000;
sort($latencies, SORT_NUMERIC);
$summary = $db->one("SELECT COUNT(*) AS identities,COUNT(t.ticket) AS tickets,
    SUM(r.state='waiting-review') AS awaiting_approval
    FROM event_keys k JOIN runs r ON (r.tenant,r.environment,r.id)=(k.tenant,k.environment,k.run)
    LEFT JOIN tickets t ON (t.tenant,t.environment,t.run)=(r.tenant,r.environment,r.id)
    WHERE k.tenant='capacity' AND k.environment='test' AND k.source='capacity-feed'");
$receiptCount = $db->one("SELECT COUNT(*) AS count FROM inbox WHERE tenant='capacity' AND environment='test'")['count'];
$dispatchCount = $db->one("SELECT COUNT(*) AS count FROM deliveries WHERE tenant='capacity' AND environment='test'")['count'];
if (($replays !== 100) || ((int) $summary['identities'] !== 900) || ((int) $summary['tickets'] !== 900) ||
    ((int) $summary['awaiting_approval'] !== 900) || ($receiptCount !== 0) || ($dispatchCount !== 0)) {
    throw new RuntimeException('Capacity run has missing tickets, incorrect replay or unapproved outbound activity.');
}
echo Values::json([
    'php' => PHP_VERSION, 'mysql' => $db->one('SELECT VERSION() AS version')['version'],
    'mode' => 'rule', 'workers' => 1, 'warmup_events' => 24, 'submissions' => count($sequence),
    'duplicate_submissions' => $replays, 'unique_runs' => (int) $summary['identities'],
    'unique_tickets' => (int) $summary['tickets'], 'waiting_review' => (int) $summary['awaiting_approval'],
    'dispatches' => $dispatchCount, 'inbox_receipts' => $receiptCount, 'node_steps' => $steps,
    'ingress_ms' => round($ingress, 3), 'ingress_p95_ms' => round($latencies[949], 3), 'elapsed_ms' => round($elapsed, 3),
    'peak_php_bytes' => memory_get_peak_usage(true),
]), PHP_EOL;
