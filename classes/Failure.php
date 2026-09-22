<?php
namespace APP\plugins\generic\pdfMetadata\classes;

/** Only stable codes and validation errors may reach the browser, never paths/stderr. */
class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422, public readonly array $details = [])
    {
        parent::__construct($reason);
    }
}
