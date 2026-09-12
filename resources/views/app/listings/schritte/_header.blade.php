@php
    $wizardSteps = \App\Http\Controllers\App\Support\WizardSteps::TITEL;
    $befunde = app(\App\Domain\Listing\CompletenessCheck::class)->befunde($listing);
    $schritteMitBefund = collect($befunde)
        ->filter(fn ($befund) => $befund->istBlockierend())
        ->pluck('schritt')
        ->unique();
@endphp

<div class="page-header">
    <h1>{{ $listing->objektnummer }}: {{ \App\Http\Controllers\App\Support\WizardSteps::titel($schritt) }}</h1>
    <div class="page-actions">
        <a href="{{ route('app.listings.show', $listing) }}" class="btn btn-ghost">Zur Übersicht</a>
    </div>
</div>

<div class="table-wrap">
    <ul class="stepper">
        @foreach ($wizardSteps as $nummer => $titel)
            <li
                data-step="{{ $nummer }}"
                class="{{ $nummer === $schritt ? 'is-current' : ($nummer < $schritt ? 'is-done' : '') }} {{ $schritteMitBefund->contains($nummer) ? 'has-issues' : '' }}"
            >
                <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => $nummer]) }}">{{ $titel }}</a>
            </li>
        @endforeach
        <li class="{{ $schritteMitBefund->isEmpty() ? '' : 'has-issues' }}">
            <a href="{{ route('app.listings.review', ['listing' => $listing]) }}">Prüfen</a>
        </li>
    </ul>
</div>

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
