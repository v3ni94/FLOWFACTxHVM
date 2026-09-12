@props([
    'name',
    'value',
    'label',
    'hint' => null,
    'checked' => false,
    'type' => 'radio',
    'reveals' => null,
    'disabled' => false,
])
{{--
    Große Auswahlkachel (Masterprompt-Abgleich B.1, docs/ui-klassen.md
    erweitert um .kachel). Funktioniert ohne JavaScript als normales
    Radio- oder Checkbox-Feld; flow.js ergänzt nur die optische
    Markierung und das Ein-/Ausblenden über data-reveals.
--}}
<label class="kachel @if($checked) is-selected @endif">
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        value="{{ $value }}"
        @checked($checked)
        @disabled($disabled)
        @if ($reveals) data-reveals="{{ $reveals }}" @endif
    >
    <span class="kachel-title">{{ $label }}</span>
    @if ($hint)
        <span class="kachel-hint">{{ $hint }}</span>
    @endif
</label>
