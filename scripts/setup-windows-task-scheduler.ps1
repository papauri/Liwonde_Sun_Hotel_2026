# Windows Task Scheduler Setup for Cache Clearing
# Run this script as Administrator to set up automatic cache clearing

# Configuration
$ScriptPath = Join-Path $PSScriptRoot "scheduled-cache-clear.php"
$ProjectPath = Split-Path $PSScriptRoot -Parent
$TaskName = "Hotel Website - Cache Clearing"
$TaskDescription = "Automatically clears website cache based on admin panel settings"

# Find PHP executable
$PhpPath = (Get-Command php -ErrorAction SilentlyContinue).Source

if (-not $PhpPath) {
    Write-Host "ERROR: PHP not found in PATH. Please install PHP or add it to your PATH." -ForegroundColor Red
    Write-Host "You can download PHP from: https://windows.php.net/download/" -ForegroundColor Yellow
    exit 1
}

Write-Host "==================================================================" -ForegroundColor Cyan
Write-Host "  Hotel Website - Windows Task Scheduler Setup" -ForegroundColor Cyan
Write-Host "==================================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "PHP Path: $PhpPath" -ForegroundColor Green
Write-Host "Script Path: $ScriptPath" -ForegroundColor Green
Write-Host "Project Path: $ProjectPath" -ForegroundColor Green
Write-Host ""

# Check if running as Administrator
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
$isAdmin = $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Please right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    exit 1
}

# Check if task already exists
$existingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

if ($existingTask) {
    Write-Host "Task '$TaskName' already exists." -ForegroundColor Yellow
    $overwrite = Read-Host "Do you want to overwrite it? (Y/N)"
    
    if ($overwrite -eq 'Y' -or $overwrite -eq 'y') {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Existing task removed." -ForegroundColor Green
    } else {
        Write-Host "Setup cancelled." -ForegroundColor Yellow
        exit 0
    }
}

# Create scheduled task action
$action = New-ScheduledTaskAction `
    -Execute $PhpPath `
    -Argument "`"$ScriptPath`"" `
    -WorkingDirectory $ProjectPath

# Create trigger to run every 1 minute (works for all interval types)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration ([System.TimeSpan]::MaxValue)

# Create task settings
$settings = New-ScheduledTaskSettings `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false `
    -MultipleInstances Parallel `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

# Create principal to run under current user
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType S4U -RunLevel Highest

# Register the scheduled task
try {
    Register-ScheduledTask `
        -TaskName $TaskName `
        -Description $TaskDescription `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Force
    
    Write-Host ""
    Write-Host "==================================================================" -ForegroundColor Green
    Write-Host "  SUCCESS! Task Scheduler has been configured." -ForegroundColor Green
    Write-Host "==================================================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Task Name: $TaskName" -ForegroundColor Cyan
    Write-Host "Runs Every: 1 minute" -ForegroundColor Cyan
    Write-Host "Script: $ScriptPath" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "The script will check your admin panel settings and run cache" -ForegroundColor Yellow
    Write-Host "clearing based on the interval you configured (30sec, 1min, etc)." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "To modify settings, go to: Admin Panel > Cache Management" -ForegroundColor Yellow
    Write-Host "To view logs, check: logs/cache-clear.log" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Task Scheduler Commands:" -ForegroundColor Cyan
    Write-Host "  View task:   Get-ScheduledTask -TaskName '$TaskName'" -ForegroundColor Gray
    Write-Host "  Remove task: Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false" -ForegroundColor Gray
    Write-Host "  Run now:     Start-ScheduledTask -TaskName '$TaskName'" -ForegroundColor Gray
    Write-Host ""
    
    # Test the task
    Write-Host "Testing the task..." -ForegroundColor Yellow
    Start-ScheduledTask -TaskName $TaskName
    Start-Sleep -Seconds 3
    
    if (Test-Path "$ProjectPath\logs\cache-clear.log") {
        Write-Host "SUCCESS! Log file created. Last entries:" -ForegroundColor Green
        Get-Content "$ProjectPath\logs\cache-clear.log" -Tail 5
    } else {
        Write-Host "Note: Log file not yet created. Check admin panel settings." -ForegroundColor Yellow
    }
    
} catch {
    Write-Host ""
    Write-Host "ERROR: Failed to create scheduled task." -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
