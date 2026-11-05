import 'package:flutter/material.dart';

import '../../services/app_update_service.dart';
import '../../utils/theme.dart';
import 'update_screen.dart';

/// Always available, including when profile fetching or account editing fails.
/// Listen only around this row: progress must not rebuild the profile form.
class AppUpdatesTile extends StatelessWidget {
  final AppUpdateService? service;
  const AppUpdatesTile({super.key, this.service});

  @override
  Widget build(BuildContext context) {
    final svc = service ?? AppUpdateService();
    return AnimatedBuilder(
      animation: svc,
      builder: (context, _) => ListTile(
        contentPadding: EdgeInsets.zero,
        leading: const Icon(Icons.system_update_alt_rounded,
            color: AppTheme.primary),
        title: const Text('App updates'),
        subtitle: Text(svc.statusLabel),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => AppUpdatesNavigation.open(context, service: svc),
      ),
    );
  }
}
