import 'dart:convert';
import 'package:crypto/crypto.dart';

class ProfileImageReference {
  const ProfileImageReference({
    required this.present,
    this.version,
    this.url,
  });

  final bool present;
  final String? version;
  final String? url;

  static final RegExp _opaqueVersion = RegExp(r'^[a-f0-9]{64}$');

  factory ProfileImageReference.fromJson(Object? value) {
    if (value == null) {
      return const ProfileImageReference(present: false);
    }
    if (value is! Map) {
      final str = value.toString().trim();
      if (str.isNotEmpty && (str.startsWith('http') || str.startsWith('/'))) {
        return ProfileImageReference(present: true, url: str);
      }
      return const ProfileImageReference(present: false);
    }
    final map = Map<String, dynamic>.from(value);
    final rawPresent = map['present'];
    final bool present;
    if (rawPresent is bool) {
      present = rawPresent;
    } else if (rawPresent == 1 || rawPresent == '1' || rawPresent == 'true') {
      present = true;
    } else if (rawPresent == 0 || rawPresent == '0' || rawPresent == 'false' || rawPresent == null) {
      present = false;
    } else {
      present = false;
    }

    final rawVersion = map['version'];
    String? version = (rawVersion == null || rawVersion.toString().trim().isEmpty)
        ? null
        : rawVersion.toString().trim();

    if (present && (version == null || !_opaqueVersion.hasMatch(version))) {
      final rawUrl = map['url']?.toString().trim();
      if (rawUrl != null && rawUrl.isNotEmpty) {
        version = sha256.convert(utf8.encode(rawUrl)).toString();
      } else {
        version = sha256.convert(utf8.encode('image_present')).toString();
      }
    }
    if (!present) {
      version = null;
    }

    final rawUrl = map['url'];
    final url = rawUrl == null ? null : rawUrl.toString().trim();
    return ProfileImageReference(
      present: present,
      version: version,
      url: url == null || url.isEmpty ? null : url,
    );
  }

  static ProfileImageReference? tryFromJson(Object? value) {
    try {
      return ProfileImageReference.fromJson(value);
    } catch (_) {
      return const ProfileImageReference(present: false);
    }
  }

  Map<String, dynamic> toJson() => {
        'present': present,
        'version': version,
        'url': url,
      };
}

class UserProfile {
  const UserProfile({
    required this.id,
    required this.username,
    required this.email,
    required this.fullName,
    required this.role,
    required this.isActive,
    required this.memberId,
    required this.profileImage,
    required this.profileVersion,
    required this.createdAt,
    required this.lastLogin,
    required this.assignments,
  });

  final int id;
  final String username;
  final String? email;
  final String fullName;
  final String role;
  final bool isActive;
  final int? memberId;
  final ProfileImageReference profileImage;
  final String profileVersion;
  final String? createdAt;
  final String? lastLogin;
  final List<Map<String, dynamic>>? assignments;

  static final RegExp _profileVersion = RegExp(r'^[a-f0-9]{64}$');

  factory UserProfile.fromJson(Object? value) {
    if (value is! Map) {
      throw const FormatException('Profile payload is missing.');
    }
    final map = Map<String, dynamic>.from(value);
    final id = _positiveInt(map['id'] ?? map['user_id'] ?? map['uid']);
    final username = _requiredText(map['username'] ?? map['usr']);

    if (id == null || username == null) {
      throw const FormatException('Profile payload is invalid.');
    }

    final String fullName =
        _requiredText(map['full_name'] ?? map['name']) ?? username;
    final String role =
        _requiredText(map['role'] ?? map['rol']) ?? 'user';

    final rawActive = map['is_active'];
    final bool active;
    if (rawActive == null) {
      active = true;
    } else if (rawActive is bool) {
      active = rawActive;
    } else if (rawActive == 1 || rawActive == '1' || rawActive == 'true') {
      active = true;
    } else if (rawActive == 0 || rawActive == '0' || rawActive == 'false') {
      active = false;
    } else {
      active = true;
    }

    final rawEmail = map['email'];
    final email = (rawEmail == null || rawEmail.toString().trim().isEmpty)
        ? null
        : rawEmail.toString().trim();

    final rawImage = map['profile_image'];
    final ProfileImageReference profileImage;
    if (rawImage == null) {
      profileImage = const ProfileImageReference(present: false);
    } else {
      profileImage = ProfileImageReference.fromJson(rawImage);
    }

    String? version = _requiredText(map['profile_version'] ?? map['version']);
    if (version == null || !_profileVersion.hasMatch(version)) {
      version = synthesizeVersion(
        username: username,
        fullName: fullName,
        email: email,
        imagePath: profileImage.present ? (profileImage.url ?? 'image') : null,
      );
    }

    final rawMemberId = map['member_id'];
    final int? memberId;
    if (rawMemberId == null || rawMemberId == '' || rawMemberId == 0 || rawMemberId == '0') {
      memberId = null;
    } else {
      memberId = _positiveInt(rawMemberId);
    }

    final rawAssignments = map['assignments'];
    List<Map<String, dynamic>>? assignments;
    if (rawAssignments != null && rawAssignments is List) {
      assignments = rawAssignments
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList(growable: false);
    }

    return UserProfile(
      id: id,
      username: username,
      email: email,
      fullName: fullName,
      role: role,
      isActive: active,
      memberId: memberId,
      profileImage: profileImage,
      profileVersion: version,
      createdAt: _nullableText(map['created_at']),
      lastLogin: _nullableText(map['last_login']),
      assignments: assignments,
    );
  }

