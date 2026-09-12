<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Settings\SettingsRepository;
use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Einladung eines neuen Benutzers (Masterprompt Abschnitt 6, Abgleich B.2).
 *
 * Enthält den signierten Link /einladung/{token}. Der rohe Token wird nie
 * gespeichert (siehe App\Models\UserInvitation), deshalb wird er hier
 * ausdrücklich übergeben statt aus der Einladung gelesen.
 *
 * Kein Logo, keine Abhängigkeit vom CI-Briefbogen: eine schlichte
 * Textnachricht reicht für eine Kontoeinladung.
 */
class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $firmenname;

    public readonly string $einladungsUrl;

    public function __construct(
        public readonly UserInvitation $invitation,
        string $token,
    ) {
        $this->firmenname = (string) (app(SettingsRepository::class)->get('firma.name') ?: 'Hausverwaltung Müller GmbH');
        $this->einladungsUrl = URL::signedRoute('invitation.show', ['token' => $token]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Einladung zu Müller FLOW',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-invitation',
        );
    }
}
