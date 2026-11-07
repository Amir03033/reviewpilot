<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Models\GbpLocationLink;
use App\Models\GoogleConnection;
use App\Services\Billing\Entitlements;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleLocationService;
use App\Services\Google\GoogleOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class GoogleIntegrationController extends Controller
{
    public function __construct(private GoogleOAuth $oauth, private GoogleLocationService $locations) {}

    public function index(): Response
    {
        $connections = GoogleConnection::query()->with('accounts.locationLinks')->latest()->get();

        return Inertia::render('integrations/Index', [
            'configured' => $this->oauth->isConfigured(),
            'pubsubConfigured' => filled(config('services.google.pubsub_topic')),
            'connections' => $connections->map(fn (GoogleConnection $c) => [
                'id' => $c->id,
                'email' => $c->google_email,
                'status' => $c->status->value,
                'last_error' => $c->last_error,
                'accounts' => $c->accounts->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->display_name ?? $a->account_name,
                    'notifications' => (bool) $a->notifications_registered_at,
                    'locations' => $a->locationLinks->map(fn (GbpLocationLink $l) => [
                        'id' => $l->id,
                        'title' => $l->title,
                        'address' => $l->address,
                        'verified' => $l->is_verified,
                        'imported' => (bool) $l->location_id,
                        'location_id' => $l->location_id,
                        'sync_state' => $l->sync_state,
                    ]),
                ]),
            ]),
            'canManage' => $this->membership()->role->canManageOrganization(),
            'planActive' => app(Entitlements::class)->isActive($this->organization()),
        ]);
    }

    // Inertia::location() answers Inertia requests with a 409 + X-Inertia-Location
    // (a plain Response, not a redirect), so the return type must allow any Response.
    public function redirect(Request $request): SymfonyResponse
    {
        $this->authorizeManage();

        if (! $this->oauth->isConfigured()) {
            return $this->backWith('Google is not configured yet. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env.', 'error');
        }

        $pkce = $this->oauth->pkcePair();
        $state = Str::random(40);
        $request->session()->put('google_oauth', [
            'state' => $state,
            'verifier' => $pkce['verifier'],
            'organization_id' => $this->organization()->id,
        ]);

        return Inertia::location($this->oauth->authorizationUrl($state, $pkce['challenge'], $request->user()->email));
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $session = $request->session()->pull('google_oauth');

        if (! $session || ! hash_equals($session['state'], (string) $request->query('state')) || $session['organization_id'] !== $this->organization()->id) {
            $this->toast('Google sign-in expired or was tampered with. Please try again.', 'error');

            return redirect()->route('integrations.index');
        }

        if ($request->filled('error')) {
            $this->toast('Google connection was cancelled.', 'error');

            return redirect()->route('integrations.index');
        }

        try {
            $tokens = $this->oauth->exchangeCode((string) $request->query('code'), $session['verifier']);

            if (! str_contains($tokens['scope'], 'business.manage')) {
                $this->toast('Please allow access to manage your Business Profile.', 'error');

                return redirect()->route('integrations.index');
            }

            $connection = $this->locations->storeConnection($this->organization(), $request->user(), $tokens);
        } catch (GoogleApiException $e) {
            report($e);
            $this->toast('Google sign-in failed: :error', 'error', ['error' => $e->getMessage()]);

            return redirect()->route('integrations.index');
        }

        // The login itself worked. Listing locations can still fail (most often: Business
        // Profile API access not approved yet, quota 0). Keep the connection and explain.
        try {
            $found = $this->locations->discover($connection);
        } catch (\Throwable $e) {
            report($e);
            $hint = $e instanceof GoogleApiException && in_array($e->status, [403, 429], true)
                ? __('Google has probably not approved Business Profile API access for this project yet (quota 0). Use "Refresh list" once it is approved.')
                : __('Try "Refresh list" in a minute.');
            $this->toast(__('Google account connected, but locations could not be loaded: :error', ['error' => $e->getMessage()]).' '.$hint, 'error');

            return redirect()->route('integrations.index');
        }

        $this->toast($found ? 'Connected. Found :count location(s) – choose which to import.' : 'Connected, but no Business Profile locations were found for this Google account.', replace: ['count' => $found]);

        return redirect()->route('integrations.index');
    }

    public function refresh(string $connectionId): RedirectResponse
    {
        $this->authorizeManage();
        $connection = GoogleConnection::query()->findOrFail($connectionId);

        try {
            $count = $this->locations->discover($connection);
        } catch (\Throwable $e) {
            report($e);

            return $this->backWith('Locations could not be loaded: :error', 'error', ['error' => $e->getMessage()]);
        }

        return $this->backWith('Found :count location(s).', replace: ['count' => $count]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $data = $request->validate(['links' => ['required', 'array', 'min:1'], 'links.*' => ['string']]);

        $imported = 0;
        $skipped = [];
        foreach (GbpLocationLink::query()->whereIn('id', $data['links'])->with('account.connection')->get() as $link) {
            if (! $link->is_verified) {
                $skipped[] = $link->title.' ('.__('not verified on Google').')';

                continue;
            }

            try {
                $this->locations->import($link);
                $imported++;
            } catch (\InvalidArgumentException $e) {
                $skipped[] = $link->title.' ('.__($e->getMessage()).')';
            }
        }

        $message = __('Imported :count location(s). Reviews are being synced.', ['count' => $imported]);
        if ($skipped) {
            $message .= ' '.__('Skipped: :list', ['list' => implode('; ', $skipped)]);
        }

        return $this->backWith($message, $imported ? 'success' : 'error');
    }

    public function disconnect(string $connectionId): RedirectResponse
    {
        $this->authorizeManage();
        $connection = GoogleConnection::query()->findOrFail($connectionId);

        if ($connection->status !== ConnectionStatus::Revoked) {
            $this->locations->disconnect($connection);
        }

        return $this->backWith('Google disconnected. Locations from this account stopped syncing.');
    }
}
