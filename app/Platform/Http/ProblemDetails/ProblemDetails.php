<?php

declare(strict_types=1);

namespace App\Platform\Http\ProblemDetails;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 9457 Problem Details document with a stable application error code.
 */
final class ProblemDetails
{
    public const CONTENT_TYPE = 'application/problem+json';

    /**
     * @param  array<string, mixed>  $extensions  Additional members (e.g. "errors" for validation).
     * @param  array<string, string|list<string>>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $code,
        public readonly ?string $detail = null,
        public readonly string $type = 'about:blank',
        public readonly array $extensions = [],
        public readonly array $headers = [],
    ) {}

    public static function forStatus(int $status, ?string $detail = null, ?string $code = null): self
    {
        return new self(
            status: $status,
            title: self::titleFor($status),
            code: $code ?? self::codeFor($status),
            detail: $detail,
        );
    }

    public static function titleFor(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Error';
    }

    /**
     * Default stable code for an HTTP status, e.g. 404 => "http.not_found".
     */
    public static function codeFor(int $status): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', self::titleFor($status)), '_'));

        return 'http.'.($slug !== '' ? $slug : (string) $status);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'code' => $this->code,
        ], static fn (mixed $value): bool => $value !== null) + $this->extensions;
    }

    public function toResponse(): JsonResponse
    {
        $response = new JsonResponse($this->toArray(), $this->status, $this->headers);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}
