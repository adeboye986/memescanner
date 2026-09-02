<?php

namespace App\Http\Controllers;

use App\Services\IntegrationConnectionService;
use Illuminate\Http\RedirectResponse;

class TestIntegrationController extends Controller
{
    public function __invoke(string $integration, IntegrationConnectionService $connections): RedirectResponse
    {
        try {
            return back()->with('success', $connections->test($integration));
        } catch (\Throwable) {
            return back()->with('error', 'Connection test failed. Check the integration configuration and application logs.');
        }
    }
}
