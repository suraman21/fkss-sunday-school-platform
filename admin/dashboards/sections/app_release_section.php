<?php
/**
 * Super Admin — Mobile App Release Management Section
 */
$arProjectRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
require_once $arProjectRoot . '/admin/backend/services/AppReleaseManager.php';
$arRelease = \App\Services\AppReleaseManager::getReleaseInfo($arProjectRoot);
$arFromApk = ($arRelease['version_source'] ?? '') === 'apk';
?>
<style>
/* ═══ App Release UI Custom Styles (High Contrast & Professional Ergonomics) ═══ */
#section-app_release {
    color: #f1f5f9;
}
#section-app_release .ar-card {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 0.75rem;
    padding: 1.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2), 0 2px 4px -2px rgba(0, 0, 0, 0.2);
}
#section-app_release .ar-form-group {
    margin-bottom: 1.25rem;
}
#section-app_release .ar-label {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.825rem;
    font-weight: 600;
    color: #e2e8f0;
    margin-bottom: 0.45rem;
    letter-spacing: 0.01em;
}
#section-app_release .ar-label .req {
    color: #f87171;
    font-weight: bold;
}
#section-app_release .ar-input,
#section-app_release .ar-select,
#section-app_release .ar-textarea {
    width: 100%;
    padding: 0.7rem 0.95rem;
    background-color: #0f172a !important;
    border: 1.5px solid #334155 !important;
    border-radius: 0.5rem;
    color: #f8fafc !important;
    font-size: 0.875rem;
    line-height: 1.5;
    transition: all 0.15s ease-in-out;
    box-sizing: border-box;
}
#section-app_release .ar-input::placeholder,
#section-app_release .ar-textarea::placeholder {
    color: #7c8aa5;
    opacity: 1;
}
#section-app_release .ar-input:focus,
#section-app_release .ar-select:focus,
#section-app_release .ar-textarea:focus {
    outline: none !important;
    border-color: #38bdf8 !important;
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25) !important;
    background-color: #090d16 !important;
}
#section-app_release .ar-select option {
    background-color: #0f172a;
    color: #f8fafc;
    padding: 0.5rem;
}
#section-app_release .ar-help {
    display: block;
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 0.4rem;
    line-height: 1.4;
}
#section-app_release .ar-toggle-card {
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 0.5rem;
    padding: 0.85rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
    transition: border-color 0.15s ease;
}
#section-app_release .ar-toggle-card:hover {
    border-color: #7c8aa5;
}
#section-app_release .ar-toggle-card input[type="checkbox"] {
    width: 1.15rem;
    height: 1.15rem;
    cursor: pointer;
    accent-color: #3b82f6;
    flex-shrink: 0;
}
#section-app_release .ar-toggle-card.warn-box {
    background: rgba(239, 68, 68, 0.08);
    border-color: rgba(239, 68, 68, 0.3);
}
#section-app_release .ar-toggle-card.warn-box:hover {
    border-color: rgba(239, 68, 68, 0.5);
}
#section-app_release .ar-toggle-card.warn-box input[type="checkbox"] {
    accent-color: #ef4444;
}

