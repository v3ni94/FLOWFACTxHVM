<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\PasswordResetNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'is_active',
        'darf_veroeffentlichen',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'darf_veroeffentlichen' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isMitarbeiter(): bool
    {
        return $this->role === UserRole::Mitarbeiter;
    }

    public function isLeser(): bool
    {
        return $this->role === UserRole::Leser;
    }

    /**
     * Ob dieser Benutzer Objekte veröffentlichen und zurückziehen darf
     * (Masterprompt Abschnitt 6, Abgleich B.3). Ein Administrator darf dies
     * immer, unabhängig vom Flag darf_veroeffentlichen.
     */
    public function kannVeroeffentlichen(): bool
    {
        return $this->isAdmin() || (bool) $this->darf_veroeffentlichen;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Objekte, die dieser Benutzer angelegt hat.
     *
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'erstellt_von_user_id');
    }

    /**
     * Objekte, bei denen dieser Benutzer als Ansprechpartner hinterlegt ist.
     *
     * @return HasMany<Listing, $this>
     */
    public function ansprechpartnerFuer(): HasMany
    {
        return $this->hasMany(Listing::class, 'ansprechpartner_user_id');
    }

    /**
     * Einladungen, die dieser Benutzer ausgesprochen hat (Masterprompt
     * Abschnitt 6, Abgleich B.2).
     *
     * @return HasMany<UserInvitation, $this>
     */
    public function ausgesprocheneEinladungen(): HasMany
    {
        return $this->hasMany(UserInvitation::class, 'eingeladen_von_user_id');
    }

    /**
     * Versendet die Passwort-Zurücksetzung als deutschsprachige Mail
     * (Masterprompt Abschnitt 6, Abgleich B.3) statt der englischen
     * Standardbenachrichtigung von Laravel.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetNotification($token));
    }
}
