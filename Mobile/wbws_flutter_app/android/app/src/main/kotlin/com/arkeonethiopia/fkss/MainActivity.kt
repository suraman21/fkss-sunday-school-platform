package com.arkeonethiopia.fkss

import android.app.ActivityManager
import android.content.Context
import android.content.ContentValues
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.provider.Settings
import android.view.WindowManager
import androidx.core.content.FileProvider
// FlutterFragmentActivity (not FlutterActivity): required by
// local_auth so the App Lock can use BiometricPrompt / fingerprint.
// AudioServiceFragmentActivity extends FlutterFragmentActivity and adds
// the audio_service bindings that keep P0 background mezmur playback
// alive while the app is in the background.
import com.ryanheise.audioservice.AudioServiceFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File
import java.io.FileInputStream
import java.io.IOException
import java.lang.ref.WeakReference

class MainActivity : AudioServiceFragmentActivity() {
    private val channelName = "fkss.app/updater"
    private val lockChannelName = "fkss.app/app_lock"
    private val deviceChannelName = "fkss.app/device"
    private val photoSyncChannelName = "fkss.app/photo_sync_service"
    private val noCloudChannelName = "app.fkss.photo/nocloud"
    private val photoExportChannelName = "fkss.app/photo_export"

    /**
     * S3 A.8 — kept so the engine's background-sync channel can be unbound
     * when the engine goes away. A stale channel must never be invoked by a
     * later alarm.
     */
    private var backgroundSyncChannel: MethodChannel? = null

