@props(['status'])
@php $value = $status instanceof \BackedEnum ? $status->value : (string) $status; @endphp
<span @class([
    'inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold uppercase tracking-wider',
    'border-sky-400/20 bg-sky-400/10 text-sky-300' => $value === 'qualified',
    'border-amber-400/20 bg-amber-400/10 text-amber-300' => $value === 'pending_confirmation',
    'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => $value === 'executed',
    'border-slate-600 bg-slate-800 text-slate-300' => in_array($value, ['ignored', 'expired'], true),
    'border-red-400/20 bg-red-400/10 text-red-300' => $value === 'failed',
])>{{ str_replace('_', ' ', $value) }}</span>
