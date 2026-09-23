<x-layouts.admin title="Ethereum Accounting Reviews">
    <p class="text-slate-400">Review accounting eligibility for discovered Ethereum tokens. Unknown tokens remain unapproved. A review does not initiate a trade.</p>
    <p>Evidence collection: {{ $rpcConfigured ? 'Configured; availability is checked when collecting.' : 'Unavailable: Ethereum RPC is not configured.' }}</p>
    <div class="overflow-x-auto rounded-2xl border border-slate-700 p-4">
        <table class="w-full text-left text-sm"><thead><tr><th>Token</th><th>Sources</th><th>Current decision</th><th>Review format</th><th>Pending / unsupported positions</th><th>Latest discovery snapshot</th></tr></thead>
            <tbody>@forelse($tokens as $token)
                <tr class="border-t border-slate-700"><td class="p-3"><a class="text-blue-300" href="{{ route('ethereum-eligibility.show', $token->token) }}">{{ $token->symbol ?: 'Unknown symbol' }} · {{ $token->name }}</a><div class="break-all">{{ $token->token }}</div></td>
                    <td>{{ $token->scanned ? 'Scanner ' : '' }}{{ $token->opportunity ? 'Opportunities ' : '' }}{{ $token->live ? 'LIVE positions' : '' }}</td>
                    <td>{{ $token->status ? strtoupper($token->status) : 'UNAPPROVED' }}</td><td>{{ $token->status ? ($token->review_format_version ?: 'Legacy Phase 4B') : 'No review' }}</td><td>{{ $token->waiting }}</td><td>{{ $token->seen ?: 'Unavailable' }}</td></tr>
            @empty<tr><td colspan="6">No stored Ethereum candidates.</td></tr>@endforelse</tbody>
        </table>
    </div>
    {{ $tokens->links() }}
</x-layouts.admin>
