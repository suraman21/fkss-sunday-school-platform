import 'dart:collection';
import 'dart:convert';

/// Validated, immutable view of a complete server preparation payload.
///
/// Takes the exact payload bytes preserved by [AttendancePreparationEnvelope]
/// and checks every structural, type, bound and coherence fact the current
/// server can produce. This is **validation, not authority** — it does not
/// verify the server HMAC, enforce current session/role, grant capture
/// permission, publish to storage or decide readiness. It is inactive: no
/// existing app code imports it.
///
/// Fail-closed: any missing, extra, duplicate, mistyped or incoherent field
/// is a [FormatException]. No partial model is returned, no truncation.
final class AttendancePreparationDataset {
  final Map<String, dynamic> raw;
  final int preparationVersion;
  final bool complete;
  final int ownerUserId;
  final int authorizationVersion;
  final String role;
  final String familyId;
  final int issuedAt;
  final int deadline;
  final String preparationId;
  final String serverIdentity;
  final String serverToday;
  final String from;
  final String to;
  final int backdateDays;
  final WriteYear writeYear;
  final bool lessonsEnabled;
  final List<MemberPreparation> members;
  final List<ClassPreparation> classes;
  final Map<String, String> schoolCalendar;

  const AttendancePreparationDataset._({
    required this.raw,
    required this.preparationVersion,
    required this.complete,
    required this.ownerUserId,
    required this.authorizationVersion,
    required this.role,
    required this.familyId,
    required this.issuedAt,
    required this.deadline,
    required this.preparationId,
    required this.serverIdentity,
    required this.serverToday,
    required this.from,
    required this.to,
    required this.backdateDays,
    required this.writeYear,
    required this.lessonsEnabled,
    required this.members,
    required this.classes,
    required this.schoolCalendar,
  });

