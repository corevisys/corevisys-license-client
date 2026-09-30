<?php

namespace CoreVisys\License\Http\Controllers;

use CoreVisys\License\Contracts\LicenseClientInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Renders and processes the built-in "activate your license" screen, for
 * end users who will never touch an artisan command. Registered by
 * CoreVisysServiceProvider when config('corevisys-license.ui.enabled')
 * is true; publish the view (corevisys-license::activate) to restyle it,
 * or set ui.enabled to false and build your own screen against the
 * CoreVisysLicense facade instead.
 */
class LicenseActivationController extends Controller
{
    public function __construct(protected LicenseClientInterface $license)
    {
    }

    public function show(Request $request): Response
    {
        // A fresh check() (not force-refreshed) so a freshly-expired/revoked
        // license shows its real reason on the page without hammering the
        // server on every page load.
        $status = $this->license->status() ?? $this->license->check();

        return $this->noStore(response()->view('corevisys-license::activate', [
            'status' => $status,
            'routeName' => config('corevisys-license.ui.route_name'),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'license_key' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->license->activate($validated['license_key']);

        $routeName = config('corevisys-license.ui.route_name');

        // Never echo the submitted (secret) key back to the browser. Only
        // non-secret form fields survive when the form is re-rendered after a
        // failed attempt; the key itself must not live on in old()/flash.
        $safeInput = $request->only(['_token']);

        if (! $result->success) {
            // A generic, user-facing message only. Diagnostic detail (server
            // reason codes, signatures) is logged, never shown to the browser.
            return $this->noStore(
                redirect()
                    ->route($routeName)
                    ->withInput($safeInput)
                    ->withErrors(['license_key' => 'Activation failed. Please check the key and try again.'])
            );
        }

        return $this->noStore(
            redirect()
                ->route($routeName)
                ->with('corevisys_license_activated', true)
        );
    }

    /**
     * Mark an activation-screen response as non-cacheable. The page can
     * reflect license state, so no shared or browser cache may retain it.
     *
     * @template T of \Symfony\Component\HttpFoundation\Response
     * @param  T  $response
     * @return T
     */
    protected function noStore($response)
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
