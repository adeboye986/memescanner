<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReconsiderEthereumAccountingRequest;
use App\Services\EthereumAccountingReconsideration;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class EthereumAccountingReconsiderationController extends Controller
{
    public function prepare(ReconsiderEthereumAccountingRequest $request, string $token, EthereumAccountingReconsideration $service): RedirectResponse
    {
        $form = $request->session()->get('ethereum_reconsideration_form');
        if (! is_array($form) || ($form['actor'] ?? null) !== $request->user()->id || ($form['token'] ?? null) !== $token
            || ($form['review'] ?? null) !== (int) $request->validated('review_id') || ($form['version'] ?? null) !== (int) $request->validated('review_version')) {
            throw ValidationException::withMessages(['reconsideration' => 'Open the current token review before requesting reconsideration.']);
        }
        try {
            $audit = $service->prepare($token, $form['review'], $form['version'], $form['uuid']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['reconsideration' => $exception->getMessage()]);
        }

        return to_route('ethereum-eligibility.reconsideration.show', [$token, $audit->request_uuid]);
    }

    public function show(string $token, string $requestUuid, EthereumAccountingReconsideration $service): View
    {
        return view('ethereum-eligibility.reconsideration', ['audit' => $service->find($token, $requestUuid), 'summary' => $service->summary($token)]);
    }

    public function execute(ReconsiderEthereumAccountingRequest $request, string $token, string $requestUuid, EthereumAccountingReconsideration $service): RedirectResponse
    {
        $audit = $service->execute($token, $requestUuid);

        return to_route('ethereum-eligibility.reconsideration.show', [$token, $audit->request_uuid]);
    }
}
