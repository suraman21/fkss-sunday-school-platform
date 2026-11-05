# ──────────────────────────────────────────────────────────────────────
# FKSS release build script (P65) — build and verify candidate APKs.
# Owner signing continuity and representative-device acceptance remain required. Run from Mobile/wbws_flutter_app:
#
#   powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1
#
# Optional switches:
#   -SplitPerAbi   also build arm64-v8a / armeabi-v7a APKs (~2x smaller
#                  per device) — host them via apk_arm64_path /
#                  apk_arm32_path in .fkss_app_release.php
#   -SkipTests     skip `flutter test` (only for hotfix iteration!)
#   -Clean         run `flutter clean` first (deterministic, slower)
#
# Output: dist\  with version-stamped artifacts + release-manifest.json
# (size + SHA-256 each) + hosting instructions. See docs/RELEASE.md.
# ──────────────────────────────────────────────────────────────────────
param(
    [switch]$SplitPerAbi,
    [switch]$SkipTests,
    [switch]$Clean
)

$ErrorActionPreference = 'Stop'

function Assert-Flutter {
    if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
        throw 'flutter is not on PATH. Open a terminal where "flutter --version" works and retry.'
    }
}

function Invoke-Step {
    param([string]$Label, [scriptblock]$Block)
    Write-Host ""
    Write-Host "==> $Label" -ForegroundColor Cyan
    & $Block
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed (exit $LASTEXITCODE)."
    }
}

Assert-Flutter
# Python3.11+ is build tooling only. Missing SDK/Python must fail, never skip
# actual artifact checks. Existing signing-key selection remains unchanged.
$python = Get-Command py, python, python3 -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $python) { throw 'Python3.11+ is required for scripts/verify_android_apk.py.' }
$pythonArgs = if ($python.Name -match '^py(\.exe)?$') { @('-3') } else { @() }

# ── 0. Record the toolchain (reproducibility: the APK's behavior depends
#       on the Flutter/Gradle versions that built it) ───────────────────
$toolchain = (& flutter --version) -join "`n"
Write-Host $toolchain
$flutterLine = ($toolchain -split "`n" | Where-Object { $_ -match '^Flutter ' } | Select-Object -First 1)
$flutterVersion = if ($flutterLine) { $flutterLine.Trim() } else { 'unknown' }

# ── 1. Version from pubspec.yaml (single source of truth) ─────────────
$versionLine = (Select-String -Path pubspec.yaml -Pattern '^version:\s*(.+)$' | Select-Object -First 1).Matches[0].Groups[1].Value.Trim()
if (-not $versionLine) { throw 'Could not read version from pubspec.yaml' }
$parts = $versionLine -split '\+'
$appVersion = $parts[0]
$appBuild = if ($parts.Count -gt 1) { $parts[1] } else { '0' }
Write-Host ""
Write-Host "Building FKSS $appVersion (build $appBuild)" -ForegroundColor Green

if ($Clean) { Invoke-Step 'flutter clean' { flutter clean } }
Invoke-Step 'flutter pub get --enforce-lockfile' { flutter pub get --enforce-lockfile }

if (-not $SkipTests) {
    Invoke-Step 'flutter test' { flutter test }
}

# ── 2. Build the universal APK (supported ABIs, not every Android phone) ─
Invoke-Step 'flutter build apk --release (universal)' { flutter build apk --release --target-platform 'android-arm,android-arm64,android-x64' }

$dist = Join-Path (Get-Location) 'dist'
New-Item -ItemType Directory -Force -Path $dist | Out-Null
# Fresh manifest directory per build: dist/<version>+<build>/
$stamp = "$appVersion+$appBuild"
$outDir = Join-Path $dist $stamp
if (Test-Path $outDir) { Remove-Item -Recurse -Force $outDir }
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$artifacts = @()

function Add-Artifact {
    param([string]$Source, [string]$Name, [string]$Kind)
    if (-not (Test-Path $Source)) { throw "Expected build output not found: $Source" }
    $dest = Join-Path $outDir $Name
    $abis = if ($Kind -eq 'universal') { 'armeabi-v7a,arm64-v8a,x86_64' } else { $Kind }
    $reportName = "$Name.compatibility.json"
    Invoke-Step "Verify $Kind APK metadata, native libraries, alignment and signatures" {
        # Resolve provider separators before passing a path to a native tool;
        # this also exercises the owner procedure on Linux PowerShell in CI.
        $resolvedSource = (Resolve-Path $Source).Path
        & $python.Source @pythonArgs scripts/verify_android_apk.py $resolvedSource --abis $abis --version $appVersion --version-code $appBuild --report (Join-Path $outDir $reportName)
    }
    Copy-Item $Source $dest -Force
    $hash = (Get-FileHash -Algorithm SHA256 $dest).Hash.ToLower()
    $script:artifacts += [ordered]@{
        kind    = $Kind
        name    = $Name
        size_mb = [math]::Round((Get-Item $dest).Length / 1MB, 1)
        sha256  = $hash
        compatibility_report = $reportName
    }
    Write-Host ("  {0}  {1} MB  {2}" -f $Name, ([math]::Round((Get-Item $dest).Length / 1MB, 1)), $hash.Substring(0, 16)) -ForegroundColor Yellow
}

Add-Artifact 'build\app\outputs\flutter-apk\app-release.apk' "fkss-$stamp-universal.apk" 'universal'

# ── 3. Optional per-ABI builds (phones then download ~2x less) ────────
if ($SplitPerAbi) {
    Invoke-Step 'flutter build apk --split-per-abi' { flutter build apk --release --split-per-abi }
    Add-Artifact 'build\app\outputs\flutter-apk\app-armeabi-v7a-release.apk' "fkss-$stamp-armeabi-v7a.apk" 'armeabi-v7a'
    Add-Artifact 'build\app\outputs\flutter-apk\app-arm64-v8a-release.apk' "fkss-$stamp-arm64-v8a.apk" 'arm64-v8a'
}

# ── 4. The manifest — what was built, by what, with which hashes ─────
$manifest = [ordered]@{
    app_version = $appVersion
    app_build   = $appBuild
    toolchain   = $flutterVersion
    built_at    = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    artifacts   = $artifacts
}
$manifestPath = Join-Path $outDir 'release-manifest.json'
$manifest | ConvertTo-Json -Depth 4 | Out-File -FilePath $manifestPath -Encoding utf8

Write-Host ""
Write-Host "Done. Artifacts in: $outDir" -ForegroundColor Green
Write-Host ""
Write-Host 'HOSTING (see docs/RELEASE.md for the full checklist):' -ForegroundColor Cyan
Write-Host "  1. Copy fkss-$stamp-universal.apk to the server as the universal file:"
Write-Host '       /home/<user>/fkss_releases/fkss.apk            (apk_path)'
if ($SplitPerAbi) {
    Write-Host '  2. Copy the per-ABI files:'
    Write-Host '       /home/<user>/fkss_releases/fkss-arm64.apk    (apk_arm64_path)'
    Write-Host '       /home/<user>/fkss_releases/fkss-arm32.apk    (apk_arm32_path)'
}
Write-Host '  3. In /home/<user>/.fkss_app_release.php set latest_version / latest_build'
Write-Host "     to '$appVersion' / $appBuild  (min_build only when old phones MUST update)."
Write-Host '  4. Verify: open /app/download in a browser — the file must download,'
Write-Host '     and the app must offer the update within a minute.'
