param([Parameter(ValueFromRemainingArguments = $true)][string[]] $Arguments)

$ErrorActionPreference = 'Stop'
$Owner = 'Itassistancebd'
$Repository = 'laravel-master-core'
$RepositoryUrl = "https://github.com/$Owner/$Repository.git"
$ExpectedBranch = 'main'
$PayloadRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$InstallRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..\..\..')).Path
$MetadataPath = Join-Path $PSScriptRoot '..\..\install.json'
$MetadataPath = [IO.Path]::GetFullPath($MetadataPath)

function Get-Metadata {
    if (-not (Test-Path -LiteralPath $MetadataPath -PathType Leaf)) { return $null }
    return Get-Content -LiteralPath $MetadataPath -Raw | ConvertFrom-Json
}

function Get-CommandVersion([string] $Name, [string[]] $VersionArguments) {
    $command = Get-Command $Name -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $command) { return $null }
    $priorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = & $command.Source @VersionArguments 2>&1 | Out-String }
    finally { $ErrorActionPreference = $priorAction }
    return @{ Path=$command.Source; Output=$output.Trim() }
}

function Convert-ToolVersion([string] $Output, [string] $Pattern) {
    $match = [regex]::Match($Output,$Pattern,[Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if (-not $match.Success) { return $null }
    try { return [version] $match.Groups[1].Value } catch { return $null }
}

function Assert-Toolchain([switch] $Frontend) {
    $phpName = if (-not [string]::IsNullOrWhiteSpace($env:MASTER_CORE_PHP)) { $env:MASTER_CORE_PHP } else { 'php' }
    $php = Get-CommandVersion $phpName @('-v')
    $phpVersion = if ($php) { Convert-ToolVersion $php.Output 'PHP\s+(\d+\.\d+(?:\.\d+)?)' } else { $null }
    if ($null -eq $phpVersion -or $phpVersion -lt [version]'8.4' -or $phpVersion -ge [version]'9.0') {
        throw "PHP 8.4+ was not found (selected '$phpName'). Set MASTER_CORE_PHP to a compatible php.exe or configure your current-user PATH, then open a new terminal."
    }
    $composer = Get-CommandVersion 'composer' @('--version')
    $composerVersion = if ($composer) { Convert-ToolVersion $composer.Output 'Composer version\s+v?(\d+\.\d+(?:\.\d+)?)' } else { $null }
    if ($null -eq $composerVersion -or $composerVersion.Major -ne 2) {
        throw 'Composer 2.x was not found on PATH. Install Composer 2 and open a new terminal.'
    }
    $git = Get-CommandVersion 'git' @('--version')
    $gitVersion = if ($git) { Convert-ToolVersion $git.Output 'git version\s+(\d+\.\d+(?:\.\d+)?)' } else { $null }
    if ($null -eq $gitVersion -or $gitVersion -lt [version]'2.0') {
        throw 'Git 2.x was not found on PATH. Install Git for Windows and open a new terminal.'
    }
    if ($Frontend) {
        $node = Get-CommandVersion 'node' @('--version')
        $npm = Get-CommandVersion 'npm.cmd' @('--version')
        if ($null -eq $node -or $null -eq $npm) { throw 'Node.js and npm are required for the selected frontend packages.' }
        $nodeVersion = Convert-ToolVersion $node.Output 'v?(\d+\.\d+(?:\.\d+)?)'
        $npmVersion = Convert-ToolVersion $npm.Output 'v?(\d+\.\d+(?:\.\d+)?)'
        $nodeSupported = $nodeVersion -and (($nodeVersion.Major -eq 20 -and $nodeVersion.Minor -ge 19) -or ($nodeVersion.Major -ge 22 -and ($nodeVersion.Major -gt 22 -or $nodeVersion.Minor -ge 12)))
        if (-not $nodeSupported) {
            throw 'Node.js must satisfy the repository profile (>=20.19 on the 20.x line, or >=22.12).'
        }
        if ($null -eq $npmVersion -or $npmVersion -lt [version]'7.0') { throw 'npm 7 or newer is required for frontend packages.' }
    }
}

function Invoke-Git([string] $Root, [string[]] $GitArguments, [switch] $Quiet) {
    $priorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & git -C $Root @GitArguments 2>&1
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $priorAction }
    if (-not $Quiet -and $output) { $output | ForEach-Object { Write-Output $_ } }
    return @{ ExitCode=$code; Output=@($output | ForEach-Object { [string] $_ }) }
}

function Get-CoreRepository {
    $rootResult = Invoke-Git (Get-Location).Path @('rev-parse','--show-toplevel') -Quiet
    if ($rootResult.ExitCode -ne 0 -or $rootResult.Output.Count -eq 0) { throw 'This command must run inside the original Laravel Master Core Git checkout.' }
    $root = @($rootResult.Output | Where-Object { Test-Path -LiteralPath ([string] $_).Trim() -PathType Container } | Select-Object -Last 1)[0]
    if (-not $root) { throw 'Git did not return a valid Core checkout path.' }
    $root = ([string] $root).Trim()
    $remoteResult = Invoke-Git $root @('remote','get-url','origin') -Quiet
    if ($remoteResult.ExitCode -ne 0 -or $remoteResult.Output.Count -eq 0) { throw 'The Core checkout has no origin remote.' }
    $remote = $remoteResult.Output[-1].Trim()
    $canonical = $remote -match '^(https://github\.com/Itassistancebd/laravel-master-core(?:\.git)?|git@github\.com:Itassistancebd/laravel-master-core(?:\.git)?)$'
    if (-not $canonical) { throw "origin is not the authorized Core repository ($RepositoryUrl). No GitHub update will be attempted." }
    $branchResult = Invoke-Git $root @('branch','--show-current') -Quiet
    $branch = @($branchResult.Output | Where-Object { $_ -match '^[A-Za-z0-9._/-]+$' } | Select-Object -Last 1)
    $branch = if ($branch.Count) { ([string] $branch[-1]).Trim() } else { '' }
    if ($branch -ne $ExpectedBranch) { throw "The Core maintenance workflow is restricted to '$ExpectedBranch'; current branch is '$branch'." }
    $headResult = Invoke-Git $root @('rev-parse','HEAD') -Quiet
    if ($headResult.ExitCode -ne 0) { throw 'Could not resolve local HEAD.' }
    $head = @($headResult.Output | Where-Object { $_ -match '^[0-9a-f]{40}$' } | Select-Object -Last 1)
    if (-not $head.Count) { throw 'Git did not return a valid local HEAD hash.' }
    return @{ Root=$root; Remote=$remote; Branch=$branch; Head=([string] $head[-1]).Trim() }
}

function Get-RemoteHead($RepositoryInfo) {
    $result = Invoke-Git $RepositoryInfo.Root @('ls-remote','origin',"refs/heads/$ExpectedBranch") -Quiet
    if ($result.ExitCode -ne 0) { return @{ Ok=$false; Sha=''; Error=($result.Output -join ' ') } }
    $refLine = @($result.Output | Where-Object { $_ -match "^[0-9a-f]{40}\s+refs/heads/$ExpectedBranch$" } | Select-Object -Last 1)
    if (-not $refLine.Count) { return @{ Ok=$true; Sha=''; Error='Remote branch does not exist.' } }
    return @{ Ok=$true; Sha=($refLine[-1] -split '\s+')[0]; Error='' }
}

function Get-RemoteRelation($RepositoryInfo) {
    $remote = Get-RemoteHead $RepositoryInfo
    if (-not $remote.Ok) { return "UNAVAILABLE: $($remote.Error)" }
    if (-not $remote.Sha) { return 'BLOCKED: origin/main was not found.' }
    if ($remote.Sha -eq $RepositoryInfo.Head) { return 'UP TO DATE: local HEAD equals origin/main.' }
    $known = Invoke-Git $RepositoryInfo.Root @('cat-file','-e',"$($remote.Sha)^{commit}") -Quiet
    if ($known.ExitCode -ne 0) { return "DIFFERENT: local $($RepositoryInfo.Head.Substring(0,12)); remote $($remote.Sha.Substring(0,12)); remote commit is not in the local object database, so ancestry is unknown without a fetch." }
    $counts = Invoke-Git $RepositoryInfo.Root @('rev-list','--left-right','--count',"$($RepositoryInfo.Head)...$($remote.Sha)") -Quiet
    if ($counts.ExitCode -ne 0 -or $counts.Output.Count -eq 0) { return 'UNKNOWN: could not compare branch ancestry.' }
    $values = $counts.Output[-1].Trim() -split '\s+'
    $ahead = [int] $values[0]
    $behind = [int] $values[1]
    if ($ahead -eq 0 -and $behind -gt 0) { return "BEHIND by $behind commit(s); review remote changes before publishing." }
    if ($ahead -gt 0 -and $behind -eq 0) { return "AHEAD by $ahead commit(s); remote is an ancestor of local HEAD." }
    return "DIVERGED: local is ahead by $ahead and behind by $behind commit(s). Publishing is blocked."
}

function Get-PathRisk([string] $Path) {
    $normalized = $Path.Replace('\','/').TrimStart('/')
    if ($normalized -match '(^|/)\.env(?:\..*)?$' -and $normalized -ne '.env.example') { return '.env or environment-specific file' }
    if ($normalized -match '(^|/)(\.php-runtime\.local\.json|auth\.json|\.npmrc|\.composer/auth\.json)$') { return 'local package-manager or PHP runtime configuration' }
    if ($normalized -match '(^|/)(vendor|node_modules|\.git|test-results|playwright-report|artifacts|coverage|dist|build|\.cache|cache|tmp|temp|backups?)(/|$)') { return 'generated, dependency, cache, backup, or temporary content' }
    if ($normalized -match '\.(pem|pfx|p12|key|ppk|sqlite|sqlite3|db|dump|sql|log|bak|backup|zip|7z|rar|exe|dll|bin)$') { return 'private key, database, generated binary, log, or backup file' }
    if ($normalized -match '(^|/)storage/(logs|framework|app/private)(/|$)|(^|/)bootstrap/cache(/|$)|(^|/)public/build(/|$)') { return 'runtime or generated application data' }
    return ''
}

function Test-SecretText([string] $Text) {
    $patterns = @(
        '-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----',
        '\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{30,}|npm_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16})\b',
        '(?i)\b(?:api[_-]?key|client[_-]?secret|secret|password|passwd|access[_-]?token|refresh[_-]?token|token)\s*[:=]\s*["'']?(?!CHANGE_ME|YOUR_|<|\$\{)[A-Za-z0-9_./+=-]{20,}'
    )
    foreach ($pattern in $patterns) { if ([regex]::IsMatch($Text, $pattern)) { return $true } }
    return $false
}

function Get-ChangeInventory($RepositoryInfo) {
    $result = Invoke-Git $RepositoryInfo.Root @('status','--short','--untracked-files=all') -Quiet
    if ($result.ExitCode -ne 0) { throw 'Could not inspect the Core worktree.' }
    $items = [Collections.Generic.List[object]]::new()
    foreach ($line in @($result.Output | Where-Object { $_ -match '^(?: M|M |A |\?\? | D|D |R |C |U |\sM|\sA|\sD|\sR)' })) {
        if ($line.Length -lt 4) { continue }
        $path = $line.Substring(3)
        $risk = Get-PathRisk $path
        if ($path.Contains(' -> ')) { $risk = 'renamed path requires manual review' }
        if (-not $risk) {
            $untracked = $line.StartsWith('??')
            if ($untracked) {
                $filePath = Join-Path $RepositoryInfo.Root $path
                if (Test-Path -LiteralPath $filePath -PathType Leaf) {
                    $info = Get-Item -LiteralPath $filePath
                    if ($info.Length -gt 2MB) { $risk = 'large untracked file requires manual review' }
                    elseif ($info.Length -gt 0) {
                        $bytes = [IO.File]::ReadAllBytes($filePath)
                        if ($bytes -contains 0) { $risk = 'binary untracked file requires manual review' }
                        elseif (Test-SecretText ([Text.Encoding]::UTF8.GetString($bytes))) { $risk = 'possible credential detected in untracked content' }
                    }
                }
            } else {
                $diff = Invoke-Git $RepositoryInfo.Root @('diff','--no-ext-diff','--no-color','--unified=0','HEAD','--',$path) -Quiet
                $added = @($diff.Output | Where-Object { $_.StartsWith('+') -and -not $_.StartsWith('+++') }) -join "`n"
                if (Test-SecretText $added) { $risk = 'possible credential detected in added or modified content' }
            }
        }
        $items.Add([pscustomobject]@{ Status=$line.Substring(0,2); Path=$path; Risk=$risk; Untracked=$line.StartsWith('??') })
    }
    return $items
}

function Show-Status($RepositoryInfo, $Items) {
    Write-Output "Core repository: $($RepositoryInfo.Root)"
    Write-Output "Remote: $($RepositoryInfo.Remote)"
    Write-Output "Branch: $($RepositoryInfo.Branch)"
    Write-Output "HEAD: $($RepositoryInfo.Head)"
    Write-Output "Remote sync: $(Get-RemoteRelation $RepositoryInfo)"
    if ($Items.Count -eq 0) { Write-Output 'Worktree: clean'; return }
    Write-Output 'Worktree changes:'
    foreach ($item in $Items) {
        $suffix = if ($item.Risk) { " [BLOCKED: $($item.Risk)]" } else { '' }
        Write-Output ("  {0} {1}{2}" -f $item.Status,$item.Path,$suffix)
    }
}

function Show-Changes($RepositoryInfo, $Items) {
    Show-Status $RepositoryInfo $Items
    $safe = @($Items | Where-Object { -not $_.Risk })
    if ($safe.Count -eq 0) { Write-Output 'No safe changes are available to display.'; return }
    $modified = @($safe | Where-Object { $_.Status -match 'M' }).Count
    $added = @($safe | Where-Object { $_.Status -match 'A|\?\?' }).Count
    $deleted = @($safe | Where-Object { $_.Status -match 'D' }).Count
    Write-Output "`nChange summary: $modified modified, $added added/untracked, $deleted deleted, $(@($Items | Where-Object { $_.Risk }).Count) blocked."
    $tracked = @($safe | Where-Object { -not $_.Untracked } | ForEach-Object { $_.Path })
    if ($tracked.Count) {
        Write-Output "`nTracked diff summary (against HEAD):"
        Invoke-Git $RepositoryInfo.Root (@('diff','--stat','HEAD','--') + $tracked)
        Write-Output "`nTracked diff (against HEAD):"
        Invoke-Git $RepositoryInfo.Root (@('diff','--no-ext-diff','--no-color','HEAD','--') + $tracked)
    }
    foreach ($item in @($safe | Where-Object { $_.Untracked })) {
        Write-Output "`nNew file preview: $($item.Path)"
        $file = Join-Path $RepositoryInfo.Root $item.Path
        $lines = Get-Content -LiteralPath $file
        $shown = 0
        foreach ($line in $lines) {
            if ($shown -ge 120) { Write-Output '... preview truncated at 120 lines'; break }
            Write-Output ('+ ' + $line); $shown++
        }
    }
}

function Publish-DryRun($RepositoryInfo, $Items) {
    Show-Status $RepositoryInfo $Items
    $blocked = @($Items | Where-Object { $_.Risk })
    $safe = @($Items | Where-Object { -not $_.Risk })
    Write-Output "`nPublish dry-run: no files will be staged; no commit or upload will occur."
    Write-Output "Eligible files for later explicit selection: $($safe.Count)"
    foreach ($item in $safe) { Write-Output "  $($item.Status) $($item.Path)" }
    if ($blocked.Count) { Write-Output "Blocked files: $($blocked.Count)" }
    $check = Invoke-Git $RepositoryInfo.Root @('diff','--check','HEAD') -Quiet
    Write-Output ("Diff whitespace check: " + $(if ($check.ExitCode -eq 0) { 'PASS' } else { 'FAIL' }))
    if ($check.Output.Count -gt 0) { $check.Output | ForEach-Object { Write-Output $_ } }
    Write-Output ("Target: origin/$ExpectedBranch ($RepositoryUrl)")
}

function Publish-Changes($RepositoryInfo, $Items) {
    if (-not [Environment]::UserInteractive) { throw 'Publishing requires an interactive terminal; no Git state was changed.' }
    $fetch = Invoke-Git $RepositoryInfo.Root @('fetch','--no-tags','origin',$ExpectedBranch) -Quiet
    if ($fetch.ExitCode -ne 0) { throw "Could not fetch origin/$ExpectedBranch. No files were staged. $($fetch.Output -join ' ')" }
    $remoteBefore = Get-RemoteHead $RepositoryInfo
    if (-not $remoteBefore.Ok -or -not $remoteBefore.Sha) { throw 'Could not verify origin/main; no files were staged.' }
    $fetched = Invoke-Git $RepositoryInfo.Root @('rev-parse','FETCH_HEAD') -Quiet
    if ($fetched.ExitCode -ne 0 -or $fetched.Output[-1].Trim() -ne $remoteBefore.Sha) { throw 'The remote changed during preflight. Review origin/main and try again; no files were staged.' }
    $ancestor = Invoke-Git $RepositoryInfo.Root @('merge-base','--is-ancestor',$remoteBefore.Sha,$RepositoryInfo.Head) -Quiet
    if ($ancestor.ExitCode -ne 0) { throw 'Local main is behind or diverged from origin/main. Review and integrate remote work manually before publishing; no files were staged.' }

    $safe = @($Items | Where-Object { -not $_.Risk })
    if ($safe.Count -eq 0) { throw 'There are no eligible changed files to publish.' }
    Write-Output 'Choose exact changed file paths to stage. Enter one path per prompt; blank ends selection.'
    $selected = [Collections.Generic.List[string]]::new()
    while ($true) {
        $path = Read-Host 'Path (or blank to finish)'
        if ([string]::IsNullOrWhiteSpace($path)) { break }
        if ($path -notin @($safe | ForEach-Object { $_.Path })) { Write-Warning 'That path is not eligible in the current change inventory.'; continue }
        if ($path -notin $selected) { $selected.Add($path) }
    }
    if ($selected.Count -eq 0) { throw 'No files selected; no Git state was changed.' }
    $message = Read-Host 'Commit message'
    if ([string]::IsNullOrWhiteSpace($message) -or $message.Contains("`n") -or $message.Contains("`r")) { throw 'A single-line commit message is required; no Git state was changed.' }
    Write-Output "`nAction preview: stage only $($selected.Count) selected file(s), commit as '$message', then push origin/$ExpectedBranch."
    foreach ($path in $selected) { Write-Output "  $path" }
    if ((Read-Host 'Type STAGE to stage exactly these paths') -cne 'STAGE') { throw 'Staging cancelled.' }

    $freshItems = Get-ChangeInventory $RepositoryInfo
    foreach ($path in $selected) {
        $current = @($freshItems | Where-Object { $_.Path -ceq $path }) | Select-Object -First 1
        if ($null -eq $current -or $current.Risk) { throw "Selected path changed or failed safety review: $path. Nothing further was staged." }
    }
    $add = Invoke-Git $RepositoryInfo.Root (@('add','--') + @($selected)) -Quiet
    if ($add.ExitCode -ne 0) { throw "git add failed for selected paths: $($add.Output -join ' ')" }
    $cached = Invoke-Git $RepositoryInfo.Root @('diff','--cached','--check') -Quiet
    if ($cached.ExitCode -ne 0) { throw 'Staged diff check failed. Review the staged paths; no commit or push was attempted.' }
    Write-Output (Invoke-Git $RepositoryInfo.Root (@('diff','--cached','--stat','--') + @($selected)) -Quiet).Output
    if ((Read-Host 'Type COMMIT to create this commit') -cne 'COMMIT') { throw 'Commit cancelled. Selected paths remain staged; no work was discarded.' }
    $commit = Invoke-Git $RepositoryInfo.Root (@('commit','--only','-m',$message,'--') + @($selected)) -Quiet
    if ($commit.ExitCode -ne 0) { throw "Commit failed. Selected files remain available in the worktree/index: $($commit.Output -join ' ')" }
    $localHead = Invoke-Git $RepositoryInfo.Root @('rev-parse','HEAD') -Quiet
    if ($localHead.ExitCode -ne 0) { throw 'Commit completed but local HEAD could not be read; do not retry publishing blindly.' }
    if ((Read-Host "Type PUSH to push $($localHead.Output[-1].Trim()) to origin/$ExpectedBranch") -cne 'PUSH') {
        Write-Warning "Commit created locally: $($localHead.Output[-1].Trim()). Push was cancelled."
        return
    }
    $remoteNow = Get-RemoteHead $RepositoryInfo
    if (-not $remoteNow.Ok -or $remoteNow.Sha -ne $remoteBefore.Sha) {
        throw 'origin/main changed after commit confirmation. The commit is local; review remote updates before retrying.'
    }
    $push = Invoke-Git $RepositoryInfo.Root @('push','origin',$ExpectedBranch) -Quiet
    if ($push.ExitCode -ne 0) { throw "Push failed. Local commit $($localHead.Output[-1].Trim()) was preserved: $($push.Output -join ' ')" }
    $verified = Get-RemoteHead $RepositoryInfo
    if ($verified.Ok -and $verified.Sha -eq $localHead.Output[-1].Trim()) {
        Write-Output "Published $($localHead.Output[-1].Trim()); origin/$ExpectedBranch matches local HEAD."
    } else { Write-Warning 'Push returned success but remote hash verification did not match local HEAD.' }
}

function Invoke-NewProject([string[]] $CliArguments) {
    if ($CliArguments.Count -lt 2) { throw 'Usage: master new <project-name> [--no-interaction].' }
    $name = $CliArguments[1]
    if ($name -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]*$') { throw 'Project name must contain only letters, numbers, dot, underscore, and hyphen; it must start with a letter or number.' }
    if ($name.EndsWith('.') -or $name.EndsWith(' ') -or $name -match '^(?i:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$') {
        throw 'Project name is not valid as a Windows directory name.'
    }
    $parent = [IO.Path]::GetFullPath((Get-Location).Path)
    $target = [IO.Path]::GetFullPath((Join-Path $parent $name))
    if (Test-Path -LiteralPath $target) { throw "Target already exists: $target. Existing files will not be overwritten." }
    Assert-Toolchain -Frontend
    $noInteraction = $CliArguments -contains '--no-interaction'
    $unknown = @($CliArguments | Select-Object -Skip 2 | Where-Object { $_ -ne '--no-interaction' })
    if ($unknown.Count) { throw "Unsupported project creation option(s): $($unknown -join ', ')." }
    if (-not $noInteraction -and (Read-Host "Create a Laravel Master Core project at '$target'? [y/N]") -notmatch '^(?i:y|yes)$') {
        throw 'Project creation cancelled before files were written.'
    }

    $sourceTemp = Join-Path $env:TEMP ('MasterCore-project-source-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $sourceTemp | Out-Null
    $archive = Join-Path $sourceTemp 'source.zip'
    try {
        if (-not [string]::IsNullOrWhiteSpace($env:MASTER_CORE_SOURCE_ROOT)) {
            $source = [IO.Path]::GetFullPath($env:MASTER_CORE_SOURCE_ROOT)
            $sourceRef = 'local-development-source'
            $sourceCommit = 'local-source'
        } else {
            $headers = @{ 'User-Agent' = 'Laravel-Master-Core-CLI'; 'Accept' = 'application/vnd.github+json' }
            $commitRecord = Invoke-RestMethod -Uri "https://api.github.com/repos/$Owner/$Repository/commits/$ExpectedBranch" -Headers $headers -TimeoutSec 30
            $sourceCommit = [string] $commitRecord.sha
            if ($sourceCommit -notmatch '^[0-9a-f]{40}$') { throw 'GitHub returned an invalid source commit identifier.' }
            $sourceRef = $ExpectedBranch
            Invoke-WebRequest -Uri "https://api.github.com/repos/$Owner/$Repository/zipball/$sourceCommit" -Headers $headers -OutFile $archive -TimeoutSec 180
            if (-not (Test-Path -LiteralPath $archive -PathType Leaf) -or (Get-Item -LiteralPath $archive).Length -lt 4 -or (Get-Item -LiteralPath $archive).Length -gt 500MB) {
                throw 'The GitHub source archive is empty or exceeds the 500 MB safety limit.'
            }
            $extractRoot = Join-Path $sourceTemp 'extract'
            New-Item -ItemType Directory -Path $extractRoot | Out-Null
            Add-Type -AssemblyName System.IO.Compression.FileSystem
            $zip = [IO.Compression.ZipFile]::OpenRead($archive)
            $expandedBytes = [long] 0
            $entryCount = 0
            try {
                foreach ($entry in $zip.Entries) {
                    if ([string]::IsNullOrWhiteSpace($entry.FullName)) { continue }
                    $entryCount++
                    $expandedBytes += $entry.Length
                    if ($entryCount -gt 150000 -or $expandedBytes -gt 1GB) { throw 'GitHub archive exceeds the extraction entry or expanded-size safety limit.' }
                    $unixType = ($entry.ExternalAttributes -shr 16) -band 0xF000
                    if ($unixType -eq 0xA000) { throw 'GitHub source archive contains a symbolic link; extraction was refused.' }
                    $relative = $entry.FullName.Replace('/', [IO.Path]::DirectorySeparatorChar)
                    $destination = [IO.Path]::GetFullPath((Join-Path $extractRoot $relative))
                    $prefix = [IO.Path]::GetFullPath($extractRoot) + [IO.Path]::DirectorySeparatorChar
                    if (-not $destination.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { throw 'GitHub source archive contains a path outside its extraction directory.' }
                    if ($entry.Name -eq '' -or $entry.FullName.EndsWith('/')) {
                        New-Item -ItemType Directory -Force -Path $destination | Out-Null
                    } else {
                        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination) | Out-Null
                        [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $destination, $false)
                    }
                }
            } finally { $zip.Dispose() }
            $sourceDirectory = Get-ChildItem -LiteralPath $extractRoot -Directory | Select-Object -First 1
            if ($null -eq $sourceDirectory) { throw 'GitHub archive did not contain a source directory.' }
            $source = $sourceDirectory.FullName
        }

        $required = @('artisan','composer.json','composer.lock','package.json','package-lock.json','.env.example','packages/core/master-core/composer.json','packages/core/foundation/composer.json','packages/tools/project-generator/bin/master-core','packages/tools/project-generator/src/Installation/ProjectInstaller.php')
        foreach ($relative in $required) {
            if (-not (Test-Path -LiteralPath (Join-Path $source $relative) -PathType Leaf)) { throw "Core source is incomplete; required '$relative' is missing." }
        }
        if (-not (Test-Path -LiteralPath (Join-Path $source 'install.ps1') -PathType Leaf) -or -not (Test-Path -LiteralPath (Join-Path $source 'VERSION') -PathType Leaf)) {
            Write-Warning 'This source archive does not yet contain the published bootstrap and version metadata. Generated project creation can continue, but fresh-PC installer/update availability requires publishing those files to main.'
        }

        $php = if (-not [string]::IsNullOrWhiteSpace($env:MASTER_CORE_PHP)) { [IO.Path]::GetFullPath($env:MASTER_CORE_PHP) } else { (Get-Command 'php' -ErrorAction Stop | Select-Object -First 1).Source }
        if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw "Selected PHP executable does not exist: $php" }
        $composerCommand = Get-Command 'composer.bat' -ErrorAction SilentlyContinue | Select-Object -First 1
        $composerPhar = if ($composerCommand) { Join-Path (Split-Path -Parent $composerCommand.Source) 'composer.phar' } else { '' }
        $composerExecutable = ''
        if (-not $composerPhar -or -not (Test-Path -LiteralPath $composerPhar -PathType Leaf)) {
            $composerExe = Get-Command 'composer.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($composerExe) { $composerExecutable = $composerExe.Source }
        }
        $node = Get-Command 'node.exe' -ErrorAction Stop | Select-Object -First 1
        $npm = Get-Command 'npm.cmd' -ErrorAction Stop | Select-Object -First 1
        $npmCli = Join-Path (Split-Path -Parent $npm.Source) 'node_modules/npm/bin/npm-cli.js'
        if (-not (Test-Path -LiteralPath $npmCli -PathType Leaf)) { throw 'npm CLI JavaScript entrypoint could not be resolved beside npm.cmd.' }
        $generator = Join-Path $source 'packages/tools/project-generator/bin/master-core'
        $arguments = @($generator, 'template', $target, "--source-root=$source", "--source-ref=$sourceRef", "--source-commit=$sourceCommit", "--php-binary=$php", "--node-binary=$($node.Source)", "--npm-cli=$npmCli", '--no-interaction')
        if ($composerPhar -and (Test-Path -LiteralPath $composerPhar -PathType Leaf)) { $arguments += "--composer-phar=$composerPhar" }
        elseif ($composerExecutable) { $arguments += "--composer-executable=$composerExecutable" }
        else { throw 'Composer was found, but neither a trusted Composer PHAR nor composer.exe could be resolved for safe process execution.' }

        Write-Host 'Generating from the existing Laravel application. Core packages resolve from monorepo paths; Composer/npm install third-party dependencies.'
        & $php @arguments
        if ($LASTEXITCODE -ne 0) { throw "The Phase 11 monorepo-template installer failed with exit code $LASTEXITCODE." }
        Remove-Item -LiteralPath $sourceTemp -Recurse -Force
        Write-Host "Project created and verified: $target"
        Write-Host 'Next: cd into the project, run php artisan serve, and run approved database migrations manually when you are ready.'
    } catch {
        $reason = $_.Exception.Message
        if (Test-Path -LiteralPath $sourceTemp) { throw "$reason. Downloaded source was preserved for review at: $sourceTemp" }
        throw $reason
    }
}

function Update-Cli {
    $installer = Join-Path $PSScriptRoot '..\install.ps1'
    $installer = [IO.Path]::GetFullPath($installer)
    if (-not (Test-Path -LiteralPath $installer -PathType Leaf)) { throw 'The versioned installer is missing; reinstall from the approved GitHub source.' }
    & powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File $installer -InstallRoot $InstallRoot
    if ($LASTEXITCODE -ne 0) { throw 'CLI update failed. The current installed version was preserved.' }
}

try {
    if ($Arguments.Count -eq 0) { $Arguments = @('--help') }
    $verb = $Arguments[0]
    switch ($verb) {
        { $_ -in '--help','-h','help' } {
            @'
Master Core CLI

  master --help
  master --version
  master new <project-name> [--no-interaction]
  master update
  master status
  master changes
  master publish --dry-run
  master publish

Project generation copies the existing Laravel application from the canonical GitHub monorepo archive. Core Composer packages resolve from included relative path repositories; third-party packages use Composer/npm normally.
The maintainer publish command is interactive; dry-run never stages, commits, or uploads.
'@ | Write-Output
            break
        }
        '--version' {
            $metadata = Get-Metadata
            if ($null -eq $metadata) { Write-Output 'Master Core CLI (source checkout; no installed release metadata)' }
            else { Write-Output "Master Core CLI $($metadata.version) ($($metadata.ref) @ $($metadata.commit))" }
            break
        }
        'new' { Invoke-NewProject $Arguments; break }
        'update' { Update-Cli; break }
        { $_ -in 'status','changes','publish' } {
            $repositoryInfo = Get-CoreRepository
            $items = Get-ChangeInventory $repositoryInfo
            if ($verb -eq 'status') { Show-Status $repositoryInfo $items; break }
            if ($verb -eq 'changes') { Show-Changes $repositoryInfo $items; break }
            $dryRun = $Arguments -contains '--dry-run'
            if ($dryRun) { Publish-DryRun $repositoryInfo $items; break }
            Publish-Changes $repositoryInfo $items
            break
        }
        default { throw "Unknown command '$verb'. Run master --help." }
    }
} catch {
    Write-Error ("Master Core: " + $_.Exception.Message)
    exit 1
}
