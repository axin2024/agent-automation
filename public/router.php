<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && in_array($path, ['/app.js', '/app.css', '/sample.json'], true)) {
    return false;
}
require __DIR__ . '/index.php';
