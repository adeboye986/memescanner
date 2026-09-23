<x-layouts.admin title="Confirm Ethereum Accounting Decision">
    @php $input = $confirmation['input']; $observation = $confirmation['observation']; @endphp
    @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <h1 class="text-xl">Confirm {{ strtoupper($input['decision']) }}</h1>
    <p>Publication records an accounting eligibility decision. It does not execute a trade or reconsider positions.</p>
    <dl class="space-y-2 break-all">
        <dt>Token</dt><dd>{{ $input['token_address'] }}</dd><dt>Chain</dt><dd>Ethereum mainnet · {{ $input['chain_id'] }}</dd>
        <dt>Policy version</dt><dd>{{ $input['policy_version'] }}</dd><dt>Review format</dt><dd>{{ \App\Services\EthereumEligibilityReviewService::FORMAT }}</dd>
        <dt>Runtime SHA-256</dt><dd>{{ $observation['code_sha256'] ?? 'Not collected for rejection' }}</dd>
        <dt>Observation block / hash</dt><dd>{{ $observation['block_number'] ?? 'Not collected' }} / {{ $observation['block_hash'] ?? 'Not collected' }}</dd>
        <dt>Collected at</dt><dd>{{ $observation['collected_at'] ?? 'Not collected' }}</dd>
        <dt>Evidence digest</dt><dd>{{ $input['evidence_digest'] }}</dd><dt>Submission identifier</dt><dd>{{ $input['submission_id'] }}</dd>
        <dt>Supersedes / head version</dt><dd>{{ $input['expected_review_id'] ?? 'No review' }} / {{ $input['expected_version'] }}</dd>
        <dt>Reviewer</dt><dd>{{ $reviewer->name }} · {{ $reviewer->email }} · #{{ $reviewer->id }}</dd><dt>Rationale</dt><dd>{{ $input['rationale'] }}</dd>
        @if($observation)<dt>Reviewed source reference / digest</dt><dd>{{ $input['assertions']['source_reference'] }} / {{ $input['assertions']['source_sha256'] }}</dd>
            <dt>Historical applicability</dt><dd>{{ $input['assertions']['historical_applicability'] }}</dd><dt>Reviewer notes</dt><dd>{{ $input['assertions']['reviewer_notes'] }}</dd>
            @foreach(\App\Services\EthereumEligibilityReviewService::WORKFLOW_CONCLUSIONS as $label)<dt>{{ $label }}</dt><dd>Confirmed by reviewer</dd>@endforeach
        @endif
    </dl>
    <form method="POST" action="{{ route('ethereum-eligibility.publish', $input['token_address']) }}" class="space-y-4">@csrf
        <input type="hidden" name="submission_id" value="{{ $input['submission_id'] }}"><input type="hidden" name="expected_review_id" value="{{ $input['expected_review_id'] }}"><input type="hidden" name="expected_version" value="{{ $input['expected_version'] }}"><input type="hidden" name="evidence_digest" value="{{ $input['evidence_digest'] }}">
        <label class="block">Confirm current password<input type="password" name="current_password" required autocomplete="current-password" class="block rounded bg-slate-900 p-2"></label>
        <button class="rounded bg-blue-700 px-4 py-2">Publish this exact decision</button>
    </form>
    <a href="{{ route('ethereum-eligibility.show', $input['token_address']) }}" class="text-blue-300">Return to review</a>
</x-layouts.admin>
