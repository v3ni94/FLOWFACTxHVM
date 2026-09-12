@php
    $ersterSchritt = \App\Http\Controllers\App\Support\WizardSteps::ERSTER_SCHRITT;
@endphp
<div class="wizard-actions">
    <div class="cluster">
        @if ($schritt > $ersterSchritt)
            <button type="submit" name="aktion" value="zurueck" class="btn btn-secondary" formnovalidate>Zurück</button>
        @endif
        <button type="submit" name="aktion" value="speichern" class="btn btn-secondary" formnovalidate>Entwurf speichern</button>
        <button type="submit" name="aktion" value="weiter" class="btn btn-primary">Weiter</button>
    </div>
    @include('app.listings.schritte._autosave')
</div>
