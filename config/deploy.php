<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Betrieb und Auslieferung
|------------------------------------------------------------------------------
|
| Schlüssel, die ausschließlich den Betrieb der Anwendung hinter dem
| IONOS-Proxy und die Wartungsendpunkte betreffen. Keine Zugangsdaten.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Vertrauenswürdige Proxys
    |--------------------------------------------------------------------------
    |
    | Hinter dem IONOS-Proxy erreicht die Anfrage PHP unverschlüsselt und mit
    | der Adresse des Proxys. Schema, Host, Port und Client-IP stehen dann nur
    | in den X-Forwarded-Headern. Ohne Vertrauenskonfiguration
    |
    |   - leitet ForceHttps endlos auf https um, weil isSecure() falsch ist,
    |   - protokolliert das Übertragungsprotokoll die Proxy-Adresse statt des
    |     Clients,
    |   - werden signierte Medien-URLs mit http statt https erzeugt und
    |     verworfen.
    |
    | Werte:
    |   leer        kein Proxy wird vertraut (Standard, lokale Entwicklung)
    |   *           allen Proxys vertrauen
    |   a.b.c.d,... kommagetrennte Adressen oder CIDR-Bereiche
    |
    | SICHERHEITSABWÄGUNG zu "*": IONOS veröffentlicht die Adressen seiner
    | Proxys nicht, deshalb ist "*" auf IONOS Webhosting die praktikable
    | Einstellung (Architektur Abschnitt 6). Das Risiko besteht darin, dass ein
    | Client die X-Forwarded-Header selbst setzt. Das ist nur dann möglich,
    | wenn er PHP direkt und nicht über den Proxy erreicht. Auf IONOS
    | Webhosting läuft jede Anfrage über die Plattform, die die Header des
    | Clients überschreibt.
    |
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    |--------------------------------------------------------------------------
    | Wartungsendpunkte
    |--------------------------------------------------------------------------
    |
    | Für Hosting ohne Shellzugang (docs/betrieb/installation.md). Nur POST,
    | eigener Token je Endpunkt, verglichen mit hash_equals. Ein leerer Token
    | schaltet den jeweiligen Endpunkt ab (404).
    |
    */
    'cron_schedule_token' => env('CRON_SCHEDULE_TOKEN', ''),
    'cron_install_token' => env('CRON_INSTALL_TOKEN', ''),

];
