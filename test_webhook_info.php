<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$canal = \App\Models\CanalEntreprise::where('entreprise_id', 1)->where('type', 'telegram')->first();
echo "Canal: {$canal->bot_username}\n";

$ch = curl_init('https://api.telegram.org/bot' . $canal->token . '/getWebhookInfo');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$result = curl_exec($ch);
echo $result . PHP_EOL;

$ch2 = curl_init('https://api.telegram.org/bot' . $canal->token . '/getMe');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
echo curl_exec($ch2) . PHP_EOL;