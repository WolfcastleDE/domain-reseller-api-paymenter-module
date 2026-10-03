<?php

namespace App\Classes\Extension;

/**
 * Minimal stand-in for Paymenter's extension base class.
 */
class Extension
{
    public function __construct(public $config = []) {}

    public function config($key)
    {
        return $this->config[$key] ?? null;
    }
}
