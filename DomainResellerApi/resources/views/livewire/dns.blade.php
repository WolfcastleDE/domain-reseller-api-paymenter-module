@php($m = 'domain-reseller-api::messages.')
<div class="flex flex-col gap-4">
    @if ($loadError)
        @include('domain-reseller-api::partials.error', ['message' => $loadError])
    @elseif (!$managed)
        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <h4 class="text-lg font-semibold">{{ __($m . 'dns.title') }}</h4>
            <p class="text-sm text-base/50 my-2">{{ __($m . 'dns.not_managed') }}</p>
            <x-button.primary type="button" class="!w-fit" wire:click="enableManagedDns"
                wire:confirm="{{ __($m . 'nameservers.managed_confirm') }}">
                {{ __($m . 'dns.enable_managed') }}
            </x-button.primary>
        </div>
    @else
        <div class="bg-background-secondary border border-neutral rounded-lg p-4 overflow-x-auto">
            <div class="flex flex-row justify-between items-center mb-3">
                <h4 class="text-lg font-semibold">{{ __($m . 'dns.title') }}</h4>
                <span class="text-sm text-base/50">{{ trans_choice($m . 'dns.count', count($records), ['count' => count($records)]) }}</span>
            </div>
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="border-b border-neutral">
                        <th class="py-2 pr-2">{{ __($m . 'dns.type') }}</th>
                        <th class="py-2 pr-2">{{ __($m . 'dns.name') }}</th>
                        <th class="py-2 pr-2">{{ __($m . 'dns.content') }}</th>
                        <th class="py-2 pr-2">{{ __($m . 'dns.ttl') }}</th>
                        <th class="py-2 pr-2">{{ __($m . 'dns.priority') }}</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr class="border-b border-neutral/50" wire:key="record-{{ $record['id'] }}">
                            <td class="py-2 pr-2 font-semibold">{{ $record['type'] }}</td>
                            <td class="py-2 pr-2 font-mono break-all">{{ $record['name'] === '' ? '@' : $record['name'] }}</td>
                            <td class="py-2 pr-2 font-mono break-all">{{ $record['content'] }}</td>
                            <td class="py-2 pr-2">{{ $record['ttl'] ?? '—' }}</td>
                            <td class="py-2 pr-2">{{ $record['priority'] ?? '—' }}</td>
                            <td class="py-2">
                                <div class="flex flex-row gap-2 justify-end">
                                    <x-button.secondary type="button" class="!w-fit" wire:click="edit('{{ $record['id'] }}')"
                                        title="{{ __($m . 'dns.edit') }}">
                                        <x-ri-pencil-line class="size-4" />
                                    </x-button.secondary>
                                    <x-button.danger type="button" class="!w-fit" wire:click="delete('{{ $record['id'] }}')"
                                        wire:confirm="{{ __($m . 'dns.delete_confirm') }}" title="{{ __($m . 'dns.delete') }}">
                                        <x-ri-delete-bin-line class="size-4" />
                                    </x-button.danger>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-4 text-center text-base/50">{{ __($m . 'dns.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <h4 class="text-lg font-semibold mb-3">
                {{ $editing ? __($m . 'dns.edit_record') : __($m . 'dns.add_record') }}
            </h4>
            <form wire:submit="save" class="flex flex-col gap-3">
                <div class="grid md:grid-cols-4 gap-3">
                    <x-form.select name="type" wire:model.live="type" :label="__($m . 'dns.type')" :disabled="(bool) $editing">
                        @foreach ($types as $recordType)
                            <option value="{{ $recordType }}">{{ $recordType }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.input name="name" wire:model="name" :label="__($m . 'dns.name')" placeholder="@" />
                    <x-form.input name="ttl" type="number" wire:model="ttl" :label="__($m . 'dns.ttl')" placeholder="3600" />
                    @if (in_array($type, $priorityTypes, true))
                        <x-form.input name="priority" type="number" wire:model="priority" :label="__($m . 'dns.priority')" placeholder="10" />
                    @endif
                </div>
                <x-form.input name="content" wire:model="content" :label="__($m . 'dns.content')"
                    :placeholder="__($m . 'dns.content_placeholder_' . strtolower($type))" />
                <p class="text-xs text-base/50">{{ __($m . 'dns.name_hint', ['domain' => $this->displayDomain()]) }}</p>
                <div class="flex flex-row gap-2">
                    <x-button.primary type="submit" class="!w-fit">
                        {{ $editing ? __($m . 'dns.update') : __($m . 'dns.add') }}
                    </x-button.primary>
                    @if ($editing)
                        <x-button.secondary type="button" class="!w-fit" wire:click="resetForm">
                            {{ __($m . 'dns.cancel') }}
                        </x-button.secondary>
                    @endif
                </div>
            </form>
        </div>

        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <h4 class="text-lg font-semibold">{{ __($m . 'dns.zone_file') }}</h4>
            <p class="text-sm text-base/50 mt-1 mb-3">{{ __($m . 'dns.zone_file_description') }}</p>
            <form wire:submit="importZone" class="flex flex-col gap-3">
                <x-form.textarea name="zoneFile" wire:model="zoneFile" :label="__($m . 'dns.zone_import_label')" rows="6"
                    placeholder="www 3600 IN A 203.0.113.10" />
                <div class="flex flex-row gap-2">
                    <x-button.primary type="submit" class="!w-fit" wire:confirm="{{ __($m . 'dns.zone_import_confirm') }}">
                        {{ __($m . 'dns.zone_import') }}
                    </x-button.primary>
                    <x-button.secondary type="button" class="!w-fit" wire:click="exportZone">
                        {{ __($m . 'dns.zone_export') }}
                    </x-button.secondary>
                </div>
            </form>
        </div>
    @endif
</div>
