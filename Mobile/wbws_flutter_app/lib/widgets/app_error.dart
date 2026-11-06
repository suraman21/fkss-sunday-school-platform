import 'dart:async';
import 'package:flutter/material.dart';
import '../utils/theme.dart';

/// Error codes for structured error handling.
/// Format: WBSS-[category][number]
/// Categories: N=Network, A=Auth, D=Data, S=Sync, U=Unknown
class AppError {
  final String code;
  final String title;
  final String message;
  final IconData icon;
  final Color color;
  final bool canRetry;

  const AppError({
    required this.code,
    required this.title,
    required this.message,
    this.icon = Icons.error_outline,
    this.color = AppTheme.danger,
    this.canRetry = true,
  });

  /// Parse from API response message
  static AppError fromMessage(String? msg, {bool isNetwork = false, bool isAuth = false}) {
    if (isAuth) return authExpired;
    if (isNetwork) return noConnection;

    final lower = (msg ?? '').toLowerCase();
    if (lower.contains('timeout') || lower.contains('taking too long')) return timeout;
    if (lower.contains('no internet') || lower.contains('socket')) return noConnection;
    // App's own ApiService vocabulary: offline/DNS-shaped failures are
    // network states, never WBSS-U01. Single source — call sites need no sniffing.
    if (lower.contains('waiting for network') || lower.contains('could not reach the school')) return noConnection;
    if (lower.contains('school is busy')) return serverError;
    if (lower.contains('401') || lower.contains('unauthorized') || lower.contains('expired')) return authExpired;
    if (lower.contains('403') || lower.contains('forbidden')) return forbidden;
    if (lower.contains('404') || lower.contains('not found')) return notFound;
    if (lower.contains('500') || lower.contains('server')) return serverError;
    if (lower.contains('parse') || lower.contains('format')) return parseError;

    return AppError(
      code: 'WBSS-U01',
      title: 'Something went wrong',
      message: msg ?? 'An unexpected error occurred',
      icon: Icons.warning_amber_rounded,
      color: AppTheme.warning,
    );
  }

  // ── Standard errors ──

  static const noConnection = AppError(
    code: 'WBSS-N01',
    title: 'Waiting for network',
    message: 'Your work stays saved on this phone. Turn on data or Wi-Fi to refresh.',
    icon: Icons.cloud_off_rounded,
    color: AppTheme.warning,
  );

  static const timeout = AppError(
    code: 'WBSS-N02',
    title: 'Server Slow',
    message: 'The server is taking too long to respond',
    icon: Icons.hourglass_top_rounded,
    color: AppTheme.warning,
  );

  static const authExpired = AppError(
    code: 'WBSS-A01',
    title: 'Session Expired',
    message: 'Please login again',
    icon: Icons.lock_clock_rounded,
    color: AppTheme.info,
    canRetry: false,
  );

  static const forbidden = AppError(
    code: 'WBSS-A02',
    title: 'Access Denied',
    message: 'You don\'t have permission for this action',
    icon: Icons.block_rounded,
    color: AppTheme.danger,
    canRetry: false,
  );

  static const notFound = AppError(
    code: 'WBSS-D01',
    title: 'Not Found',
    message: 'The requested data could not be found',
    icon: Icons.search_off_rounded,
    color: AppTheme.warning,
  );

  static const serverError = AppError(
    code: 'WBSS-D02',
    title: 'Server Error',
    message: 'Something went wrong on the server',
    icon: Icons.dns_rounded,
    color: AppTheme.danger,
  );

  static const parseError = AppError(
    code: 'WBSS-D03',
    title: 'Data Error',
    message: 'Received unexpected data from the server',
    icon: Icons.data_object_rounded,
    color: AppTheme.warning,
  );

  static const noData = AppError(
    code: 'WBSS-D04',
    title: 'No Data',
    message: 'No cached data available',
    icon: Icons.inbox_rounded,
    color: AppTheme.info,
    canRetry: true,
  );
}

/// Professional error display card with auto-retry countdown.
class AppErrorCard extends StatefulWidget {
  final AppError error;
  final VoidCallback? onRetry;
  final bool autoRetry;
  final int autoRetrySeconds;

  const AppErrorCard({
    super.key,
    required this.error,
    this.onRetry,
    this.autoRetry = false,
    this.autoRetrySeconds = 15,
  });

  @override
  State<AppErrorCard> createState() => _AppErrorCardState();
}

class _AppErrorCardState extends State<AppErrorCard>
    with SingleTickerProviderStateMixin {
  Timer? _retryTimer;
  int _countdown = 0;
  late AnimationController _fadeCtrl;
  late Animation<double> _fadeAnim;

  @override
  void initState() {
    super.initState();
    _fadeCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 400),
    );
    _fadeAnim = CurvedAnimation(parent: _fadeCtrl, curve: Curves.easeOut);
    _fadeCtrl.forward();

    if (widget.autoRetry && widget.error.canRetry && widget.onRetry != null) {
      _countdown = widget.autoRetrySeconds;
      _retryTimer = Timer.periodic(const Duration(seconds: 1), (t) {
        if (!mounted) { t.cancel(); return; }
        setState(() => _countdown--);
        if (_countdown <= 0) {
          t.cancel();
          widget.onRetry?.call();
        }
      });
    }
  }

  @override
  void dispose() {
    _retryTimer?.cancel();
    _fadeCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _fadeAnim,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: Card(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: Padding(
            padding: const EdgeInsets.all(28),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Icon
                Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    color: widget.error.color.withOpacity(0.1),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Icon(widget.error.icon, size: 30, color: widget.error.color),
                ),
                const SizedBox(height: 16),

                // Title
                Text(
                  widget.error.title,
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 6),

                // Message
                Text(
                  widget.error.message,
                  style: TextStyle(fontSize: 13, color: AppTheme.textSecondary),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 6),

                // Error code — small, gray, for debugging
                Text(
                  widget.error.code,
                  style: TextStyle(fontSize: 10, color: AppTheme.textSecondary,
                      fontFamily: 'monospace', letterSpacing: 1),
                ),

                if (widget.error.canRetry && widget.onRetry != null) ...[
                  const SizedBox(height: 18),

                  // Retry button
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: widget.onRetry,
                      icon: const Icon(Icons.refresh_rounded, size: 18),
                      label: Text(_countdown > 0
                          ? 'Retry (${_countdown}s)'
                          : 'Retry'),
                      style: ElevatedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12)),
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

