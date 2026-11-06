import 'api_service.dart';
import 'attendance_calendar_cache.dart';
import 'attendance_preparation_envelope.dart';
import 'attendance_preparation_fetcher.dart';
import 'attendance_preparation_store.dart';
import 'catalog_service.dart';
import 'grades_preparation_envelope.dart';
import 'grades_preparation_fetcher.dart';
import 'grades_preparation_store.dart';
import 'legacy_outbox_models.dart';
import 'local_db.dart';
import 'timetable_cache.dart';
import '../utils/attendance_calendar.dart';
import '../utils/config.dart';
import '../utils/roster.dart';

/// Login-time offline workspace prefetch (Phase 1).
///
/// When the user signs in (and on app resume — same trigger), download what
/// their role needs so every class/subject/assessment opens offline:
/// classes, per-class roster + subjects + assessments + today's sheet (field
/// roles only), class-day calendar, timetable, dashboard, both preparation
/// bundles. A teacher with 5 classes costs ~26 small JSON calls, sequential,
/// skip-if-cached — 2G-safe, no images/audio.
///
/// Hard rules (contract tests pin these):
/// - one owner: called ONLY from SessionService (never from screens);
/// - every network/cache step re-checks the session generation and
///   `sessionSuperseded`, so user A's warm never writes under user B;
/// - transport failures (offline/429/auth) stop quietly; data failures skip
///   one item and continue; nothing here ever blocks UI or throws outward.
/// Deliberately NOT warmed: per-assessment grade sheets (long tail, stays
/// lazy-on-open with cache) and whole-school lists for dept/admin roles.
class WarmStore {
  static final WarmStore _instance = WarmStore._internal();
  factory WarmStore() => _instance;
  WarmStore._internal();

  /// Defensive bound on warmed classes per run. Teachers hold a handful;
  /// the cap only bites pathological accounts (and dept/admin roles never
  /// enter the per-class loop at all).
  static const int maxWarmClasses = 20;

  int? _runningGeneration;
  bool Function()? activeSessionGate;
  int Function()? sessionGenerationProvider;

  bool _ownsGeneration(int generation) =>
      activeSessionGate?.call() != false &&
      generation == (sessionGenerationProvider?.call() ?? generation);

  Future<void> afterLogin() async {
    if (activeSessionGate?.call() == false || !ApiService().isLoggedIn) {
      return;
    }
    final generation = sessionGenerationProvider?.call() ?? 0;
    if (_runningGeneration == generation) return;
    _runningGeneration = generation;
    try {
      final classes = await CatalogService()
          .classes(expectedGeneration: generation);
      if (!_ownsGeneration(generation)) return;
      // Shared workspace first (all roles; server scopes or refuses each
      // call, so nothing beyond the bearer's permission can land).
      if (!await _warmShared(generation)) return;
      // Per-class workspace: ONLY field roles that own a handful of
      // classes. Dept/admin roles can see the whole school — warming every
      // visible class for them would be a prefetch storm.
      final role = ApiService().userRole;
      final fieldRole =
          role == UserRoles.teacher || role == UserRoles.attendanceTaker;
      if (!fieldRole) return;
      var warmed = 0;
      for (final c in classes) {
        if (!_ownsGeneration(generation) || warmed >= maxWarmClasses) return;
        final id = _asInt(c is Map ? c['id'] : null);
        if (id == null) continue;
        warmed++;
        // Sequential on purpose: bounded 2G load, no fan-out storm.
        if (!await _warmClass(id, generation)) return;
      }
    } catch (_) {
      // Never block the UI. Next login/resume/open will try again.
    } finally {
      if (_runningGeneration == generation) _runningGeneration = null;
    }
  }

  /// One class's offline workspace. False = stop everything (offline/429).
  Future<bool> _warmClass(int classId, int generation) async {
    if (!await _warmRoster(classId, generation)) return false;
    if (!await _warmGrades(classId, generation)) return false;
    if (!await _warmAttendance(classId, generation)) return false;
    return true;
  }

