import 'dart:collection';
import 'dart:convert';

/// Validated, immutable view of a complete server grades preparation payload.
///
/// Same safety posture as [AttendancePreparationDataset]: validation, not
/// authority. Does not verify HMAC, enforce session/role, or publish.
/// Inactive: no app code imports it yet.
///
/// Payload comes from `GET /grades/preparation` via [GradesPreparationEnvelope]
/// and is validated byte-for-byte. Fail-closed: any missing/extra/duplicate/
/// mistyped/incoherent field is a [FormatException].
final class GradesPreparationDataset {
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
  final WriteYear writeYear;
  final List<GradeMember> members;
  final List<GradeClass> classes;
  final List<GradeTerm> terms;

  const GradesPreparationDataset._({
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
    required this.writeYear,
    required this.members,
    required this.classes,
    required this.terms,
  });

  static GradesPreparationDataset decode(String payloadUtf8) {
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
      'write_year',
      'terms',
      'members',
      'classes',
    };
    _exactKeys(map, topKeys, 'payload');
    if (map['preparation_version'] != 1) throw const FormatException('Unsupported preparation_version');
    if (map['complete'] != true) throw const FormatException('Preparation must be complete');
    final ownerUserId = _id(map['owner_user_id'], 'owner_user_id');
    final authorizationVersion = _id(map['authorization_version'], 'authorization_version');
    final role = _nonEmptyString(map['role'], 'role');
    final familyId = _hex(map['family_id'], 'family_id');
    final issuedAt = _positiveInt(map['issued_at'], 'issued_at');
    final deadline = _positiveInt(map['deadline'], 'deadline');
    if (deadline <= issuedAt || deadline - issuedAt > 30 * 86400) throw const FormatException('Invalid preparation lease bounds');
    final preparationId = _hex(map['preparation_id'], 'preparation_id');
    final serverIdentity = _serverIdentity(map['server_identity']);
    final serverToday = _date(map['server_today'], 'server_today');
    final writeYear = WriteYear._decode(_asMap(map['write_year'], 'write_year'));
    if (writeYear.from.compareTo(writeYear.to) > 0) throw const FormatException('Invalid write_year window');
    if (serverToday.compareTo(writeYear.from) < 0 || serverToday.compareTo(writeYear.to) > 0) throw const FormatException('server_today outside write_year');
    final members = (_asList(map['members'], 'members')).asMap().entries.map((e) => GradeMember._decode(_asMap(e.value, 'members[${e.key}]'))).toList();
    _noDuplicateMembers(members);
    final terms = (_asList(map['terms'], 'terms')).asMap().entries.map((e) => GradeTerm._decode(_asMap(e.value, 'terms[${e.key}]'))).toList();
    final classes = (_asList(map['classes'], 'classes')).asMap().entries.map((e) => GradeClass._decode(_asMap(e.value, 'classes[${e.key}]'), members)).toList();
    _noDuplicateClasses(classes);
    return GradesPreparationDataset._(
      raw: UnmodifiableMapView(Map<String, dynamic>.from(map)),
      preparationVersion: 1, complete: true, ownerUserId: ownerUserId, authorizationVersion: authorizationVersion,
      role: role, familyId: familyId, issuedAt: issuedAt, deadline: deadline,
      preparationId: preparationId, serverIdentity: serverIdentity, serverToday: serverToday,
      writeYear: writeYear, members: UnmodifiableListView(members), classes: UnmodifiableListView(classes),
      terms: UnmodifiableListView(terms),
    );
  }

  static void _noDuplicateMembers(List<GradeMember> members) {
    final seen = <int>{};
    for (final m in members) { if (!seen.add(m.memberId)) throw FormatException('Duplicate member_id ${m.memberId}'); }
  }
  static void _noDuplicateClasses(List<GradeClass> classes) {
    final seen = <int>{};
    for (final c in classes) { if (!seen.add(c.classId)) throw FormatException('Duplicate class_id ${c.classId}'); }
  }
}

final class WriteYear {
  final int id; final String from; final String to; final String status;
  const WriteYear._({required this.id, required this.from, required this.to, required this.status});
  static WriteYear _decode(Map<String, dynamic> map) {
    const keys = {'id','from','to','status'}; _exactKeys(map, keys, 'write_year');
    final id = _id(map['id'], 'write_year.id'); final from = _date(map['from'], 'write_year.from'); final to = _date(map['to'], 'write_year.to');
    final status = _nonEmptyString(map['status'], 'write_year.status'); if (status != 'active') throw const FormatException('write_year.status must be active');
    return WriteYear._(id: id, from: from, to: to, status: status);
  }
}

