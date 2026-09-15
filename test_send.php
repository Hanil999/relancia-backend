<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$canal = \App\Models\CanalEntreprise::where('type', 'telegram')->where('actif', true)->first();
$last = \App\Models\MessageCanal::where('canal', 'Telegram')->latest()->first();

echo "Canal: {$canal->bot_username}\n";
echo "Dernier message: convers = {$last->conversation_id} | {$last->direction} | {$last->texte}\n";

$service = app(\App\Services\TelegramService::class);
try {
    $result = $service->envoyerMessage($canal, $last->conversation_id, "✅ Test de connexion — le bot Relancia fonctionne.");
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}