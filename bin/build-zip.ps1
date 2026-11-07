# PowerShell script to package Tierza plugin into installable ZIP
$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$DistDir = Join-Path $ProjectRoot "dist"
$ZipFile = Join-Path $DistDir "tierza.zip"

Write-Host "Executing build packager via PHP..." -ForegroundColor Cyan
php (Join-Path $PSScriptRoot "build-zip.php")

if (Test-Path $ZipFile) {
    Write-Host "Verified ZIP created successfully at: $ZipFile" -ForegroundColor Green
} else {
    Write-Error "Failed to generate $ZipFile"
}