final class GradeMember {
  final int memberId; final String studentName; final String fatherName; final String memberCode;
  const GradeMember._({required this.memberId, required this.studentName, required this.fatherName, required this.memberCode});
  static GradeMember _decode(Map<String, dynamic> map) {
    const keys = {'member_id','student_name','father_name','member_code'}; _exactKeys(map, keys, 'member');
    return GradeMember._(memberId: _id(map['member_id'], 'member.member_id'), studentName: _nonEmptyString(map['student_name'], 'member.student_name'), fatherName: _nonEmptyString(map['father_name'], 'member.father_name'), memberCode: _nonEmptyString(map['member_code'], 'member.member_code'));
  }
}

final class GradeTerm {
  final int id; final String termName; final int termNumber; final bool isCurrent; final bool isReopened;
  const GradeTerm._({required this.id, required this.termName, required this.termNumber, required this.isCurrent, required this.isReopened});
  static GradeTerm _decode(Map<String, dynamic> map) {
    const keys = {'id','term_name','term_number','is_current','is_reopened'}; _exactKeys(map, keys, 'term');
    return GradeTerm._(id: _id(map['id'], 'term.id'), termName: _nonEmptyString(map['term_name'], 'term.term_name'), termNumber: _nonNegativeInt(map['term_number'], 'term.term_number'), isCurrent: _bool(map['is_current'], 'term.is_current'), isReopened: _bool(map['is_reopened'], 'term.is_reopened'));
  }
}

final class GradeSubject {
  final int id; final String subjectName; final String? subjectNameEn; final String? subjectCode;
  const GradeSubject._({required this.id, required this.subjectName, required this.subjectNameEn, required this.subjectCode});
  static GradeSubject _decode(Map<String, dynamic> map) {
    const keys = {'id','subject_name','subject_name_en','subject_code'}; _exactKeys(map, keys, 'subject');
    return GradeSubject._(id: _id(map['id'], 'subject.id'), subjectName: _nonEmptyString(map['subject_name'], 'subject.subject_name'), subjectNameEn: map['subject_name_en'] == null ? null : _nonEmptyString(map['subject_name_en'], 'subject.subject_name_en'), subjectCode: map['subject_code'] == null ? null : _nonEmptyString(map['subject_code'], 'subject.subject_code'));
  }
}

final class GradeAssessment {
  final int id; final int subjectId; final String assessmentName; final String assessmentType; final double weightPercentage; final double maxScore; final int assessmentOrder; final bool isPublished;
  const GradeAssessment._({required this.id, required this.subjectId, required this.assessmentName, required this.assessmentType, required this.weightPercentage, required this.maxScore, required this.assessmentOrder, required this.isPublished});
  static GradeAssessment _decode(Map<String, dynamic> map) {
    const keys = {'id','subject_id','assessment_name','assessment_type','weight_percentage','max_score','assessment_order','is_published'}; _exactKeys(map, keys, 'assessment');
    return GradeAssessment._(id: _id(map['id'], 'assessment.id'), subjectId: _id(map['subject_id'], 'assessment.subject_id'), assessmentName: _nonEmptyString(map['assessment_name'], 'assessment.assessment_name'), assessmentType: _nonEmptyString(map['assessment_type'], 'assessment.assessment_type'), weightPercentage: _double(map['weight_percentage'], 'assessment.weight_percentage'), maxScore: _double(map['max_score'], 'assessment.max_score'), assessmentOrder: _nonNegativeInt(map['assessment_order'], 'assessment.assessment_order'), isPublished: _bool(map['is_published'], 'assessment.is_published'));
  }
}