  /// Returns false when warming must stop (offline/429/auth); true otherwise.
  Future<bool> _warmShared(int generation) async {
    final api = ApiService();
    final db = LocalDb();
    // Dashboard (skip if cached).
    try {
      final cachedStats = await db.getCachedDashboardStats();
      if (!_ownsGeneration(generation)) return false;
      if (cachedStats == null) {
        final res = await api.getDashboardStats();
        if (!_ownsGeneration(generation) || res.sessionSuperseded) return false;
        if (_isTransportStop(res)) return false;
        if (res.success && res.data is Map && _ownsGeneration(generation)) {
          await db.cacheDashboardStats(
              Map<String, dynamic>.from(res.data as Map), api.userRole);
        }
      }
    } catch (_) {}
    if (!_ownsGeneration(generation)) return false;
    // Class-day calendar (etag → cheap 304 when unchanged).
    try {
      final scope = AttendanceCalendarCache.key(
          AppConfig.apiBaseUrl, api.userId, api.authorizationVersion);
      final cached = await AttendanceCalendarCache().read(scope);
      if (!_ownsGeneration(generation)) return false;
      final etag = cached?.payload['etag'];
      final res =
          await api.getAttendanceCalendar(etag: etag is String ? etag : null);
      if (!_ownsGeneration(generation) || res.sessionSuperseded) return false;
      if (_isTransportStop(res)) return false;
      if (!res.notModified && res.success && res.data != null) {
        final calendar = AttendanceCalendar.parse(res.data);
        if (calendar != null && _ownsGeneration(generation)) {
          await AttendanceCalendarCache().save(scope, calendar,
              isCurrent: () => _ownsGeneration(generation));
        }
      }
    } catch (_) {}
    if (!_ownsGeneration(generation)) return false;
    // Timetable week (skip if cached).
    try {
      final uid = api.userId;
      final have = await TimetableCache.instance.read(uid);
      if (!_ownsGeneration(generation)) return false;
      if (have == null) {
        final res = await api.getMyTimetableWeek();
        if (!_ownsGeneration(generation) || res.sessionSuperseded) return false;
        if (_isTransportStop(res)) return false;
        if (res.success && res.data is Map && _ownsGeneration(generation)) {
          await TimetableCache.instance
              .save(uid, Map<String, dynamic>.from(res.data as Map));
        }
      }
    } catch (_) {}
    if (!_ownsGeneration(generation)) return false;
    // Preparation bundles (server refuses roles without preps — silent skip).
    try {
      await _warmPreparations(generation);
    } catch (_) {}
    return _ownsGeneration(generation);
  }

  Future<void> _warmPreparations(int generation) async {
    final api = ApiService();
    final db = LocalDb();
    final fid = api.familyId;
    if (fid == null || fid.isEmpty) return;
    final rec = await db.getLocalSession();
    final session = LegacyWriteSession(
      ownerUserId: rec.ownerUserId ?? api.userId,
      authorizationVersion:
          rec.authorizationVersion ?? api.authorizationVersion,
      runtimeGeneration: rec.generation,
    );
    if (!session.isValid || !_ownsGeneration(generation)) return;
    try {
      final scope = GradesPreparationScope(
        ownerUserId: session.ownerUserId,
        authorizationVersion: session.authorizationVersion,
        role: api.userRole,
        familyId: fid,
        serverIdentity: AppConfig.apiBaseUrl,
      );
      await GradesPreparationFetcher(store: GradesPreparationStore(db: db))
          .fetchAndPublish(
              session: session, get: (p) => api.get(p), expectedScope: scope);
    } catch (_) {}
    if (!_ownsGeneration(generation)) return;
    try {
      final scope = AttendancePreparationScope(
        ownerUserId: session.ownerUserId,
        authorizationVersion: session.authorizationVersion,
        role: api.userRole,
        familyId: fid,
        serverIdentity: AppConfig.apiBaseUrl,
      );
      await AttendancePreparationFetcher(
              store: AttendancePreparationStore(db: db))
          .fetchAndPublish(
              session: session, get: (p) => api.get(p), expectedScope: scope);
    } catch (_) {}
  }

