param(
    [string] $InstallRoot,
    [string] $SourceRoot,
    [string] $SourceArchive,
    [switch] $SkipPath,
    [string] $SourceRef = 'main',
    [string] $SourceCommit
)

$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT') { throw 'The Master Core installer requires Windows.' }
if ($PSVersionTable.PSVersion -lt [version]'5.1') { throw 'The Master Core installer requires Windows PowerShell 5.1 or PowerShell 7 on Windows.' }
$RepositoryOwner = 'Itassistancebd'
$RepositoryName = 'laravel-master-core'
$RepositoryBranch = 'main'
if ([string]::IsNullOrWhiteSpace($InstallRoot)) {
    $InstallRoot = Join-Path $env:LOCALAPPDATA 'Programs\MasterCore'
}
$InstallRoot = [IO.Path]::GetFullPath($InstallRoot)

function Get-SourceRoot {
    if (-not [string]::IsNullOrWhiteSpace($SourceRoot)) {
        $localRoot = [IO.Path]::GetFullPath($SourceRoot)
        Assert-DistributionTree $localRoot
        return @{ Root = $localRoot; Ref = $SourceRef; Commit = $(if ($SourceCommit) { $SourceCommit } else { 'local-source' }) }
    }

    $tempRoot = Join-Path $env:TEMP ('MasterCore-bootstrap-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tempRoot | Out-Null
    $ref = $RepositoryBranch
    $commit = ''
    if (-not [string]::IsNullOrWhiteSpace($SourceArchive)) {
        $archive = [IO.Path]::GetFullPath($SourceArchive)
        if (-not (Test-Path -LiteralPath $archive -PathType Leaf)) { throw 'The requested source archive does not exist.' }
        $commit = if ($SourceCommit) { $SourceCommit } else { 'local-archive' }
        $ref = $SourceRef
    } else {
        $headers = @{ 'User-Agent' = 'Laravel-Master-Core-Installer'; 'Accept' = 'application/vnd.github+json' }
        $release = $null
        try { $release = Invoke-RestMethod -Uri "https://api.github.com/repos/$RepositoryOwner/$RepositoryName/releases/latest" -Headers $headers -TimeoutSec 15 } catch { $release = $null }
        if ($release -and $release.tag_name) { $ref = [string] $release.tag_name }
        $commitRecord = Invoke-RestMethod -Uri "https://api.github.com/repos/$RepositoryOwner/$RepositoryName/commits/$ref" -Headers $headers -TimeoutSec 30
        $commit = [string] $commitRecord.sha
        if ($commit -notmatch '^[0-9a-f]{40}$') { throw 'GitHub returned an invalid source commit identifier.' }
        $archive = Join-Path $tempRoot 'source.zip'
        Invoke-WebRequest -Uri "https://api.github.com/repos/$RepositoryOwner/$RepositoryName/zipball/$commit" -Headers $headers -OutFile $archive -TimeoutSec 120
    }
    $extract = Join-Path $tempRoot 'source'
    New-Item -ItemType Directory -Path $extract | Out-Null
    if (-not (Test-Path -LiteralPath $archive -PathType Leaf) -or (Get-Item -LiteralPath $archive).Length -lt 4) {
        throw 'GitHub returned an empty source archive.'
    }
    if ((Get-Item -LiteralPath $archive).Length -gt 200MB) { throw 'GitHub source archive exceeds the 200 MB safety limit.' }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::OpenRead($archive)
    $expandedBytes = [long] 0
    $entryCount = 0
    try {
        foreach ($entry in $zip.Entries) {
            if ([string]::IsNullOrWhiteSpace($entry.FullName)) { continue }
            $entryCount++
            $expandedBytes += $entry.Length
            if ($entryCount -gt 150000 -or $expandedBytes -gt 1GB) { throw 'GitHub source archive exceeds the extraction entry or expanded-size safety limit.' }
            $unixType = ($entry.ExternalAttributes -shr 16) -band 0xF000
            if ($unixType -eq 0xA000) { throw 'GitHub source archive contains a symbolic link; extraction was refused.' }
            $relative = $entry.FullName.Replace('/', [IO.Path]::DirectorySeparatorChar)
            $destination = [IO.Path]::GetFullPath((Join-Path $extract $relative))
            $prefix = [IO.Path]::GetFullPath($extract) + [IO.Path]::DirectorySeparatorChar
            if (-not $destination.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
                throw 'GitHub source archive contains a path outside its extraction directory.'
            }
            if ($entry.Name -eq '' -or $entry.FullName.EndsWith('/')) {
                New-Item -ItemType Directory -Force -Path $destination | Out-Null
            } else {
                New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination) | Out-Null
                [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $destination, $false)
            }
        }
    } finally {
        $zip.Dispose()
    }
    $source = Get-ChildItem -LiteralPath $extract -Directory | Select-Object -First 1
    if ($null -eq $source) { throw 'GitHub source archive did not contain a repository root.' }
    Assert-DistributionTree $source.FullName
    return @{ Root = $source.FullName; Ref = $ref; Commit = $commit; Temp = $tempRoot }
}

