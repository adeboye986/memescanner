<x-layouts.admin title="Ethereum Accounting Reconsideration">
    <a class="text-blue-300" href="{{ route('ethereum-eligibility.show', $audit->token_address) }}">Return to token review</a>
    @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <h1 class="text-xl">{{ $audit->status === 'awaiting_confirmation' ? 'Confirm accounting reconsideration' : 'Accounting reconsideration result' }}</h1>
    <p>This performs accounting reconsideration only. No trades are executed, signed or broadcast.</p>
    <dl class="space-y-2 break-all">
        <dt>Token / chain</dt><dd>{{ $audit->token_address }} · Ethereum mainnet</dd>
        <dt>Exact review / head version</dt><dd>#{{ $audit->ethereum_accounting_eligibility_id }} / {{ $audit->review_head_version }}</dd>
        <dt>Review decision / policy</dt><dd>{{ strtoupper($audit->review_decision) }} / {{ $audit->policy_version }}</dd>
        <dt>Request / status</dt><dd>{{ $audit->request_uuid }} / {{ $audit->status }}</dd>
        <dt>Candidate snapshot / batch limit</dt><dd>{{ count($audit->candidate_snapshot) }} / {{ $audit->batch_limit }}</dd>
        <dt>Currently excluded: verified / discrepancy / other</dt><dd>{{ $summary['verified'] }} / {{ $summary['discrepancy'] }} / {{ $summary['other'] }}</dd>
        <dt>Additional eligible work{{ $audit->status === 'awaiting_confirmation' ? ' outside this batch' : ' at completion' }}</dt><dd>{{ $audit->remaining_count }}</dd>
        <dt>Considered / skipped / successful / failed / stale</dt><dd>{{ $audit->considered_count }} / {{ $audit->skipped_count }} / {{ $audit->succeeded_count }} / {{ $audit->failed_count }} / {{ $audit->stale_count }}</dd>
        <dt>Requested / started / finished</dt><dd>{{ $audit->created_at }} / {{ $audit->started_at ?? 'Not started' }} / {{ $audit->finished_at ?? 'Not finished' }}</dd>
    </dl>
    @if($audit->review_decision === 'rejected')
        <p>This review is REJECTED. Confirmation records a no-op: candidates are skipped without RPC verification or accounting evidence changes.</p>
    @else
        <p>Only the captured pending/unsupported candidates may enter the existing inventory accounting checks. Changed identities, states, active leases and backoff are rechecked. Provisional, verified and discrepancy positions are excluded.</p>
    @endif
    @if($audit->status === 'awaiting_confirmation')
        <p>Confirmation expires at {{ $audit->expires_at }}. A changed review makes this request stale.</p>
        <form method="POST" action="{{ route('ethereum-eligibility.reconsideration.execute', [$audit->token_address, $audit->request_uuid]) }}" class="space-y-4">@csrf
            <label class="block">Confirm current password<input type="password" name="current_password" required autocomplete="current-password" class="block rounded bg-slate-900 p-2"></label>
            <button class="rounded bg-blue-700 px-4 py-2">Confirm accounting-only reconsideration</button>
        </form>
    @elseif($audit->status === 'running')
        <p>This request has already started. Refresh to view recorded progress. Re-submitting does not start it again.</p>
    @else
        <p>This request is final. Re-submitting returns this result. Any further work requires a new explicit request from the token review.</p>
    @endif
    @if($audit->outcomes)
        <h2 class="text-lg">Recorded outcomes</h2>
        <ul>@foreach(collect($audit->outcomes)->countBy('code') as $code => $count)<li>{{ str_replace('_', ' ', $code) }}: {{ $count }}</li>@endforeach</ul>
    @endif
</x-layouts.admin>
