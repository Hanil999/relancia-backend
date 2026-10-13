# Télécharge gpay_cli.exe (binaire Windows de test GoalPay).
# Source : https://goalpay.pro/docs/api/test-local
# Lien direct Google Drive (fichier du fournisseur).

param(
    [string]$FilePath = "$PSScriptRoot\..\..\gpay_cli.exe"
)

$DriveFileId = "1EOEvWN8mt7EiUNhywQyPhpvda3ZRFSzF"
$Url = "https://drive.google.com/uc?export=download&id=$DriveFileId"

if (Test-Path $FilePath) {
    Write-Host "gpay_cli.exe déjà présent : $FilePath"
    exit 0
}

Write-Host "Téléchargement de gpay_cli.exe ..."
try {
    Invoke-WebRequest -Uri $Url -OutFile $FilePath
    Write-Host "OK : $FilePath"
} catch {
    # Google Drive renvoie souvent une page de confirmation (virus scan).
    Write-Host "Téléchargement direct refusé par Google Drive, tentative via uc ..."
    $Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $First = Invoke-WebRequest -Uri $Url -WebSession $Session
    if ($First.Content -match "confirm=([0-9A-Za-z_]+)") {
        $Confirm = $Matches[1]
        Invoke-WebRequest -Uri "$Url&confirm=$Confirm" -WebSession $Session -OutFile $FilePath
        Write-Host "OK : $FilePath"
    } else {
        throw "Échec du téléchargement. Ouvrez $Url dans un navigateur et placez gpay_cli.exe à $FilePath"
    }
}

Write-Host ""
Write-Host "Vérifiez l'empreinte SHA256 (attendue d'après la doc GoalPay) :"
(Get-FileHash -Algorithm SHA256 -Path $FilePath).Hash
Write-Host "Doc : https://goalpay.pro/docs/api/test-local"