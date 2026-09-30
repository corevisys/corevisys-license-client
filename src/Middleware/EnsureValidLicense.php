<?php

namespace CoreVisys\License\Middleware;

use Closure;
use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Exceptions\InvalidLicenseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: corevisys.license
 *
 * Blocks the request unless the license is currently valid. Bypass is
 * available for local development only (config: middleware.bypass_in_local)
 * and is always logged — it can never be enabled in production.
 *
 * The middleware is registered as a NAMED ALIAS only (corevisys.license); it
 * is never pushed into the application's global middleware stack, so it only
 * ever runs on routes that explicitly opt in.
 *
 * "Excluded" routes (config: middleware.excluded_routes — route names and/or
 * Str::is path patterns) are let through with NO license check and NO server
 * call, so auth/health/activation traffic keeps working while a license is
 * invalid. The built-in activation route is ALWAYS exempt regardless of that
 * list (see isActivationRequest()), which is what makes a redirect loop
 * impossible even if an operator removes it from the configuration.
 */
class EnsureValidLicense
{
    /**
     * Mirrors the packaged middleware.excluded_routes default, used when the
     * config value is absent/null/empty. Entries are matched with Str::is()
     * against both the route name and the request path, so a bare word such as
     * "login" covers both its name and its path.
     */
    protected const DEFAULT_EXCLUDED_ROUTES = [
        'corevisys.license.activate',
        'corevisys.license.activate.store',
        'license/activate',
        'login',
        'logout',
        'health',
        'up',
    ];

    public function __construct(protected LicenseClientInterface $license)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass()) {
            Log::channel(config('corevisys-license.logging.channel', 'stack'))
                ->warning('CoreVisys license: middleware bypassed in local environment.');

            return $next($request);
        }

        // Excluded routes (and the always-exempt activation route) pass through
        // untouched: no license check is performed and no server call is made.
        if ($this->isExcluded($request)) {
            return $next($request);
        }

        if ($this->license->isValid()) {
            return $next($request);
        }

        return $this->deny($request);
    }

    protected function shouldBypass(): bool
    {
        return app()->environment('local')
            && ! app()->environment('production')
            && config('corevisys-license.middleware.bypass_in_local', false);
    }

    protected function deny(Request $request): Response
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            throw new InvalidLicenseException('This command cannot run without a valid license.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'A valid license is required to access this resource.',
                'error_code' => 'invalid_license',
            ], config('corevisys-license.middleware.abort_status', 403));
        }

        $redirect = config('corevisys-license.middleware.redirect_route')
            ?? $this->defaultActivationRoute();

        if ($redirect && \Illuminate\Support\Facades\Route::has($redirect)) {
            // Redirect-loop guard: the activation screen (and anything else in
            // excluded_routes) never redirects to itself. This is name- AND
            // path-based, so a renamed or name-prefixed activation route still
            // short-circuits here instead of bouncing between two URLs.
            if ($this->isActivationRequest($request) || $request->routeIs($redirect)) {
                abort(config('corevisys-license.middleware.abort_status', 403), 'A valid license is required to access this resource.');
            }

            return redirect()->route($redirect);
        }

        abort(config('corevisys-license.middleware.abort_status', 403), 'A valid license is required to access this resource.');
    }

    /**
     * Whether the request targets a route the operator (or the packaged
     * defaults) has exempted from the license check. Matched with Str::is()
     * against BOTH the route name and the URL path, so a default entry such as
     * "login" covers a route named "login" and a path "login" alike, and a
     * wildcard such as "admin/health/*" covers a path family.
     */
    protected function isExcluded(Request $request): bool
    {
        if ($this->isActivationRequest($request)) {
            return true;
        }

        $path = trim($request->path(), '/');
        $name = $request->route()?->getName();

        foreach ($this->excludedRoutes() as $pattern) {
            if (Str::is($pattern, $path) || ($name !== null && Str::is($pattern, $name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The activation route is exempt from the license check UNCONDITIONALLY —
     * even when an operator removes it from middleware.excluded_routes — so a
     * denied web request can always be redirected to a page that renders, and a
     * redirect loop can never form.
     *
     * Matched by route NAME (including the ".store" POST action and any name
     * prefixed from the configured activation name) and by PATH pattern against
     * the configured activation prefix, so a name-prefixed or path-based
     * variant is still recognised.
     */
    protected function isActivationRequest(Request $request): bool
    {
        $activationName = (string) (config('corevisys-license.ui.route_name') ?: 'corevisys.license.activate');

        $name = $request->route()?->getName();

        if ($name !== null
            && ($name === $activationName || str_starts_with($name, $activationName.'.'))) {
            return true;
        }

        $prefix = trim((string) config('corevisys-license.ui.route_prefix', 'license'), '/');
        $path = trim($request->path(), '/');

        // Match the activation path itself and the POST endpoint under it. This
        // stays path-based so it holds even when route names were customised.
        return $path !== ''
            && $prefix !== ''
            && Str::is($prefix.'/activate', $path);
    }

    /**
     * The configured exclusion list, falling back to the packaged defaults when
     * the value is absent, null or empty. Read only from the resolved config
     * array (which survives config:cache); no runtime environment lookup.
     *
     * @return array<int, string>
     */
    protected function excludedRoutes(): array
    {
        $configured = config('corevisys-license.middleware.excluded_routes');

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_EXCLUDED_ROUTES;
        }

        $entries = array_filter(
            array_map(
                static fn ($entry) => is_string($entry) ? trim($entry) : null,
                $configured
            ),
            static fn ($entry) => $entry !== null && $entry !== ''
        );

        return $entries === [] ? self::DEFAULT_EXCLUDED_ROUTES : array_values($entries);
    }

    /**
     * When no explicit redirect_route is configured, fall back to the
     * package's own built-in activation screen (if enabled) rather than
     * showing a bare 403 to an end user who has no way to fix it.
     */
    protected function defaultActivationRoute(): ?string
    {
        if (! config('corevisys-license.ui.enabled', true)) {
            return null;
        }

        return config('corevisys-license.ui.route_name');
    }
}
