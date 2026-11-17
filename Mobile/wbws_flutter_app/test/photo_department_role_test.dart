import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/utils/config.dart';

void main() {
  group('Photo Department mobile role', () {
    test('gets only member and profile navigation', () {
      final ids =
          getTabsForRole(UserRoles.photoDept).map((tab) => tab.id).toList();

      expect(ids, ['members', 'profile']);
    });

    test('legacy photo importer keeps the same limited access', () {
      final ids =
          getTabsForRole(UserRoles.photoImporter).map((tab) => tab.id).toList();

      expect(ids, ['members', 'profile']);
      expect(UserRoles.canBrowseMembers(UserRoles.photoImporter), isTrue);
      expect(UserRoles.canManageMembers(UserRoles.photoImporter), isFalse);
      expect(UserRoles.canUploadMemberPhotos(UserRoles.photoImporter), isTrue);
    });

    test('unknown roles receive no fallback navigation', () {
      expect(getTabsForRole('unknown_role'), isEmpty);
      expect(UserRoles.canBrowseMembers('unknown_role'), isFalse);
      expect(UserRoles.canUploadMemberPhotos('unknown_role'), isFalse);
    });
  });
}
