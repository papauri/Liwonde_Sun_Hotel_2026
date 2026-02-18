# Downloads royalty-free hotel room images from Unsplash Source
# Usage: Run from project root: scripts\download-room-images.ps1
# Requires PowerShell 5+ and internet connection

$ErrorActionPreference = 'Stop'
$targetDir = Join-Path $PSScriptRoot '..\images\rooms'

if (!(Test-Path $targetDir)) {
    New-Item -ItemType Directory -Path $targetDir | Out-Null
}

$images = @{
    'presidential-master.jpg'   = 'https://source.unsplash.com/1200x1200/?hotel,room,bed,luxury';
    'presidential-living.jpg'   = 'https://source.unsplash.com/1200x1200/?hotel,room,living,sofa';
    'presidential-bathroom.jpg' = 'https://source.unsplash.com/1200x1200/?hotel,bathroom,marble';
    'presidential-terrace.jpg'  = 'https://source.unsplash.com/1200x1200/?hotel,terrace,view';

    'executive-bedroom.jpg'     = 'https://source.unsplash.com/1200x1200/?business,hotel,room,desk';
    'executive-work.jpg'        = 'https://source.unsplash.com/1200x1200/?hotel,workspace,desk';
    'executive-lounge.jpg'      = 'https://source.unsplash.com/1200x1200/?hotel,lounge,sofa';
    'executive-bathroom.jpg'    = 'https://source.unsplash.com/1200x1200/?hotel,bathroom,modern';

    'family-main.jpg'           = 'https://source.unsplash.com/1200x1200/?family,hotel,room';
    'family-second.jpg'         = 'https://source.unsplash.com/1200x1200/?kids,bedroom,hotel';
    'family-living.jpg'         = 'https://source.unsplash.com/1200x1200/?family,living,room';
    'family-kitchen.jpg'        = 'https://source.unsplash.com/1200x1200/?kitchenette,hotel,apartment';
}

Write-Host "Downloading room images to $targetDir" -ForegroundColor Cyan

foreach ($name in $images.Keys) {
    $url = $images[$name]
    $dest = Join-Path $targetDir $name
    Write-Host " -> $name" -ForegroundColor Yellow
    Invoke-WebRequest -Uri $url -OutFile $dest
}

Write-Host "Done. Images saved in: $targetDir" -ForegroundColor Green
