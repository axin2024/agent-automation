<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_URI'] !== '/v1/chat/completions') {
    http_response_code(404);
    exit;
}
$request = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
$fields = json_decode($request['messages'][1]['content'] ?? '[]', true, 8, JSON_THROW_ON_ERROR);
if ($request['model'] !== 'contract-fixture' || $fields !== ['order_no' => 'ORD-4123', 'state' => 'shipped'] ||
    ($request['response_format']['json_schema']['schema']['required'] ?? []) !== ['closing'] || isset($request['tools'])) {
    http_response_code(422);
    exit;
}
header('Content-Type: application/json');
echo json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{}']]],
    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 3]], JSON_THROW_ON_ERROR);
