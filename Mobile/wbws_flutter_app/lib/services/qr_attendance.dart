import 'dart:async';

/// ════════════════════════════════════════════════════════════
/// QR-scan attendance (Phase 8) — payload parsing + feedback model.
///
/// The QR on a member's card/roster tile is an IDENTIFIER, never a
/// credential (same payload as the HR ID card):
///   {SITE_URL}/member.php?code={member_code}
/// Every scan is re-validated against the open roster on-device and
/// again server-side at sync (membership + duplicate + lock rules).
/// ════════════════════════════════════════════════════════════
class QrAttendance {
  /// Accepts, in order: the ID-card URL form, the compact token
  /// `FKSS1:<member_id>:<member_code>`, or a raw member code.
  /// Returns the member code, or null when unparseable.
  static String? extractMemberCode(String? raw) {
    if (raw == null) return null;
    final text = raw.trim();
    if (text.isEmpty) return null;

    // 1) ID-card URL: …/member.php?code=XYZ (possibly with more params)
    final codeParam = RegExp(r'code=([^&#\s]+)').firstMatch(text);
    if (text.contains('member.php') && codeParam != null) {
      return Uri.decodeComponent(codeParam.group(1) ?? '').trim();
    }

    // 2) Compact token: FKSS1:<id>:<code>
    if (text.startsWith('FKSS1:')) {
      final parts = text.split(':');
      if (parts.length >= 3 && parts[2].trim().isNotEmpty) {
        return parts[2].trim();
      }
      return null;
    }

    // 3) Raw member code (short, no spaces, no URL markers)
    if (text.length <= 40 && !text.contains(' ') && !text.contains('://')) {
      return text;
    }
    return null;
  }
}

enum QrFeedbackKind {
  ok,
  duplicate,
  wrongGroup,
  notFound,
  inactive,
  invalid,
  locked
}

/// Big, high-contrast, Amharic-first scan feedback (event check-in
/// UX: instant unambiguous state, the line never stops).
class QrFeedback {
  final QrFeedbackKind kind;
  final String title;
  final String sub;

  const QrFeedback._(this.kind, this.title, this.sub);

  static String statusLabel(String status) {
    switch (status) {
      case 'present':
        return 'ተገኘ';
      case 'absent':
        return 'አልተገኘም';
      case 'late':
        return 'ዘግይቷል';
      case 'excused':
        return 'ይቅርታ';
      default:
        return status;
    }
  }

  factory QrFeedback.ok({required String name}) =>
      QrFeedback._(QrFeedbackKind.ok, 'ተመዝግቧል ✓', '$name — ተገኘ');

  factory QrFeedback.duplicate(
          {required String name, required String status}) =>
      QrFeedback._(QrFeedbackKind.duplicate, 'ቀድሞ ተመዝግቧል!',
          '$name — ዛሬ በ«${statusLabel(status)}» ተመዝግቧል። ለውጥ ካስፈለገ በእጅ ይምረጡ');

  factory QrFeedback.wrongGroup(
          {required String name, required String ownGroup}) =>
      QrFeedback._(QrFeedbackKind.wrongGroup, 'የተሳሳተ ክፍል!',
          '$name — ትክክለኛው ክፍል $ownGroup');

  factory QrFeedback.notFound() => QrFeedback._(QrFeedbackKind.notFound,
      'አልተገኘም!', 'ኮዱ በዚህ መሣሪያ ላይ የለም። በስም ይፈልጉ ወይም ውሂብ ያዘምኑ');

  factory QrFeedback.inactive({required String name}) => QrFeedback._(
      QrFeedbackKind.inactive, 'ንቁ አይደለም!', '$name — የአባልነት ሁኔታው ንቁ አይደለም።');

  /// Member lookup (Phase 9): the scanned code resolved to a member.
  factory QrFeedback.memberFound({required String name}) =>
      QrFeedback._(QrFeedbackKind.ok, '\u1270\u1308\u129d\u1270\u12cd\u120d', name);

  factory QrFeedback.invalid() => QrFeedback._(QrFeedbackKind.invalid,
      'ልክ ያልሆነ ኮድ!', 'ይህ ቁራጽ የአባል መገኛ ኪውአር ኮድ አይደለም። እባክዎ እንደገና ይሞክሩ።');

  factory QrFeedback.locked() => QrFeedback._(QrFeedbackKind.locked,
      'ቅጹ ተቆልጓአል!', 'የተላከ/የጸደቀ ቅጽ ነው፤ በስካን መለወጥ አይቻልም።');

  factory QrFeedback.storageError() => const QrFeedback._(QrFeedbackKind.invalid,
      'አልተመዘገበም — Not saved',
      'Phone storage refused the save. Free up space and try again.');

  bool get isSuccess => kind == QrFeedbackKind.ok;
  bool get isWarning =>
      kind == QrFeedbackKind.duplicate || kind == QrFeedbackKind.locked;
}

/// Debouncer for the instant-autosave path (scan OR manual tap →
/// durable local draft within milliseconds of the mutation).
class Debounce {
  Timer? _t;
  void run(Duration after, void Function() fn) {
    _t?.cancel();
    _t = Timer(after, fn);
  }

  void cancel() {
    _t?.cancel();
    _t = null;
  }

  void dispose() => cancel();
}