function Assert-DistributionTree([string] $Root) {
    $required = @(
        'VERSION', 'install.ps1', 'scripts/master-cli.ps1', 'composer.json', 'package.json',
        'packages/tools/project-generator/bin/master-core',
        'packages/tools/project-generator/resources/profiles/master-core-v1.json',
        'packages/tools/project-generator/src/Console/Application.php',
        'packages/core/master-core/composer.json', 'packages/core/foundation/composer.json',
        'packages/core/ui/package.json'
    )
    foreach ($path in $required) {
        if (-not (Test-Path -LiteralPath (Join-Path $Root $path) -PathType Leaf)) {
            throw "Source checkout is incomplete: required file '$path' is missing. The public branch must contain the installer and CLI before bootstrap installation is available."
        }
    }
}

function Assert-NoReparsePath([string] $Path) {
    $cursor = [IO.Path]::GetFullPath($Path)
    while (-not [string]::IsNullOrWhiteSpace($cursor)) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw "Refusing to install through a symbolic link or reparse point: $cursor"
            }
        }
        $parent = Split-Path -Parent $cursor
        if (-not $parent -or $parent -eq $cursor) { break }
        $cursor = $parent
    }
}

function Get-PayloadFiles([string] $Root) {
    $files = [Collections.Generic.List[string]]::new()
    foreach ($path in @('VERSION', 'install.ps1', 'scripts/master-cli.ps1', 'composer.json', 'package.json')) {
        $files.Add((Join-Path $Root $path))
    }
    foreach ($file in Get-ChildItem -LiteralPath (Join-Path $Root 'packages/tools/project-generator') -File -Recurse) {
        $files.Add($file.FullName)
    }
    foreach ($directory in @('foundation','master-core','settings','auth','user-management','roles-permissions','audit-logs','module-manager','system-health','notifications','crud-engine')) {
        $packageRoot = Join-Path $Root "packages/core/$directory"
        foreach ($manifest in @('composer.json','package.json')) {
            $file = Join-Path $packageRoot $manifest
            if (Test-Path -LiteralPath $file -PathType Leaf) { $files.Add($file) }
        }
    }
    return $files
}

function Get-PayloadHash([string] $Root) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $builder = [Text.StringBuilder]::new()
        foreach ($file in (Get-PayloadFiles $Root | Sort-Object)) {
            $relative = $file.Substring($Root.Length).TrimStart('\','/')
            [void] $builder.Append($relative).Append(':')
            [void] $builder.Append([Convert]::ToBase64String([IO.File]::ReadAllBytes($file))).Append("`n")
        }
        return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($builder.ToString()))).Replace('-','')).ToLowerInvariant()
    } finally { $sha.Dispose() }
}

function Copy-DistributionPayload([string] $Source, [string] $Destination) {
    New-Item -ItemType Directory -Path $Destination | Out-Null
    foreach ($file in (Get-PayloadFiles $Source)) {
        $relative = $file.Substring($Source.Length).TrimStart('\','/')
        $destinationFile = Join-Path $Destination $relative
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destinationFile) | Out-Null
        Copy-Item -LiteralPath $file -Destination $destinationFile
    }
}

