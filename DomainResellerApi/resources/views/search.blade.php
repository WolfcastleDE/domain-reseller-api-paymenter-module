@php($m = 'domain-reseller-api::messages.')
<div class="container mt-14">
    <div class="bg-background-secondary border border-neutral p-6 rounded-lg">
        <h1 class="text-2xl font-semibold">{{ __($m . 'search.title') }}</h1>
        <p class="text-sm text-base/50 mt-1">{{ __($m . 'search.description') }}</p>

        <form wire:submit="search" class="flex flex-row gap-2 mt-4 items-end">
            <x-form.input name="query" wire:model="query" :placeholder="__($m . 'search.placeholder')" divClass="flex-1" />
            <x-button.primary type="submit" class="!w-fit">{{ __($m . 'search.button') }}</x-button.primary>
        </form>

        @if ($error)
            <p class="text-red-500 text-sm mt-3">{{ $error }}</p>
        @endif
    </div>

    @if (count($results) > 0)
        <div class="bg-background-secondary border border-neutral rounded-lg mt-4 overflow-x-auto">
            <table class="w-full text-sm text-left">
                <tbody>
                    @foreach ($results as $row)
                        <tr class="border-b border-neutral/50" wire:key="result-{{ $row['domain'] }}">
                            <td class="p-4 font-semibold break-all">{{ $row['domain'] }}</td>
                            <td class="p-4">
                                @if ($row['available'])
                                    <span class="font-semibold text-green-500">{{ __($m . 'search.available') }}</span>
                                @elseif ($row['premium'])
                                    <span class="font-semibold text-orange-500">{{ __($m . 'search.premium') }}</span>
                                @else
                                    <span class="font-semibold text-red-500">{{ __($m . 'search.taken') }}</span>
                                @endif
                            </td>
                            <td class="p-4">{{ $row['price'] }}</td>
                            <td class="p-4">
                                <div class="flex justify-end">
                                    @if ($row['order_url'])
                                        <a href="{{ $row['order_url'] }}" wire:navigate>
                                            <x-button.primary class="!w-fit">{{ __($m . 'search.order') }}</x-button.primary>
                                        </a>
                                    @elseif ($row['transfer_url'])
                                        <a href="{{ $row['transfer_url'] }}" wire:navigate>
                                            <x-button.secondary class="!w-fit">{{ __($m . 'search.transfer') }}</x-button.secondary>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
