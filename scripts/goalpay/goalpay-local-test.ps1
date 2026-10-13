# Test local GoalPay — demarre Laravel, expose le webhook et (optionnellement)
# relie le webhook via gpay_cli.
#
# Deux modes :
#
#   A) Avec gpay_cli (recommande si le compte est marchand) :
#        .\scripts\goalpay\goalpay-local-test.ps1 -Gid "gid_xxxxxxxx"
#      gpay_cli demarre son propre tunnel ngrok et enregistre l'URL webhook
#      cote GoalPay ; il renvoie egalement la secret_key a mettre dans
#      GOALPAY_WEBHOOK_SECRET du .env.
#      Attention : -Gid doit etre le gid d'un compte MARCHAND, sinon GoalPay
#      repond 401 "Vous n'etes pas encore marchand".
#
#   B) Sans gpay_cli (compte non marchand) :
#        .\scripts\goalpay\goalpay-local-test.ps1
#      Seul Laravel est demarre ; il faut alors configurer l'URL webhook
#      manuellement dans l'espace marchand GoalPay :
#        https://<url-ngrok>/api/webhooks/goalpay
#
# Prerequis :
#   1. GOALPAY_TOKEN renseigne dans le .env (GOALPAY_SANDBOX=true en test)
#   2. ps1 scripts\goalpay\download-gpay-cli.ps1   (mode A uniquement)
#
# Usage : .\scripts\goalpay\goalpay-local-test.ps1 -Gid "gid_xxxxxxxx" -Port 3001

param(
    [string]$Gid,

    [int]$Port = 3001
)

$ErrorActionPreference = "Stop"
$Backend = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$Cli = Join-Path $Backend "gpay_cli.exe"

Write-Host "=== 1. Serveur Laravel sur le port $Port (webhook local) ==="
Start-Process -FilePath "php" -ArgumentList "artisan", "serve", "--host=127.0.0.1", "--port=$Port" `
    -WorkingDirectory $Backend -WindowStyle Hidden
Start-Sleep -Seconds 4

try {
    $ping = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/api/webhooks/goalpay" -Method POST `
        -Body '{"ping":true}' -ContentType "application/json" -UseBasicParsing -TimeoutSec 15
    Write-Host "   webhook local OK : HTTP $($ping.StatusCode) $($ping.Content)"
} catch {
    Write-Host "   webhook local injoignable : $($_.Exception.Message)" -ForegroundColor Yellow
}

if (-not $Gid) {
    Write-Host ""
    Write-Host "=== 2. Mode B : webhook a configurer manuellement ===" -ForegroundColor Cyan
    Write-Host "   Dans l'espace marchand GoalPay, entrez l'URL :"
    Write-Host "   http://127.0.0.1:$Port/api/webhooks/goalpay  (ou l'URL ngrok publique)"
    Write-Host "   Puis payez un lien de checkout pour recevoir un vrai webhook."
    return
}

if (-not (Test-Path $Cli)) {
    Write-Host "gpay_cli.exe introuvable. Lancez d'abord :" -ForegroundColor Yellow
    Write-Host "  ps1 scripts\goalpay\download-gpay-cli.ps1"
    exit 1
}

Write-Host ""
Write-Host "=== 2. Mode A : gpay_cli (lance ngrok + enregistre le webhook) ===" -ForegroundColor Cyan
Write-Host "   Le tunnel reste actif jusqu'a ce que vous fermiez ce terminal."
Write-Host "   Copiez la secret_key renvoyee vers GOALPAY_WEBHOOK_SECRET du .env."
Write-Host ""
& $Cli --gid=$Gid --port=$Port --uri=/api/webhooks/goalpay
