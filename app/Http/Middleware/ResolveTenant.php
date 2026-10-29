<?php

namespace App\Http\Middleware;

use App\Exceptions\MediaException;
use App\Services\Media\MediaPathService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the X-Client-Code header and stores the validated tenant code on
 * the request. Form data is never consulted for the tenant.
 */
class ResolveTenant
{
    public const ATTRIBUTE = 'tenant';

    public function __construct(private readonly MediaPathService $paths) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('media.tenant.header', 'X-Client-Code');
        $tenant = $request->header($header);

        if ($tenant === null || $tenant === '') {
            throw MediaException::badRequest('The '.$header.' header is required.');
        }

        $request->attributes->set(self::ATTRIBUTE, $this->paths->assertTenant($tenant));

        return $next($request);
    }

    /** Validated tenant for the current request, or null when no header was sent. */
    public static function optional(Request $request, MediaPathService $paths): ?string
    {
        $tenant = $request->header((string) config('media.tenant.header', 'X-Client-Code'));

        return ($tenant === null || $tenant === '') ? null : $paths->assertTenant($tenant);
    }
}
