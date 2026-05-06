<#
.SYNOPSIS
    Build a single release ZIP for WP Waiver Engine.

.DESCRIPTION
    Outputs one ZIP file using plugin root folder `wp-waiver-engine/`.
    Freemius packaging strips premium-only code from the free build.

.EXAMPLE
    .\bin\build-release.ps1
#>

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$PluginRoot = (Resolve-Path (Join-Path $ScriptDir '..')).Path

# ---------------------------------------------------------------------------
# Read version from plugin header
# ---------------------------------------------------------------------------
$MainFile = Join-Path $PluginRoot 'wp-waiver-engine.php'
if (-not (Test-Path $MainFile)) {
    Write-Error "Cannot find wp-waiver-engine.php at $PluginRoot"
    exit 1
}

$VersionLine = Select-String -Path $MainFile -Pattern '^\s*\*\s*Version:\s*(.+)$' | Select-Object -First 1
if (-not $VersionLine) {
    Write-Error "Could not parse Version from wp-waiver-engine.php"
    exit 1
}

$Version = $VersionLine.Matches[0].Groups[1].Value.Trim()
Write-Host "Building WP Waiver Engine v$Version ..."

# ---------------------------------------------------------------------------
# Ensure Composer is available
# ---------------------------------------------------------------------------
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    Write-Error @"
Composer is not installed or not on PATH.
Install it from https://getcomposer.org/download/ and re-run this script.
"@
    exit 1
}

# ---------------------------------------------------------------------------
# Install / refresh production dependencies
# ---------------------------------------------------------------------------
Write-Host "`n-- Running composer install --no-dev --optimize-autoloader ..."
Push-Location $PluginRoot
composer install --no-dev --optimize-autoloader --quiet
if ($LASTEXITCODE -ne 0) {
    Write-Warning "composer install failed. Continuing with existing vendor/ directory."
}
Pop-Location
Write-Host "   vendor/ is ready."

# ---------------------------------------------------------------------------
# Validate Freemius SDK presence in vendor/
# ---------------------------------------------------------------------------
$FreemiusStart = Join-Path $PluginRoot 'vendor\freemius\wordpress-sdk\start.php'
if (-not (Test-Path $FreemiusStart)) {
        Write-Error @"
Freemius SDK is missing: $FreemiusStart

Run:
    composer require freemius/wordpress-sdk
Then re-run this release script.
"@
        exit 1
}

Write-Host "   Freemius SDK found in vendor/."

# ---------------------------------------------------------------------------
# Exclusions
# ---------------------------------------------------------------------------
$ExcludePatterns = @(
    '.git*',
    '.tmp-phpini*',
    '.release-stage*',
    'bin*',
    'composer.*',
    'instructions*',
    '*.zip'
)

function Get-ReleaseFiles {
    $files = Get-ChildItem -Path $PluginRoot -Recurse -File -Force | Where-Object {
        $rel = $_.FullName.Substring($PluginRoot.Length + 1)

        $excluded = $false
        foreach ($pat in $ExcludePatterns) {
            if ($rel -like "$pat*" -or $rel -like "*\$pat*") {
                $excluded = $true
                break
            }
        }

        if ($excluded) { return $false }

        return $true
    }

    return $files
}

function New-ReleaseZip {
    param(
        [Parameter(Mandatory = $true)]
        [string]$ZipName
    )

    $zipPath = Join-Path $PluginRoot $ZipName
    if (Test-Path $zipPath) {
        Remove-Item $zipPath -Force
    }

    $files = Get-ReleaseFiles

    Add-Type -Assembly 'System.IO.Compression'
    Add-Type -Assembly 'System.IO.Compression.FileSystem'

    $zipStream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::Create)
    $archive   = New-Object System.IO.Compression.ZipArchive(
        $zipStream,
        [System.IO.Compression.ZipArchiveMode]::Create
    )

    $fileCount = 0
    foreach ($file in $files) {
        $rel       = $file.FullName.Substring($PluginRoot.Length + 1) -replace '\\', '/'
        $entryName = "wp-waiver-engine/$rel"

        $entry       = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        $fileStream  = [System.IO.File]::OpenRead($file.FullName)
        $fileStream.CopyTo($entryStream)
        $fileStream.Dispose()
        $entryStream.Dispose()
        $fileCount++
    }

    $archive.Dispose()
    $zipStream.Dispose()

    Write-Host "   Packed $fileCount files -> $ZipName"
}

function Test-ZipContainsEntry {
    param(
        [Parameter(Mandatory = $true)]
        [string]$ZipPath,

        [Parameter(Mandatory = $true)]
        [string]$EntryPath
    )

    Add-Type -Assembly 'System.IO.Compression'
    Add-Type -Assembly 'System.IO.Compression.FileSystem'

    $zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        $entry = $zip.GetEntry($EntryPath)
        return $null -ne $entry
    }
    finally {
        $zip.Dispose()
    }
}

# ---------------------------------------------------------------------------
# Build a single release ZIP
# ---------------------------------------------------------------------------
Write-Host "`n-- Building release ZIP ..."

$ZipName = "wp-waiver-engine-$Version.zip"
New-ReleaseZip -ZipName $ZipName

$FreemiusZipEntry = 'wp-waiver-engine/vendor/freemius/wordpress-sdk/start.php'
$ZipPath = Join-Path $PluginRoot $ZipName

if (-not (Test-ZipContainsEntry -ZipPath $ZipPath -EntryPath $FreemiusZipEntry)) {
    Write-Error "Release ZIP is missing Freemius SDK entry: $FreemiusZipEntry"
    exit 1
}

Write-Host "`n[OK] Release ZIP created:"
Write-Host "  - $ZipName"
Write-Host "  - Freemius SDK verified"
Write-Host "`nPlugin folder slug: wp-waiver-engine"

