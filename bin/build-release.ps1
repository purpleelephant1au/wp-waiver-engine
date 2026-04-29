<#
.SYNOPSIS
    Build a production-ready ZIP for WP Waiver Engine.

.DESCRIPTION
    1. Ensures Composer is available.
    2. Runs `composer install --no-dev` so vendor/ is present and clean.
    3. Reads the version from wp-waiver-engine.php automatically.
    4. Creates a ZIP at the repo root named wp-waiver-engine-<version>.zip
       containing only the files needed to run the plugin (no dev/build artifacts).

.EXAMPLE
    .\bin\build-release.ps1
#>

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$PluginRoot = (Resolve-Path (Join-Path $ScriptDir '..'))
$PluginRoot = $PluginRoot.Path

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
    Pop-Location
    Write-Error "composer install failed. Aborting."
    exit 1
}
Pop-Location
Write-Host "   vendor/ is ready."

# ---------------------------------------------------------------------------
# Files/folders to EXCLUDE from the ZIP
# ---------------------------------------------------------------------------
$ExcludeRelative = @(
    '.git',
    '.gitignore',
    'bin',
    'composer.json',
    'composer.lock',
    'vendor/autoload.php'    # included via vendor/ folder below
    '*.zip'
)
# Patterns applied with -like against the relative path
$ExcludePatterns = @(
    '.git*',
    'bin*',
    'composer.*',
    '*.zip'
)

# ---------------------------------------------------------------------------
# Build ZIP
# ---------------------------------------------------------------------------
$ZipName = "wp-waiver-engine-$Version.zip"
$ZipPath = Join-Path $PluginRoot $ZipName

if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}

Write-Host "`n-- Collecting files ..."

# Gather all files recursively, excluding unwanted paths
$AllFiles = Get-ChildItem -Path $PluginRoot -Recurse -File -Force | Where-Object {
    $rel = $_.FullName.Substring($PluginRoot.Length + 1)  # e.g. "includes/class-core.php"

    # Exclude anything starting with these folder/file prefixes
    $excluded = $false
    foreach ($pat in $ExcludePatterns) {
        if ($rel -like "$pat*" -or $rel -like "*\$pat*") {
            $excluded = $true
            break
        }
    }
    -not $excluded
}

# Create a temp staging folder so the ZIP contains a single top-level folder
$TempDir  = Join-Path ([System.IO.Path]::GetTempPath()) "wpwe-build-$Version"
$StageDir = Join-Path $TempDir 'wp-waiver-engine'

if (Test-Path $TempDir) { Remove-Item $TempDir -Recurse -Force }
New-Item -ItemType Directory -Path $StageDir | Out-Null

foreach ($File in $AllFiles) {
    $rel    = $File.FullName.Substring($PluginRoot.Length + 1)
    $dest   = Join-Path $StageDir $rel
    $destDir = Split-Path $dest -Parent
    if (-not (Test-Path $destDir)) {
        New-Item -ItemType Directory -Path $destDir | Out-Null
    }
    Copy-Item $File.FullName -Destination $dest
}

Write-Host "   Staged $($AllFiles.Count) files."

# Compress
Compress-Archive -Path (Join-Path $TempDir 'wp-waiver-engine') -DestinationPath $ZipPath
Remove-Item $TempDir -Recurse -Force

Write-Host "`n[OK] Release ZIP created: $ZipName"
Write-Host "  Path: $ZipPath"
Write-Host "`nUpload this ZIP via: WordPress Admin -> Plugins -> Add New -> Upload Plugin"
