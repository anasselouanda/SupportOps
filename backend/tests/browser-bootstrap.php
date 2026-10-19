<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

require_once dirname(__DIR__).'/vendor/autoload.php';

$browserTestDirectory = getenv('SUPPORTOPS_BROWSER_TEST_DIR');
$temporaryRoot = realpath(sys_get_temp_dir());
$browserTestRoot = is_string($browserTestDirectory) ? realpath($browserTestDirectory) : false;

if (
    $temporaryRoot === false || $browserTestRoot === false
    || dirname($browserTestRoot) !== $temporaryRoot
    || ! preg_match('/^SupportOps-mvp-\d{8}-\d{6}$/', basename($browserTestRoot))
    || ! is_file($browserTestRoot.'/database.sqlite')
    || ! is_file($browserTestRoot.'/app-key')
) {
    throw new RuntimeException('Browser tests require their own newly prepared temporary directory.');
}

$browserEnvironment = [
    'APP_ENV' => 'browser-testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => trim(file_get_contents($browserTestRoot.'/app-key')),
    'APP_URL' => 'http://127.0.0.1:8001',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $browserTestRoot.'/database.sqlite',
    'DB_URL' => '',
    'LARAVEL_STORAGE_PATH' => $browserTestRoot.'/storage',
    'CACHE_STORE' => 'file',
    'SESSION_DRIVER' => 'file',
    'SESSION_COOKIE' => 'supportops_mvp_session',
    'SESSION_DOMAIN' => 'null',
    'SESSION_SECURE_COOKIE' => 'false',
    'SESSION_SAME_SITE' => 'lax',
    'SANCTUM_STATEFUL_DOMAINS' => 'employee.localhost:5174,technician.localhost:5174,admin.localhost:5174,other.localhost:5174,empty.localhost:5174,disabled.localhost:5174',
    'FRONTEND_URL' => 'http://employee.localhost:5174',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'BCRYPT_ROUNDS' => '4',
    'LOG_CHANNEL' => 'single',
];

foreach ($browserEnvironment as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}

$application = require dirname(__DIR__).'/bootstrap/app.php';
if ($application->configurationIsCached()) {
    throw new RuntimeException('Browser tests refuse cached application configuration.');
}
$application->make(Kernel::class)->bootstrap();
$configuration = $application->make('config');
$normalizeBrowserPath = static fn (string $path): string => str_replace('\\', '/', $path);

if (
    ! $application->environment('browser-testing')
    || $configuration->get('database.default') !== 'sqlite'
    || $configuration->get('database.connections.sqlite.database') !== $browserTestRoot.'/database.sqlite'
    || ! in_array($configuration->get('database.connections.sqlite.url'), ['', null], true)
    || $configuration->get('session.driver') !== 'file'
    || $normalizeBrowserPath($configuration->get('session.files')) !== $normalizeBrowserPath($browserTestRoot.'/storage/framework/sessions')
    || $configuration->get('cache.default') !== 'file'
) {
    throw new RuntimeException('Refusing to run browser tests outside the isolated SQLite and storage directory.');
}

$connection = $application->make('db')->connection();
if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== $browserTestRoot.'/database.sqlite') {
    throw new RuntimeException('Unexpected effective browser-test database connection.');
}
$connection->select('SELECT 1 AS connected');

return $application;
