import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/main.dart';
import 'package:fkss_app/services/interrupted_database_migration.dart';

void main() {
  testWidgets(
      'migration hold explains preservation without destructive actions',
      (tester) async {
    await tester.pumpWidget(const OfflineDataProtectionFailureApp(
      detail: 'Migration files were preserved.',
      migrationRecoveryRequired: true,
    ));

    expect(find.text('Offline storage could not be opened'), findsOneWidget);
    expect(find.textContaining('Offline database files need review'),
        findsOneWidget);
    expect(find.textContaining('Do not clear app data or reinstall'),
        findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
    expect(find.textContaining('Free up some phone storage'), findsNothing);
    expect(find.text('Discard permanently'), findsNothing);
    expect(find.text('Erase local data & reset'), findsNothing);
  });

  testWidgets('ordinary bootstrap failure retains existing retry guidance',
      (tester) async {
    await tester.pumpWidget(const OfflineDataProtectionFailureApp(
      detail: 'Synthetic storage error',
    ));

    expect(find.textContaining('Free up some phone storage'), findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
    expect(find.text('Synthetic storage error'), findsOneWidget);
    expect(find.textContaining('Offline database files need review'),
        findsNothing);
  });

  testWidgets('orphan sidecar hold uses neutral review guidance',
      (tester) async {
    const error = OrphanedDatabaseSidecar('offline.db-wal');
    await tester.pumpWidget(OfflineDataProtectionFailureApp(
      detail: error.toString(),
      migrationRecoveryRequired: true,
    ));
    expect(find.textContaining('Offline database files need review'),
        findsOneWidget);
    expect(find.textContaining('SQLite sidecar files were preserved'),
        findsOneWidget);
    expect(find.textContaining('Historical'), findsNothing);
    expect(find.textContaining('Free up some phone storage'), findsNothing);
    expect(find.text('Retry'), findsOneWidget);
    expect(find.text('Erase local data & reset'), findsNothing);
  });
}