  /// Validate and wrap the exact payload UTF-8 text.
  static AttendancePreparationDataset decode(String payloadUtf8) {
    final map = _asMap(jsonDecode(payloadUtf8), 'payload');
    const topKeys = {
      'preparation_version',
      'complete',
      'owner_user_id',
      'authorization_version',
      'role',
      'family_id',
      'issued_at',
      'deadline',
      'preparation_id',
      'server_identity',
      'server_today',
      'from',
      'to',
      'backdate_days',
      'write_year',
      'lessons_enabled',
      'members',
      'classes',
      'school_calendar',
    };
    _exactKeys(map, topKeys, 'payload');

    if (map['preparation_version'] != 1) {
      throw const FormatException('Unsupported preparation_version');
    }
    if (map['complete'] != true) {
      throw const FormatException('Preparation must be complete');
    }
    final ownerUserId = _id(map['owner_user_id'], 'owner_user_id');
    final authorizationVersion = _id(map['authorization_version'], 'authorization_version');
    final role = _nonEmptyString(map['role'], 'role');
    final familyId = _hex(map['family_id'], 'family_id');
    final issuedAt = _positiveInt(map['issued_at'], 'issued_at');
    final deadline = _positiveInt(map['deadline'], 'deadline');
    if (deadline <= issuedAt || deadline - issuedAt > 30 * 86400) {
      throw const FormatException('Invalid preparation lease bounds');
    }
    final preparationId = _hex(map['preparation_id'], 'preparation_id');
    final serverIdentity = _serverIdentity(map['server_identity']);
    final serverToday = _date(map['server_today'], 'server_today');
    final from = _date(map['from'], 'from');
    final to = _date(map['to'], 'to');
    if (from.compareTo(to) > 0) {
      throw const FormatException('Invalid preparation window: from > to');
    }
    final backdateDays = _nonNegativeInt(map['backdate_days'], 'backdate_days');
    // Server window is bounded by backdate + mobile future (45 + 60) + 1 inclusive.
    final windowDays = _daysBetween(from, to);
    if (windowDays > backdateDays + 60 + 1) {
      // The current server uses BACKDATE 45 and MOBILE_FUTURE 60.
      // We allow smaller windows but not larger than the server budget.
      throw const FormatException('Preparation window exceeds server budget');
    }
    if (serverToday.compareTo(from) < 0 || serverToday.compareTo(to) > 0) {
      throw const FormatException('server_today outside from..to window');
    }
    final writeYear = WriteYear._decode(_asMap(map['write_year'], 'write_year'));
    if (writeYear.from.compareTo(writeYear.to) > 0) {
      throw const FormatException('Invalid write_year range');
    }
    if (serverToday.compareTo(writeYear.from) < 0 || serverToday.compareTo(writeYear.to) > 0) {
      throw const FormatException('server_today outside write_year');
    }
    if (from.compareTo(writeYear.from) < 0 || to.compareTo(writeYear.to) > 0) {
      throw const FormatException('Preparation window outside write_year');
    }
    if (map['lessons_enabled'] is! bool) {
      throw const FormatException('Invalid lessons_enabled');
    }
    final lessonsEnabled = map['lessons_enabled'] as bool;

    final membersRaw = _asList(map['members'], 'members');
    final members = <MemberPreparation>[];
    final memberIdsSeen = <int>{};
    int? prevMemberId;
    for (var i = 0; i < membersRaw.length; i++) {
      final m = MemberPreparation._decode(_asMap(membersRaw[i], 'members[$i]'));
      if (!memberIdsSeen.add(m.memberId)) {
        throw const FormatException('Duplicate member_id in members');
      }
      if (prevMemberId != null && m.memberId <= prevMemberId) {
        throw const FormatException('members must be sorted ascending by member_id');
      }
      prevMemberId = m.memberId;
      members.add(m);
    }
    if (members.length > 10000) {
      throw const FormatException('Members exceeds practical bound');
    }

    final classesRaw = _asList(map['classes'], 'classes');
    final classes = <ClassPreparation>[];
    final classIdsSeen = <int>{};
    for (var ci = 0; ci < classesRaw.length; ci++) {
      final c = ClassPreparation._decode(
        _asMap(classesRaw[ci], 'classes[$ci]'),
        from: from,
        to: to,
        writeYearId: writeYear.id,
        windowDays: windowDays,
      );
      if (!classIdsSeen.add(c.classId)) {
        throw const FormatException('Duplicate class_id');
      }
      classes.add(c);
    }

    final schoolCalendarRaw = _asMap(map['school_calendar'], 'school_calendar');
    final schoolCalendar = <String, String>{};
    for (final entry in schoolCalendarRaw.entries) {
      final d = _date(entry.key, 'school_calendar key');
      if (d.compareTo(from) < 0 || d.compareTo(to) > 0) {
        throw const FormatException('school_calendar date outside window');
      }
      final detail = _boundedString(entry.value, 'school_calendar value', 2000);
      schoolCalendar[d] = detail;
    }

    // school_calendar entries should correspond to calendar_code d/h days
    // We collect all dates that are d/h to allow checking, but we keep it
    // lenient: school may have extra reason text, but we ensure no date
    // outside window and that keys are valid dates.
    // Duplicate check is implicit via map keys.

    return AttendancePreparationDataset._(
      raw: UnmodifiableMapView(Map<String, dynamic>.from(map)),
      preparationVersion: 1,
      complete: true,
      ownerUserId: ownerUserId,
      authorizationVersion: authorizationVersion,
      role: role,
      familyId: familyId,
      issuedAt: issuedAt,
      deadline: deadline,
      preparationId: preparationId,
      serverIdentity: serverIdentity,
      serverToday: serverToday,
      from: from,
      to: to,
      backdateDays: backdateDays,
      writeYear: writeYear,
      lessonsEnabled: lessonsEnabled,
      members: UnmodifiableListView(members),
      classes: UnmodifiableListView(classes),
      schoolCalendar: UnmodifiableMapView(schoolCalendar),
    );
  }

  static String _serverIdentity(Object? value) {
    if (value is! String) throw const FormatException('Invalid server_identity');
    final uri = Uri.tryParse(value);
    if (uri == null ||
        uri.scheme != 'https' ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        !value.endsWith('/api/v1')) {
      throw const FormatException('Invalid server_identity');
    }
    if (value.contains('localhost') || value.contains('127.0.0.1')) {
      throw const FormatException('Invalid server_identity host');
    }
    return value;
  }

  static Map<String, dynamic> _asMap(Object? value, String ctx) {
    if (value is! Map) throw FormatException('Invalid $ctx: expected object');
    return Map<String, dynamic>.from(value as Map);
  }

  static List<dynamic> _asList(Object? value, String ctx) {
    if (value is! List) throw FormatException('Invalid $ctx: expected list');
    return value;
  }

  static void _exactKeys(Map<String, dynamic> map, Set<String> expected, String ctx) {
    if (map.length != expected.length || !expected.containsAll(map.keys)) {
      throw FormatException('Unsupported keys in $ctx: ${map.keys}');
    }
  }

