<?php

namespace App\Services\Crm\Exceptions;

use RuntimeException;

/**
 * Gagal sinkronisasi konektor CRM — membawa http_status (bila ada) supaya
 * delivery log mencatat status HTTP yang sebenarnya, bukan hanya pesan.
 */
class CrmConnectorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
