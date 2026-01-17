# test_concurrency.ps1 - Run concurrent order ID tests
# Run with: .\test_concurrency.ps1

$scriptPath = "c:\UniServerZ\www\test_nextid.php"

# Run 20 concurrent PHP processes
$jobs = @()
for ($i = 1; $i -le 20; $i++) {
    $jobs += Start-Job -ScriptBlock {
        param($path)
        & php $path
    } -ArgumentList $scriptPath
}

# Wait for all jobs to complete
$jobs | Wait-Job

# Collect and display results
$results = $jobs | Receive-Job
$results | ForEach-Object { Write-Host $_ }

# Clean up
$jobs | Remove-Job

Write-Host "Concurrency test complete. Check for duplicate IDs above."