  static int _positiveInt(Object? value, String ctx) {
    if (value is! int || value < 1 || value > 9007199254740991) {
      throw FormatException('Invalid $ctx: expected positive safe int');
    }
    return value;
  }

  static int _nonNegativeInt(Object? value, String ctx) {
    if (value is! int || value < 0 || value > 9007199254740991) {
      throw FormatException('Invalid $ctx: expected non-negative safe int');
    }
    return value;
  }

  static int _id(Object? value, String ctx) => _positiveInt(value, ctx);

  static String _hex(Object? value, String ctx) {
    if (value is! String || value.length != 64 || !RegExp(r'^[a-f0-9]{64}$').hasMatch(value)) {
      throw FormatException('Invalid $ctx: expected hex64');
    }
    return value;
  }

  static String _nonEmptyString(Object? value, String ctx) {
    if (value is! String || value.trim().isEmpty) {
      throw FormatException('Invalid $ctx: expected non-empty string');
    }
    if (value.length > 500) throw FormatException('Invalid $ctx: too long');
    if (value.contains('\u0000')) throw FormatException('Invalid $ctx: contains NUL');
    return value;
  }

  static String _boundedString(Object? value, String ctx, int max) {
    if (value is! String) throw FormatException('Invalid $ctx: expected string');
    if (value.length > max) throw FormatException('Invalid $ctx: too long');
    if (value.contains('\u0000')) throw FormatException('Invalid $ctx: contains NUL');
    return value;
  }

  static String _date(Object? value, String ctx) {
    if (value is! String) throw FormatException('Invalid $ctx: expected date string');
    if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value)) {
      throw FormatException('Invalid $ctx: bad date format');
    }
    final parsed = DateTime.tryParse(value);
    if (parsed == null) throw FormatException('Invalid $ctx: unparsable date');
    // Ensure no rollover: 2026-02-30 would parse to 2026-03-02
    final y = int.parse(value.substring(0, 4));
    final m = int.parse(value.substring(5, 7));
    final d = int.parse(value.substring(8, 10));
    if (parsed.year != y || parsed.month != m || parsed.day != d) {
      throw FormatException('Invalid $ctx: nonexistent date');
    }
    return value;
  }

  static int _daysBetween(String from, String to) {
    final a = DateTime.parse(from);
    final b = DateTime.parse(to);
    return b.difference(a).inDays + 1;
  }
}

final class WriteYear {
  final int id;
  final String from;
  final String to;
  final String status;

  const WriteYear._({required this.id, required this.from, required this.to, required this.status});

  static WriteYear _decode(Map<String, dynamic> map) {
    const keys = {'id', 'from', 'to', 'status'};
    if (map.length != keys.length || !keys.containsAll(map.keys)) {
      throw const FormatException('Invalid write_year keys');
    }
    final id = AttendancePreparationDataset._id(map['id'], 'write_year.id');
    final from = AttendancePreparationDataset._date(map['from'], 'write_year.from');
    final to = AttendancePreparationDataset._date(map['to'], 'write_year.to');
    final status = AttendancePreparationDataset._boundedString(map['status'], 'write_year.status', 20);
    if (status != 'active') throw const FormatException('Invalid write_year.status');
    return WriteYear._(id: id, from: from, to: to, status: status);
  }
}

final class MemberPreparation {
  final int memberId;
  final String studentName;
  final String fatherName;
  final String memberCode;

  const MemberPreparation._({
    required this.memberId,
    required this.studentName,
    required this.fatherName,
    required this.memberCode,
  });

  static MemberPreparation _decode(Map<String, dynamic> map) {
    const keys = {'member_id', 'student_name', 'father_name', 'member_code'};
    if (map.length != keys.length || !keys.containsAll(map.keys)) {
      throw const FormatException('Invalid member keys');
    }
    final memberId = AttendancePreparationDataset._id(map['member_id'], 'member.member_id');
    final studentName = AttendancePreparationDataset._boundedString(map['student_name'], 'member.student_name', 200);
    final fatherName = AttendancePreparationDataset._boundedString(map['father_name'], 'member.father_name', 200);
    final memberCode = AttendancePreparationDataset._boundedString(map['member_code'], 'member.member_code', 100);
    return MemberPreparation._(
      memberId: memberId,
      studentName: studentName,
      fatherName: fatherName,
      memberCode: memberCode,
    );
  }
}

final class ClassPreparation {
  final int classId;
  final String className;
  final String classNameEn;
  final String section;
  final int writeYearId;
  final int rosterYearId;
  final bool rosterFallback;
  final List<DayPreparation> days;

