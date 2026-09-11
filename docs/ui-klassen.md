# UI-Klassenvertrag Müller FLOW

Stand: 11.09.2026. Verbindlich für alle Blade-Ansichten. Kein Frontend-Build (ADR-002). Alle Klassen sind in
`public/css/flow.css` definiert, alle Tokens als CSS-Variablen im `:root`. Ansichten verwenden ausschließlich
diese Klassen. Neue Bedarfe erweitern diese Datei, kein Sonderbau je Seite.

## Tokens (CSS-Variablen)

| Variable | Wert | Verwendung |
| --- | --- | --- |
| --hvm-orange | #E6A83C | Primärbutton, Akzentlinie, Schrittziffer, Fortschritt. Nie als Textfarbe |
| --hvm-orange-dark | #C98F2B | Hover Primärbutton, Fokusring |
| --hvm-orange-soft | #FBF1DE | Akzentflächen, Icon-Kreise |
| --hvm-canvas | #FAF8F4 | Seitenhintergrund |
| --hvm-canvas-deep | #F3F0EA | Zweite Flächenstufe, Hover, Zebra |
| --hvm-linie | #E6E3DD | Linien, Kartenrahmen |
| --hvm-hellgrau | #D7D8DA | Rahmen von Eingabefeldern |
| --hvm-mittelgrau | #9C9D9F | Hover-Rahmen |
| --hvm-text-sekundaer | #5C5C5E | Sekundärtext (AA-konform). Anthrazit #87888A nie als Fließtext |
| --hvm-text | #1A1A1A | Fließtext, Überschriften |
| --hvm-graphit | #141414 | Dunkle Flächen (Kopfzeile) |
| --status-success | #1F7A4D | mit --status-success-soft #E3F3EA |
| --status-warning | #A66A00 | mit --status-warning-soft #FBF1DE |
| --status-error | #B3261E | mit --status-error-soft #FBE7E5 |
| --status-info | #2F5D8A | mit --status-info-soft #E7EFF7 |
| --radius-card | 1.25rem | Karten |
| --radius-field | 0.75rem | Eingabefelder |
| --radius-pill | 999px | Buttons, Badges |
| --shadow-hairline | 0 0 0 1px var(--hvm-linie) | Einzige Schattenstufe für Karten |
| --shadow-float | 0 12px 32px rgba(20,20,20,.12) | Nur Menüs und Dialoge |

Schrift: system-ui, "Helvetica Neue", Arial, sans-serif. Keine Webfonts, keine externen Ressourcen, keine
Base64-Bilder. Icons als Inline-SVG mit currentColor. Übergänge nur transition: color, background-color,
border-color 150ms. prefers-reduced-motion wird respektiert.

## Layout

| Klasse | Bedeutung |
| --- | --- |
| .app-shell | Gesamtrahmen: Kopfzeile oben, Inhalt darunter |
| .app-header | Dunkle Kopfzeile (Graphit) mit 3px Kennlinie oben (Orange), Logo links, Navigation, Benutzer rechts |
| .app-nav a, .app-nav a.is-active | Navigationslinks |
| .app-main | Inhaltsbereich, max-width 1200px, Innenabstand 24px, mobil 16px |
| .page-header, .page-header h1, .page-header .page-actions | Seitenkopf mit Titel links und Aktionen rechts |
| .eyebrow | Kleine Versalienzeile mit orangefarbenem 8px-Strich davor |
| .grid, .grid-2, .grid-3 | Responsive Raster, bricht unter 768px auf eine Spalte |
| .stack | Vertikaler Abstand 16px zwischen Kindern |
| .cluster | Horizontale Gruppe mit 8px Abstand, umbrechend |

## Komponenten

| Klasse | Bedeutung |
| --- | --- |
| .card, .card-title, .card-body, .card-footer | Weiße Karte mit Hairline-Rahmen, Radius --radius-card |
| .card.card-canvas | Karte in Canvas-Deep |
| .btn | Basis: Pillform, 44px Höhe, Schrift 600 |
| .btn-primary | Orange Fläche, Text Graphit |
| .btn-secondary | Weiß mit Hairline-Rahmen |
| .btn-ghost | Ohne Rahmen |
| .btn-danger | Fehlerrot |
| .btn-sm, .btn-lg | 36px und 52px |
| .badge, .badge-success, .badge-warning, .badge-error, .badge-info, .badge-neutral | Statusmarke, immer mit Text, nie nur Farbe |
| .alert, .alert-success, .alert-warning, .alert-error, .alert-info | Hinweisbox mit linkem 3px-Balken |
| .field | Umschließt label, Eingabe, Hilfetext, Fehler |
| .field label | 600, 14px |
| .field input, .field select, .field textarea | Höhe 44px, Rahmen Hellgrau, Radius --radius-field, Fokusring Orange-Dark |
| .field .hint | Hilfetext sekundär, 13px |
| .field .error | Fehlertext in --status-error, 13px |
| .field.has-error input | Rahmen in --status-error |
| .field-inline | Checkbox oder Radio mit Text nebeneinander |
| .checkbox-group, .radio-group | Gruppen von .field-inline |
| .table-wrap, .table | Tabelle mit Zebra, Kopfzeile in Canvas-Deep, horizontal scrollbar in .table-wrap |
| .kv, .kv dt, .kv dd | Schlüssel-Wert-Liste zweispaltig |
| .stepper, .stepper li, .stepper li.is-done, .stepper li.is-current | Fortschritt des Assistenten mit Schrittziffer im orangefarbenen Kreis |
| .stat, .stat-value, .stat-label | Kennzahlkarte |
| .empty-state | Leerer Zustand mit Hinweistext und Aktion |
| .dropzone | Uploadfläche mit gestricheltem Rahmen |
| .thumb-grid, .thumb | Bildraster mit Sortiergriffen |
| .status-row | Zeile mit Badge, Text und Zeitstempel |
| .modal, .modal-backdrop | Bestätigungsdialog, nur mit --shadow-float |
| .visually-hidden | Nur für Screenreader |

## Verhalten (public/js/flow.js, ohne Framework)

| Datenattribut | Bedeutung |
| --- | --- |
| data-confirm="Text" | Bestätigungsdialog vor Formularabsendung |
| data-warmmiete | Container mit Eingabefeldern, berechnet die Warmmiete zur Anzeige (Serverwert bleibt maßgeblich) |
| data-sortable | Bildraster mit Sortierung über Pfeiltasten und Schaltflächen (keine Drag-Bibliothek) |
| data-autosubmit | Auswahlfeld, das das Formular absendet |
| data-toggle="#id" | Ein- und Ausblenden eines Bereichs |

Jede Interaktion funktioniert ohne JavaScript grundlegend (Formulare absenden, Bestätigung als Zwischenseite).
