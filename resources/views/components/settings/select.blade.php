@props(['name', 'label', 'value', 'options'])
<label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}
    <select name="{{ $name }}" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm normal-case text-white">@foreach($options as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected(old($name, $value) === $optionValue)>{{ $optionLabel }}</option>@endforeach</select>
</label>
