<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Einladung zu Müller FLOW</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; line-height: 1.5;">
    <p>Guten Tag {{ $invitation->name }},</p>

    <p>
        {{ $firmenname }} lädt Sie ein, ein Benutzerkonto in Müller FLOW anzulegen,
        der Erfassungs- und Veröffentlichungsanwendung für Immobilienobjekte.
    </p>

    <p>
        <a href="{{ $einladungsUrl }}" style="display:inline-block; padding: 10px 20px; background:#1a2b4c; color:#ffffff; text-decoration:none; border-radius:4px;">
            Einladung annehmen
        </a>
    </p>

    <p>Falls die Schaltfläche nicht funktioniert, kopieren Sie bitte diesen Link in Ihren Browser:</p>
    <p style="word-break: break-all;">{{ $einladungsUrl }}</p>

    <p>
        Diese Einladung ist {{ \App\Models\UserInvitation::GUELTIGKEIT_STUNDEN }} Stunden gültig
        und kann nur einmal verwendet werden. Wenn Sie diese Einladung nicht erwartet haben,
        können Sie diese E-Mail ignorieren.
    </p>

    <p>Mit freundlichen Grüßen<br>{{ $firmenname }}</p>
</body>
</html>
