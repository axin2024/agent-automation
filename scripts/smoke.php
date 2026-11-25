<?php

declare(strict_types=1);

// Run against a new test workspace. Delivery is to the local SQL inbox.
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$token = getenv('AUTOMATION_SMOKE_TOKEN');
if (!preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D', $base) || !$token) {
    throw new RuntimeException('Set AUTOMATION_SMOKE_TOKEN and supply the loopback URL.');
}

$sample = json_decode(file_get_contents(dirname(__DIR__) . '/public/sample.json'), true, 32, JSON_THROW_ON_ERROR);
$info = callApi('workspace');
if ($info['environment'] !== 'test' || $info['role'] !== 'admin') {
    throw new RuntimeException('The smoke script requires a test administrator.');
}
callApi('connections/orders-demo', ['capability' => 'orders', 'expected' => 0, 'settings' => []]);
callApi('connections/draft-demo', ['capability' => 'model', 'expected' => 0, 'settings' => []]);
callApi('connections/inbox-demo', ['capability' => 'inbox', 'expected' => 0, 'settings' => ['recipient_domain' => 'example.test']]);
callApi('orders/ORD-4001', ['state' => 'delivered', 'expected' => 0]);
callApi('graph/validate', ['definition' => $sample['definition']]);
$published = callApi('flows/customer-care', ['definition' => $sample['definition'], 'bindings' => $sample['bindings'],
    'expected' => 0, 'activate' => true]);
$submission = ['source' => 'sample', 'event' => $sample['event']];
$run = callApi('flows/customer-care/events', $submission);
$duplicate = callApi('flows/customer-care/events', $submission);
if (!$duplicate['replayed'] || $duplicate['id'] !== $run['id']) {
    throw new RuntimeException('Duplicate submission did not return the same run.');
}
$path = 'runs/' . $run['id'];
$waiting = waitFor($path, 'waiting-review');
$approvals = array_values(array_filter($waiting['approvals'], static fn (array $row): bool => $row['state'] === 'pending'));
if (count($approvals) !== 1 || callApi('inbox') !== []) {
    throw new RuntimeException('Expected one pending approval and an empty inbox.');
}
$approval = $approvals[0];
callApi($path . '/approvals/review', ['revision' => $approval['revision'], 'digest' => $approval['digest'], 'action' => 'approve']);
$complete = waitFor($path, 'completed');
$inbox = callApi('inbox');
if ($complete['result'] !== 'sent' || count($inbox) !== 1 || ($inbox[0]['recipient'] ?? '') !== 'buyer@example.test') {
    throw new RuntimeException('Approved sample did not arrive exactly once in the local inbox.');
}
echo json_encode(['workspace' => $info['tenant'], 'workflow_revision' => $published['revision'], 'run' => $run['id'],
    'result' => $complete['result'], 'receipt' => $inbox[0]['receipt'], 'duplicate_replayed' => true], JSON_THROW_ON_ERROR), PHP_EOL;

function waitFor(string $path, string $state): array
{
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $current = callApi($path);
        if ($current['state'] === $state) {
            return $current;
        }
        if (in_array($current['state'], ['failed', 'cancelled', 'waiting-input'], true)) {
            throw new RuntimeException('Run stopped in ' . $current['state']);
        }
        usleep(250000);
    }
    throw new RuntimeException("Timed out waiting for $state.");
}

function callApi(string $path, ?array $body = null): array
{
    global $base, $token;
    $request = curl_init($base . '/api/' . $path);
    $headers = ['Authorization: Bearer ' . $token];
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 3000, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($request, $options + [CURLOPT_HTTPHEADER => $headers]);
    $response = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_HTTP_CODE);
    curl_close($request);
    if (!is_string($response) || $status !== 200) {
        throw new RuntimeException("$path returned HTTP $status: " . (is_string($response) ? $response : 'network error'));
    }
    return json_decode($response, true, 32, JSON_THROW_ON_ERROR);
}
