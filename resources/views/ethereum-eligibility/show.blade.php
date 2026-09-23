<x-layouts.admin title="Ethereum Token Accounting Review">
    <a class="text-blue-300" href="{{ route('ethereum-eligibility.index') }}">All review candidates</a>
    @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <h1 class="text-xl">{{ $candidate->symbol ?: 'Unknown symbol' }} · {{ $candidate->name }}</h1><code class="break-all">{{ $candidate->token }}</code>
    <p>Ethereum mainnet · Chain ID 1 · Current decision: {{ $candidate->status ? strtoupper($candidate->status) : 'UNAPPROVED' }}</p>
    @if($stale)<p role="alert">This form is stale. Submitting it will require a fresh review of the current decision.</p>@endif
    <section class="rounded-2xl border border-slate-700 p-5">
        <h2 class="text-lg">Trusted server observation</h2>
        <p class="text-slate-400">Collected at a finalized Ethereum block. Matching bytecode does not prove non-proxy behavior or standard accounting semantics. Those require sourced human review.</p>
        @if($form['observation'])
            <dl>@foreach($form['observation'] as $key => $value)<dt class="mt-2 text-slate-400">{{ str_replace('_', ' ', $key) }}</dt><dd class="break-all">{{ $value }}</dd>@endforeach</dl>
        @else<p>No trusted observation collected. Approval is unavailable; rejection remains available.</p>@endif
        <form method="POST" action="{{ route('ethereum-eligibility.collect', $candidate->token) }}" class="mt-4">@csrf
            <input type="hidden" name="submission_id" value="{{ $form['submission_id'] }}"><input type="hidden" name="expected_review_id" value="{{ $form['expected_review_id'] }}"><input type="hidden" name="expected_version" value="{{ $form['expected_version'] }}"><input type="hidden" name="evidence_digest" value="{{ $digest }}">
            <button class="rounded bg-blue-700 px-4 py-2">Collect trusted evidence</button>
        </form>
    </section>
    <form method="POST" action="{{ route('ethereum-eligibility.preview', $candidate->token) }}" class="space-y-4 rounded-2xl border border-slate-700 p-5">@csrf
        <input type="hidden" name="submission_id" value="{{ $form['submission_id'] }}"><input type="hidden" name="expected_review_id" value="{{ $form['expected_review_id'] }}"><input type="hidden" name="expected_version" value="{{ $form['expected_version'] }}"><input type="hidden" name="evidence_digest" value="{{ $digest }}">
        <h2 class="text-lg">Human review conclusions</h2>
        <p class="text-slate-400">Approval requires every conclusion and supporting source evidence. These are reviewer assertions, not automatic contract certification. Rejection needs only a rationale.</p>
        <label class="block">Decision<select name="decision" class="block rounded bg-slate-900 p-2"><option value="rejected">REJECTED</option><option value="approved" @disabled(!$form['observation'])>APPROVED</option></select></label>
        <label class="block">Rationale (required)<textarea name="rationale" maxlength="255" required class="block w-full rounded bg-slate-900 p-2">{{ old('rationale') }}</textarea></label>
        <label class="block">Reviewed source reference<input name="source_reference" maxlength="2048" value="{{ old('source_reference') }}" class="block w-full rounded bg-slate-900 p-2"></label>
        <label class="block">Reviewed source SHA-256<input name="source_sha256" maxlength="64" value="{{ old('source_sha256') }}" class="block w-full rounded bg-slate-900 p-2"></label>
        <label class="block">Historical applicability evidence<textarea name="historical_applicability" maxlength="4096" class="block w-full rounded bg-slate-900 p-2">{{ old('historical_applicability') }}</textarea></label>
        @foreach($conclusions as $key => $label)<label class="block"><input type="checkbox" name="conclusions[{{ $key }}]" value="1" @checked(old('conclusions.'.$key))> {{ $label }}</label>@endforeach
        <label class="block">Reviewer notes<textarea name="reviewer_notes" maxlength="4096" class="block w-full rounded bg-slate-900 p-2">{{ old('reviewer_notes') }}</textarea></label>
        <button class="rounded bg-blue-700 px-4 py-2">Preview exact decision</button>
    </form>
    <section class="space-y-3 rounded-2xl border border-slate-700 p-5">
        <h2 class="text-lg">Explicit accounting reconsideration</h2>
        <p>Eligible candidates: {{ $reconsiderationSummary['eligible'] }} · Excluded verified: {{ $reconsiderationSummary['verified'] }} · Excluded discrepancy: {{ $reconsiderationSummary['discrepancy'] }} · Excluded other: {{ $reconsiderationSummary['other'] }}</p>
        <p>Batch limit: {{ $reconsiderationSummary['limit'] }}. {{ $reconsiderationSummary['eligible'] > $reconsiderationSummary['limit'] ? 'More eligible work exists beyond one batch.' : 'All currently eligible candidates fit in one batch.' }}</p>
        <p>Source identity is rechecked before claim. Provisional inventory keeps its normal finality/backoff processing. Publishing a review never starts reconsideration. No trades are executed.</p>
        @if(in_array($candidate->status, ['approved', 'rejected'], true))
            <form method="POST" action="{{ route('ethereum-eligibility.reconsideration.prepare', $candidate->token) }}">@csrf
                <input type="hidden" name="review_id" value="{{ $reconsiderationForm['review'] }}"><input type="hidden" name="review_version" value="{{ $reconsiderationForm['version'] }}">
                <button class="rounded bg-blue-700 px-4 py-2">Review accounting reconsideration</button>
            </form>
        @else<p>A current approved or rejected accounting review is required.</p>@endif
        @if($reconsiderationHistory->isNotEmpty())
            <h3>Your recent reconsideration requests</h3>
            <ul>@foreach($reconsiderationHistory as $audit)<li><a class="text-blue-300" href="{{ route('ethereum-eligibility.reconsideration.show', [$candidate->token, $audit->request_uuid]) }}">{{ $audit->created_at }} · Review #{{ $audit->ethereum_accounting_eligibility_id }} · {{ $audit->status }} · {{ $audit->considered_count }} considered / {{ $audit->succeeded_count }} successful / {{ $audit->skipped_count }} skipped / {{ $audit->failed_count }} failed / {{ $audit->stale_count }} stale</a></li>@endforeach</ul>
        @endif
    </section>
    <section class="space-y-4"><h2 class="text-lg">Immutable review history</h2>
        @forelse($history as $review)
            <article class="rounded-2xl border border-slate-700 p-4"><h3>#{{ $review->id }} · {{ strtoupper($review->status) }} · {{ $review->review_format_version ?: 'Legacy Phase 4B review' }}</h3>
                <p>Supersedes: {{ $review->supersedes_review_id ? '#'.$review->supersedes_review_id : 'Not recorded' }} · Reviewed: {{ $review->reviewed_at }}</p>
                <p>Reviewer: {{ $review->reviewer_identity ? (($review->reviewer_identity['name'] ?? '').' · '.($review->reviewer_identity['email'] ?? '')) : 'Not recorded (legacy)' }}</p>
                <p>Policy: {{ $review->policy_version }} · Source: {{ $review->review_source }}</p><p>Rationale: {{ $review->reason }}</p>
                @if($review->review_format_version)<p class="break-all">Runtime SHA-256: {{ $review->code_sha256 ?: 'Not collected for rejection' }} · Evidence digest: {{ $review->evidence_digest }}</p>
                    <p class="break-all">Source reference: {{ data_get($review->review_evidence, 'submission.assertions.source_reference', 'Not supplied') }}</p>
                    <p class="break-all">Source digest: {{ data_get($review->review_evidence, 'submission.assertions.source_sha256', 'Not supplied') }}</p>
                    <p class="break-all">Observation block / hash: {{ $review->observation_block_number ?? 'Not collected' }} / {{ $review->observation_block_hash ?? 'Not collected' }}</p>
                    <p>Evidence collected: {{ $review->evidence_collected_at ?? 'Not collected' }} · Reviewer ID: {{ $review->reviewer_user_id }}</p>
                    <p>Historical applicability: {{ data_get($review->review_evidence, 'submission.assertions.historical_applicability', 'Not supplied') }}</p>
                    <p>{{ data_get($review->review_evidence, 'submission.assertions.reviewer_notes', '') }}</p>
                    @if(data_get($review->review_evidence, 'submission.assertions.workflow_conclusions'))
                        <details><summary>Recorded reviewer conclusions</summary>
                            @foreach($conclusions as $key => $label)<p>{{ $label }}: {{ data_get($review->review_evidence, 'submission.assertions.workflow_conclusions.'.$key) === true ? 'Confirmed by reviewer' : 'Not confirmed' }}</p>@endforeach
                        </details>
                    @endif
                @endif
            </article>
        @empty<p>No review history. This token is unapproved.</p>@endforelse
        {{ $history->links() }}
    </section>
</x-layouts.admin>
