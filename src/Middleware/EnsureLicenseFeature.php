<?php

namespace CoreVisys\License\Middleware;

use Closure;
use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Events\LicenseFeatureDenied;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: corevisys.feature:feature-name
 *
 * Usage: Route::middleware('corevisys.feature:reports')->group(...)
 */
class EnsureLicenseFeature
{
    public function __construct(protected LicenseClientInterface $license)
    {
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($this->license->isValid() && $this->license->feature($feature)) {
            return $next($request);
        }

        Event::dispatch(new LicenseFeatureDenied($this->license->status(), ['feature' => $feature]));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "This feature ({$feature}) is not included in the current license.",
                'error_code' => 'feature_not_licensed',
            ], config('corevisys-license.middleware.abort_status', 403));
        }

        abort(config('corevisys-license.middleware.abort_status', 403), "This feature ({$feature}) is not included in the current license.");
    }
}
