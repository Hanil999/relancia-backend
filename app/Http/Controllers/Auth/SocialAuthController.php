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

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider)
    {
        $this->ensureProviderIsValid($provider);

        $frontendUrl = rtrim(config('app.frontend_url'), '/') . '/oauth/callback';

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning("Échec OAuth {$provider}: " . $e->getMessage());

            return redirect()->away($frontendUrl . '?error=' . urlencode('Connexion via ' . ucfirst($provider) . ' impossible.'));
        }

        // On retrouve l'utilisateur par provider_id, sinon par email (fusion de compte), sinon on le crée.
        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if (! $user) {
            $user = User::where('email', $socialUser->getEmail())->first();
        }

        if ($user) {
            $user->forceFill([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar' => $socialUser->getAvatar(),
            ])->save();
        } else {
            $user = User::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Utilisateur',
                'email' => $socialUser->getEmail(),
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

    private function ensureProviderIsValid(string $provider): void
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);
    }
}
