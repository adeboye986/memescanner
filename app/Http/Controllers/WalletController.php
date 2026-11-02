<?php

namespace App\Http\Controllers;

use App\Services\WalletDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __invoke(Request $request, WalletDashboardService $wallets): View
    {
        return view('wallets.index', [
            'user' => $request->user(),
            'walletDashboard' => $wallets->forUser($request->user()),
        ]);
    }
}