  static String synthesizeVersion({
    required String username,
    required String fullName,
    String? email,
    String? imagePath,
  }) {
    final canonical = jsonEncode({
      'username': username,
      'email': email,
      'full_name': fullName,
      'profile_image_path': imagePath,
    });
    return sha256.convert(utf8.encode(canonical)).toString();
  }

  static UserProfile? tryFromJson(Object? value) {
    if (value == null) return null;
    try {
      return UserProfile.fromJson(value);
    } catch (_) {
      return null;
    }
  }

  UserProfile copyWith({
    String? username,
    String? email,
    bool clearEmail = false,
    String? fullName,
    ProfileImageReference? profileImage,
    String? profileVersion,
  }) =>
      UserProfile(
        id: id,
        username: username ?? this.username,
        email: clearEmail ? null : (email ?? this.email),
        fullName: fullName ?? this.fullName,
        role: role,
        isActive: isActive,
        memberId: memberId,
        profileImage: profileImage ?? this.profileImage,
        profileVersion: profileVersion ?? this.profileVersion,
        createdAt: createdAt,
        lastLogin: lastLogin,
        assignments: assignments,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'username': username,
        'email': email,
        'full_name': fullName,
        'role': role,
        'is_active': isActive,
        'member_id': memberId,
        'profile_image': profileImage.toJson(),
        'profile_version': profileVersion,
        'created_at': createdAt,
        'last_login': lastLogin,
        'assignments': assignments,
      };

  static int? _positiveInt(Object? value) {
    final parsed = value is int ? value : int.tryParse('${value ?? ''}');
    return parsed != null && parsed > 0 ? parsed : null;
  }

  static String? _requiredText(Object? value) {
    final text = value is String ? value.trim() : '';
    return text.isEmpty ? null : text;
  }

  static String? _nullableText(Object? value) {
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }
}

class ProfileImageInputPolicy {
  static const int maximumBytes = 4 * 1024 * 1024;
  static const int minimumDimension = 64;
  static const int maximumDimension = 4096;
  static const int maximumPixels = 12000000;

  static String? validateDimensions(int width, int height) {
    if (width < minimumDimension || height < minimumDimension) {
      return 'Image dimensions must be at least 64 × 64 pixels.';
    }
    if (width > maximumDimension ||
        height > maximumDimension ||
        width * height > maximumPixels) {
      return 'Image dimensions must be at most 4096 × 4096 pixels and 12 megapixels.';
    }
    return null;
  }
}

class ProfileInputPolicy {
  static final RegExp _username =
      RegExp(r'^[a-z0-9][a-z0-9_.]*[a-z0-9]$');
  static final RegExp _consecutivePunctuation = RegExp(r'[._]{2}');
  static final RegExp _simpleEmail =
      RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$');

  static String normalizeUsername(String value) => value.trim().toLowerCase();

  static String? validateUsername(String value) {
    final normalized = normalizeUsername(value);
    if (normalized.length < 3 ||
        normalized.length > 50 ||
        !_username.hasMatch(normalized) ||
        _consecutivePunctuation.hasMatch(normalized)) {
      return 'Use 3–50 lowercase letters, numbers, dots or underscores. '
          'Begin and end with a letter or number and do not repeat punctuation.';
    }
    return null;
  }

  static String? validateFullName(String value) {
    final name = value.trim();
    if (name.isEmpty) return 'Full name is required.';
    if (name.runes.length > 100) {
      return 'Full name must be 100 characters or fewer.';
    }
    if (name.runes.any((rune) => rune < 32 || rune == 127)) {
      return 'Full name contains an unsupported character.';
    }
    return null;
  }

  static String? validateEmail(String value) {
    final email = value.trim().toLowerCase();
    if (email.isEmpty) return null;
    if (email.length > 100 || !_simpleEmail.hasMatch(email)) {
      return 'Enter a valid email address.';
    }
    return null;
  }

  static String? validateNewPassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) {
    if (currentPassword.isEmpty ||
        newPassword.isEmpty ||
        confirmation.isEmpty) {
      return 'Fill in all password fields.';
    }
    if (newPassword.runes.length < 6) {
      return 'New password must be at least 6 characters.';
    }
    if (_utf8Length(newPassword) > 72) {
      return 'New password must be at most 72 UTF-8 bytes.';
    }
    if (newPassword == currentPassword) {
      return 'New password must differ from the current password.';
    }
    if (newPassword != confirmation) {
      return 'New passwords do not match.';
    }
    return null;
  }

  static int _utf8Length(String value) {
    var length = 0;
    for (final rune in value.runes) {
      if (rune <= 0x7f) {
        length += 1;
      } else if (rune <= 0x7ff) {
        length += 2;
      } else if (rune <= 0xffff) {
        length += 3;
      } else {
        length += 4;
      }
    }
    return length;
  }
}
