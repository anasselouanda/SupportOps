<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Signature('supportops:create-admin')]
#[Description('Créer le premier administrateur SupportOps avec une saisie interactive et un mot de passe masqué')]
class CreateInitialAdmin extends Command
{
    public function handle(AdminUserService $service): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Cette commande exige une saisie interactive.');

            return self::FAILURE;
        }

        if (User::query()->where('role', User::ROLE_ADMIN)->exists()) {
            $this->error('Un administrateur existe déjà. Utilisez la gestion des utilisateurs.');

            return self::FAILURE;
        }

        $name = $this->askValidated('Nom', 'name', ['required', 'string', 'max:255']);
        $email = $this->askValidated('Adresse e-mail', 'email', ['required', 'string', 'email', 'max:255']);

        if (User::query()->where('email', $email)->exists()) {
            $this->error('Cette adresse e-mail est déjà utilisée.');

            return self::FAILURE;
        }

        do {
            $password = $this->secret('Mot de passe', false);
            $confirmation = $this->secret('Confirmer le mot de passe', false);
            $validator = Validator::make([
                'password' => $password,
                'password_confirmation' => $confirmation,
            ], ['password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']], $this->administratorValidationMessages(), ['password' => 'mot de passe']);

            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
        } while ($validator->fails());

        try {
            $service->createInitialAdministrator(['name' => $name, 'email' => $email, 'password' => $password]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $errors) {
                foreach ($errors as $error) {
                    $this->error($error);
                }
            }

            return self::FAILURE;
        }

        $this->info('Premier administrateur créé avec succès.');

        return self::SUCCESS;
    }

    /** @param array<int, string> $rules */
    private function askValidated(string $question, string $field, array $rules): string
    {
        do {
            $value = trim((string) $this->ask($question));
            $validator = Validator::make([$field => $value], [$field => $rules], $this->administratorValidationMessages(), [
                'name' => 'nom',
                'email' => 'adresse e-mail',
            ]);

            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
        } while ($validator->fails());

        return $value;
    }

    /** @return array<string, string> */
    private function administratorValidationMessages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'string' => 'Le champ :attribute doit être du texte.',
            'email' => 'Le champ :attribute doit contenir une adresse e-mail valide.',
            'max.string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
            'min.string' => 'Le champ :attribute doit contenir au moins :min caractères.',
            'confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ];
    }
}