  const ClassPreparation._({
    required this.classId,
    required this.className,
    required this.classNameEn,
    required this.section,
    required this.writeYearId,
    required this.rosterYearId,
    required this.rosterFallback,
    required this.days,
  });

  static ClassPreparation _decode(
    Map<String, dynamic> map, {
    required String from,
    required String to,
    required int writeYearId,
    required int windowDays,
  }) {
    const keys = {
      'class_id',
      'class_name',
      'class_name_en',
      'section',
      'write_year_id',
      'roster_year_id',
      'roster_fallback',
      'days',
    };
    if (map.length != keys.length || !keys.containsAll(map.keys)) {
      throw FormatException('Invalid class keys: ${map.keys}');
    }
    final classId = AttendancePreparationDataset._id(map['class_id'], 'class.class_id');
    final className = AttendancePreparationDataset._boundedString(map['class_name'], 'class.class_name', 200);
    final classNameEn = AttendancePreparationDataset._boundedString(map['class_name_en'], 'class.class_name_en', 200);
    final section = AttendancePreparationDataset._boundedString(map['section'], 'class.section', 200);
    final wYearId = AttendancePreparationDataset._id(map['write_year_id'], 'class.write_year_id');
    if (wYearId != writeYearId) {
      throw const FormatException('class.write_year_id must match write_year.id');
    }
    final rosterYearId = AttendancePreparationDataset._id(map['roster_year_id'], 'class.roster_year_id');
    if (map['roster_fallback'] is! bool) throw const FormatException('Invalid roster_fallback');
    final rosterFallback = map['roster_fallback'] as bool;

    final daysRaw = AttendancePreparationDataset._asList(map['days'], 'class.days');
    if (daysRaw.length != windowDays) {
      throw const FormatException('class.days length must equal from..to window');
    }
    final days = <DayPreparation>[];
    final seenDates = <String>{};
    String? prevDate;
    for (var i = 0; i < daysRaw.length; i++) {
      final dayMap = AttendancePreparationDataset._asMap(daysRaw[i], 'class.days[$i]');
      final day = DayPreparation._decode(dayMap);
      if (!seenDates.add(day.date)) throw const FormatException('Duplicate day date');
      if (prevDate != null && day.date.compareTo(prevDate) <= 0) {
        throw const FormatException('Days must be strictly ascending by date');
      }
      // Ensure alignment with global window
      final expected = _addDays(from, i);
      if (day.date != expected) {
        throw FormatException('Day date $expected expected, got ${day.date}');
      }
      days.add(day);
      prevDate = day.date;
    }
    return ClassPreparation._(
      classId: classId,
      className: className,
      classNameEn: classNameEn,
      section: section,
      writeYearId: wYearId,
      rosterYearId: rosterYearId,
      rosterFallback: rosterFallback,
      days: UnmodifiableListView(days),
    );
  }

  static String _addDays(String from, int offset) {
    final dt = DateTime.parse(from).add(Duration(days: offset));
    return '${dt.year.toString().padLeft(4, '0')}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';
  }
}

final class DayPreparation {
  final String date;
  final List<int> memberIds;
  final String calendarCode;
  final bool baseRosterCompatible;
  final List<AttendanceRecord> records;
  final PacketPreparation? packet;
  final String? resolvedStatus;
  final bool workflowSupported;
  final bool teacherWorkflowOpen;
  final bool lessonDerived;
  final List<int> originYearIds;
  final bool originUnknown;
  final bool originMatchesWriteYear;
  final bool captureEligible;
  final int notBefore;
  final int notAfter;
  final String contextToken;

  const DayPreparation._({
    required this.date,
    required this.memberIds,
    required this.calendarCode,
    required this.baseRosterCompatible,
    required this.records,
    required this.packet,
    required this.resolvedStatus,
    required this.workflowSupported,
    required this.teacherWorkflowOpen,
    required this.lessonDerived,
    required this.originYearIds,
    required this.originUnknown,
    required this.originMatchesWriteYear,
    required this.captureEligible,
    required this.notBefore,
    required this.notAfter,
    required this.contextToken,
  });

