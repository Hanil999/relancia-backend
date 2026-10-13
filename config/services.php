<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'commission_pct' => env('STRIPE_COMMISSION_PCT', 5),
        'currency' => env('STRIPE_CURRENCY', 'EUR'),
        'taux_conversion' => env('STRIPE_TARIF_CONVERSION', 4900),
    ],
    'goalpay' => [
        'token' => env('GOALPAY_TOKEN'),
        'webhook_secret' => env('GOALPAY_WEBHOOK_SECRET'),
        'api_url' => env('GOALPAY_API_URL', 'https://api.goalpay.pro'),
        // true => /api/sandbox/payement/service (compte de test), false => production
        'sandbox' => env('GOALPAY_SANDBOX', false),
        'success_url' => env('GOALPAY_SUCCESS_URL'),
        'fail_url' => env('GOALPAY_FAIL_URL'),
        'commission_pct' => env('GOALPAY_COMMISSION_PCT', 5),
        'verify_signature' => env('GOALPAY_VERIFY_SIGNATURE', true),
    ],

    // IA conversationnelle, API compatible OpenAI. Sans cle, les reponses
    // automatiques restent gerees par les regles de ReponseAutomatiqueService.
    //
    // Defaut : Google Gemini, palier gratuit (entree et sortie gratuites).
    // Pour un autre fournisseur, il suffit de changer BASE_URL et MODEL
    // (xAI : https://api.x.ai/v1 + grok-4.3).
    'ia' => [
        'api_key' => env('IA_API_KEY'),
        'base_url' => env('IA_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
        'model' => env('IA_MODEL', 'gemini-3.5-flash-lite'),
        'temperature' => (float) env('IA_TEMPERATURE', 0.3),
        // Mesure du 06/10/2026 : gemini-3.5-flash-lite repond en 1-3 s, alors
        // que gemini-3.8-flash met 37 s et rend une reponse VIDE (les jetons
        // de raisonnement consomment le budget max_tokens).
        'max_tokens' => (int) env('IA_MAX_TOKENS', 1024),
        // Delai avant repli sur les regles. La distribution est bimodale
        // (1-3 s, ou au-dela du delai) : au-dela de 5 s, aucun gain mesure.
        'timeout' => (int) env('IA_TIMEOUT', 5),
        // Securite commerciale : verifier chaque montant et chaque stock cite
        // par le modele contre la base avant de l'envoyer au client.
        'verifier_montants' => env('IA_VERIFIER_MONTANTS', true),
    ],

    'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],

'facebook' => [
    'client_id' => env('FACEBOOK_CLIENT_ID'),
    'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
    'redirect' => env('FACEBOOK_REDIRECT_URI'),
],

];
