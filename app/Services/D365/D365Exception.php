<?php

namespace App\Services\D365;

class D365Exception extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $details = '',
    ) {
        parent::__construct($message);
    }

    public function details(): string
    {
        return $this->details;
    }
}
