import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/department_offline_policy.dart';

void main() {
  test('exactly two attendance scopes are offline today plus grades', () {
    expect(DepartmentOfflinePolicy.offlineEnabledToday, hasLength(3));
    expect(DepartmentOfflinePolicy.isOfflineEnabled(OfflineDepartment.educationAttendance), isTrue);
    expect(DepartmentOfflinePolicy.isOfflineEnabled(OfflineDepartment.mezmurAttendance), isTrue);
    expect(DepartmentOfflinePolicy.isOfflineEnabled(OfflineDepartment.grades), isTrue);
  });

  test('grades is enabled, no longer planned', () {
    expect(DepartmentOfflinePolicy.isPlanned(OfflineDepartment.grades), isFalse);
    expect(DepartmentOfflinePolicy.isOfflineEnabled(OfflineDepartment.grades), isTrue);
  });

  test('other departments are online-only and not offline-enabled', () {
    for (final d in DepartmentOfflinePolicy.onlineOnly) {
      expect(DepartmentOfflinePolicy.isOnlineOnly(d), isTrue);
      expect(DepartmentOfflinePolicy.isOfflineEnabled(d), isFalse);
    }
    expect(DepartmentOfflinePolicy.onlineOnly, contains(OfflineDepartment.members));
    expect(DepartmentOfflinePolicy.onlineOnly, contains(OfflineDepartment.finance));
  });

  test('no department is both offline-enabled and online-only', () {
    final overlap = DepartmentOfflinePolicy.offlineEnabledToday.intersection(DepartmentOfflinePolicy.onlineOnly);
    expect(overlap, isEmpty);
    final overlapPlanned = DepartmentOfflinePolicy.plannedNext.intersection(DepartmentOfflinePolicy.offlineEnabledToday);
    expect(overlapPlanned, isEmpty);
  });
}
