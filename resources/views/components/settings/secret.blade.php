@props(['name', 'label', 'masked' => null])
<label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }} <span class="normal-case text-emerald-400">{{ $masked ?: 'Not configured' }}</span>
    <input name="{{ $name }}" type="password" value="" autocomplete="new-password" placeholder="Leave blank to keep existing" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm normal-case text-white">
</label>
