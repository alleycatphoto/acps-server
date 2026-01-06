# ACPS v3.5.0 Remote Server Git Pull Trigger
# Usage: .\deploy.ps1 [hawksnest|hawkmoon|zip|all]
# This script SSH's into remote servers and tells them to git pull

param(
    [Parameter(Mandatory=$false)]
    [ValidateSet("hawksnest","hawkmoon","zip","all")]
    [string]$Target = "all"
)

$ErrorActionPreference = "Stop"

# Load config
$config = Get-Content "deploy.config.json" | ConvertFrom-Json

function Write-Status {
    param($Message, $Color = "Cyan")
    Write-Host "[DEPLOY] $Message" -ForegroundColor $Color
}

function Trigger-HawksNest {
    Write-Status "Triggering git pull on Hawks Nest (North Carolina)..." "Yellow"
    
    $server = $config.servers.hawksnest
    $serverHost = $server.hostname
    $user = $server.user
    $path = $server.path
    
    Write-Status "Connecting to ${user}@${serverHost}..."
    
    # SSH and run git pull
    ssh "${user}@${serverHost}" "cd $path; git pull origin main; composer install --no-dev --optimize-autoloader"
    
    if ($LASTEXITCODE -eq 0) {
        Write-Status "✅ Hawks Nest pulled latest code successfully" "Green"
    } else {
        Write-Status "❌ Hawks Nest git pull failed" "Red"
    }
}

function Trigger-Location2 {
    Write-Status "Triggering git pull on Hawk Moon..." "Yellow"
    
    $server = $config.servers.hawkmoon
    
    if ($server.hostname -eq "TBD") {
        Write-Status "⚠️  Hawk Moon not configured yet. Update deploy.config.json" "Yellow"
        return
    }
    
    $serverHost = $server.hostname
    $user = $server.user
    $path = $server.path
    
    Write-Status "Connecting to ${user}@${serverHost}..."
    ssh "${user}@${serverHost}" "cd $path; git pull origin main; composer install --no-dev --optimize-autoloader"
    
    if ($LASTEXITCODE -eq 0) {
        Write-Status "✅ Hawk Moon pulled latest code successfully" "Green"
    } else {
        Write-Status "❌ Hawk Moon git pull failed" "Red"
    }
}

function Trigger-Location3 {
    Write-Status "Triggering git pull on Zip..." "Yellow"
    
    $server = $config.servers.zip
    
    if ($server.hostname -eq "TBD") {
        Write-Status "⚠️  Zip server not configured yet. Update deploy.config.json" "Yellow"
        return
    }
    
    $serverHost = $server.hostname
    $user = $server.user
    $path = $server.path
    
    Write-Status "Connecting to ${user}@${serverHost}..."
    ssh "${user}@${serverHost}" "cd $path; git pull origin main; composer install --no-dev --optimize-autoloader"
    
    if ($LASTEXITCODE -eq 0) {
        Write-Status "✅ Zip server pulled latest code successfully" "Green"
    } else {
        Write-Status "❌ Zip server git pull failed" "Red"
    }
}

# Main deployment logic
Write-Status "🚀 ACPS v3.5.0 Remote Git Pull Trigger" "Magenta"
Write-Status "Target: $Target" "Cyan"
Write-Status "Servers pull from: alleycatphoto/acps-server" "DarkGray"
Write-Status ""

switch ($Target) {
    "hawksnest" { Trigger-HawksNest }
    "hawkmoon" { Trigger-Location2 }
    "zip" { Trigger-Location3 }
    "all" {
        Trigger-HawksNest
        Write-Status ""
        Trigger-Location2
        Write-Status ""
        Trigger-Location3
    }
}

Write-Status ""
Write-Status "🎉 Git pull trigger complete!" "Green"
Write-Status "NOTE: GitHub Actions auto-triggers these on push to built-responsive/ACPS-8.0 main" "Cyan"