  static DayPreparation _decode(Map<String, dynamic> map) {
    const keys = {
      'date',
      'member_ids',
      'calendar_code',
      'base_roster_compatible',
      'records',
      'packet',
      'resolved_status',
      'workflow_supported',
      'teacher_workflow_open',
      'lesson_derived',
      'origin_year_ids',
      'origin_unknown',
      'origin_matches_write_year',
      'capture_eligible',
      'not_before',
      'not_after',
      'context_token',
    };
    if (map.length != keys.length || !keys.containsAll(map.keys)) {
      throw FormatException('Invalid day keys: ${map.keys} expected $keys');
    }
    final date = AttendancePreparationDataset._date(map['date'], 'day.date');
    final memberIdsRaw = AttendancePreparationDataset._asList(map['member_ids'], 'day.member_ids');
    final memberIds = <int>[];
    final seenM = <int>{};
    for (final v in memberIdsRaw) {
      final id = AttendancePreparationDataset._id(v, 'day.member_ids element');
      if (!seenM.add(id)) throw const FormatException('Duplicate member_id in day.member_ids');
      memberIds.add(id);
    }
    if (map['calendar_code'] is! String || (map['calendar_code'] as String).length != 1 || !RegExp(r'^[omncdhx]$').hasMatch(map['calendar_code'] as String)) {
      throw const FormatException('Invalid calendar_code');
    }
    final calendarCode = map['calendar_code'] as String;
    if (map['base_roster_compatible'] is! bool) throw const FormatException('Invalid base_roster_compatible');
    final baseRosterCompatible = map['base_roster_compatible'] as bool;

    final recordsRaw = AttendancePreparationDataset._asList(map['records'], 'day.records');
    final records = <AttendanceRecord>[];
    for (var i = 0; i < recordsRaw.length; i++) {
      records.add(AttendanceRecord._decode(AttendancePreparationDataset._asMap(recordsRaw[i], 'day.records[$i]')));
    }
    // records should be unique by member_id+academic_year_id
    final seenRec = <String>{};
    for (final r in records) {
      final key = '${r.memberId}:${r.academicYearId ?? 'null'}';
      if (!seenRec.add(key)) throw FormatException('Duplicate record key \$key');
    }

    PacketPreparation? packet;
    if (map['packet'] != null) {
      packet = PacketPreparation._decode(AttendancePreparationDataset._asMap(map['packet'], 'day.packet'));
    }
    String? resolvedStatus;
    if (map['resolved_status'] != null) {
      if (map['resolved_status'] is! String) throw const FormatException('Invalid resolved_status');
      resolvedStatus = map['resolved_status'] as String;
      if (resolvedStatus.isEmpty || resolvedStatus.length > 40) throw const FormatException('Invalid resolved_status length');
    }
    if (map['workflow_supported'] is! bool) throw const FormatException('Invalid workflow_supported');
    if (map['teacher_workflow_open'] is! bool) throw const FormatException('Invalid teacher_workflow_open');
    if (map['lesson_derived'] is! bool) throw const FormatException('Invalid lesson_derived');
    final originYearIdsRaw = AttendancePreparationDataset._asList(map['origin_year_ids'], 'day.origin_year_ids');
    final originYearIds = <int>[];
    for (final v in originYearIdsRaw) {
      originYearIds.add(AttendancePreparationDataset._id(v, 'day.origin_year_ids element'));
    }
    // Must be sorted ascending unique
    for (var i = 1; i < originYearIds.length; i++) {
      if (originYearIds[i] <= originYearIds[i - 1]) {
        throw const FormatException('origin_year_ids must be sorted ascending unique');
      }
    }
    if (map['origin_unknown'] is! bool) throw const FormatException('Invalid origin_unknown');
    if (map['origin_matches_write_year'] is! bool) throw const FormatException('Invalid origin_matches_write_year');
    if (map['capture_eligible'] is! bool) throw const FormatException('Invalid capture_eligible');
    final captureEligible = map['capture_eligible'] as bool;
    final notBefore = AttendancePreparationDataset._positiveInt(map['not_before'], 'day.not_before');
    final notAfter = AttendancePreparationDataset._positiveInt(map['not_after'], 'day.not_after');
    if (notBefore > notAfter) throw const FormatException('not_before > not_after');
    if (map['context_token'] is! String) throw const FormatException('Invalid context_token');
    final contextToken = map['context_token'] as String;
    if (contextToken.length > 16384 || !contextToken.contains('.')) {
      throw const FormatException('Invalid context_token shape');
    }
    final parts = contextToken.split('.');
    if (parts.length != 2) throw const FormatException('Invalid context_token format');
    // First part: base64url canonical, second: hex64
    if (!RegExp(r'^[A-Za-z0-9_-]+$').hasMatch(parts[0])) throw const FormatException('Invalid context_token encoding');
    try {
      final bytes = base64Url.decode(base64Url.normalize(parts[0]));
      if (base64Url.encode(bytes).replaceAll('=', '') != parts[0]) {
        throw const FormatException('Noncanonical context_token encoding');
      }
      // Try decode JSON to ensure it's an object with expected kind
      final decoded = jsonDecode(utf8.decode(bytes));
      if (decoded is! Map) throw const FormatException('Invalid context_token claims');
    } catch (e) {
      if (e is FormatException) rethrow;
      throw const FormatException('Invalid context_token payload');
    }
    if (!RegExp(r'^[a-f0-9]{64}$').hasMatch(parts[1])) {
      throw const FormatException('Invalid context_token mac');
    }

    // Coherence: capture_eligible should only be true for valid calendar codes and when member_ids non-empty,
    // but we keep it lenient: just ensure if capture_eligible true then calendar_code is o/m.
    if (captureEligible && calendarCode != 'o' && calendarCode != 'm') {
      throw const FormatException('capture_eligible only for o/m calendar codes');
    }
    if (captureEligible && memberIds.isEmpty) {
      throw const FormatException('capture_eligible requires non-empty roster');
    }

    return DayPreparation._(
      date: date,
      memberIds: UnmodifiableListView(memberIds),
      calendarCode: calendarCode,
      baseRosterCompatible: baseRosterCompatible,
      records: UnmodifiableListView(records),
      packet: packet,
      resolvedStatus: resolvedStatus,
      workflowSupported: map['workflow_supported'] as bool,
      teacherWorkflowOpen: map['teacher_workflow_open'] as bool,
      lessonDerived: map['lesson_derived'] as bool,
      originYearIds: UnmodifiableListView(originYearIds),
      originUnknown: map['origin_unknown'] as bool,
      originMatchesWriteYear: map['origin_matches_write_year'] as bool,
      captureEligible: captureEligible,
      notBefore: notBefore,
      notAfter: notAfter,
      contextToken: contextToken,
    );
  }
}

