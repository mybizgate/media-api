<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain error for the media service. Rendered as a JSON envelope by
 * bootstrap/app.php so controllers never build error responses themselves.
 */
class MediaException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Client errors (4xx) are expected traffic and are not reported to the
     * log; server errors fall through to Laravel's default reporting.
     */
    public function report(): bool
    {
        return $this->status < 500;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function badRequest(string $message): self
    {
        return new self($message, 400);
    }

    public static function forbidden(string $message = 'This media is private.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Media not found.'): self
    {
        return new self($message, 404);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function validation(string $message, array $errors = []): self
    {
        return new self($message, 422, $errors);
    }

    public static function server(string $message = 'The media could not be stored.'): self
    {
        return new self($message, 500);
    }
}
