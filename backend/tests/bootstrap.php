<?php

require dirname(__DIR__).'/vendor/autoload.php';

$requiredEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
];

foreach ($requiredEnvironment as $name => $expectedValue) {
    $actualValue = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    if ($actualValue !== $expectedValue) {
        throw new RuntimeException('Unsafe test environment: '.$name.' does not match the isolated test configuration.');
    }

    $_ENV[$name] = $_SERVER[$name] = $expectedValue;
    putenv($name.'='.$expectedValue);
}

$application = require dirname(__DIR__).'/bootstrap/app.php';

if ($application->configurationIsCached()) {
    throw new RuntimeException('Tests require an uncached configuration before application bootstrap.');
}

$applicationKey = $_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? getenv('APP_KEY');

if (! is_string($applicationKey) || $applicationKey === '') {
    $applicationKey = 'base64:'.base64_encode(random_bytes(32));
    $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $applicationKey;
    putenv('APP_KEY='.$applicationKey);
}

unset($application, $applicationKey, $requiredEnvironment, $name, $expectedValue, $actualValue);
