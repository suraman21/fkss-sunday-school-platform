import 'package:flutter/material.dart';

import '../../services/app_update_service.dart';
import '../../services/device_tier_service.dart';
import '../../services/session_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';

/// Banner and Profile share this route and, more importantly, one operation.
/// Prevent rapid taps from stacking duplicate detail routes.
class AppUpdatesNavigation {
  static bool _open = false;

  static Future<void> open(BuildContext context,
      {AppUpdateService? service}) async {
    if (_open) return;
    _open = true;
    try {
      await Navigator.of(context).push<void>(MaterialPageRoute(
        builder: (_) => UpdateScreen(service: service),
      ));
    } finally {
      _open = false;
    }
  }
}

class UpdateScreen extends StatelessWidget {
  final bool blocking;
  final AppUpdateService? service;
  const UpdateScreen({super.key, this.blocking = false, this.service});

  Future<void> _check(AppUpdateService svc) async {
    await svc.check();
    // A check also controls background transmission; release the temporary
    // hold even on failure. Successful config changes reach shell/root listeners.
    SessionCoordinator().reconcileReleaseGates();
  }

  Future<void> _download(AppUpdateService svc) async {
    try {
      await svc.downloadApk();
    } on AppUpdateFailure {
      // Safe stage-specific error is published by the shared operation.
    }
  }

  String _headline(AppUpdateService svc, bool required) {
    if (required) return 'Update required to continue';
    if (svc.isDownloading) return svc.statusLabel;
    if (svc.hasReadyDownload) return 'Ready to install';
    if (svc.decision.any) return 'Update available';
    if (svc.isChecking) return 'Checking for updates…';
    if (svc.checkFailed) return 'Couldn’t check for updates';
    if (svc.lastSuccessfulCheck == null) return 'Check for FKSS updates';
    if (!svc.hasVersionInformation) {
      return 'Version information unavailable';
    }
    return 'You’re up to date';
  }

  String _size(int bytes) => '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';

