{{-- Contact fields bound to "$prefix.<field>" of the surrounding Livewire component. --}}
@php($m = 'domain-reseller-api::messages.')
<div class="grid md:grid-cols-2 gap-3">
    <x-form.input name="{{ $prefix }}.firstName" wire:model="{{ $prefix }}.firstName" :label="__($m . 'contacts.first_name')" required />
    <x-form.input name="{{ $prefix }}.lastName" wire:model="{{ $prefix }}.lastName" :label="__($m . 'contacts.last_name')" required />
</div>
<x-form.input name="{{ $prefix }}.organization" wire:model="{{ $prefix }}.organization"
    :label="$organizationRequired ? __($m . 'contacts.organization') : __($m . 'contacts.organization_optional')" :required="$organizationRequired" />
<div class="grid md:grid-cols-3 gap-3">
    <div class="md:col-span-2">
        <x-form.input name="{{ $prefix }}.street" wire:model="{{ $prefix }}.street" :label="__($m . 'contacts.street')" required />
    </div>
    <x-form.input name="{{ $prefix }}.houseNumber" wire:model="{{ $prefix }}.houseNumber" :label="__($m . 'contacts.house_number')" required />
</div>
<div class="grid md:grid-cols-3 gap-3">
    <x-form.input name="{{ $prefix }}.postalCode" wire:model="{{ $prefix }}.postalCode" :label="__($m . 'contacts.postal_code')" required />
    <div class="md:col-span-2">
        <x-form.input name="{{ $prefix }}.city" wire:model="{{ $prefix }}.city" :label="__($m . 'contacts.city')" required />
    </div>
</div>
<div class="grid md:grid-cols-2 gap-3">
    <x-form.input name="{{ $prefix }}.state" wire:model="{{ $prefix }}.state" :label="__($m . 'contacts.state')" />
    <x-form.select name="{{ $prefix }}.country" wire:model="{{ $prefix }}.country" :label="__($m . 'contacts.country')" required>
        <option value=""></option>
        @foreach ($countries as $code => $countryName)
            <option value="{{ $code }}">{{ $countryName }}</option>
        @endforeach
    </x-form.select>
</div>
<div class="grid md:grid-cols-2 gap-3">
    <x-form.input name="{{ $prefix }}.phone" wire:model="{{ $prefix }}.phone" :label="__($m . 'contacts.phone')" placeholder="+49.301234567" required />
    <x-form.input name="{{ $prefix }}.email" type="email" wire:model="{{ $prefix }}.email" :label="__($m . 'contacts.email')" required />
</div>
