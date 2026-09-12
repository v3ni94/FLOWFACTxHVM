<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Einladung eines neuen Benutzers per E-Mail (Masterprompt Abschnitt 6,
 * Abgleich B.2/B.3).
 *
 * Der eingeladene Benutzer wird erst beim Abschluss der Einladung (Vergabe
 * eines Passworts über /einladung/{token}) als Zeile in "users" angelegt;
 * bis dahin existiert nur dieser Datensatz. Es wird ausschließlich der
 * SHA-256-Hash des Einladungstokens gespeichert (token_hash), nie der rohe
 * Token selbst. Der rohe Token existiert nur im signierten Link der E-Mail
 * und im Rückgabewert von issue()/reissue().
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $darf_veroeffentlichen
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property int $eingeladen_von_user_id
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class UserInvitation extends Model
{
    /** @use HasFactory<UserInvitationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Gültigkeitsdauer einer neu ausgesprochenen oder erneut versendeten
     * Einladung (Masterprompt Abschnitt 6, Abgleich B.2: 72 Stunden).
     */
    public const int GUELTIGKEIT_STUNDEN = 72;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'darf_veroeffentlichen' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Erstellt eine neue Einladung und liefert sie zusammen mit dem rohen,
     * nur an dieser Stelle sichtbaren Token zurück (64 Hexadezimalzeichen,
     * Masterprompt Abschnitt 6, Abgleich B.2).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(
        string $name,
        string $email,
        UserRole $role,
        bool $darfVeroeffentlichen,
        User $eingeladenVon,
    ): array {
        $token = bin2hex(random_bytes(32));

        $invitation = self::query()->create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'darf_veroeffentlichen' => $darfVeroeffentlichen,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(self::GUELTIGKEIT_STUNDEN),
            'eingeladen_von_user_id' => $eingeladenVon->id,
        ]);

        return [$invitation, $token];
    }

    /**
     * Ersetzt den Token durch einen neuen und setzt die Gültigkeitsfrist neu;
     * der alte Token wird dadurch sofort ungültig, ein zuvor erfolgter
     * Widerruf wird aufgehoben (Masterprompt Abschnitt 6, Abgleich B.2:
     * "Admin kann erneut senden").
     *
     * @return string Der neue, rohe Token.
     */
    public function reissue(): string
    {
        $token = bin2hex(random_bytes(32));

        $this->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(self::GUELTIGKEIT_STUNDEN),
            'revoked_at' => null,
        ])->save();

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $token))->first();
    }

    public function istAbgelaufen(): bool
    {
        return $this->expires_at->isPast();
    }

    public function istVerwendet(): bool
    {
        return $this->accepted_at !== null;
    }

    public function istWiderrufen(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Ob über diese Einladung noch ein Konto angelegt werden kann.
     */
    public function istGueltig(): bool
    {
        return ! $this->istAbgelaufen() && ! $this->istVerwendet() && ! $this->istWiderrufen();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function eingeladenVon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eingeladen_von_user_id');
    }
}
