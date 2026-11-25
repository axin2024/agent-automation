<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Db;
use AgentAutomation\Problem;
use AgentAutomation\Values;

require dirname(__DIR__) . '/bootstrap.php';

$args = array_slice($argv, 1);
$command = array_shift($args) ?? 'help';
if (in_array($command, ['help', '--help', '-h'], true)) {
    echo "Agent Automation\n"
        . "  migrate [through]                                   Apply MySQL schema versions.\n"
        . "  provision tenant test|production actor role [hours]   Create an environment-scoped token.\n"
        . "  revoke tenant test|production actor                  Revoke all current tokens for one actor.\n"
        . "  work [steps]                                          Run at most N worker steps.\n"
        . "  worker                                               Poll for workflow or delivery work.\n"
        . "  version                                              Read the application version.\n";
    exit(0);
}
if (in_array($command, ['version', '--version'], true)) {
    echo trim(file_get_contents(dirname(__DIR__) . '/VERSION') ?: 'dev'), PHP_EOL;
    exit(0);
}
try {
    $app = new Application(Db::connect());
    $result = match ($command) {
        'migrate' => count($args) <= 1
            ? ['applied' => $app->db->migrate(isset($args[0]) ? cliInteger($args[0], 1, 10) : null)]
            : throw Problem::invalid('Usage: migrate [through].'),
        'provision' => count($args) >= 4 && count($args) <= 5
            ? $app->access->provision($args[0], $args[1], $args[2], $args[3], isset($args[4]) ? cliInteger($args[4], 1, 744) : 24)
            : throw Problem::invalid('Usage: provision tenant environment actor role [hours].'),
        'revoke' => count($args) === 3
            ? ['revoked' => $app->access->revoke($args[0], $args[1], $args[2])]
            : throw Problem::invalid('Usage: revoke tenant environment actor.'),
        'work' => count($args) <= 1
            ? ['processed' => work($app, isset($args[0]) ? cliInteger($args[0], 1, 100000) : 100)]
            : throw Problem::invalid('Usage: work [steps].'),
        'worker' => $args === [] ? worker($app) : throw Problem::invalid('Usage: worker.'),
        default => throw Problem::invalid('Unknown command.'),
    };
    echo Values::json($result), PHP_EOL;
} catch (Problem $problem) {
    fwrite(STDERR, $problem->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Automation failed (' . $error::class . ').' . PHP_EOL);
    exit(1);
}

function cliInteger(string $text, int $min, int $max): int
{
    if (!ctype_digit($text)) {
        throw Problem::invalid('Expected a positive integer.');
    }
    return Values::number((int) $text, 'argument', $min, $max);
}

function work(Application $app, int $steps): int
{
    $processed = 0;
    while ($processed < $steps && ($app->deliveries->pump() || $app->engine->step())) {
        $processed++;
    }
    return $processed;
}

function worker(Application $app): never
{
    while (true) {
        if (work($app, 100) === 0) {
            usleep(200000);
        }
    }
}
