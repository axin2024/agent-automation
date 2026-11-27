<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/case-fixtures.php';

$db = Db::connect();
$db->migrate();
$clock = new DateTimeImmutable('2026-11-27T08:30:00Z');
$app = new Application($db, static function () use (&$clock): DateTimeImmutable { return $clock; });
$actor = caseCredentials($app, 'case-capacity');
$admin = $actor['supervisor']['scope'];
$operator = $actor['investigator']['scope'];
$app->caseTemplates->publish($admin, 'returns', 0, caseTemplate());
$references = [];
$covered = [];
$seedStart = hrtime(true);
for ($i = 0; $i < 10000; $i++) {
    $reference = 'capacity-' . $i;
    $references[] = $reference;
    $id = caseIntake($app, $operator, $reference)['case_id'];
    if ($i < 100) {
        $covered[] = $id;
    }
}
$seedMs = (hrtime(true) - $seedStart) / 1000000;
caseCheck($db->one("SELECT COUNT(*) AS total FROM service_cases WHERE tenant='case-capacity' AND state='investigating'")['total'] === 10000,
    'ten thousand independently opened cases');
// Move a known, finite cohort ahead of the injected acceptance clock.
foreach ($covered as $id) {
    $db->exec("UPDATE service_cases SET deadline='2026-11-27 08:29:00' WHERE tenant='case-capacity' AND environment='test' AND case_id=?", [$id]);
}
$rate = 50;
$warmup = 5;
$seconds = 20;
$views = [];
$events = [];
$records = [];
$accepted = $expectedConflict = $unexpected = $timeouts = $notified = 0;
$start = hrtime(true);
for ($event = 0; $event < ($warmup + $seconds) * $rate; $event++) {
    $due = $start + (int) ($event * (1000000000 / $rate));
    $remaining = $due - hrtime(true);
    if ($remaining > 1000) {
        usleep((int) ($remaining / 1000));
    }
    $sample = $event >= $warmup * $rate;
    $began = hrtime(true);
    $outcome = 'accepted';
    try {
        if ($event % 10 === 0) {
            $notified += (int) $app->cases->escalate();
        }
        $reference = $references[($event * 7919) % count($references)];
        $result = $app->cases->intake($operator, 'redelivery-' . $reference,
            ['case_type' => 'returns', 'source' => 'after-sales', 'external_ref' => $reference,
                'customer_ref' => 'customer-local-100', 'subject' => "After-sales issue $reference", 'order_no' => 'ORD-4001']);
        caseCheck(strlen($result['case_id']) === 32, 'write receipt with stable identity');
        $accepted += (int) $sample;
    } catch (\AgentAutomation\Problem $error) {
        if ($error->status === 409) {
            $outcome = 'rejected';
            $expectedConflict += (int) $sample;
        } else {
            $outcome = 'error';
            $unexpected++;
        }
    }
    $beforeView = hrtime(true);
    $queue = $app->cases->queue($operator, 'investigating', '', '', 50);
    caseCheck(count($queue) === 50, 'populated queue page');
    if ($sample) {
        $views[] = (hrtime(true) - $beforeView) / 1000000;
        $events[] = (hrtime(true) - $began) / 1000000;
        $timeouts += (int) (end($events) >= 5000);
        $records[] = ['sample' => count($events), 'outcome' => $outcome, 'concurrency' => 1,
            'todo_ms' => round(end($views), 3), 'event_ms' => round(end($events), 3)];
    }
}
$duration = (hrtime(true) - $start) / 1e9;
sort($views);
sort($events);
$p95 = static fn (array $values): float => round($values[(int) ceil(count($values) * 0.95) - 1], 3);
$notifications = $db->one("SELECT COUNT(*) AS all_count,COUNT(DISTINCT case_id) AS unique_count FROM case_escalations
    WHERE tenant='case-capacity' AND environment='test'");
caseCheck($accepted === 1000 && $unexpected === 0 && $timeouts === 0 && $p95($views) <= 800,
    '1000 measured events and bounded case queue latency');
caseCheck($notified === 100 && $notifications['all_count'] === 100 && $notifications['unique_count'] === 100 &&
    !$app->cases->escalate(), 'one receipt per due case, none for repeat polls');
echo Values::json([
    'platform' => PHP_OS_FAMILY, 'machine_arch' => php_uname('m'), 'php' => PHP_VERSION,
    'mysql' => $db->one('SELECT VERSION() AS version')['version'], 'open_cases' => 10000,
    'seed_ms' => round($seedMs, 3), 'warmup_seconds' => $warmup, 'sample_seconds' => $seconds,
    'elapsed_seconds' => round($duration, 3), 'target_events_per_second' => $rate, 'sample_events' => count($views),
    'accepted_events' => $accepted, 'rejected_events' => $expectedConflict, 'unexpected_errors' => $unexpected, 'timeouts' => $timeouts,
    'todo_p95_ms' => $p95($views), 'event_p95_ms' => $p95($events), 'escalation_receipts' => $notifications['all_count'],
    'duplicate_notifications' => $notifications['all_count'] - $notifications['unique_count'],
    'records' => $records,
]), PHP_EOL;
