@php
    $m = 'domain-reseller-api::messages.';
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('d. M Y') : '—';
    $yesNo = fn ($value) => $value === null ? '—' : ($value ? __($m . 'client.yes') : __($m . 'client.no'));
@endphp
<div class="p-4 flex flex-col gap-4">
    @if ($error)
        @include('domain-reseller-api::partials.error', ['message' => $error])
    @endif

    <div class="grid md:grid-cols-2 gap-4">
        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <h4 class="text-lg font-semibold mb-2">{{ __($m . 'client.domain_details') }}</h4>
            <dl class="grid grid-cols-2 gap-y-1 text-sm">
                <dt class="text-base/50">{{ __($m . 'client.domain') }}</dt>
                <dd class="font-semibold break-all">{{ $displayDomain }}</dd>

                <dt class="text-base/50">{{ __($m . 'client.status') }}</dt>
                <dd>
                    <span class="font-semibold @if (($info['status'] ?? null) === 'active') text-green-500 @elseif (in_array($info['status'] ?? null, ['expired', 'redemption', 'pending_delete', 'deleted', 'transferred_away'])) text-red-500 @else text-orange-500 @endif">
                        {{ $statusLabel }}
                    </span>
                </dd>

                @if ($info)
                    <dt class="text-base/50">{{ __($m . 'client.registered_at') }}</dt>
                    <dd>{{ $date($info['registeredAt'] ?? null) }}</dd>

                    <dt class="text-base/50">{{ __($m . 'client.registry_expiry') }}</dt>
                    <dd>{{ $date($info['expiresAt'] ?? null) }}</dd>

                    <dt class="text-base/50">{{ __($m . 'client.auto_renew') }}</dt>
                    <dd>{{ $yesNo($info['autoRenew'] ?? null) }}</dd>

                    <dt class="text-base/50">{{ __($m . 'client.dnssec') }}</dt>
                    <dd>{{ $yesNo($info['dnssecEnabled'] ?? null) }}</dd>
                @endif
            </dl>
            @if (!empty($info['stateInfo']['description']))
                <p class="text-xs text-base/50 mt-3">{{ $info['stateInfo']['description'] }}</p>
            @endif
        </div>

        @if ($info)
            <div class="bg-background-secondary border border-neutral rounded-lg p-4">
                <h4 class="text-lg font-semibold mb-2">{{ __($m . 'client.nameservers') }}</h4>
                @if ($info['useManagedDns'] ?? false)
                    <p class="text-sm text-base/50 mb-2">{{ __($m . 'client.managed_dns_active') }}</p>
                @endif
                <ul class="text-sm font-mono">
                    @forelse ($info['nameservers'] ?? [] as $ns)
                        <li>{{ $ns }}</li>
                    @empty
                        <li class="text-base/50">—</li>
                    @endforelse
                </ul>

                <h4 class="text-lg font-semibold mt-4 mb-2">{{ __($m . 'client.contacts') }}</h4>
                <dl class="grid grid-cols-2 gap-y-1 text-sm">
                    @foreach (['owner', 'admin', 'tech', 'billing'] as $role)
                        @if (!empty($info['contacts'][$role]))
                            <dt class="text-base/50">{{ __($m . 'contacts.role_' . $role) }}</dt>
                            <dd class="font-mono">{{ $info['contacts'][$role] }}</dd>
                        @endif
                    @endforeach
                </dl>
            </div>
        @endif
    </div>
</div>
