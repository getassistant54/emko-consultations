# Скрипт сборки дистрибутива WordPress плагина с гарантированными Unix-путями (forward slash /)
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = $PSScriptRoot
if (-not $root) { $root = "H:\ОБУЧЕНИЕ\ВАЙБКОДИНГ\emko-consultations" }

$distDir = Join-Path $root "dist"
if (-not (Test-Path $distDir)) { New-Item -ItemType Directory -Path $distDir -Force | Out-Null }

$distZip = Join-Path $distDir "emko-consultations.zip"
$geminiZip = "d:\РАБОТА\ЕМКО-АНИМАЦИЯ\Gemini\emko-consultations.zip"

if (Test-Path $distZip) { Remove-Item -Force $distZip }

$zip = [System.IO.Compression.ZipFile]::Open($distZip, [System.IO.Compression.ZipArchiveMode]::Create)

$files = @(
    "emko-consultations.php",
    "emko-booking.php",
    "assets/booking-widget.css",
    "assets/booking-widget.js",
    "includes/class-admin.php",
    "includes/class-api.php",
    "includes/class-caldav.php",
    "includes/class-getcourse.php"
)

foreach ($rel in $files) {
    $cleanRel = $rel.Replace('\', '/')
    $systemRel = $cleanRel.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
    $fullPath = Join-Path $root $systemRel
    
    if (-not (Test-Path $fullPath)) {
        Write-Error "File not found: $fullPath"
        continue
    }

    $entryName = "emko-consultations/" + $cleanRel
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $fullPath, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    Write-Host " [OK] $entryName"
}

$zip.Dispose()

# Копируем также в корень рабочей папки Gemini
if (Test-Path (Split-Path $geminiZip)) {
    Copy-Item $distZip $geminiZip -Force
    Write-Host " Copied to: $geminiZip"
}

Write-Host "`nДистрибутив собран успешно:"
Get-Item $distZip | Select-Object Name, Length, LastWriteTime
