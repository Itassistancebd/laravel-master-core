function Resolve-MasterCoreGitRefCommit {
    param(
        [AllowNull()][string[]] $RemoteLines,
        [Parameter(Mandatory = $true)][string] $Ref
    )

    $name = $Ref
    if ($Ref.StartsWith('refs/heads/', [StringComparison]::Ordinal)) {
        $name = $Ref.Substring('refs/heads/'.Length)
        $fullRef = "refs/heads/$name"
    } elseif ($Ref.StartsWith('refs/tags/', [StringComparison]::Ordinal)) {
        $name = $Ref.Substring('refs/tags/'.Length)
        $fullRef = "refs/tags/$name"
    } else {
        $fullRef = "refs/heads/$name"
    }

    if ([string]::IsNullOrWhiteSpace($name) -or $name.StartsWith('/') -or $name.EndsWith('/') -or
        $name.Contains('..') -or $name.Contains('//') -or $name.Contains('\') -or
        $name.Contains(' ') -or $name.Contains('~') -or $name.Contains('^') -or
        $name.Contains(':') -or $name.Contains('?') -or $name.Contains('*') -or
        $name.Contains('[') -or $name.Contains('@{') -or $name.EndsWith('.') -or
        ($name -split '/' | Where-Object { $_ -eq '' -or $_ -eq '.' -or $_ -eq '..' -or $_ -match '\.lock$' })) {
        return $null
    }

    $expected = [Collections.Generic.List[string]]::new()
    if ($fullRef.StartsWith('refs/tags/', [StringComparison]::Ordinal)) { $expected.Add("$fullRef^{}") }
    $expected.Add($fullRef)
    $found = @{}
    foreach ($line in @($RemoteLines)) {
        if ($line -notmatch '^([0-9a-fA-F]{40})\t([^\t]+)$') { continue }
        $lineRef = $Matches[2]
        if ($expected.Contains($lineRef)) { $found[$lineRef] = $Matches[1].ToLowerInvariant() }
    }

    foreach ($candidate in $expected) {
        if ($found.ContainsKey($candidate)) {
            $commit = [string] $found[$candidate]
            if ($commit -match '^[0-9a-f]{40}$') { return $commit }
            return $null
        }
    }
    return $null
}
