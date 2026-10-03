@php($m = 'domain-reseller-api::messages.')
<div class="flex flex-col gap-4">
    @if ($loadError)
        @include('domain-reseller-api::partials.error', ['message' => $loadError])
    @else
        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <h4 class="text-lg font-semibold">{{ __($m . 'nameservers.title') }}</h4>
            <p class="text-sm text-base/50 mb-4">
                @if ($useManagedDns)
                    {{ __($m . 'nameservers.managed_hint') }}
                @else
                    {{ __($m . 'nameservers.custom_hint') }}
                @endif
            </p>

            <form wire:submit="save" class="flex flex-col gap-3">
                @foreach ($nameservers as $index => $ns)
                    <div class="flex flex-row gap-2 items-end" wire:key="ns-{{ $index }}">
                        <x-form.input name="nameservers.{{ $index }}" wire:model="nameservers.{{ $index }}"
                            :label="__($m . 'nameservers.nameserver', ['number' => $index + 1])"
                            placeholder="ns{{ $index + 1 }}.example.com" />
                        @if (count($nameservers) > 2)
                            <x-button.secondary type="button" class="!w-fit" wire:click="removeNameserver({{ $index }})"
                                title="{{ __($m . 'nameservers.remove') }}">
                                <x-ri-delete-bin-line class="size-4" />
                            </x-button.secondary>
                        @endif
                    </div>
                @endforeach

                @error('nameservers')
                    <p class="text-red-500 text-xs">{{ $message }}</p>
                @enderror

                <div class="flex flex-row flex-wrap gap-2">
                    @if (count($nameservers) < 6)
                        <x-button.secondary type="button" class="!w-fit" wire:click="addNameserver">
                            {{ __($m . 'nameservers.add') }}
                        </x-button.secondary>
                    @endif
                    <x-button.primary type="submit" class="!w-fit">
                        {{ __($m . 'nameservers.save') }}
                    </x-button.primary>
                </div>
            </form>
        </div>

        @unless ($useManagedDns)
            <div class="bg-background-secondary border border-neutral rounded-lg p-4">
                <h4 class="text-lg font-semibold">{{ __($m . 'nameservers.managed_title') }}</h4>
                <p class="text-sm text-base/50 mb-4">{{ __($m . 'nameservers.managed_description') }}</p>
                <x-button.secondary type="button" class="!w-fit" wire:click="useManaged"
                    wire:confirm="{{ __($m . 'nameservers.managed_confirm') }}">
                    {{ __($m . 'nameservers.use_managed') }}
                </x-button.secondary>
            </div>
        @endunless
    @endif
</div>
