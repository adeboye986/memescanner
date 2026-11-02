@props(['status'])
@php
    $opportunityStatus = $status instanceof \App\Enums\TradeOpportunityStatus
        ? $status
        : \App\Enums\TradeOpportunityStatus::tryFrom((string) $status);
    $value = $opportunityStatus?->value ?? (string) $status;
    $label = $opportunityStatus?->label() ?? str($value)->replace('_', ' ')->title();
@endphp
<span data-opportunity-status="{{ $value }}" @class([
    'inline-flex whitespace-nowrap rounded-full border px-2 py-0.5 text-[10px] font-semibold',
    'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => $value === 'qualified',
    'border-amber-400/20 bg-amber-400/10 text-amber-300' => $value === 'pending_confirmation',
    'border-blue-400/20 bg-blue-400/10 text-blue-300' => $value === 'executing',
    'border-emerald-400/20 bg-emerald-400/10 text-emerald-300' => $value === 'executed',
    'border-slate-600 bg-slate-800 text-slate-300' => in_array($value, ['ignored', 'expired'], true),
    'border-red-400/20 bg-red-400/10 text-red-300' => $value === 'failed',
    'border-slate-600 bg-slate-800 text-slate-300' => $opportunityStatus === null,
])>{{ $label }}</span>
