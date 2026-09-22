<?php
namespace APP\plugins\generic\pdfMetadata\classes;

/** Stateless, short-lived review ticket; contents are not confidential. */
class Ticket
{
    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32 || str_contains($secret, 'YouMustSet') || str_starts_with($secret, 'REPLACE_')) {
            throw new Failure('configuration', 503);
        }
    }

    public function issue(array $claims): string
    {
        $payload = base64_encode(json_encode($claims + ['expires' => time() + 900], JSON_THROW_ON_ERROR));
        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    public function verify(string $ticket, array $identity): array
    {
        if (strlen($ticket) > 8192) {
            throw new Failure('expired', 409);
        }
        $parts = explode('.', $ticket);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $this->secret), $parts[1])) {
            throw new Failure('expired', 409);
        }
        $claims = json_decode(base64_decode($parts[0], true) ?: '', true);
        if (!is_array($claims) || ($claims['expires'] ?? 0) < time()) {
            throw new Failure('expired', 409);
        }
        foreach ($identity as $key => $value) {
            if (($claims[$key] ?? null) !== $value) {
                throw new Failure('forbidden', 403);
            }
        }
        return $claims;
    }
}
