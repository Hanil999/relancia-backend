<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    // Étape 1 : l'utilisateur saisit son email, Laravel envoie le lien (via Mail, config à faire dans .env)
    public function forgotPassword(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => ['required', 'email'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => $validator->errors()->first()], 422);
    }

    // Plus besoin de closure — User::sendPasswordResetNotification() s'en charge
    $status = Password::sendResetLink($request->only('email'));

    return response()->json([
        'message' => 'Si un compte existe pour cet email, un lien de réinitialisation a été envoyé.',
    ]);
}


    // Étape 2 : l'utilisateur arrive depuis le lien de l'email avec le token, et choisit un nouveau mot de passe
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => \Illuminate\Support\Facades\Hash::make($password),
                ])->save();

                // On révoque les anciens tokens API par sécurité après un changement de mot de passe
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }
}
