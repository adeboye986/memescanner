<span @class([
    'inline-flex items-center rounded px-2 py-1 text-[10px] font-semibold leading-none',
    'bg-amber-400/15 text-amber-200' => in_array($scan['status_key'], ['pending', 'completed_with_warnings'], true),
    'bg-blue-400/15 text-blue-200' => $scan['status_key'] === 'running',
    'bg-emerald-400/15 text-emerald-200' => $scan['status_key'] === 'completed',
    'bg-red-400/15 text-red-200' => $scan['status_key'] === 'failed',
    'bg-slate-700 text-slate-300' => $scan['status_key'] === 'unavailable',
])>
    {{ $scan['status_label'] }}
</span>
