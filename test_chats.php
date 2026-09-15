<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$rows = DB::select("SELECT id, direction, texte, created_at FROM messages_canal WHERE conversation_id = '8039153050' ORDER BY id DESC LIMIT 12");

echo "=== DERNIERS MESSAGES (chat 8039153050) ===\n";
foreach (array_reverse($rows) as $r) {
    $texte = mb_substr($r->texte ?? '', 0, 120);
    echo $r->created_at . " | " . str_pad($r->direction, 7) . " | " . $texte . "\n";
}

$canal = \App\Models\CanalEntreprise::where('type', 'telegram')->where('actif', true)->first();
$service = app(\App\Services\TelegramService::class);
echo "\n=== ENVOI TEST ===\n";
try {
    $result = $service->envoyerMessage($canal, '8039153050', "Test bot Relancia — réponse directe OK.");
    echo json_encode(['ok' => $result['ok'] ?? false]);
} catch (\Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}
echo "\n";