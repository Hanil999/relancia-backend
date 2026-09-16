<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const ALLOWED_PROVIDERS = ['google', 'facebook'];

    public function redirect(string $provider)
    {
        $this->ensureProviderIsValid($provider);

        $driver = Socialite::driver($provider)->stateless();

        // Forcer le sélecteur de compte à chaque tentative : sans cela le
        // navigateur réutilise silencieusement le dernier compte connecté.
        if ($provider === 'facebook') {
            $driver->with(['auth_type' => 'rerequest']);
        } elseif ($provider === 'google') {
            $driver->with(['prompt' => 'select_account']);
        }

        return $driver->redirect();
    }

    public function callback(string $provider)
    {
        $this->ensureProviderIsValid($provider);

        $frontendUrl = rtrim(config('app.maintenance.frontend_url', 'http://localhost:8081'), '/') . '/oauth/callback';

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning("Échec OAuth {$provider}: " . $e->getMessage());

            return redirect()->away($frontendUrl . '?error=' . urlencode('Connexion via ' . ucfirst($provider) . ' impossible.'));
        }

        // Un compte Relancia par compte social : on identifie strictement par
        // (provider, provider_id). Plus de fusion par email, sinon deux
        // comptes Facebook/Google différents avec le même email tombent
        // toujours sur le même compte Relancia.
        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Utilisateur',
                'email' => $this->emailSocialUnique($provider, $socialUser),
                'password' => null,
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar' => $socialUser->getAvatar(),
                'email_verified_at' => now(),
            ]);
            $user->assignRole('user');
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return redirect()->away($frontendUrl . '?token=' . urlencode($token));
    }

    private function emailSocialUnique(string $provider, $socialUser): string
    {
        $email = trim((string) $socialUser->getEmail());

        if ($email !== '' && ! User::where('email', $email)->exists()) {
            return $email;
        }

        // Email absent OU déjà utilisé par un autre compte : on génère un
        // email unique et déterministe pour respecter la contrainte UNIQUE.
        $fallback = "{$provider}_{$socialUser->getId()}@relancia.local";

        Log::warning('Email social indisponible, email de repli utilisé', [
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
            'email_fourni' => $email !== '' ? $email : null,
            'email_repli' => $fallback,
        ]);

        return $fallback;
    }

    private function ensureProviderIsValid(string $provider): void
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);
    }
}
