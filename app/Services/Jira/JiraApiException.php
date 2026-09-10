<?php

declare(strict_types=1);

namespace App\Services\Jira;

use RuntimeException;
use Throwable;

class JiraApiException extends RuntimeException
{
    /**
     * @param  int  $status  The HTTP status code returned by Jira (0 for network-level failures).
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