final class GradeClass {
  final int classId; final String className; final String classNameEn; final String section;
  final int writeYearId; final int rosterYearId; final bool rosterFallback;
  final List<int> memberIds; final List<GradeSubject> subjects; final List<GradeAssessment> assessments;
  const GradeClass._({required this.classId, required this.className, required this.classNameEn, required this.section, required this.writeYearId, required this.rosterYearId, required this.rosterFallback, required this.memberIds, required this.subjects, required this.assessments});
  static GradeClass _decode(Map<String, dynamic> map, List<GradeMember> allMembers) {
    const keys = {'class_id','class_name','class_name_en','section','write_year_id','roster_year_id','roster_fallback','member_ids','subjects','assessments'}; _exactKeys(map, keys, 'class');
    final classId = _id(map['class_id'], 'class.class_id'); final className = _nonEmptyString(map['class_name'], 'class.class_name'); final classNameEn = _nonEmptyString(map['class_name_en'], 'class.class_name_en'); final section = _nonEmptyString(map['section'], 'class.section');
    final writeYearId = _id(map['write_year_id'], 'class.write_year_id'); final rosterYearId = _id(map['roster_year_id'], 'class.roster_year_id'); final rosterFallback = _bool(map['roster_fallback'], 'class.roster_fallback');
    final memberIds = (_asList(map['member_ids'], 'class.member_ids')).map((v) => _id(v, 'class.member_ids[]')).toList();
    final memberSet = allMembers.map((m)=>m.memberId).toSet(); for (final id in memberIds) { if (!memberSet.contains(id)) throw FormatException('class $classId member_id $id not in members'); }
    if (memberIds.length != memberIds.toSet().length) throw const FormatException('Duplicate member_ids in class');
    final subjects = (_asList(map['subjects'], 'class.subjects')).asMap().entries.map((e) => GradeSubject._decode(_asMap(e.value, 'class.subjects[${e.key}]'))).toList();
    final subjectIds = subjects.map((s)=>s.id).toSet(); if (subjectIds.length != subjects.length) throw FormatException('Duplicate subject id in class $classId');
    final assessments = (_asList(map['assessments'], 'class.assessments')).asMap().entries.map((e) => GradeAssessment._decode(_asMap(e.value, 'class.assessments[${e.key}]'))).toList();
    final seenA = <int>{}; for (final a in assessments) { if (!seenA.add(a.id)) throw FormatException('Duplicate assessment id ${a.id} in class $classId'); if (!subjectIds.contains(a.subjectId)) throw FormatException('assessment ${a.id} subject ${a.subjectId} not in class $classId subjects'); }
    return GradeClass._(classId: classId, className: className, classNameEn: classNameEn, section: section, writeYearId: writeYearId, rosterYearId: rosterYearId, rosterFallback: rosterFallback, memberIds: UnmodifiableListView(memberIds), subjects: UnmodifiableListView(subjects), assessments: UnmodifiableListView(assessments));
  }
}

// ---- helpers (shared with attendance) ----
Map<String, dynamic> _asMap(dynamic v, String ctx) { if (v is Map<String, dynamic>) return v; if (v is Map) return Map<String, dynamic>.from(v); throw FormatException('Expected map for $ctx'); }
List<dynamic> _asList(dynamic v, String ctx) { if (v is List) return v; throw FormatException('Expected list for $ctx'); }
void _exactKeys(Map<String, dynamic> map, Set<String> expected, String ctx) { final keys = map.keys.toSet(); if (keys.length != expected.length || !keys.containsAll(expected)) throw FormatException('Wrong keys for $ctx: expected $expected got $keys'); }
int _id(dynamic v, String ctx) { if (v is int && v > 0 && v <= 9007199254740991) return v; throw FormatException('Invalid id for $ctx: $v'); }
int _positiveInt(dynamic v, String ctx) { if (v is int && v > 0) return v; throw FormatException('Invalid positive int for $ctx'); }
int _nonNegativeInt(dynamic v, String ctx) { if (v is int && v >= 0) return v; throw FormatException('Invalid non-negative int for $ctx'); }
double _double(dynamic v, String ctx) { if (v is num) return v.toDouble(); throw FormatException('Invalid double for $ctx'); }
bool _bool(dynamic v, String ctx) { if (v is bool) return v; throw FormatException('Invalid bool for $ctx'); }
String _nonEmptyString(dynamic v, String ctx) { if (v is String && v.isNotEmpty) return v; throw FormatException('Invalid string for $ctx'); }
String _hex(dynamic v, String ctx) { if (v is String && RegExp(r'^[a-f0-9]{64}$').hasMatch(v)) return v; throw FormatException('Invalid hex64 for $ctx'); }
String _date(dynamic v, String ctx) { if (v is String && RegExp(r'^[0-9]{4}-[0-9]{2}-[0-9]{2}$').hasMatch(v)) { final y=int.parse(v.substring(0,4)), m=int.parse(v.substring(5,7)), d=int.parse(v.substring(8,10)); if (m>=1&&m<=12&&d>=1&&d<=31) return v; } throw FormatException('Invalid date for $ctx'); }
String _serverIdentity(dynamic v) { if (v is String && v.startsWith('https://') && v.endsWith('/api/v1') && !v.contains(' ')) return v; throw const FormatException('Invalid server_identity'); }
int _daysBetween(String from, String to) { return DateTime.parse(to).difference(DateTime.parse(from)).inDays; }
