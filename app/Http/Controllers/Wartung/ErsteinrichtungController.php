<?php

declare(strict_types=1);

namespace App\Http\Controllers\Wartung;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Ersteinrichtung ohne Shellzugang: legt den ersten Administrator an.
 *
 * Funktioniert ausschließlich, solange noch kein Benutzer existiert, und nur
 * mit dem Installationstoken (config('deploy.cron_install_token')). Sobald
 * ein Benutzer vorhanden ist, antwortet der Endpunkt mit 409, weitere
 * Benutzer werden über Einladungen im Adminbereich angelegt. Das erzeugte
 * Passwort wird genau einmal in der Antwort ausgegeben und nicht protokolliert.
 */
class ErsteinrichtungController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = (string) config('deploy.cron_install_token', '');

        if ($configuredToken === '') {
            abort(404);
        }

        $providedToken = (string) ($request->header('X-Cron-Token') ?: $request->query('token', ''));

        if ($providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            abort(403, 'Ungültiger Token.');
        }

        if (User::query()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Es existiert bereits ein Benutzer. Weitere Benutzer werden über Einladungen im Adminbereich angelegt.',
            ], 409);
        }

        $validator = Validator::make(
            ['email' => (string) $request->query('email', ''), 'name' => (string) $request->query('name', '')],
            ['email' => ['required', 'email'], 'name' => ['nullable', 'string', 'max:120']],
            [],
            ['email' => 'E-Mail-Adresse', 'name' => 'Name']
        );

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()->all()], 422);
        }

        $email = (string) $request->query('email');
        $name = trim((string) $request->query('name')) ?: Str::headline(Str::before($email, '@'));
        $klartextPasswort = Str::password(16);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::Admin,
            'password' => Hash::make($klartextPasswort),
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Administrator "'.$user->name.'" ('.$user->email.') wurde angelegt. Das Passwort wird nur jetzt angezeigt, bitte nach der ersten Anmeldung ändern.',
            'passwort' => $klartextPasswort,
        ]);
    }
}
