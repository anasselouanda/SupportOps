<?php

declare(strict_types=1);
use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli-server') {
    throw new RuntimeException('Use only the loopback PHP test server.');
}
$application = require __DIR__.'/browser-bootstrap.php';
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__browser-fixture' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'environment' => $application->environment(),
        'driver' => $application->make('db')->connection()->getDriverName(),
        'database' => $application->make('db')->connection()->getDatabaseName(),
    ], JSON_THROW_ON_ERROR);

    return;
}
$application->handleRequest(Request::capture());
