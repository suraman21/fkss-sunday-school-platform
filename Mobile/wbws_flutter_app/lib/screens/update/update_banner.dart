import 'package:flutter/material.dart';
import '../../services/app_update_service.dart';
import '../../utils/theme.dart';
import 'update_screen.dart';

/// P65: the optional-update banner — the quiet half of the update UX.
///
/// Forced updates (min_build / force_update) take over the whole app at
/// the root (main.dart). Normal releases appear here: a slim band at the
/// top of the shell until the user updates — or dismisses it for this
/// session (it returns on the next launch, Telegram-style).
///
/// Rebuilds itself from [AppUpdateService.revision] the moment the
/// launch/resume server check completes, so the shell never needs a
/// full rebuild for it (a whole-shell rebuild visibly hitched the
/// kept-alive tabs).
class UpdateBanner extends StatefulWidget {
  final AppUpdateService? service;
  const UpdateBanner({super.key, this.service});

  @override
  State<UpdateBanner> createState() => _UpdateBannerState();
}

class _UpdateBannerState extends State<UpdateBanner> {
  String? _dismissedRelease;

  @override
  Widget build(BuildContext context) {
    final svc = widget.service ?? AppUpdateService();
    return ValueListenableBuilder<int>(
      valueListenable: svc.revision,
      builder: (context, _, __) {
        final cfg = svc.config;
        if ((_dismissedRelease != null &&
                _dismissedRelease == svc.releaseKey) ||
            !svc.decision.optional ||
            cfg == null) {
          return const SizedBox.shrink();
        }
        return TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: 1),
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
          builder: (context, t, child) => Opacity(
            opacity: t,
            child: Transform.translate(
              offset: Offset(0, -8 * (1 - t)),
              child: child,
            ),
          ),
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
            decoration: BoxDecoration(
              color: AppTheme.primary.withValues(alpha: 0.10),
              border: Border(
                bottom:
                    BorderSide(color: AppTheme.primary.withValues(alpha: 0.25)),
              ),
            ),
            child: SafeArea(
              bottom: false,
              minimum: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                children: [
                  const Icon(Icons.system_update_alt_rounded,
                      size: 18, color: AppTheme.primary),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'New FKSS version ${cfg.latestVersion} is available',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w600),
                    ),
                  ),
                  TextButton(
                    onPressed: () =>
                        AppUpdatesNavigation.open(context, service: svc),
                    style: TextButton.styleFrom(
                      foregroundColor: AppTheme.primary,
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      minimumSize: const Size(48, 48),
                      tapTargetSize: MaterialTapTargetSize.padded,
                    ),
                    child: const Text('Update',
                        style: TextStyle(fontWeight: FontWeight.w700)),
                  ),
                  IconButton(
                    tooltip: 'Dismiss update reminder',
                    constraints:
                        const BoxConstraints(minWidth: 48, minHeight: 48),
                    onPressed: () {
                      setState(() => _dismissedRelease = svc.releaseKey);
                      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                        content: Text(
                            'You can update anytime from Profile → App updates.'),
                      ));
                    },
                    icon: const Icon(Icons.close_rounded,
                        size: 20, color: AppTheme.textSecondary),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}
