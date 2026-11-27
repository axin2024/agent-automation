<?php

declare(strict_types=1);

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo '{}';
    return;
}
$request = json_decode(file_get_contents('php://input'), true, 12, JSON_THROW_ON_ERROR);
$raw = $request['messages'][1]['content'] ?? '';
$materials = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
$role = $request['response_format']['json_schema']['name'] ?? '';
$expected = [
    'case_classify' => ['e1' => ['kind' => 'customer-summary', 'fields' => ['issue_code' => 'delivery']]],
    'case_investigate' => ['e1' => ['kind' => 'customer-summary', 'fields' => ['issue_code' => 'delivery']],
        'e2' => ['kind' => 'order', 'fields' => ['status' => 'delivered']]],
    'case_recommend' => ['e1' => ['kind' => 'order', 'fields' => ['status' => 'delivered']],
        'e2' => ['kind' => 'payment-proof', 'fields' => ['ceiling_minor' => 21000, 'status' => 'captured']]],
];
foreach ($expected as &$group) {
    foreach ($group as &$material) {
        ksort($material);
    }
    unset($material);
}
unset($group);
if (!isset($expected[$role]) || $materials !== $expected[$role] || str_contains($raw, 'fragment') ||
    str_contains($raw, 'demo-contact') || str_contains($raw, 'ORD-4001') || str_contains($raw, 'payee') ||
    str_contains($raw, 'source') || str_contains($raw, 'evidence_id') || isset($request['tools'])) {
    http_response_code(422);
    echo '{"error":"model material contract failed"}';
    return;
}
$recommend = ($request['model'] ?? '') === 'fabricated-fixture';
$value = match ($role) {
    'case_classify' => ['label' => 'delivery', 'citations' => ['e1']],
    'case_investigate' => ['label' => 'delivered', 'citations' => ['e2']],
    'case_recommend' => ['label' => 'refund', 'citations' => $recommend ? ['e1', 'e9'] : ['e1', 'e2']],
};
echo json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($value)]]],
    'usage' => ['prompt_tokens' => 17, 'completion_tokens' => 7]], JSON_THROW_ON_ERROR);
