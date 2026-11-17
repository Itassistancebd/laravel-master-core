param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('php', 'composer')]
    [string] $Command,

    [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
    [string[]] $CommandArguments = @()
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtimeConfig = Join-Path $projectRoot '.php-runtime.local.json'

if (-not (Test-Path -LiteralPath $runtimeConfig -PathType Leaf)) {
    throw "Project PHP runtime is not configured. Create .php-runtime.local.json with the path to your PHP 8.4+ executable (see docs/development/local-development.md)."
}

$config = Get-Content -LiteralPath $runtimeConfig -Raw | ConvertFrom-Json
$phpPath = [string] $config.php
$runtimeArguments = @()
foreach ($extension in @($config.extensions)) {
    if ([string]::IsNullOrWhiteSpace([string] $extension) -or [string] $extension -notmatch '^[A-Za-z0-9_-]+$') {
        throw "Invalid PHP extension name in .php-runtime.local.json."
    }

    $runtimeArguments += @('-d', "extension=$extension")
}
if ([string]::IsNullOrWhiteSpace($phpPath) -or -not (Test-Path -LiteralPath $phpPath -PathType Leaf)) {
    throw "Configured PHP executable was not found: '$phpPath'. Update .php-runtime.local.json."
}

$versionOutput = (& $phpPath @runtimeArguments -r 'echo PHP_VERSION;') -join ''
if ($LASTEXITCODE -ne 0 -or $versionOutput -notmatch '^8\.(4|[5-9]|[1-9][0-9])\.') {
    throw "Laravel Master Core requires PHP 8.4+. Configured executable '$phpPath' reports '$versionOutput'. Set php in .php-runtime.local.json to the XAMPP2 PHP 8.4+ executable."
}

if (@($config.extensions) -contains 'zip') {
    & $phpPath @runtimeArguments -r 'exit(extension_loaded(chr(122).chr(105).chr(112)) ? 0 : 1);'
    if ($LASTEXITCODE -ne 0) {
        throw "The configured PHP zip extension could not be loaded. Check the PHP 8.4 extension files beside '$phpPath'."
    }
}

$env:PHP_BINARY = $phpPath
$env:PATH = "$(Split-Path -Parent $phpPath);$env:PATH"
Write-Host "Project PHP runtime: $phpPath (PHP $versionOutput)"
$ErrorActionPreference = 'Continue'

if ($Command -eq 'php') {
    & $phpPath @runtimeArguments @CommandArguments
} else {
    $composer = (Get-Command composer.bat -ErrorAction Stop).Source
    $composerPhar = Join-Path (Split-Path -Parent $composer) 'composer.phar'
    if (-not (Test-Path -LiteralPath $composerPhar -PathType Leaf)) {
        throw "Composer PHAR was not found beside '$composer'."
    }

    & $phpPath @runtimeArguments $composerPhar @CommandArguments
}

if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
