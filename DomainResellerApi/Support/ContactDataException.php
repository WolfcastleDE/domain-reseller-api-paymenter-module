<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

use InvalidArgumentException;

/**
 * The customer profile lacks data the registries require (address, phone, ...).
 */
class ContactDataException extends InvalidArgumentException
{
    /**
     * @param  string[]  $problems
     */
    public function __construct(private readonly array $problems)
    {
        parent::__construct('The customer profile is incomplete for a domain contact: ' . implode(' ', $problems));
    }

    /**
     * @return string[]
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
