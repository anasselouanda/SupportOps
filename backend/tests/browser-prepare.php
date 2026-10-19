<?php

declare(strict_types=1);
use App\Models\Category;
use App\Models\User;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;
use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Test fixtures can only be prepared from the CLI.');
}

$browserTestDirectory = $argv[1] ?? '';
$temporaryRoot = realpath(sys_get_temp_dir());
if (
    $temporaryRoot === false || dirname($browserTestDirectory) !== $temporaryRoot
    || ! preg_match('/^SupportOps-mvp-\d{8}-\d{6}$/', basename($browserTestDirectory))
    || file_exists($browserTestDirectory)
) {
    throw new RuntimeException('Choose a new SupportOps-mvp-YYYYMMDD-HHMMSS directory directly under the system temp directory. Existing paths are never reused.');
}

mkdir($browserTestDirectory, 0700);
foreach (['storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data', 'storage/logs'] as $subdirectory) {
    mkdir($browserTestDirectory.'/'.$subdirectory, 0700, true);
}
fclose(fopen($browserTestDirectory.'/database.sqlite', 'x'));
file_put_contents($browserTestDirectory.'/app-key', 'base64:'.base64_encode(random_bytes(32)));
putenv('SUPPORTOPS_BROWSER_TEST_DIR='.$browserTestDirectory);
$application = require __DIR__.'/browser-bootstrap.php';

fwrite(STDOUT, json_encode([
    'environment' => $application->environment(),
    'driver' => $application->make('db')->connection()->getDriverName(),
    'database' => $application->make('db')->connection()->getDatabaseName(),
    'storage' => $application->storagePath(),
], JSON_THROW_ON_ERROR).PHP_EOL);

$exitCode = $application->make(Kernel::class)->call('migrate', ['--force' => true, '--no-interaction' => true]);
if ($exitCode !== 0) {
    throw new RuntimeException('Isolated fixture migrations failed.');
}

$password = bin2hex(random_bytes(16));
$accounts = [];
foreach ([
    'employee' => ['Employé Recette', 'employee', true],
    'technician' => ['Technicien Recette', 'technician', true],
    'admin' => ['Administrateur Recette', 'admin', true],
    'other' => ['Autre Employé Recette', 'employee', true],
    'empty' => ['Employé Sans Ticket', 'employee', true],
    'disabled' => ['Technicien Inactif Recette', 'technician', false],
] as $label => [$name, $role, $active]) {
    $accounts[$label] = User::factory()->create([
        'name' => $name, 'email' => $label.'@mvp.test', 'role' => $role,
        'is_active' => $active, 'password' => $password,
    ]);
}
$category = Category::factory()->create(['name' => 'Réseau Recette']);
$inactiveCategory = Category::factory()->inactive()->create(['name' => 'Archive Recette']);
$ticketService = $application->make(TicketService::class);
for ($index = 1; $index <= 18; $index++) {
    $ticketService->create($accounts['employee'], [
        'title' => sprintf('RECETTE ticket %02d', $index),
        'description' => 'Demande fictive reproductible pour la pagination de recette.',
        'category_id' => $category->id,
    ]);
}
$privateTicket = $ticketService->create($accounts['other'], [
    'title' => 'RECETTE privé autre employé', 'description' => 'Ce ticket fictif doit rester hors du périmètre du premier employé.',
    'category_id' => $category->id,
]);
$technicianTicket = $ticketService->create($accounts['technician'], [
    'title' => 'RECETTE créé et assigné au technicien', 'description' => 'Ticket fictif pour vérifier une union sans double comptage.',
    'category_id' => $category->id,
]);
$application->make(TicketWorkflowService::class)->assign($accounts['admin'], $technicianTicket->id, $accounts['technician']->id);
file_put_contents($browserTestDirectory.'/access.json', json_encode([
    'password' => $password,
    'accounts' => collect($accounts)->map(fn (User $user): array => ['id' => $user->id, 'email' => $user->email, 'role' => $user->role])->all(),
    'category_id' => $category->id, 'inactive_category_id' => $inactiveCategory->id,
    'private_ticket_id' => $privateTicket->id, 'technician_ticket_id' => $technicianTicket->id,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
fwrite(STDOUT, 'Prepared isolated fixtures. Access file is local and must not be included in reports: '.$browserTestDirectory.'/access.json'.PHP_EOL);