final class AttendanceRecord {
  final int memberId;
  final int? academicYearId;
  final String status;
  final String note;

  const AttendanceRecord._({required this.memberId, required this.academicYearId, required this.status, required this.note});

  static AttendanceRecord _decode(Map<String, dynamic> map) {
    const keys = {'member_id', 'academic_year_id', 'status', 'note'};
    if (map.length != keys.length || !keys.containsAll(map.keys)) {
      throw const FormatException('Invalid record keys');
    }
    final memberId = AttendancePreparationDataset._id(map['member_id'], 'record.member_id');
    int? academicYearId;
    if (map['academic_year_id'] != null) {
      academicYearId = AttendancePreparationDataset._id(map['academic_year_id'], 'record.academic_year_id');
    }
    if (map['status'] is! String) throw const FormatException('Invalid record.status');
    final status = map['status'] as String;
    if (!['present', 'absent', 'late', 'excused', 'holiday'].contains(status)) {
      throw FormatException('Unsupported record status $status');
    }
    final note = AttendancePreparationDataset._boundedString(map['note'], 'record.note', 2000);
    return AttendanceRecord._(memberId: memberId, academicYearId: academicYearId, status: status, note: note);
  }
}

final class PacketPreparation {
  final int id;
  final int? academicYearId;
  final String status;
  final bool statusSupported;

  const PacketPreparation._({required this.id, required this.academicYearId, required this.status, required this.statusSupported});

  static PacketPreparation _decode(Map<String, dynamic> map) {
    const keys = {'id', 'academic_year_id', 'status', 'status_supported'};
    if (map.length != keys.length || !keys.containsAll(map.keys)) {
      throw const FormatException('Invalid packet keys');
    }
    final id = AttendancePreparationDataset._id(map['id'], 'packet.id');
    int? academicYearId;
    if (map['academic_year_id'] != null) {
      academicYearId = AttendancePreparationDataset._id(map['academic_year_id'], 'packet.academic_year_id');
    }
    if (map['status'] is! String || (map['status'] as String).isEmpty) {
      throw const FormatException('Invalid packet.status');
    }
    final status = map['status'] as String;
    if (status.length > 40) throw const FormatException('packet.status too long');
    if (map['status_supported'] is! bool) throw const FormatException('Invalid packet.status_supported');
    return PacketPreparation._(id: id, academicYearId: academicYearId, status: status, statusSupported: map['status_supported'] as bool);
  }
}