/* Drop Zone & Resumable Upload Styles */
.apk-drop-box {
    background: #0f172a;
    border: 2px dashed #334155;
    border-radius: 0.75rem;
    padding: 2.25rem 1.25rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
    user-select: none;
}
.apk-drop-box:hover, .apk-drop-box.drag-active {
    border-color: #38bdf8;
    background: #131e36;
    box-shadow: 0 0 15px rgba(56, 189, 248, 0.15);
}
.apk-progress-track {
    width: 100%;
    height: 12px;
    background: #0f172a;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #334155;
    position: relative;
}
.apk-progress-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #38bdf8, #10b981);
    border-radius: 6px;
    transition: width 0.15s ease;
    box-shadow: 0 0 10px rgba(56, 189, 248, 0.5);
}
.apk-stat-pill {
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 0.4rem;
    padding: 0.35rem 0.65rem;
    font-size: 0.75rem;
    color: #cbd5e1;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
</style>

<!-- ═══ MOBILE APP RELEASE MANAGER ═══ -->
<section id="section-app_release" class="section <?= ($activeSection ?? '') === 'app_release' ? 'active' : '' ?>"<?= ($activeSection ?? '') === 'app_release' ? '' : ' hidden' ?>>
    <div class="sec-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem">
        <div>
            <h2 class="sec-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.4rem;color:#f8fafc"><i class="fa-solid fa-mobile-screen-button" style="color:#38bdf8"></i> Mobile App Release Manager</h2>
            <p class="sec-desc" style="color:#94a3b8;font-size:0.875rem">In-app update management, resilient chunked APK upload & version enforcement</p>
        </div>
        <button type="button" class="btn btn-outline btn-sm" onclick="if(window.AppReleaseUI)window.AppReleaseUI.refresh()"><i class="fa-solid fa-rotate"></i> Refresh Status</button>
    </div>

    <div id="app-release-alert-box"></div>

    <!-- Live Status & Upload Grid -->
    <div class="grid-2" style="margin-bottom:1.5rem;gap:1.5rem">
        <!-- Active Release Status Card -->
        <div class="ar-card">
            <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.1rem;color:#f8fafc;margin-bottom:1rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
                <i class="fa-solid fa-circle-info" style="color:#38bdf8"></i> Active Release Status
            </h3>
            <div id="app-release-status-container">
                <div style="display:flex;flex-direction:column;gap:1rem">
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;background:#0f172a;border-radius:0.5rem;border:1px solid #334155;flex-wrap:wrap;gap:0.75rem">
                        <div>
                            <div style="font-size:1.2rem;font-weight:700;color:#f8fafc;letter-spacing:0.02em">
                                Version <?= htmlspecialchars((string)($arRelease['latest_version'] ?? '1.5.1')) ?> 
                                <span style="font-size:.85rem;color:#94a3b8;font-weight:400">(Build <?= htmlspecialchars((string)($arRelease['latest_build'] ?? '25')) ?>)</span>
                            </div>
                            <div style="font-size:.775rem;color:#cbd5e1;margin-top:.3rem">
                                Min required: v<?= htmlspecialchars((string)($arRelease['min_version'] ?? '1.0.0')) ?> (Build <?= htmlspecialchars((string)($arRelease['min_build'] ?? '1')) ?>)
                                <?php if (!empty($arRelease['force_update'])): ?>
                                    • <span style="color:#f87171;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Mandatory Update Gate</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <?php if (!empty($arRelease['download_available'])): ?>
                                <span style="background:rgba(16,185,129,0.15);color:#4ade80;border:1px solid rgba(16,185,129,0.4);padding:.4rem .9rem;border-radius:99px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:.35rem"><i class="fa-solid fa-circle-check"></i> Published</span>
                            <?php else: ?>
                                <span style="background:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.4);padding:.4rem .9rem;border-radius:99px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:.35rem"><i class="fa-solid fa-circle-xmark"></i> No APK Uploaded</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top:.25rem">
                        <div style="font-size:.75rem;font-weight:700;color:#94a3b8;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:0.75px">Active Artifacts</div>
                        <?php 
                        $details = $arRelease['artifacts_detail'] ?? [];
                        if (empty($details)): 
                        ?>
                            <div style="padding:1rem;background:#0f172a;border:1px dashed #334155;border-radius:0.5rem;font-size:.825rem;color:#94a3b8;text-align:center">
                                <i class="fa-solid fa-box-open" style="font-size:1.25rem;display:block;margin-bottom:0.4rem;color:#7c8aa5"></i>No APK binary uploaded yet. Use the upload card on the right to publish a build.
                            </div>
                        <?php else: ?>
                            <?php foreach ($details as $abi => $art): ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;margin-bottom:.5rem;background:#0f172a;border-radius:.5rem;border:1px solid #334155;border-left:4px solid #38bdf8">
                                    <div style="min-width:0;flex:1">
                                        <div style="font-size:.85rem;font-weight:600;color:#f8fafc;display:flex;align-items:center;gap:.45rem">
                                            <i class="fa-solid fa-cube" style="color:#38bdf8;font-size:.8rem"></i> <?= htmlspecialchars($abi === 'universal' ? 'Universal APK (All devices)' : ($abi === 'arm64-v8a' ? 'ARM64-v8a (64-bit split)' : 'ARMeabi-v7a (32-bit legacy)')) ?>
                                            <span style="font-size:.75rem;color:#94a3b8;font-weight:400">(<?= htmlspecialchars($art['size_formatted'] ?? '') ?>)</span>
                                        </div>
                                        <div style="font-size:.7rem;color:#cbd5e1;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.2rem" title="SHA-256: <?= htmlspecialchars($art['sha256'] ?? '') ?>">
                                            <span style="color:#7c8aa5">SHA-256:</span> <?= htmlspecialchars($art['sha256'] ?? '') ?>
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:.35rem;margin-left:.75rem">
                                        <button type="button" class="btn btn-outline btn-sm" style="padding:.35rem .6rem;font-size:.75rem;color:#f87171;border-color:rgba(239,68,68,0.4);background:#1e293b" onclick="AppReleaseUI.deleteApk('<?= htmlspecialchars($abi) ?>')" title="Delete artifact"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($arRelease['download_available'])): ?>
                        <div style="margin-top:.75rem;display:flex;gap:.75rem;flex-wrap:wrap">
                            <a href="/api/v1/app/download" target="_blank" class="btn btn-outline btn-sm" style="flex:1;justify-content:center;background:#0f172a;border-color:#334155;color:#e2e8f0;padding:0.6rem 1rem"><i class="fa-solid fa-download" style="color:#38bdf8"></i> Test Direct APK Download</a>
                            <a href="/api/v1/app/config" target="_blank" class="btn btn-outline btn-sm" style="flex:1;justify-content:center;background:#0f172a;border-color:#334155;color:#e2e8f0;padding:0.6rem 1rem"><i class="fa-solid fa-code" style="color:#a78bfa"></i> View Config JSON</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Resumable APK Upload Card -->
        <div class="ar-card">
            <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.1rem;color:#f8fafc;margin-bottom:1rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
                <i class="fa-solid fa-cloud-arrow-up" style="color:#10b981"></i> Upload New APK Binary
            </h3>

            <div class="ar-form-group">
                <label class="ar-label" for="upload-abi-select">
                    <i class="fa-solid fa-microchip" style="color:#94a3b8;font-size:0.75rem"></i> Target Architecture
                </label>
                <select id="upload-abi-select" class="ar-select" onchange="if(window.AppReleaseUI)window.AppReleaseUI.onAbiChange(this.value)">
                    <option value="universal">Universal APK (Recommended — Compatible with all Android devices)</option>
                    <option value="arm64-v8a">ARM64-v8a (64-bit split APK — ~50% smaller download)</option>
                    <option value="armeabi-v7a">ARMeabi-v7a (32-bit legacy split APK)</option>
                </select>
            </div>

            <!-- Upload Engine Container -->
            <div id="apk-upload-engine-box">
                <!-- State 1: Idle Drag & Drop -->
                <div id="upload-idle-state" class="apk-drop-box" onclick="document.getElementById('upload-apk-input').click()">
                    <input type="file" id="upload-apk-input" accept=".apk,application/vnd.android.package-archive" style="display:none" onchange="if(window.AppReleaseUI)window.AppReleaseUI.onFileInputChange(this)" onclick="event.stopPropagation()">
                    <i class="fa-solid fa-file-arrow-up" style="font-size:2.5rem;color:#38bdf8;margin-bottom:0.75rem;display:inline-block"></i>
                    <div style="font-size:0.95rem;font-weight:600;color:#f8fafc">Drag &amp; Drop APK File Here</div>
                    <div style="font-size:0.8rem;color:#94a3b8;margin-top:0.25rem">or click anywhere in this box to browse</div>
                    <div style="margin-top:0.75rem;display:flex;justify-content:center;gap:0.5rem;flex-wrap:wrap">
                        <span class="apk-stat-pill"><i class="fa-solid fa-bolt" style="color:#38bdf8"></i> Resumable Chunks (2MB)</span>
                        <span class="apk-stat-pill"><i class="fa-solid fa-shield-halved" style="color:#10b981"></i> Auto-Retry Protection</span>
                        <span class="apk-stat-pill"><i class="fa-solid fa-server" style="color:#a78bfa"></i> cPanel Safe</span>
                    </div>
                </div>

                <!-- State 2: File Staged / Preflight -->
                <div id="upload-stage-state" style="display:none;background:#0f172a;border:1px solid #334155;border-radius:0.75rem;padding:1.25rem">
                    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem">
                        <i class="fa-solid fa-file-lines" style="font-size:2rem;color:#38bdf8"></i>
                        <div style="min-width:0;flex:1">
                            <div id="stage-file-name" style="font-weight:700;color:#f8fafc;font-size:0.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">fkss.apk</div>
                            <div style="font-size:0.775rem;color:#94a3b8;margin-top:0.2rem">
                                Size: <span id="stage-file-size" style="color:#e2e8f0;font-weight:600">--</span> • 
                                Plan: <span id="stage-file-chunks" style="color:#38bdf8">--</span> • 
                                Target: <span id="stage-file-abi" style="color:#a78bfa">--</span>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;gap:0.75rem">
                        <button type="button" id="btn-start-upload" class="btn btn-primary" onclick="AppReleaseUI.startUpload()" style="flex:2;justify-content:center;padding:0.65rem 1rem">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Start Reliable Upload
                        </button>
                        <button type="button" class="btn btn-outline" onclick="AppReleaseUI.resetUploadState()" style="flex:1;justify-content:center;background:#1e293b;border-color:#334155;color:#e2e8f0">
                            Cancel
                        </button>
                    </div>
                </div>

                <!-- State 3: Upload In Progress -->
                <div id="upload-progress-state" style="display:none;background:#0f172a;border:1px solid #334155;border-radius:0.75rem;padding:1.25rem">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
                        <span id="upload-status-title" style="font-size:0.875rem;font-weight:600;color:#f8fafc">
                            <i class="fa-solid fa-spinner fa-spin" style="color:#38bdf8"></i> Uploading APK in Safe Chunks...
                        </span>
                        <span id="upload-pct-display" style="font-size:1.1rem;font-weight:700;color:#38bdf8">0%</span>
                    </div>

                    <div class="apk-progress-track" style="margin-bottom:0.85rem">
                        <div id="upload-progress-fill" class="apk-progress-fill"></div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;font-size:0.775rem;color:#cbd5e1;margin-bottom:0.85rem;background:#1e293b;padding:0.65rem 0.85rem;border-radius:0.5rem">
                        <div>Transfer: <span id="upload-bytes-display" style="font-weight:600;color:#f8fafc">-- / --</span></div>
                        <div style="text-align:right">Progress: <span id="upload-chunk-display" style="font-weight:600;color:#38bdf8">Chunk --</span></div>
                        <div>Speed: <span id="upload-speed-display" style="font-weight:600;color:#10b981">-- MB/s</span></div>
                        <div style="text-align:right">Time Remaining: <span id="upload-eta-display" style="font-weight:600;color:#e2e8f0">--</span></div>
                    </div>

                    <div id="upload-status-subtext" style="font-size:0.75rem;color:#94a3b8;margin-bottom:0.85rem;min-height:1rem">
                        Automatic retry enabled for cPanel network tolerance.
                    </div>

                    <button type="button" class="btn btn-outline btn-sm" onclick="AppReleaseUI.cancelUpload()" style="width:100%;justify-content:center;background:#1e293b;border-color:rgba(239,68,68,0.4);color:#f87171">
                        <i class="fa-solid fa-xmark"></i> Cancel Upload
                    </button>
                </div>

                <!-- State 4: Server Assembling & Checksum Hash -->
                <div id="upload-assembling-state" style="display:none;background:#0f172a;border:1px solid #334155;border-radius:0.75rem;padding:1.5rem;text-align:center">
                    <i class="fa-solid fa-arrows-spin fa-spin" style="font-size:2.5rem;color:#a78bfa;margin-bottom:0.85rem;display:inline-block"></i>
                    <div style="font-size:1rem;font-weight:700;color:#f8fafc">Assembling APK on Server</div>
                    <div style="font-size:0.8rem;color:#cbd5e1;margin-top:0.4rem;max-width:380px;margin-left:auto;margin-right:auto">
                        Merging <span id="assemble-chunks-count" style="color:#38bdf8;font-weight:700">--</span> chunks, writing storage, and computing cryptographic SHA-256 sidecar...
                    </div>
                </div>

                <!-- State 5: Success State -->
                <div id="upload-success-state" style="display:none;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.4);border-radius:0.75rem;padding:1.25rem">
                    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.85rem">
                        <i class="fa-solid fa-circle-check" style="font-size:2rem;color:#4ade80"></i>
                        <div>
                            <div style="font-weight:700;color:#f8fafc;font-size:0.95rem">APK Published Successfully!</div>
                            <div id="success-apk-version" style="font-size:0.775rem;color:#cbd5e1;margin-top:0.15rem">--</div>
                        </div>
                    </div>
                    <div style="font-size:0.75rem;color:#cbd5e1;background:#0f172a;padding:0.6rem 0.8rem;border-radius:0.4rem;border:1px solid #334155;margin-bottom:1rem">
                        <div>File: <span id="success-apk-name" style="font-weight:600;color:#f8fafc">--</span> (<span id="success-apk-size">--</span>)</div>
                        <div id="success-apk-sha" style="font-family:monospace;font-size:0.7rem;color:#94a3b8;word-break:break-all;margin-top:0.25rem"></div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="AppReleaseUI.resetUploadState()" style="width:100%;justify-content:center;background:#0f172a;border-color:#334155;color:#e2e8f0">
                        <i class="fa-solid fa-upload"></i> Upload Another Build
                    </button>
                </div>

                <!-- State 6: Error State -->
                <div id="upload-error-state" style="display:none;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.4);border-radius:0.75rem;padding:1.25rem">
                    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.85rem">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size:2rem;color:#f87171"></i>
                        <div>
                            <div style="font-weight:700;color:#f87171;font-size:0.95rem">Upload Interrupted</div>
                            <div id="upload-error-message" style="font-size:0.775rem;color:#cbd5e1;margin-top:0.2rem">An error occurred during upload.</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:0.75rem">
                        <button type="button" class="btn btn-primary" onclick="AppReleaseUI.startUpload()" style="flex:1;justify-content:center;background:#ef4444;border-color:#ef4444">
                            <i class="fa-solid fa-rotate-right"></i> Retry Upload
                        </button>
                        <button type="button" class="btn btn-outline" onclick="AppReleaseUI.resetUploadState()" style="flex:1;justify-content:center;background:#0f172a;border-color:#334155;color:#e2e8f0">
                            Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Version Policy & Announcement Form -->
    <div class="ar-card" style="margin-bottom:1.5rem">
        <h3 class="card-title" style="display:flex;align-items:center;gap:0.5rem;font-size:1.1rem;color:#f8fafc;margin-bottom:0.75rem;border-bottom:1px solid #334155;padding-bottom:0.75rem">
            <i class="fa-solid fa-sliders" style="color:#a78bfa"></i> Version Policy &amp; Update Settings
        </h3>
        
        <form id="form-release-config" onsubmit="AppReleaseUI.saveConfig(event)">
            <?= csrfField() ?>
            <div class="grid-2" style="gap:1.5rem;margin-bottom:0.5rem">
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-latest-version">
                        <i class="fa-solid fa-tag" style="color:#94a3b8;font-size:0.75rem"></i> Latest Version String <span class="req">*</span>
                    </label>
                    <input type="text" name="latest_version" id="cfg-latest-version" class="ar-input" value="<?= htmlspecialchars((string)($arRelease['latest_version'] ?? '1.5.1')) ?>" placeholder="1.5.1" required<?= $arFromApk ? ' readonly' : '' ?>>
                    <span class="ar-help" id="cfg-version-source"><?= $arFromApk
                        ? 'Read from the published APK (' . htmlspecialchars((string)($arRelease['apk_package'] ?? '')) . '). Upload a new APK to change it.'
                        : 'No readable APK is published, so this typed value is used.' ?></span>
                </div>
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-latest-build">
                        <i class="fa-solid fa-code-commit" style="color:#94a3b8;font-size:0.75rem"></i> Latest Build Number (VersionCode) <span class="req">*</span>
                    </label>
                    <input type="number" name="latest_build" id="cfg-latest-build" class="ar-input" value="<?= htmlspecialchars((string)($arRelease['latest_build'] ?? '25')) ?>" placeholder="25" min="1" required<?= $arFromApk ? ' readonly' : '' ?>>
                    <span class="ar-help">Build number inside the APK (versionCode).</span>
                </div>
            </div>

            <div class="grid-2" style="gap:1.5rem;margin-bottom:0.5rem">
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-min-version">
                        <i class="fa-solid fa-shield" style="color:#94a3b8;font-size:0.75rem"></i> Minimum Required Version
                    </label>
                    <input type="text" name="min_version" id="cfg-min-version" class="ar-input" value="<?= htmlspecialchars((string)($arRelease['min_version'] ?? '1.0.0')) ?>" placeholder="1.0.0">
                    <span class="ar-help">Devices running older versions will be blocked until updated</span>
                </div>
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-min-build">
                        <i class="fa-solid fa-shield-halved" style="color:#94a3b8;font-size:0.75rem"></i> Minimum Required Build Code
                    </label>
                    <input type="number" name="min_build" id="cfg-min-build" class="ar-input" value="<?= htmlspecialchars((string)($arRelease['min_build'] ?? '1')) ?>" placeholder="1" min="1">
                    <span class="ar-help">Devices below this build number will be blocked until updated</span>
                </div>
            </div>

            <div class="ar-form-group">
                <label class="ar-toggle-card warn-box" for="cfg-force-update">
                    <input type="checkbox" name="force_update" id="cfg-force-update" value="1" <?= !empty($arRelease['force_update']) ? 'checked' : '' ?>>
                    <div>
                        <div style="font-size:0.875rem;font-weight:600;color:#f87171;display:flex;align-items:center;gap:0.4rem">
                            <i class="fa-solid fa-triangle-exclamation"></i> Enforce Mandatory Update Gate
                        </div>
                        <div style="font-size:0.75rem;color:#cbd5e1;margin-top:0.15rem">
                            When enabled, displays a full-screen blocking modal on all older app versions preventing further usage until the update is installed.
                        </div>
                    </div>
                </label>
            </div>

            <div class="ar-form-group">
                <label class="ar-label" for="cfg-release-notes">
                    <i class="fa-solid fa-file-lines" style="color:#94a3b8;font-size:0.75rem"></i> Release Notes / Changelog
                </label>
                <textarea name="release_notes" id="cfg-release-notes" class="ar-textarea" rows="4" placeholder="• Faster QR attendance&#10;• Offline sync improvements&#10;• Bug fixes in Mezmur player"><?= htmlspecialchars((string)($arRelease['release_notes'] ?? '')) ?></textarea>
                <span class="ar-help">Shown directly to teachers and students on the in-app update prompt</span>
            </div>

            <div class="grid-2" style="gap:1.5rem;margin-bottom:0.5rem">
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-banner-text">
                        <i class="fa-solid fa-bullhorn" style="color:#94a3b8;font-size:0.75rem"></i> In-App Top Banner Announcement
                    </label>
                    <input type="text" name="banner_text" id="cfg-banner-text" class="ar-input" value="<?= htmlspecialchars((string)($arRelease['banner_text'] ?? '')) ?>" placeholder="New update available with offline sync improvements!">
                    <span class="ar-help">Optional non-intrusive alert banner shown at top of the mobile home screen</span>
                </div>
                <div class="ar-form-group">
                    <label class="ar-label" for="cfg-banner-kind">
                        <i class="fa-solid fa-palette" style="color:#94a3b8;font-size:0.75rem"></i> Banner Tone / Style
                    </label>
                    <select name="banner_kind" id="cfg-banner-kind" class="ar-select">
                        <option value="info" <?= ($arRelease['banner_kind'] ?? 'info') === 'info' ? 'selected' : '' ?>>Information (Blue)</option>
                        <option value="warn" <?= ($arRelease['banner_kind'] ?? '') === 'warn' ? 'selected' : '' ?>>Warning / Alert (Amber)</option>
                    </select>
                    <span class="ar-help">Controls the accent color and icon of the in-app announcement banner</span>
                </div>
            </div>

            <div class="ar-form-group" style="margin-bottom:1.5rem">
                <label class="ar-toggle-card" for="cfg-drains-enabled">
                    <input type="checkbox" name="background_drains_enabled" id="cfg-drains-enabled" value="1" <?= ($arRelease['background_drains_enabled'] ?? true) !== false ? 'checked' : '' ?>>
                    <div>
                        <div style="font-size:0.875rem;font-weight:600;color:#e2e8f0;display:flex;align-items:center;gap:0.4rem">
                            <i class="fa-solid fa-cloud-arrow-up" style="color:#38bdf8"></i> Enable Background Outbox Sync Drains
                        </div>
                        <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.15rem">
                            Allows mobile devices to sync offline queues. Uncheck only during active backend maintenance.
                        </div>
                    </div>
                </label>
            </div>

            <div style="display:flex;justify-content:flex-end">
                <button type="submit" id="btn-save-cfg" class="btn btn-primary" style="padding:0.75rem 1.75rem;font-size:0.875rem"><i class="fa-solid fa-floppy-disk"></i> Save Release Policy</button>
            </div>
        </form>
    </div>
</section>
