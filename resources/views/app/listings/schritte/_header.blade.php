@php
    $wizardSteps = \App\Http\Controllers\App\Support\WizardSteps::TITEL;
@endphp

<div class="page-header">
    <h1>{{ $listing->objektnummer }}: {{ \App\Http\Controllers\App\Support\WizardSteps::titel($schritt) }}</h1>
    <div class="page-actions">
        <a href="{{ route('app.listings.show', $listing) }}" class="btn btn-ghost">Zur Übersicht</a>
    </div>
</div>

<ul class="stepper">
    @foreach ($wizardSteps as $nummer => $titel)
        <li data-step="{{ $nummer }}" class="{{ $nummer === $schritt ? 'is-current' : ($nummer < $schritt ? 'is-done' : '') }}">
            <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => $nummer]) }}">{{ $titel }}</a>
        </li>
    @endforeach
</ul>

@if (! $vollstaendigkeit->istVollstaendig())
    <div class="alert alert-info">
        Für die Veröffentlichung fehlen noch: {{ implode(', ', $vollstaendigkeit->fehlend) }}.
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-error">
        <ul>
            @foreach ($errors->all() as $fehler)
                <li>{{ $fehler }}</li>
            @endforeach
        </ul>
    </div>
@endif
