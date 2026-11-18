$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\..\scripts\git-ref-parser.ps1')

$commit = '0123456789abcdef0123456789abcdef01234567'
$tagObject = 'abcdef0123456789abcdef0123456789abcdef01'
$cases = @(
    @{ Name = 'valid branch output'; Ref = 'main'; Lines = @("$commit`trefs/heads/main"); Expected = $commit }
    @{ Name = 'valid lightweight tag output'; Ref = 'refs/tags/v1.0.0'; Lines = @("$commit`trefs/tags/v1.0.0"); Expected = $commit }
    @{ Name = 'annotated tag uses peeled commit'; Ref = 'refs/tags/v1.0.0'; Lines = @("$tagObject`trefs/tags/v1.0.0", "$commit`trefs/tags/v1.0.0^{}"); Expected = $commit }
    @{ Name = 'tab separated output'; Ref = 'main'; Lines = @("$commit`trefs/heads/main"); Expected = $commit }
    @{ Name = 'empty output'; Ref = 'main'; Lines = @(); Expected = $null }
    @{ Name = 'malformed output'; Ref = 'main'; Lines = @("not-a-hash`trefs/heads/main", "$commit refs/heads/main"); Expected = $null }
    @{ Name = 'missing remote ref'; Ref = 'main'; Lines = @("$commit`trefs/heads/other"); Expected = $null }
    @{ Name = 'unsafe ref is rejected'; Ref = 'refs/heads/../main'; Lines = @("$commit`trefs/heads/main"); Expected = $null }
)

$failures = [Collections.Generic.List[string]]::new()
foreach ($case in $cases) {
    $actual = Resolve-MasterCoreGitRefCommit -RemoteLines $case.Lines -Ref $case.Ref
    if ($actual -cne $case.Expected) { $failures.Add("$($case.Name): expected '$($case.Expected)', got '$actual'") }
}
if ($failures.Count) { throw ($failures -join [Environment]::NewLine) }
Write-Output "PASS: $($cases.Count) Git ref parser cases"