  @override
  Widget build(BuildContext context) {
    final svc = service ?? AppUpdateService();
    return AnimatedBuilder(
      animation: svc,
      builder: (context, _) {
        final required = blocking || svc.decision.force;
        final cfg = svc.config;
        final busy = svc.isDownloading || svc.isOpeningInstaller;
        final artifact = AppUpdateService.artifactFor(
          cfg,
          DeviceTierService.instance.info?.primaryAbi,
        );
        final checked = svc.lastSuccessfulCheck;
        return PopScope(
          canPop: !required,
          child: Scaffold(
            appBar: AppBar(
              title: const Text('App updates'),
              automaticallyImplyLeading: !required,
              actions: [
                TextButton(
                  onPressed: busy || svc.isChecking ? null : () => _check(svc),
                  style: TextButton.styleFrom(foregroundColor: Colors.white),
                  child: Text(svc.isChecking ? 'Checking…' : 'Check again'),
                ),
              ],
            ),
            body: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                Icon(
                  svc.hasReadyDownload
                      ? Icons.verified_outlined
                      : Icons.system_update_alt_rounded,
                  size: 48,
                  color: AppTheme.primary,
                ),
                const SizedBox(height: 16),
                Semantics(
                  header: true,
                  child: Text(
                    _headline(svc, required),
                    style: const TextStyle(
                        fontSize: 22, fontWeight: FontWeight.w700),
                    textAlign: TextAlign.center,
                  ),
                ),
                const SizedBox(height: 20),
                Card(
                    child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                            'Installed: ${AppConfig.appVersion} (${AppConfig.appBuild})'),
                        if (svc.decision.any && cfg != null) ...[
                          const SizedBox(height: 8),
                          Text(
                              'Available: ${cfg.latestVersion} (${cfg.latestBuild})'),
                          if ((artifact?.sizeBytes ?? 0) > 0) ...[
                            const SizedBox(height: 8),
                            Text(
                                'Expected download: ${_size(artifact!.sizeBytes)}'),
                          ],
                        ],
                        const SizedBox(height: 8),
                        Text(
                          checked == null
                              ? 'No successful version check yet'
                              : 'Last successful check: ${MaterialLocalizations.of(context).formatShortDate(checked.toLocal())}, '
                                  '${MaterialLocalizations.of(context).formatTimeOfDay(TimeOfDay.fromDateTime(checked.toLocal()))}',
                          style: const TextStyle(
                              fontSize: 12, color: AppTheme.textSecondary),
                        ),
                      ]),
                )),
                if (svc.checkFailed) ...[
                  const SizedBox(height: 12),
                  const Text(
                      'Could not refresh version information. Check your connection and try again. Any earlier information shown here is not a fresh confirmation.'),
                ],
                if (svc.isChecking) ...[
                  const SizedBox(height: 16),
                  const LinearProgressIndicator(
                      semanticsLabel: 'Checking for updates'),
                ],
                if (svc.decision.any &&
                    (!svc.isDownloading || svc.downloadTargetsCurrentRelease) &&
                    (cfg?.releaseNotes.trim().isNotEmpty ?? false)) ...[
                  const SizedBox(height: 20),
                  const Text('What’s new',
                      style: TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  Text(cfg!.releaseNotes, style: const TextStyle(height: 1.5)),
                ],
                const SizedBox(height: 24),
                if (svc.isDownloading) ...[
                  LinearProgressIndicator(
                    value: svc.downloadPhase == AppDownloadPhase.verifying
                        ? null
                        : svc.downloadProgress,
                    semanticsLabel:
                        svc.downloadPhase == AppDownloadPhase.verifying
                            ? 'Verifying downloaded file'
                            : 'Downloading update',
                    semanticsValue: svc.downloadProgress == null
                        ? null
                        : '${(svc.downloadProgress! * 100).floor()} percent',
                  ),
                  const SizedBox(height: 12),
                  Text(svc.statusLabel),
                  if (svc.transferConfig != null)
                    Text('Downloading: ${svc.transferConfig!.latestVersion} '
                        '(${svc.transferConfig!.latestBuild})'),
                  if (!svc.downloadTargetsCurrentRelease)
                    const Text(
                        'A newer release is available. The older transfer will stop; download the latest update afterward.'),
                  if (svc.totalBytes > 0)
                    Text(
                        '${_size(svc.receivedBytes)} of ${_size(svc.totalBytes)}'),
                  const SizedBox(height: 8),
                  const Text(
                      'Keep FKSS open while downloading. Returning to this page shows the same operation; continuation after closing the app is not guaranteed.',
                      style: TextStyle(
                          fontSize: 12, color: AppTheme.textSecondary)),
                ] else if (svc.hasReadyDownload) ...[
                  FilledButton.icon(
                    onPressed:
                        svc.isOpeningInstaller ? null : svc.installReadyApk,
                    icon: const Icon(Icons.install_mobile_outlined),
                    label: Text(svc.isOpeningInstaller
                        ? 'Opening installation…'
                        : 'Install update'),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                      'Android may ask for permission and confirmation. Installation may close FKSS. Downloading alone does not install the update.',
                      style: TextStyle(
                          fontSize: 12, color: AppTheme.textSecondary)),
                ] else if (svc.canDownload) ...[
                  FilledButton.icon(
                    onPressed:
                        svc.isChecking || busy ? null : () => _download(svc),
                    icon: const Icon(Icons.download_rounded),
                    label: Text(svc.downloadPhase == AppDownloadPhase.failed
                        ? 'Download again'
                        : 'Download update'),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                      'Mobile data charges may apply. If interrupted, this version restarts the download; saved-progress resume is not yet supported.',
                      style: TextStyle(
                          fontSize: 12, color: AppTheme.textSecondary)),
                ] else if (svc.decision.any) ...[
                  const Text(
                      'No Android update file is available for this device. Contact the administrator for a compatible release.'),
                ] else if (!svc.isChecking) ...[
                  OutlinedButton.icon(
                    onPressed: () => _check(svc),
                    icon: const Icon(Icons.refresh),
                    label: const Text('Check for updates'),
                  ),
                ],
                if (svc.downloadError != null) ...[
                  const SizedBox(height: 16),
                  Semantics(
                      liveRegion: true,
                      child: Text(svc.downloadError!,
                          style: const TextStyle(color: AppTheme.danger))),
                ],
                if (svc.installMessage != null) ...[
                  const SizedBox(height: 16),
                  Semantics(liveRegion: true, child: Text(svc.installMessage!)),
                ],
              ],
            ),
          ),
        );
      },
    );
  }
}
