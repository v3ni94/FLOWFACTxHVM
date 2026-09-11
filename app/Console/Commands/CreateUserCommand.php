<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Einziger Weg, Benutzer anzulegen (Anforderung 8: der Seeder erzeugt keine
 * Benutzer). Ohne --password wird ein zufälliges Passwort erzeugt und genau
 * einmal auf der Konsole ausgegeben.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'flow:user:create
        {email : E-Mail-Adresse, dient als Login}
        {--name= : Anzeigename, Standard ist der Teil vor dem @-Zeichen}
        {--role=mitarbeiter : admin oder mitarbeiter}
        {--password= : Ohne Angabe wird ein zufälliges Passwort erzeugt und einmalig ausgegeben}';

    protected $description = 'Legt einen Benutzer für Müller FLOW an';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $role = (string) $this->option('role');

        $validator = Validator::make(
            [
                'email' => $email,
                'role' => $role,
            ],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'role' => ['required', 'in:'.implode(',', array_column(UserRole::cases(), 'value'))],
            ],
            [],
            [
                'email' => 'E-Mail-Adresse',
                'role' => 'Rolle',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $fehler) {
                $this->error($fehler);
            }

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: Str::headline(Str::before($email, '@')));

        $eingegebenesPasswort = $this->option('password');
        $generiert = $eingegebenesPasswort === null;
        $klartextPasswort = $eingegebenesPasswort !== null
            ? (string) $eingegebenesPasswort
            : Str::password(16);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::from($role),
            'password' => Hash::make($klartextPasswort),
            'is_active' => true,
        ]);

        $this->info('Der Benutzer "'.$user->name.'" ('.$user->email.', Rolle '.$user->role->label().') wurde angelegt.');

        if ($generiert) {
            $this->warn('Zufällig erzeugtes Passwort (wird nur jetzt angezeigt): '.$klartextPasswort);
        }

        return self::SUCCESS;
    }
}
