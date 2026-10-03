<?php

namespace App\Events\Invoice;

class Paid
{
    public function __construct(public object $invoice) {}
}
