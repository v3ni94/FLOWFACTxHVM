{{--
    Autosave-Anzeige (Masterprompt-Abgleich B.1: "Wird gespeichert", danach
    "Gespeichert um HH:MM" oder "Nicht gespeichert" mit Fehlermeldung).
    flow.js befüllt dieses Element, sobald das umgebende Formular
    data-autosave trägt. Ohne JavaScript bleibt es leer, das normale
    Absenden über die Schaltflächen funktioniert unabhängig davon.
--}}
<span class="autosave-status" data-autosave-status aria-live="polite"></span>