function Ensure-Launcher([string] $Path, [string] $ExpectedMarker, [string] $Content) {
    if (Test-Path -LiteralPath $Path -PathType Leaf) {
        if (([IO.File]::GetAttributes($Path) -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Refusing to replace a launcher symbolic link or reparse point: $Path"
        }
        $existingContent = [IO.File]::ReadAllText($Path)
        $existing = ($existingContent -split "`r?`n",2)[0]
        if ($existing -ne $ExpectedMarker -or ($existingContent -replace "`r`n","`n") -cne ($Content -replace "`r`n","`n")) {
            throw "Refusing to overwrite an existing non-Master-Core file: $Path"
        }
        return
    }
    [IO.File]::WriteAllText($Path, $Content, [Text.UTF8Encoding]::new($false))
}

function Add-UserPath([string] $Directory) {
    $key = [Microsoft.Win32.Registry]::CurrentUser.OpenSubKey('Environment', $true)
    if ($null -eq $key) { throw 'Could not open the current-user environment registry key.' }
    try {
        $current = [string] $key.GetValue('Path', '', [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
        $parts = @($current -split ';' | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
        if (-not ($parts | Where-Object { [string]::Equals($_.TrimEnd('\'), $Directory.TrimEnd('\'), [StringComparison]::OrdinalIgnoreCase) })) {
            $key.SetValue('Path', (($parts + $Directory) -join ';'), [Microsoft.Win32.RegistryValueKind]::ExpandString)
        }
    } finally { $key.Dispose() }
}

function Show-ToolAvailability([string] $Name, [string[]] $VersionArguments, [string] $Minimum) {
    $resolvedName = if ($Name -eq 'php' -and -not [string]::IsNullOrWhiteSpace($env:MASTER_CORE_PHP)) { $env:MASTER_CORE_PHP } else { $Name }
    $command = Get-Command $resolvedName -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $command) { Write-Warning "$Name $Minimum was not found on PATH. Install it before using project generation."; return }
    $priorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = (& $command.Source @VersionArguments 2>&1 | Out-String).Trim() }
    finally { $ErrorActionPreference = $priorAction }
    $version = [regex]::Match($output, '(?:PHP|Composer version|git version)\s+v?(\d+\.\d+(?:\.\d+)?)', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if (-not $version.Success) { Write-Warning "$Name is present at $($command.Source), but its version could not be read."; return }
    $parsed = [version] $version.Groups[1].Value
    $minimumVersion = [version] $Minimum
    if ($parsed -lt $minimumVersion) { Write-Warning "$Name $parsed is below required $Minimum at $($command.Source)."; return }
    Write-Host "$Name $parsed detected at $($command.Source)"
}

try {
    $sourceInfo = Get-SourceRoot
    $source = $sourceInfo.Root
    $versionText = (Get-Content -LiteralPath (Join-Path $source 'VERSION') -Raw).Trim()
    if ($versionText -notmatch '^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$') { throw 'VERSION must contain a semantic version.' }
    $fingerprint = Get-PayloadHash $source
    $commitPrefix = $sourceInfo.Commit -replace '[^0-9a-f]', ''
    if ($commitPrefix.Length -lt 12) { $commitPrefix = 'local' }
    else { $commitPrefix = $commitPrefix.Substring(0,12) }
    $versionId = $commitPrefix + '-' + $fingerprint.Substring(0, 8)
    $versionsRoot = Join-Path $InstallRoot 'versions'
    $versionRoot = Join-Path $versionsRoot $versionId
    Assert-NoReparsePath $InstallRoot
    New-Item -ItemType Directory -Force -Path $versionsRoot | Out-Null
    Assert-NoReparsePath $versionsRoot
    if (-not (Test-Path -LiteralPath $versionRoot -PathType Container)) {
        New-Item -ItemType Directory -Path $versionRoot | Out-Null
        Assert-NoReparsePath $versionRoot
        Copy-DistributionPayload $source (Join-Path $versionRoot 'payload')
        $metadata = [ordered]@{ version=$versionText; version_id=$versionId; source='https://github.com/Itassistancebd/laravel-master-core'; ref=$sourceInfo.Ref; commit=$sourceInfo.Commit; installed_utc=[DateTime]::UtcNow.ToString('o') }
        [IO.File]::WriteAllText((Join-Path $versionRoot 'install.json'), ($metadata | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
    } else {
        Assert-NoReparsePath $versionRoot
        $existingMetaPath = Join-Path $versionRoot 'install.json'
        $existingPayload = Join-Path $versionRoot 'payload'
        $metadataPresent = Test-Path -LiteralPath $existingMetaPath -PathType Leaf
        $cliPresent = Test-Path -LiteralPath (Join-Path $existingPayload 'scripts/master-cli.ps1') -PathType Leaf
        $generatorPresent = Test-Path -LiteralPath (Join-Path $existingPayload 'packages/tools/project-generator/bin/master-core') -PathType Leaf
        if (-not ($metadataPresent -and $cliPresent -and $generatorPresent)) {
            throw "Version directory exists without a valid installer payload; preserving it at $versionRoot"
        }
        try {
            $existingMetadata = Get-Content -LiteralPath $existingMetaPath -Raw | ConvertFrom-Json
        } catch {
            throw "Existing CLI version metadata is invalid; preserving it at $versionRoot"
        }
        if ([string]$existingMetadata.version_id -ne $versionId -or [string]$existingMetadata.version -ne $versionText) {
            throw "Existing CLI version metadata does not match the requested source; preserving it at $versionRoot"
        }
        $existingFingerprint = Get-PayloadHash $existingPayload
        if ($existingFingerprint -ne $fingerprint) {
            throw "Existing CLI version payload failed integrity verification; preserving it at $versionRoot"
        }
    }

    $dispatcher = @'
# Master Core managed launcher
$ErrorActionPreference = 'Stop'
$base = $PSScriptRoot
$pointer = Join-Path $base 'current.json'
if (-not (Test-Path -LiteralPath $pointer -PathType Leaf)) { throw "Master Core CLI is not installed correctly. Run install.ps1 again." }
$current = Get-Content -LiteralPath $pointer -Raw | ConvertFrom-Json
if ([string]$current.version_id -notmatch '^[a-z0-9-]{1,64}$') { throw "Master Core current-version metadata is invalid. Run install.ps1 again." }
$entry = Join-Path $base ("versions\" + $current.version_id + "\payload\scripts\master-cli.ps1")
if (-not (Test-Path -LiteralPath $entry -PathType Leaf)) { throw "Master Core CLI version '$($current.version_id)' is incomplete. Run install.ps1 again." }
& $entry @args
exit $LASTEXITCODE
'@
    $cmdMarker = '@REM Master Core managed command shim'
    $cmd = @'
@REM Master Core managed command shim
@echo off
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0master.ps1" %*
exit /b %ERRORLEVEL%
'@
    New-Item -ItemType Directory -Force -Path $InstallRoot | Out-Null
    Ensure-Launcher (Join-Path $InstallRoot 'master.ps1') '# Master Core managed launcher' ($dispatcher + "`r`n")
    Ensure-Launcher (Join-Path $InstallRoot 'master.cmd') $cmdMarker ($cmd + "`r`n")

    $pointerPath = Join-Path $InstallRoot 'current.json'
    if ((Test-Path -LiteralPath $pointerPath -PathType Leaf) -and (([IO.File]::GetAttributes($pointerPath) -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        throw "Refusing to replace a current-version pointer symbolic link or reparse point: $pointerPath"
    }
    if ((Test-Path -LiteralPath $pointerPath) -and -not (Test-Path -LiteralPath $pointerPath -PathType Leaf)) {
        throw "Refusing to overwrite a non-file current-version pointer path: $pointerPath"
    }
    if (Test-Path -LiteralPath $pointerPath -PathType Leaf) {
        try { $priorPointer = Get-Content -LiteralPath $pointerPath -Raw | ConvertFrom-Json }
        catch { throw "Refusing to overwrite an invalid current-version pointer: $pointerPath" }
        $validPointerId = [string]$priorPointer.version_id -match '^[a-z0-9-]{1,64}$'
        $validPointerVersion = [string]$priorPointer.version -match '^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$'
        if (-not $validPointerId -or -not $validPointerVersion) {
            throw "Refusing to overwrite an unrelated current-version pointer: $pointerPath"
        }
    }
    $pointer = [ordered]@{ version_id=$versionId; version=$versionText; source_ref=$sourceInfo.Ref; source_commit=$sourceInfo.Commit }
    $pointerTemp = Join-Path $InstallRoot ('current.' + [guid]::NewGuid().ToString('N') + '.tmp')
    [IO.File]::WriteAllText($pointerTemp, ($pointer | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
    if (Test-Path -LiteralPath $pointerPath -PathType Leaf) {
        $backupPointer = $pointerPath + '.backup.' + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ')
        [IO.File]::Replace($pointerTemp, $pointerPath, $backupPointer)
    } else {
        [IO.File]::Move($pointerTemp, $pointerPath)
    }
    if (-not $SkipPath) { Add-UserPath $InstallRoot }

    Write-Host "Master Core CLI $versionText installed at $InstallRoot"
    Write-Host "Source: $($sourceInfo.Ref) ($($sourceInfo.Commit))"
    Write-Host 'User configuration is stored outside this managed installation folder.'
    if (-not $SkipPath) { Write-Host 'The CLI directory was registered in the current user PATH. Open a new terminal and run master --help.' }
    Show-ToolAvailability 'php' @('-v') '8.4'
    Show-ToolAvailability 'composer' @('--version') '2.0'
    Show-ToolAvailability 'git' @('--version') '2.0'
    if ($sourceInfo.Temp) { Write-Host "Verified GitHub archive preserved in temporary workspace: $($sourceInfo.Temp)" }
} catch {
    Write-Error ("Master Core installation failed safely: " + $_.Exception.Message)
    throw
}
