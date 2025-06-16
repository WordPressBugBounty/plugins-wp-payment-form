<?php

namespace WPPayForm\App\Modules\Refund\Exceptions;

class RefundException extends \Exception
{
    protected int $statusCode;

    public function __construct(string $message, int $statusCode = 400)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}