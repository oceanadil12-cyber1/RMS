# Builds the hand-off folder for the web developer: one ZIP with exactly the files
# that go into public_html on the host, plus the go-live guide.
# The owner's settings (admin password, emails, chat keys) go in as
# api/site-settings.php, made from this computer's api/config.php, so the server
# only has to connect its database (the admin panel asks for it once).
# Local data never goes in: api/config.php, api/data/ and api/cache/ are git-ignored,
# and only the website's own folders are taken.
$ErrorActionPreference = 'Stop'
$repo = $PSScriptRoot
$out  = Join-Path (Split-Path $repo -Parent) 'RMS Website - For Developer'
$zip  = Join-Path $out 'rms-website-upload.zip'

# What belongs on the web server: root pages + these folders.
$folders = @('admin/', 'api/', 'css/', 'data/', 'dine-in/', 'fonts/', 'images/', 'js/', 'kds/')
$rootFiles = '^(?:[^/]+\.html|404\.php|\.htaccess|robots\.txt)$'
$never = '(^|/)(config\.php|site-settings\.php|\.config-[^/]*|Thumbs\.db|desktop\.ini|\.DS_Store)$|\.(bak|log|tmp)$|^api/(data|cache|vendor)/'

Push-Location $repo
try {
    # Tracked files plus new ones not yet committed; git-ignored files (secrets, data) are left out.
    $files = @(git -c core.quotepath=off ls-files --cached --others --exclude-standard | Where-Object {
        $f = $_
        ($f -match $rootFiles) -or (@($folders | Where-Object { $f.StartsWith($_) }).Count -gt 0)
    } | Where-Object { $_ -notmatch $never -and (Test-Path -LiteralPath $_ -PathType Leaf) } | Sort-Object)
    # The files the website can't work without: a build missing any of them stops here
    # (api/checkout.php once vanished in an auto-save, which broke online ordering).
    $critical = @('.htaccess', 'index.html', 'menu.html', 'events.html', '404.php', 'api/setup.sql', 'api/lib.php', 'api/db.php',
        'api/menu.php', 'api/checkout.php', 'api/contact.php', 'api/subscribe.php', 'api/chat.php', 'api/dine-in-order.php',
        'api/waiter-call.php', 'api/kds.php', 'api/happy-hour.php', 'api/chat-settings.php', 'admin/index.php', 'admin/inc/setup.php', 'kds/index.php',
        'kds/api.php', 'dine-in/index.php', 'js/order.js', 'js/chat.js', 'js/main.js', 'data/menu.json')
    foreach ($must in $critical) {
        if (-not ($files -contains $must)) { throw "$must is missing: the website needs it. Restore it before building the package." }
    }

    $commit  = (git rev-parse --short HEAD).Trim()
    $version = (git describe --tags --always 2>$null)
    $dirty   = [bool](git status --porcelain)
} finally { Pop-Location }

# Fresh output folder: just the ZIP, the guide and VERSION.txt. Older builds also had an
# unzipped public_html\ folder, and a hand-made public_html.zip (with an extra folder
# inside that put the site one level too deep): both removed so only one thing gets sent.
New-Item -ItemType Directory -Force $out | Out-Null
foreach ($old in 'public_html', 'public_html.zip', 'rms-website-upload.zip') {
    $p = Join-Path $out $old
    if (Test-Path $p) { Remove-Item -Recurse -Force $p }
}

# The owner's settings, from this computer's api/config.php (see make-site-settings.php).
$php = @('C:\xampp\php\php.exe', (Get-Command php -ErrorAction SilentlyContinue).Source) |
    Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
if (-not $php) { throw 'PHP not found: it is needed to build api/site-settings.php.' }
$settings = Join-Path ([System.IO.Path]::GetTempPath()) ('rms-site-settings-' + [guid]::NewGuid() + '.php')

try {
    $keys = & $php (Join-Path $repo 'make-site-settings.php') $settings
    if ($LASTEXITCODE -ne 0) { throw 'Could not build api/site-settings.php (see the message above).' }

    # ZIP with forward-slash paths (what Linux hosts and cPanel's Extract expect), and no
    # folder around the files: extracted in public_html, the site sits at the domain root.
    Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::Open($zip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($f in $files) {
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Join-Path $repo $f), $f, [System.IO.Compression.CompressionLevel]::Optimal)
        }
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $settings, 'api/site-settings.php', [System.IO.Compression.CompressionLevel]::Optimal)
    } finally { $archive.Dispose() }
} finally {
    if (Test-Path $settings) { Remove-Item -Force $settings }
}
$count = $files.Count + 1

Copy-Item -LiteralPath (Join-Path $repo 'docs\GO-LIVE.md') -Destination (Join-Path $out 'HOW-TO-GO-LIVE.md') -Force
$stamp = Get-Date -Format 'yyyy-MM-dd HH:mm'
$unsaved = ''
if ($dirty) { $unsaved = ' + changes not yet saved to GitHub' }
@(
    "RMS website - upload package",
    "Built:   $stamp",
    "Version: $version (commit $commit)$unsaved",
    "Files:   $count",
    "",
    "Extract rms-website-upload.zip in the domain's public_html folder, then open",
    "/admin/ and follow HOW-TO-GO-LIVE.md.",
    "",
    "PRIVATE: the package holds the admin password and the chat API keys",
    "(api/site-settings.php). Send it only to the person putting the site live."
) | Set-Content -Path (Join-Path $out 'VERSION.txt') -Encoding ascii

$mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
Write-Host ''
Write-Host " Package ready: $out" -ForegroundColor Green
Write-Host "   rms-website-upload.zip  $count files, $mb MB (cPanel File Manager -> Upload -> Extract)"
Write-Host '   HOW-TO-GO-LIVE.md         the steps for the developer'
Write-Host "   VERSION.txt               $version, commit $commit"
Write-Host "   Settings included:        $keys"
Write-Host ''
