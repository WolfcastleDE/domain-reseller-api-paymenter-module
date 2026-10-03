<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\DomainResellerApi\Http\WebhookController;

Route::post('/extensions/domain-reseller-api/webhook', WebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('extensions.domain-reseller-api.webhook');

Route::get('/domain-search', \Paymenter\Extensions\Servers\DomainResellerApi\Livewire\DomainSearch::class)
    ->middleware('web')
    ->name('extensions.domain-reseller-api.search');
