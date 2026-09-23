<?php

namespace App\Http\Controllers;

use App\Exceptions\EthereumAccountingException;
use App\Http\Requests\StoreEthereumEligibilityReviewRequest;
use App\Models\EthereumAccountingEligibility;
use App\Models\EthereumAccountingReconsideration;
use App\Services\EthereumEligibilityObservationCollector;
use App\Services\EthereumEligibilityReviewGeneration;
use App\Services\EthereumEligibilityReviewQueue;
use App\Services\EthereumEligibilityReviewService;
use App\Services\EthereumInventoryAccounting;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EthereumEligibilityReviewController extends Controller
{
    public function __construct(private EthereumEligibilityReviewGeneration $generations) {}

    public function index(EthereumEligibilityReviewQueue $queue): View
    {
        return view('ethereum-eligibility.index', ['tokens' => $queue->query()->paginate(25),
            'rpcConfigured' => str_starts_with((string) config('services.ethereum.rpc_url'), 'https://')]);
    }

    public function show(Request $request, string $token, EthereumEligibilityReviewQueue $queue, \App\Services\EthereumAccountingReconsideration $reconsideration): View
    {
        $candidate = $queue->query()->where('tokens.token', $token)->first();
        abort_unless($candidate, 404);
        $state = $queue->state($token);
        $form = $request->session()->get('ethereum_review_form');
        if (! $this->owns($request, $form, $token) || isset($form['published'])) {
            $form = ['actor' => (string) $request->user()->id, 'token' => $token, 'expires' => now()->addMinutes(20)->timestamp,
                'submission_id' => (string) Str::uuid(), ...$state, 'reference' => null, 'observation' => null];
            $form['review_generation'] = $this->generations->begin($request->user()->id, $token, $form['submission_id']);
            $request->session()->put('ethereum_review_form', $form);
        }

        $reconsiderationForm = $request->session()->get('ethereum_reconsideration_form');
        if (! is_array($reconsiderationForm) || ($reconsiderationForm['actor'] ?? null) !== $request->user()->id
            || ($reconsiderationForm['token'] ?? null) !== $token || ($reconsiderationForm['review'] ?? null) !== $state['expected_review_id']
            || ($reconsiderationForm['version'] ?? null) !== $state['expected_version']
            || EthereumAccountingReconsideration::query()->where('request_uuid', $reconsiderationForm['uuid'])->where('status', '!=', 'awaiting_confirmation')->exists()) {
            $reconsiderationForm = ['actor' => $request->user()->id, 'token' => $token, 'review' => $state['expected_review_id'],
                'version' => $state['expected_version'], 'uuid' => (string) Str::uuid()];
            $request->session()->put('ethereum_reconsideration_form', $reconsiderationForm);
        }

        return view('ethereum-eligibility.show', ['candidate' => $candidate, 'form' => $form, 'reconsiderationSummary' => $reconsideration->summary($token), 'reconsiderationForm' => $reconsiderationForm,
            'reconsiderationHistory' => EthereumAccountingReconsideration::query()->where('chain', 'ethereum')->where('token_address', $token)
                ->where('requested_by_user_id', $request->user()->id)->latest('id')->limit(10)
                ->get(['request_uuid', 'status', 'ethereum_accounting_eligibility_id', 'created_at', 'considered_count', 'skipped_count', 'succeeded_count', 'failed_count', 'stale_count']),
            'digest' => $this->observationDigest($form), 'stale' => $this->state($form) !== $state,
            'history' => EthereumAccountingEligibility::query()->where('chain', 'ethereum')->where('token_address', $token)->latest('id')->paginate(25),
            'conclusions' => EthereumEligibilityReviewService::WORKFLOW_CONCLUSIONS]);
    }

    public function collect(StoreEthereumEligibilityReviewRequest $request, string $token, EthereumEligibilityReviewQueue $queue, EthereumEligibilityObservationCollector $collector): RedirectResponse
    {
        $form = $this->form($request, $token, $queue);
        $request->session()->forget('ethereum_review_confirmation');
        try {
            $form['review_generation'] = $this->generations->replace($request->user()->id, $token, $form['submission_id'], $form['review_generation']);
            $evidence = $collector->capture($token);
            $this->generations->bind($request->user()->id, $token, $form['submission_id'], $form['review_generation'], $evidence['reference']);
            $form['reference'] = $evidence['reference'];
            $form['observation'] = $evidence['observation'];
            $request->session()->put('ethereum_review_form', $form);
        } catch (DomainException|EthereumAccountingException) {
            $form['reference'] = null;
            $form['observation'] = null;
            $request->session()->put('ethereum_review_form', $form);

            return back()->withErrors(['review' => 'Trusted evidence is unavailable. Approval is blocked; rejection remains available.']);
        }

        return to_route('ethereum-eligibility.show', $token)->with('success', 'Trusted observation collected. Review the source and conclusions before approval.');
    }

    public function preview(StoreEthereumEligibilityReviewRequest $request, string $token, EthereumEligibilityReviewQueue $queue, EthereumEligibilityObservationCollector $collector): RedirectResponse
    {
        $form = $this->form($request, $token, $queue);
        $approved = $request->validated('decision') === 'approved';
        $observation = null;
        if ($approved) {
            try {
                $observation = $collector->collect($token, $form['reference'] ?? '');
                if (EthereumInventoryAccounting::canonicalEvidence($observation) !== EthereumInventoryAccounting::canonicalEvidence($form['observation'])) {
                    throw new DomainException('Changed evidence.');
                }
            } catch (DomainException|EthereumAccountingException) {
                throw ValidationException::withMessages(['review' => 'Trusted evidence is unavailable or changed. Collect evidence and confirm again. Rejection remains available.']);
            }
        }
        $input = ['chain' => 'ethereum', 'chain_id' => 1, 'token_address' => $token,
            'policy_version' => EthereumAccountingEligibility::POLICY, 'decision' => $request->validated('decision'),
            'review_source' => 'operator-workflow', 'rationale' => $request->validated('rationale'),
            ...$this->state($form), 'submission_id' => $form['submission_id'], 'review_generation' => $form['review_generation'],
            'observation_reference' => $approved ? $form['reference'] : null,
            'assertions' => $approved ? ['source_reference' => $request->validated('source_reference'), 'source_sha256' => $request->validated('source_sha256'),
                'historical_applicability' => $request->validated('historical_applicability'), 'non_proxy' => true,
                'standard_transfer_accounting' => true, 'no_mutable_balance_behavior' => true,
                'workflow_conclusions' => array_fill_keys(array_keys(EthereumEligibilityReviewService::WORKFLOW_CONCLUSIONS), true),
                'reviewer_notes' => $request->validated('reviewer_notes') ?? ''] : []];
        $input['evidence_digest'] = EthereumEligibilityReviewService::digest($request->user()->id, $input, $observation);
        $confirmation = ['actor' => (string) $request->user()->id, 'token' => $token, 'expires' => $form['expires'], 'input' => $input, 'observation' => $observation];
        $request->session()->put('ethereum_review_confirmation', $confirmation);

        return to_route('ethereum-eligibility.confirm', $token);
    }

    public function confirm(Request $request, string $token): View
    {
        $confirmation = $request->session()->get('ethereum_review_confirmation');
        if (! $this->owns($request, $confirmation, $token)) {
            abort(409, 'Confirmation expired. Open the token review again.');
        }

        return view('ethereum-eligibility.confirm', ['confirmation' => $confirmation, 'reviewer' => $request->user()]);
    }

    public function publish(StoreEthereumEligibilityReviewRequest $request, string $token, EthereumEligibilityReviewService $service): RedirectResponse
    {
        $confirmation = $request->session()->get('ethereum_review_confirmation');
        if (! $this->owns($request, $confirmation, $token)) {
            throw ValidationException::withMessages(['review' => 'Confirmation expired. Review the decision again.']);
        }
        $input = $confirmation['input'];
        $this->checkSubmitted($request, $input, $input['evidence_digest']);
        try {
            $review = $service->publish($input);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['review' => $exception->getMessage()]);
        } catch (EthereumAccountingException) {
            throw ValidationException::withMessages(['review' => 'Trusted evidence is unavailable. Collect evidence and confirm again. Rejection remains available.']);
        }
        $request->session()->put('ethereum_review_form.published', true);

        return to_route('ethereum-eligibility.show', $token)->with('success', 'Review #'.$review->id.' published. No trade or accounting reconsideration was initiated.');
    }

    private function owns(Request $request, mixed $data, string $token): bool
    {
        return is_array($data) && ($data['actor'] ?? null) === (string) $request->user()->id
            && ($data['token'] ?? null) === $token && ($data['expires'] ?? 0) > now()->timestamp;
    }

    private function form(StoreEthereumEligibilityReviewRequest $request, string $token, EthereumEligibilityReviewQueue $queue): array
    {
        $form = $request->session()->get('ethereum_review_form');
        if (! $this->owns($request, $form, $token)) {
            throw ValidationException::withMessages(['review' => 'Review form expired. Open the token review again.']);
        }
        $this->checkSubmitted($request, $form, $this->observationDigest($form));
        if ($this->state($form) !== $queue->state($token)) {
            $request->session()->forget('ethereum_review_form');
            throw ValidationException::withMessages(['review' => 'The current review has changed. Reload and review the new decision first.']);
        }

        try {
            $this->generations->withCurrent($request->user()->id, $token, $form['submission_id'], $form['review_generation'], $form['reference'], static fn () => null);
        } catch (DomainException $exception) {
            $request->session()->forget(['ethereum_review_form', 'ethereum_review_confirmation']);
            throw ValidationException::withMessages(['review' => $exception->getMessage()]);
        }

        return $form;
    }

    private function checkSubmitted(StoreEthereumEligibilityReviewRequest $request, array $expected, string $digest): void
    {
        if ($request->validated('submission_id') !== $expected['submission_id'] || $request->validated('evidence_digest') !== $digest
            || ($request->validated('expected_review_id') === null ? null : (int) $request->validated('expected_review_id')) !== $expected['expected_review_id']
            || (int) $request->validated('expected_version') !== $expected['expected_version']) {
            throw ValidationException::withMessages(['review' => 'Stale or altered review confirmation. Review the exact decision again.']);
        }
    }

    private function state(array $form): array
    {
        return ['expected_review_id' => $form['expected_review_id'], 'expected_version' => $form['expected_version']];
    }

    private function observationDigest(array $form): string
    {
        return hash('sha256', EthereumInventoryAccounting::canonicalEvidence([$form['review_generation'], $form['observation']]));
    }
}
