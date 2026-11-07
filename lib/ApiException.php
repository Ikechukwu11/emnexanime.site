<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message, $httpStatus);
    }
}