    companion object {
        /**
         * Weak reference to the currently-live Flutter engine, set during
         * configureFlutterEngine and cleared during cleanUpFlutterEngine.
         * Used by PhotoSyncActionReceiver to deliver "stop sync" events
         * from the foreground-service notification back into Dart. WeakRef
         * so we don't leak the engine past its lifecycle.
         */
        @JvmField
        var liveEngine: WeakReference<FlutterEngine>? = null
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        liveEngine = WeakReference(flutterEngine)

        // S3 A.8 — the background sync producer. Both directions of the
        // contract live on this one channel: Dart asks AlarmManager to
        // schedule or cancel an opportunity, and when the alarm fires
        // BackgroundSyncReceiver invokes the Dart entry point back through
        // the same channel. No sync logic is implemented natively; see
        // BackgroundSyncProducer for the architectural rules it obeys.
        //
        // applicationContext, not `this`: the PendingIntent and the alarm
        // must outlive this Activity.
        val syncChannel =
            MethodChannel(flutterEngine.dartExecutor.binaryMessenger, BackgroundSyncProducer.CHANNEL)
        syncChannel.setMethodCallHandler { call, result ->
            BackgroundSyncProducer.handle(applicationContext, call, result)
        }
        BackgroundSyncProducer.attach(syncChannel)
        backgroundSyncChannel = syncChannel

        // P7 — photo sync foreground service (Android 8+). Dart calls
        // start/update/stop; the service holds the process priority and
        // shows an ongoing notification while uploads are running.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, photoSyncChannelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "start" -> {
                        val intent = Intent(applicationContext, PhotoSyncForegroundService::class.java)
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_TITLE,
                            call.argument<String>("title") ?: "Uploading photos")
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_CONTENT,
                            call.argument<String>("content") ?: "Preparing…")
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_PROGRESS,
                            (call.argument<Number>("progress")?.toInt()) ?: 0)
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_MAX,
                            (call.argument<Number>("max")?.toInt()) ?: 0)
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_INDETERMINATE,
                            call.argument<Boolean>("indeterminate") ?: true)
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_PAUSED,
                            call.argument<Boolean>("paused") ?: false)
                        try {
                            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                                applicationContext.startForegroundService(intent)
                            } else {
                                applicationContext.startService(intent)
                            }
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("service_start_failed", e.message, null)
                        }
                    }
                    "update" -> {
                        val intent = Intent(applicationContext, PhotoSyncForegroundService::class.java)
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_TITLE,
                            call.argument<String>("title") ?: "Uploading photos")
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_CONTENT,
                            call.argument<String>("content") ?: "")
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_PROGRESS,
                            (call.argument<Number>("progress")?.toInt()) ?: 0)
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_MAX,
                            (call.argument<Number>("max")?.toInt()) ?: 0)
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_INDETERMINATE,
                            call.argument<Boolean>("indeterminate") ?: false)
                        intent.putExtra(PhotoSyncForegroundService.EXTRA_PAUSED,
                            call.argument<Boolean>("paused") ?: false)
                        try {
                            applicationContext.startService(intent)
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("service_update_failed", e.message, null)
                        }
                    }
                    "stop" -> {
                        try {
                            applicationContext.stopService(
                                Intent(applicationContext, PhotoSyncForegroundService::class.java)
                            )
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("service_stop_failed", e.message, null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }

        // P7 — no-cloud-backup exclusion hook (app-support is already
        // excluded on default Android configs, but the explicit attribute
        // is defense-in-depth; iOS side implements this via NSURL
        // resourceValues below in the AppDelegate).
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, noCloudChannelName)
            .setMethodCallHandler { call, result ->
                if (call.method == "excludeFromBackup") {
                    val path = call.argument<String>("path")
                    if (path.isNullOrBlank()) { result.success(false); return@setMethodCallHandler }
                    try {
                        val dir = File(path)
                        if (dir.exists()) {
                            // Android: files in app-private dir (data/data/<pkg>)
                            // are NOT auto-backed-up when android:allowBackup="false"
                            // which is already set in the manifest. We set no-menu
                            // as an extra marker. Nothing to do natively here.
                            result.success(true)
                        } else {
                            result.success(false)
                        }
                    } catch (e: Exception) {
                        result.error("exclude_failed", e.message, null)
                    }
                } else {
                    result.notImplemented()
                }
            }

        // ZIP airlock export: save a copy to public Downloads/FKSS so the
        // operator can find it in any file manager and copy it by USB.
        // Android 10+ needs no permission (MediaStore RELATIVE_PATH);
        // older releases use the legacy public path, which needs
        // WRITE_EXTERNAL_STORAGE (manifest, maxSdk 28) — without the
        // grant this returns save_failed and Dart keeps share-only
        // behavior instead of failing the export.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, photoExportChannelName)
            .setMethodCallHandler { call, result ->
                if (call.method == "saveZipToDownloads") {
                    val path = call.argument<String>("path")
                    val fileName = call.argument<String>("fileName")
                    if (path.isNullOrBlank() || fileName.isNullOrBlank()) {
                        result.error("bad_args", "path and fileName are required", null)
                        return@setMethodCallHandler
                    }
                    try {
                        result.success(saveZipToPublicDownloads(path, fileName))
                    } catch (e: Exception) {
                        result.error("save_failed", e.message, null)
                    }
                } else {
                    result.notImplemented()
                }
            }

        // P65 — device capability snapshot (ABI, RAM class, OS): drives
        // the low-end device tier (image-cache budgets) and the ABI-aware
        // update download, and surfaces in Profile → Diagnostics. Read
        // only; nothing here is sensitive.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, deviceChannelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "info" -> {
                        try {
                            val am = getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
                            val mi = ActivityManager.MemoryInfo()
                            am.getMemoryInfo(mi)
                            result.success(
                                mapOf(
                                    "abi" to (Build.SUPPORTED_ABIS.firstOrNull() ?: ""),
                                    "abis" to Build.SUPPORTED_ABIS.toList(),
                                    "totalRamMb" to (mi.totalMem / (1024L * 1024L)).toInt(),
                                    "isLowRam" to am.isLowRamDevice,
                                    "sdkInt" to Build.VERSION.SDK_INT,
                                    "release" to (Build.VERSION.RELEASE ?: ""),
                                    "model" to (Build.MODEL ?: ""),
                                    "manufacturer" to (Build.MANUFACTURER ?: ""),
                                )
                            )
                        } catch (e: Exception) {
                            result.error("device_info_failed", e.message, null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }

        // Telegram-style privacy: while a passcode is configured the app
        // content must not appear in the OS recent-apps preview.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, lockChannelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "setSecureFlag" -> {
                        val on = call.argument<Boolean>("on") ?: false
                        if (on) {
                            window.setFlags(
                                WindowManager.LayoutParams.FLAG_SECURE,
                                WindowManager.LayoutParams.FLAG_SECURE
                            )
                        } else {
                            window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                        }
                        result.success(true)
                    }
                    else -> result.notImplemented()
                }
            }
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channelName)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "updateDir" -> {
                        val dir = File(cacheDir, "updates")
                        if (!dir.exists()) dir.mkdirs()
                        result.success(dir.absolutePath)
                    }
                    "canRequestPackageInstalls" -> {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            result.success(packageManager.canRequestPackageInstalls())
                        } else {
                            result.success(true)
                        }
                    }
                    "openInstallPermissionSettings" -> {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            try {
                                val intent = Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES).apply {
                                    data = Uri.parse("package:$packageName")
                                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                }
                                startActivity(intent)
                                result.success(true)
                            } catch (e: Exception) {
                                val fallbackIntent = Intent(Settings.ACTION_SECURITY_SETTINGS).apply {
                                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                }
                                startActivity(fallbackIntent)
                                result.success(true)
                            }
                        } else {
                            result.success(true)
                        }
                    }
                    "installApk" -> {
                        val path = call.argument<String>("path")
                        if (path.isNullOrBlank()) {
                            result.error("bad_path", "Missing path", null)
                            return@setMethodCallHandler
                        }
                        try {
                            val file = File(path)
                            if (!file.exists()) {
                                result.error("missing", "APK not found", null)
                                return@setMethodCallHandler
                            }
                            val uri = FileProvider.getUriForFile(
                                this,
                                "$packageName.fileprovider",
                                file
                            )
                            val intent = Intent(Intent.ACTION_VIEW).apply {
                                setDataAndType(uri, "application/vnd.android.package-archive")
                                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                            }
                            startActivity(intent)
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("install_failed", e.message, null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }
    }

    /**
     * Copies [srcPath] into public Downloads/FKSS via MediaStore and
     * returns the display path shown to the operator
     * ("Downloads/FKSS/<name>"). Bundle names carry a UUID so collisions
     * are near-impossible; the pre-Q branch appends a counter if needed.
     */
    private fun saveZipToPublicDownloads(srcPath: String, fileName: String): String {
        val src = File(srcPath)
        require(src.exists()) { "ZIP not found: $srcPath" }
        val resolver = applicationContext.contentResolver
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val values = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, fileName)
                put(MediaStore.Downloads.MIME_TYPE, "application/zip")
                put(MediaStore.Downloads.RELATIVE_PATH, "Download/FKSS")
            }
            val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values)
                ?: throw IOException("MediaStore refused the download entry")
            resolver.openOutputStream(uri)?.use { out ->
                FileInputStream(src).use { it.copyTo(out) }
            } ?: throw IOException("Cannot open MediaStore output stream")
            return "Downloads/FKSS/$fileName"
        }
        // Pre-Q: MediaStore.DATA with the legacy public path.
        @Suppress("DEPRECATION")
        val legacyDir = File(
            Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS),
            "FKSS",
        )
        if (!legacyDir.exists() && !legacyDir.mkdirs()) {
            throw IOException("Cannot create Downloads/FKSS (storage permission?)")
        }
        val base = fileName.removeSuffix(".zip")
        var name = fileName
        var attempt = 1
        while (File(legacyDir, name).exists() && attempt < 100) {
            name = "$base ($attempt).zip"
            attempt++
        }
        val values = ContentValues().apply {
            @Suppress("DEPRECATION")
            put(MediaStore.Downloads.DATA, File(legacyDir, name).absolutePath)
            put(MediaStore.Downloads.DISPLAY_NAME, name)
            put(MediaStore.Downloads.MIME_TYPE, "application/zip")
        }
        @Suppress("DEPRECATION")
        val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values)
            ?: throw IOException("MediaStore refused the download entry")
        resolver.openOutputStream(uri)?.use { out ->
            FileInputStream(src).use { it.copyTo(out) }
        } ?: throw IOException("Cannot open MediaStore output stream")
        return "Downloads/FKSS/$name"
    }

    /**
     * S3 A.8 — unbind before the engine is destroyed, so an alarm that fires
     * after the app's UI is gone finds no channel and does nothing, instead
     * of invoking a dead isolate.
     */
    override fun cleanUpFlutterEngine(flutterEngine: FlutterEngine) {
        backgroundSyncChannel?.let {
            it.setMethodCallHandler(null)
            BackgroundSyncProducer.detach(it)
        }
        backgroundSyncChannel = null
        // Stop the photo sync foreground service when the engine is torn
        // down (app exited) so we don't leave orphan notifications.
        try {
            applicationContext.stopService(
                Intent(applicationContext, PhotoSyncForegroundService::class.java)
            )
        } catch (_: Throwable) {}
        if (liveEngine?.get() == flutterEngine) liveEngine = null
        super.cleanUpFlutterEngine(flutterEngine)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        // Tapping the photo-sync notification simply brings the existing
        // task to the foreground (the intent already has
        // ACTION_MAIN / CATEGORY_LAUNCHER / SINGLE_TOP so this activity
        // is the one that appears). No explicit Dart routing — the user
        // can open the Sync Panel from the Members tab as usual.
    }
}
