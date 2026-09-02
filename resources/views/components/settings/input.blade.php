@props(['name', 'label', 'value' => null, 'type' => 'text', 'step' => null])
<label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}
    <input name="{{ $name }}" type="{{ $type }}" @if($step) step="{{ $step }}" @endif value="{{ old($name, $value) }}" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm normal-case text-white">
</label>