  Future<bool> _warmRoster(int classId, int generation) async {
    final db = LocalDb();
    try {
      final have = await db.getCachedStudents(classId);
      if (!_ownsGeneration(generation)) return false;
      if (have.isNotEmpty) return true;
      final res = await ApiService().getClassStudents(classId);
      if (!_ownsGeneration(generation) || res.sessionSuperseded) return false;
      if (_isTransportStop(res)) return false;
      if (!res.success || res.data == null) return true;
      // Canonical roster parser — same contract the screens use.
      final students = RosterParse.students(res.data);
      if (students.isEmpty || !_ownsGeneration(generation)) return true;
      await db.cacheStudents(classId, students);
      return _ownsGeneration(generation);
    } catch (_) {
      return true;
    }
  }

  Future<bool> _warmGrades(int classId, int generation) async {
    final db = LocalDb();
    try {
      final have = await db.getCachedSubjects(classId);
      if (!_ownsGeneration(generation)) return false;
      if (have.isNotEmpty) return true;
      final res = await ApiService().getGradeBootstrap(classId);
      if (!_ownsGeneration(generation) || res.sessionSuperseded) return false;
      if (_isTransportStop(res)) return false;
      if (!res.success || res.data == null) return true;
      final subjects = res.data['subjects'];
      if (subjects is List && subjects.isNotEmpty) {
        if (!_ownsGeneration(generation)) return false;
        await db.cacheSubjects(classId, subjects);
      }
      if (!_ownsGeneration(generation)) return false;
      final assessments = res.data['assessments'];
      if (assessments is List && assessments.isNotEmpty) {
        final bySubject = <int, List<dynamic>>{};
        for (final a in assessments) {
          if (a is! Map) continue;
          final sid = _asInt(a['subject_id']);
          if (sid == null) continue;
          bySubject.putIfAbsent(sid, () => []).add(a);
        }
        for (final e in bySubject.entries) {
          if (!_ownsGeneration(generation)) return false;
          await db.cacheAssessments(classId, e.key, e.value);
        }
      }
      return true;
    } catch (_) {
      return true;
    }
  }

  Future<bool> _warmAttendance(int classId, int generation) async {
    final db = LocalDb();
    final date = _today();
    try {
      final cached = await db.getCachedAttendanceResponse(classId, date);
      if (!_ownsGeneration(generation)) return false;
      if (cached != null && cached.isNotEmpty) return true;
      final res = await ApiService().getAttendance(classId, date: date);
      if (!_ownsGeneration(generation) || res.sessionSuperseded) return false;
      if (_isTransportStop(res)) return false;
      if (!res.success || res.data == null) return true;
      final raw = res.data['students'];
      if (raw is! List || raw.isEmpty) return true;
      final students = raw
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .toList();
      if (students.isEmpty || !_ownsGeneration(generation)) return true;
      await db.cacheAttendanceResponse(classId, date, students);
      if (!_ownsGeneration(generation)) return false;
      await db.cacheStudents(classId, students);
      return true;
    } catch (_) {
      return true;
    }
  }

  /// Transport-level failures (offline/DNS, 429, auth): more calls cannot
  /// succeed, so warming stops quietly until the next trigger. Anything
  /// else skips one item and continues.
  bool _isTransportStop(ApiResponse res) =>
      res.isNetworkError || res.isAuthError || res.statusCode == 429;

  static int? _asInt(dynamic v) {
    if (v is int) return v;
    return int.tryParse('$v');
  }

  static String _today() {
    final n = DateTime.now();
    final m = n.month.toString().padLeft(2, '0');
    final d = n.day.toString().padLeft(2, '0');
    return '${n.year}-$m-$d';
  }
}
