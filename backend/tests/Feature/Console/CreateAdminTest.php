<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'SecurePassword123!';

    public function test_non_interactive_command_refuses_without_creating_an_admin(): void
    {
        $this->artisan('supportops:create-admin', ['--no-interaction' => true])
            ->expectsOutput('Cette commande exige une saisie interactive.')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_initial_admin_creation_masks_password_and_confirmation_and_hashes_password(): void
    {
        $buffer = new BufferedOutput;
        $style = Mockery::mock(OutputStyle::class, [new ArrayInput([]), $buffer])->makePartial();
        foreach ([['Nom', 'Initial Admin', false], ['Adresse e-mail', 'initial@example.com', false],
            ['Mot de passe', self::PASSWORD, true], ['Confirmer le mot de passe', self::PASSWORD, true]] as [$prompt, $answer, $hidden]) {
            $style->shouldReceive('askQuestion')->once()->ordered()
                ->with(Mockery::on(function (Question $question) use ($prompt, $hidden): bool {
                    $this->assertSame($prompt, $question->getQuestion());
                    $this->assertSame($hidden, $question->isHidden());
                    if ($hidden) {
                        $this->assertFalse($question->isHiddenFallback());
                    }

                    return true;
                }))->andReturn($answer);
        }
        $this->app->bind(OutputStyle::class, fn () => $style);

        $exitCode = Artisan::call('supportops:create-admin', [], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString(self::PASSWORD, $buffer->fetch());
        $user = User::query()->where('email', 'initial@example.com')->firstOrFail();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Initial Admin', 'role' => 'admin', 'is_active' => true]);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->getRawOriginal('password')));
    }

    public function test_invalid_input_is_reprompted_before_creating_the_initial_admin(): void
    {
        $this->artisan('supportops:create-admin')
            ->expectsQuestion('Nom', '')->expectsQuestion('Nom', 'Valid Admin')
            ->expectsQuestion('Adresse e-mail', 'invalid')->expectsQuestion('Adresse e-mail', 'valid@example.com')
            ->expectsQuestion('Mot de passe', 'short')->expectsQuestion('Confirmer le mot de passe', 'short')
            ->expectsQuestion('Mot de passe', self::PASSWORD)->expectsQuestion('Confirmer le mot de passe', 'different')
            ->expectsQuestion('Mot de passe', self::PASSWORD)->expectsQuestion('Confirmer le mot de passe', self::PASSWORD)
            ->expectsOutput('Premier administrateur créé avec succès.')->assertSuccessful();

        $this->assertDatabaseHas('users', ['name' => 'Valid Admin', 'email' => 'valid@example.com', 'role' => 'admin', 'is_active' => true]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_inactive_admin_prevents_initial_admin_creation(): void
    {
        User::factory()->admin()->inactive()->create();

        $this->artisan('supportops:create-admin')
            ->expectsOutput('Un administrateur existe déjà. Utilisez la gestion des utilisateurs.')->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_email_is_refused_without_promoting_an_employee(): void
    {
        $employee = User::factory()->create(['email' => 'existing@example.com']);

        $this->artisan('supportops:create-admin')->expectsQuestion('Nom', 'Initial Admin')
            ->expectsQuestion('Adresse e-mail', 'existing@example.com')
            ->expectsOutput('Cette adresse e-mail est déjà utilisée.')->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $employee->id, 'role' => 'employee']);
        $this->assertDatabaseCount('users', 1);
    }
}
