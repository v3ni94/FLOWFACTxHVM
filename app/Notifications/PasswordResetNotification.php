<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Deutschsprachige Benachrichtigung zum Zurücksetzen des Passworts
 * (Masterprompt Abschnitt 6, Abgleich B.2). Ersetzt die englische
 * Standardbenachrichtigung von Illuminate\Auth\Notifications\ResetPassword,
 * wird über User::sendPasswordResetNotification() ausgelöst.
 */
class PasswordResetNotification extends ResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);
        $gueltigkeitMinuten = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Passwort zurücksetzen – Müller FLOW')
            ->greeting('Guten Tag,')
            ->line('Sie haben eine Zurücksetzung Ihres Passworts für Müller FLOW angefordert.')
            ->action('Passwort zurücksetzen', $url)
            ->line('Dieser Link ist '.$gueltigkeitMinuten.' Minuten gültig.')
            ->line('Wenn Sie diese Zurücksetzung nicht angefordert haben, müssen Sie nichts weiter unternehmen. Ihr Passwort bleibt unverändert.');
    }
}
