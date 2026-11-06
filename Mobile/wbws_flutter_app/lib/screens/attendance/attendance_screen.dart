import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../services/api_service.dart';
import '../../services/attendance_calendar_cache.dart';
import '../../utils/attendance_calendar.dart';
import '../../utils/config.dart';
import '../../services/app_nav.dart';
import '../../services/catalog_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/legacy_outbox_models.dart';
import '../../services/local_db.dart';
import '../../services/session_models.dart';
import '../../services/api_service.dart';
import '../../services/attendance_preparation_adapter.dart';
import '../../services/attendance_preparation_envelope.dart';
import '../../services/attendance_preparation_fetcher.dart';
import '../../services/attendance_preparation_readiness.dart';
import '../../services/attendance_preparation_store.dart';
import '../../widgets/sync_attention.dart';
import '../../services/sync_service.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/packet.dart';
import '../../utils/roster.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/action_bar.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/quick_confirm.dart';
import '../../widgets/qr_scan_sheet.dart';
import '../../widgets/status_banner.dart';
import '../../services/qr_attendance.dart';
import '../attendance_warnings/attendance_warning_widgets.dart';

class AttendanceScreen extends StatefulWidget {
  final int? initialClassId;
  /// Gregorian yyyy-MM-dd (API form). Used by the bell deep-link when
  /// Education returns / reopens / rejects a sheet (Phase 3, Q-2).
  final String? initialDate;
  const AttendanceScreen({super.key, this.initialClassId, this.initialDate})
      : _apiOverride = null, _dbOverride = null, _syncOverride = null,
        _classesLoader = null;

  /// Executes this screen with controlled I/O in regression tests.
  @visibleForTesting
  const AttendanceScreen.withCollaborators({super.key, this.initialClassId,
    this.initialDate, required ApiService api, required LocalDb db,
    required SyncService sync, required Future<List<dynamic>> Function() classes})
      : _apiOverride = api, _dbOverride = db, _syncOverride = sync,
        _classesLoader = classes;
  final ApiService? _apiOverride;
  final LocalDb? _dbOverride;
  final SyncService? _syncOverride;
  final Future<List<dynamic>> Function()? _classesLoader;

  @override
  State<AttendanceScreen> createState() => AttendanceScreenState();
}

class AttendanceScreenState extends State<AttendanceScreen> with WidgetsBindingObserver {
  late final _api = widget._apiOverride ?? ApiService();
  late final _db = widget._dbOverride ?? LocalDb();
  late final _sync = widget._syncOverride ?? SyncService();

  List<dynamic> _classes = [];
  int? _selectedClassId;
  String? _selectedClassName;
  String _selectedDate = AttendanceCalendar.ymd(AttendanceCalendar.addisToday(DateTime.now()));
  List<Map<String, dynamic>> _students = [];
  bool _loadingClasses = true;
  bool _loadingStudents = false;
  bool _rosterReady = false;
  bool _isOffline = false;
  bool _loadFailed = false;
  String? _error;
  String? _successMsg;
  String? _rosterNote;
  String _packetStatus = '';
  // Class-day-only attendance (owner rules 2026-10-20): verdict for the
  // selected date from the server ({ok, code, label, detail, is_makeup}).
  // A date/class-specific online verdict wins over the cached projection.
  // Missing information is shown explicitly; it never silently allows edits.
  Map<String, dynamic>? _dateStatus;
  AttendanceCalendar? _calendar;
  bool _calendarFromCache = false;
  /// Episode key of the last saved-calendar toast; null = fresh data shown.
  String? _calendarToastKey;
  final _calendarCache = AttendanceCalendarCache();
  int _loadEpoch = 0;
  bool _accessDenied = false;
  int? _sheetOwner;
  int? _sheetGeneration;
  int? _sheetAuthorizationVersion;
  StreamSubscription<SyncStatus>? _syncSub;
  // Preparation-based offline readiness (inactive UI hook, truthful banners).
  AttendancePreparationResolution? _prepResolution;
  final _prepAdapter = AttendancePreparationAdapter();
  String? get _prepBanner {
    final r = _prepResolution;
    if (r == null) return null;
    switch (r.reason) {
      case AttendanceReadinessReason.ready:
        return null; // No banner when ready; offline is honest.
      case AttendanceReadinessReason.missing:
        return 'No offline preparation yet — connect and refresh to download your classes and roster.';
      case AttendanceReadinessReason.staleSession:
        return 'Your sign-in changed — refresh your preparation before taking attendance offline.';
      case AttendanceReadinessReason.expired:
        return 'Your offline preparation expired — connect and refresh to renew it.';
      case AttendanceReadinessReason.clockSkew:
        return 'Your phone clock looks wrong — fix the date/time, then refresh.';
    }
  }

  Map<String, dynamic>? get _effectiveDateStatus {
    final today = AttendanceCalendar.addisToday(DateTime.now());
    final date = AttendanceCalendar.parseDate(_selectedDate);
    if (date != null && date.isAfter(today)) {
      return AttendanceCalendar.verdict(false, 'FUTURE_DATE', 'Attendance opens on the class day.');
    }
    final cached = _selectedClassId == null ? null
        : _calendar?.status(_selectedClassId!, _selectedDate, DateTime.now());
    if (cached?['code'] == 'BACKDATE_LIMIT') return cached;
    return _dateStatus ?? cached;
  }
  bool get _dayAllowed => _effectiveDateStatus?['ok'] == true;
  bool get _sheetSessionCurrent => _sheetOwner == _api.userId &&
      _sheetGeneration == (_api.sessionGenerationProvider?.call() ?? 0) &&
      _sheetAuthorizationVersion == _api.authorizationVersion;
  LegacyWriteSession get _sheetWriteSession => LegacyWriteSession(
        ownerUserId: _sheetOwner!,
        authorizationVersion: _sheetAuthorizationVersion!,
        runtimeGeneration: _sheetGeneration!,
      );
  bool get _canEdit => !_accessDenied && _sheetSessionCurrent &&
      _dayAllowed && !_locked && !_loadingStudents && _rosterReady;
  String get _dayMessage {
    final detail = _effectiveDateStatus?['detail'];
    if (detail is String && detail.isNotEmpty) return detail;
    return _effectiveDateStatus == null
        ? 'Class-day information is unavailable. Connect and refresh before taking attendance.'
        : 'Attendance cannot be recorded for this date.';
  }

  final ValueNotifier<bool> _saveGate = ValueNotifier(false);
  void _syncSaveGate() {
    _saveGate.value = _dirty.value && _canEdit;
  }
  /// Education's reason when this sheet was returned for correction.
  String? _returnNote;
  bool _noticesExpanded = false;
  int _pendingCount = 0;
  int _rejectedCount = 0;
  /// WhatsApp-state visibility: true the moment anything changes after a
  /// save; drives the Save button's grayed-out/lit appearance without
  /// rebuilding the student list (ValueListenable -> action bar only).
  final ValueNotifier<bool> _dirty = ValueNotifier(false);
  StreamSubscription<bool>? _netSub;

  // Phase 8: instant autosave (every mutation → durable local draft).
  final Debounce _autoSave = Debounce();
  DateTime _lastSyncPush = DateTime.fromMillisecondsSinceEpoch(0);
  bool _submitting = false;
  int _editRevision = 0;
  int _submissionRevision = 0;
  int _durableRevision = 0;

  static const Set<String> _validStatuses = {
    'present',
    'absent',
    'late',
    'excused',
  };

  String _statusOf(dynamic value) {
    final status = '${value ?? ''}'.trim().toLowerCase();
    return _validStatuses.contains(status) ? status : '';
  }

  String _firstStatus(Iterable<dynamic> values) {
    for (final value in values) {
      final status = _statusOf(value);
      if (status.isNotEmpty) return status;
    }
    return '';
  }

  /// Default-absent (2026-10-07): every student loads as ABSENT and
  /// the teacher (or a QR scan) marks presence. An unresolved status
  /// resolves to the conservative absent — presence is never assumed.
  String _orAbsent(String status) => status.isEmpty ? 'absent' : status;

  int _countStatus(String status) =>
      _students.where((s) => _statusOf(s['status']) == status).length;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final wantedDate = widget.initialDate;
    if (wantedDate != null && RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(wantedDate)) {
      _selectedDate = wantedDate;
    }
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (!mounted || _isOffline == !hasLink) return;
      setState(() => _isOffline = !hasLink);
      if (hasLink) {
        unawaited(_loadAttendance());
        unawaited(_loadPreparationStatus());
      } else {
        _maybeShowCalendarToast();
      }
    });
    _dirty.addListener(_syncSaveGate);
    _syncSaveGate();
    _loadClasses();
    _updatePendingCount();
    _loadPreparationStatus();

    // Listen for sync updates
    _syncSub = _sync.syncStream.listen((status) {
      if (mounted) {
        setState(() {
          _pendingCount = status.pendingAttendance;
          _rejectedCount = status.rejected;
        });
      }
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    // Queue the captured valid draft before cancelling the debounce on tab/back
    // navigation. Completion is UI-fenced below; no disposed notifier is touched.
    if (_durableRevision < _editRevision && _canEdit) {
      unawaited(_autoSaveNow());
    }
    _loadEpoch++;
    _netSub?.cancel();
    _syncSub?.cancel();
    _autoSave.dispose();
    _dirty.removeListener(_syncSaveGate);
    _dirty.dispose();
    _saveGate.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_loadAttendance());
      unawaited(_loadPreparationStatus());
    }
  }

  void refresh() {
    _loadClasses();
    _updatePendingCount();
  }

  Future<void> _updatePendingCount() async {
    final count = await _db.getPendingAttendanceCount();
    final rejected = (await _db.getRejectedBatches())
        .where((b) => b['kind'] == 'attendance')
        .length;
    if (mounted) {
      setState(() {
        _pendingCount = count;
        _rejectedCount = rejected;
      });
    }
  }

  bool _refreshingPrep = false;

  Future<void> _loadPreparationStatus() async {
    try {
      final res = await _prepAdapter.resolve();
      if (!mounted) return;
      setState(() => _prepResolution = res);
      // Silent auto-download: non-technical teachers never tap Refresh.
      if (!res.isReady && ConnectivityService().hasLink) {
        unawaited(_refreshPreparation(silent: true));
      }
    } catch (_) {
      // Preparation status is advisory; never blocks the roster.
    }
  }

  Future<void> _refreshPreparation({bool silent = false}) async {
    if (_refreshingPrep) return;
    setState(() => _refreshingPrep = true);
    try {
      final sessionRec = await _db.getLocalSession();
      final session = LegacyWriteSession(
        ownerUserId: sessionRec.ownerUserId ?? _api.userId,
        authorizationVersion: sessionRec.authorizationVersion ?? _api.authorizationVersion,
        runtimeGeneration: sessionRec.generation,
      );
      final fid = _api.familyId;
      if (fid == null || fid.isEmpty) {
        throw AttendancePreparationFetchException('AUTH_FAMILY_MISSING', 'Sign in again — refresh family is unavailable.');
      }
      final expectedScope = AttendancePreparationScope(
        ownerUserId: session.ownerUserId,
        authorizationVersion: session.authorizationVersion,
        role: _api.userRole,
        familyId: fid,
        serverIdentity: AppConfig.apiBaseUrl,
      );
      final fetcher = AttendancePreparationFetcher(store: AttendancePreparationStore(db: _db));
      await fetcher.fetchAndPublish(
        session: session,
        get: (path) => _api.get(path),
        expectedScope: expectedScope,
      );
      await _loadPreparationStatus();
      if (mounted && !silent) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Preparation refreshed'), backgroundColor: AppTheme.success),
        );
      }
      debugPrint('Preparation refresh succeeded (silent=$silent) classes=${(await _prepAdapter.resolve()).dataset?.classes.length}');
    } on AttendancePreparationFetchException catch (e) {
      // Silent for teachers, loud for admin diagnostics when not silent.
      debugPrint('Preparation refresh failed: ${e.code}: ${e.message} (silent=$silent)');
      if (mounted && !silent) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('${e.code}: ${e.message}'),
            backgroundColor: AppTheme.warning,
            duration: const Duration(seconds: 5),
          ),
        );
      }
    } catch (e) {
      debugPrint('Preparation refresh failed (unexpected): $e (silent=$silent)');
      if (mounted && !silent) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Refresh failed: $e'), backgroundColor: AppTheme.warning, duration: const Duration(seconds: 5)),
        );
      }
    } finally {
      if (mounted) setState(() => _refreshingPrep = false);
    }
  }

  Future<void> _loadClasses() async {
    final warm = widget._classesLoader == null ? CatalogService().cached : <dynamic>[];
    if (warm.isNotEmpty && mounted) {
      setState(() { _classes = warm; _loadingClasses = false; });
    } else if (mounted) {
      setState(() => _loadingClasses = true);
    }

    final classes = await (widget._classesLoader?.call() ?? CatalogService().classes());
    if (!mounted) return;
    final online = ConnectivityService().hasLink;
    setState(() {
      if (classes.isNotEmpty) {
        _classes = classes;
        _error = null;
      } else if (_classes.isEmpty) {
        _error = online
            ? 'No classes assigned'
            : 'Could not load classes. Waiting for network.';
      }
      _loadingClasses = false;
      _isOffline = !online;
    });
    AppNav().markAttendanceLoaded();

    if (_classes.isNotEmpty && _selectedClassId == null) {
      int? pick;
      final wanted = AppNav().attendanceClassId ?? widget.initialClassId;
      if (wanted != null) {
        for (final c in _classes) {
          final id = c['id'] is int ? c['id'] as int : int.tryParse('${c['id']}');
          if (id == wanted) pick = id;
        }
      }
      pick ??= _classes.first['id'] is int
          ? _classes.first['id'] as int
          : int.tryParse('${_classes.first['id']}');
      setState(() {
        _selectedClassId = pick;
        _selectedClassName = _classes.firstWhere(
            (c) => (c['id'] is int ? c['id'] : int.tryParse('${c['id']}')) == pick,
            orElse: () => _classes.first)['class_name'];
        _rosterReady = false;
        _loadingStudents = true;
      });
      _loadAttendance();
    }
  }

  Future<void> _loadAttendance() async {
    if (_selectedClassId == null) return;
    final previousEpoch = _loadEpoch;
    if (!await _flushPendingEdits() || !mounted || previousEpoch != _loadEpoch) return;
    final classId = _selectedClassId!;
    final date = _selectedDate;
    final epoch = ++_loadEpoch;
    final generation = _api.sessionGenerationProvider?.call() ?? 0;
    final owner = _api.userId;
    final authorizationVersion = _api.authorizationVersion;
    _sheetOwner = owner;
    _sheetGeneration = generation;
    _sheetAuthorizationVersion = authorizationVersion;
    final scope = AttendanceCalendarCache.key(AppConfig.apiBaseUrl, owner, _api.authorizationVersion);
    bool current() => mounted && epoch == _loadEpoch &&
        generation == (_api.sessionGenerationProvider?.call() ?? 0) &&
        owner == _api.userId && authorizationVersion == _api.authorizationVersion;
    _autoSave.cancel();
    setState(() {
      _error = null;
      _successMsg = null;
      _loadFailed = false;
      _rosterNote = null;
      _dateStatus = null;
      _calendar = null;
      _accessDenied = false;
      _rosterReady = false;
      _loadingStudents = true;
    });

    _syncSaveGate();
    try {
      final cachedCalendar = await _calendarCache.read(scope);
      if (!current()) return;
      setState(() {
        _calendar = cachedCalendar;
        _calendarFromCache = cachedCalendar != null;
      });
      _syncSaveGate();
    } catch (_) {
      // Storage failure leaves the rule unknown; the online verdict can recover.
    }
    if (!current()) return;
    unawaited(_refreshCalendar(scope, current));

    // 1. Try cache first
    final cachedSheet = await _db.getCachedAttendanceSheet(classId, date);
    if (!current()) return;
    final cachedAtt = cachedSheet == null
        ? null
        : List<Map<String, dynamic>>.from(cachedSheet['students'] as List);
    final cacheLocked = PacketLock.isLocked(
      '${cachedSheet?['submission_status'] ?? ''}',
      flagged: cachedSheet?['locked'] == true,
    );
    if (cacheLocked && current()) {
      setState(() {
        _packetStatus = PacketLock.label('${cachedSheet?['submission_status'] ?? ''}').isEmpty
            ? 'submitted'
            : '${cachedSheet?['submission_status']}';
      });
      // A cached lock is not proof the server accepted the current queued
      // submission. Never discard durable operations based only on a cache.
    }

    final pending = await _db.getPendingAttendanceRecords(classId, date);
    if (!current()) return;
    final pendingSubmission = pending.any((row) => row['packet_kind'] == 'submitted' &&
        const {'pending', 'retry_wait', 'in_flight'}.contains(row['sync_state']));
    if (pendingSubmission) setState(() => _packetStatus = 'submitted');
    final pendingMap = <int, String>{};
    final pendingNotes = <int, String>{};
    for (final p in cacheLocked ? <Map<String, dynamic>>[] : pending) {
      final mid = RosterParse.asInt(p['member_id']);
      if (mid == null) continue;
      final status = _statusOf(p['status']);
      if (status.isNotEmpty) pendingMap[mid] = status;
      pendingNotes[mid] = '${p['notes'] ?? p['note'] ?? ''}';
    }

    if (cachedAtt != null && cachedAtt.isNotEmpty) {
      final students = <Map<String, dynamic>>[];
      for (final s in cachedAtt) {
        final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
        if (mid == null) continue;
        students.add({
          ...s,
          'member_id': mid,
          'status': _orAbsent(_firstStatus([pendingMap[mid], s['status'], s['att_status']])),
          'notes': pendingNotes[mid] ?? s['notes'] ?? s['note'] ?? '',
        });
      }
      if (current()) setState(() { _students = students; _loadingStudents = false; _rosterReady = true; });
    } else {
      final cachedStudents = await _db.getCachedStudents(classId);
      if (!current()) return;
      if (cachedStudents.isNotEmpty) {
        final students = <Map<String, dynamic>>[];
        for (final s in cachedStudents) {
          final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
          if (mid == null) continue;
          students.add({
            'member_id': mid,
            'student_name': s['student_name'] ?? '',
            'father_name': s['father_name'] ?? '',
            'member_code': s['member_code'] ?? '',
            'gender': s['gender'] ?? '',
            'status': _orAbsent(_statusOf(pendingMap[mid])),
            'notes': pendingNotes[mid] ?? '',
          });
        }
        if (current()) setState(() { _students = students; _loadingStudents = false; _rosterReady = true; });
      } else {
        if (current()) setState(() { _loadingStudents = true; _rosterReady = false; });
      }
    }

    if (!current()) return;
    _syncSaveGate();
    // 2. Fetch fresh data silently
    final requestRevision = _editRevision;
    final requestSubmissionRevision = _submissionRevision;
    final res = await _api.getAttendance(classId, date: date);
    if (!current()) return;
    if (res.sessionSuperseded || res.isAuthError) {
      setState(() {
        _accessDenied = true;
        _dateStatus = null;
        _calendar = null;
        _loadingStudents = false;
        _rosterReady = false;
        _loadFailed = true;
        _error = res.message ?? 'Sign in again to verify attendance access.';
      });
      _syncSaveGate();
      return;
    }

    if (res.success && res.data != null) {
      final dStatusRaw = res.data['date_status'];
      final dStatus = (dStatusRaw is Map && dStatusRaw['ok'] is bool &&
              dStatusRaw['date'] == date)
          ? Map<String, dynamic>.from(dStatusRaw)
          : null;
      if (current()) {
        setState(() { _dateStatus = dStatus; });
        _syncSaveGate();
      }
      final parsed = RosterParse.students(res.data);
      if (parsed.isEmpty && RosterParse.reportedCount(res.data) > 0) {
        setState(() {
          _error = 'The server sent students but this phone could not read them. Pull to refresh.';
          _loadFailed = true;
          _loadingStudents = false;
          _rosterReady = true;
        });
        return;
      }
      // The roster can be edited while its silent online refresh is in flight.
      // Preserve newer local marks/notes unless the server locks the packet.
      final liveEdits = _editRevision == requestRevision
          ? <int, Map<String, dynamic>>{}
          : {for (final row in _students) row['member_id'] as int: row};
      final packetEarly = '${res.data['submission_status'] ?? ''}';
      final lockedEarly = PacketLock.isLocked(packetEarly, flagged: res.data['locked'] == true);
      if ((pendingSubmission || _submissionRevision != requestSubmissionRevision) &&
          PacketLock.isLocked(_packetStatus) && !lockedEarly) {
        // A response started before this phone submitted cannot reopen it.
        setState(() => _loadingStudents = false);
        _syncSaveGate();
        return;
      }
      final students = parsed.map((s) {
        final mid = s['member_id'] as int;
        return <String, dynamic>{
          'member_id': mid,
          'student_name': s['student_name'] ?? '',
          'father_name': s['father_name'] ?? '',
          'member_code': s['member_code'] ?? '',
          'gender': s['gender'] ?? '',
          'status': lockedEarly
              ? _orAbsent(_statusOf(s['att_status']))
              : _orAbsent(_firstStatus([liveEdits[mid]?['status'], pendingMap[mid], s['att_status']])),
          'notes': lockedEarly ? (s['notes'] ?? s['note'] ?? '') : (liveEdits[mid]?['notes'] ?? pendingNotes[mid] ?? s['notes'] ?? s['note'] ?? ''),
          // Attendance alerting Phase 2: read-only open-warning marker
          // ({id, kind, stage} or null). Server-computed; never cached.
          'warning': s['warning'] is Map ? s['warning'] : null,
        };
      }).toList();

      String? note;
      if (RosterParse.fallback(res.data)) {
        final year = RosterParse.yearName(res.data);
        note = year == null
            ? 'Showing students from a previous year. Ask Education to enroll them for this year too.'
            : 'Showing the $year roster. Ask Education to enroll them for this year too.';
      }

      final packet = '${res.data['submission_status'] ?? ''}';
      final locked = PacketLock.isLocked(packet, flagged: res.data['locked'] == true);
      if (locked) {
        setState(() => _packetStatus = packet.isEmpty ? 'submitted' : packet);
        _autoSave.cancel();
        _syncSaveGate();
        // A workflow lock is not an acceptance receipt for local work.
      }
      if (!current()) return;
      setState(() {
        _students = students;
        _loadingStudents = false;
        _rosterReady = true;
        _isOffline = !ConnectivityService().hasLink;
        _loadFailed = false;
        _rosterNote = note;
        _packetStatus = locked && packet.isEmpty ? 'submitted' : packet;
        _returnNote = PacketLock.returnNote(res.data);
      });
      _syncSaveGate();
      try {
        await _db.cacheAttendanceSnapshot(
          classId,
          date,
          students,
          expectedSession: LegacyWriteSession(
            ownerUserId: owner,
            authorizationVersion: authorizationVersion,
            runtimeGeneration: generation,
          ),
          submissionStatus: locked && packet.isEmpty ? 'submitted' : packet,
          locked: locked,
        );
      } on StaleAttendanceSyncSession {
        if (!current()) return;
        setState(() {
          _accessDenied = true;
          _rosterReady = false;
          _loadFailed = true;
          _error = 'Your session changed. Refresh before continuing. Saved work was kept.';
        });
        _syncSaveGate();
      } catch (_) {
        if (!current()) return;
        // Cache failure is not permission to discard pending or in-memory edits.
        setState(() => _error =
            'Could not confirm the local attendance cache update. Pending work was not cleared.');
      }
    } else if (_students.isEmpty && (cachedAtt == null || cachedAtt.isEmpty)) {
      setState(() {
        _error = res.message ?? 'Could not load students. Check your connection and try again.';
        _loadingStudents = false;
        _rosterReady = true;
        _loadFailed = true;
      });
    } else {
      setState(() {
        _loadingStudents = false;
        _rosterReady = true;
        _error = null;
      });
    }
  }

  /// Smart saved-calendar notice: a transient toast shown ONCE per fallback
  /// episode (not a permanent banner, not on every rebuild). Fires when the
  /// screen actually falls back to cache (refresh failed/offline) or when the
  /// connection drops mid-screen while serving cache. Re-arms only after
  /// fresh data lands, so repeat refreshes while offline stay silent.
  void _maybeShowCalendarToast() {
    if (!mounted) return;
    if (!(_calendarFromCache && _calendar != null)) return;
    final key = '$_selectedClassId|$_selectedDate';
    if (_calendarToastKey == key) return;
    _calendarToastKey = key;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Saved class-day calendar · will refresh when online'),
        backgroundColor: AppTheme.info,
        duration: Duration(seconds: 4),
      ),
    );
  }

  Future<void> _refreshCalendar(String scope, bool Function() current) async {
    final etag = _calendar?.payload['etag'];
    final res = await _api.getAttendanceCalendar(etag: etag is String ? etag : null);
    if (!current()) return;
    if (res.sessionSuperseded || res.isAuthError) {
      setState(() {
        _accessDenied = true;
        _calendar = null;
        _dateStatus = null;
      });
      _syncSaveGate();
      return;
    }
    if (res.notModified) {
      setState(() {
        _calendarFromCache = false;
        _calendarToastKey = null;
      });
      return;
    }
    if (!res.success) {
      if (current()) _maybeShowCalendarToast();
      return;
    }
    final calendar = AttendanceCalendar.parse(res.data);
    setState(() {
      _calendar = calendar;
      _calendarFromCache = false;
      _calendarToastKey = null;
    });
    _syncSaveGate();
    try {
      if (calendar == null) {
        await _calendarCache.remove(scope, isCurrent: current);
        return;
      }
      await _calendarCache.save(scope, calendar, isCurrent: current);
    } catch (_) {
      // The online verdict still applies; a failed disk cache is never permission.
    }
  }

  bool get _locked => _submitting || PacketLock.isLocked(_packetStatus);

  // Default-absent (2026-10-07): the sheet is complete by construction —
  // every student carries an explicit status (absent unless marked), so
  // class-day, session, roster and packet gates still apply. The old "mark every student"
  // gate is gone; the server still enforces the complete roster on
  // submit (roster-drift protection) and merge-upserts drafts.

  /// Telegram-send model: the SQLite write IS the save (~5 ms). Delivery is
  /// the SyncService outbox's job and is never awaited from a button tap —
  /// no spinner, no "Sending…" text, buttons stay live (last write wins:
  /// saving again simply replaces the queued packet).
  String _saveFailureMessage(Object error) => error is StaleLegacyWriteSession
      ? error.toString()
      : 'Phone storage refused the save. Free up space and try again.';

  Future<void> _saveAttendance() async {
    if (!_dayAllowed) {
      final detail = _dayMessage;
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(detail), backgroundColor: AppTheme.warning),
        );
      }
      return;
    }
    if (_selectedClassId == null || _students.isEmpty) return;
    if (!_canEdit) return;
    _autoSave.cancel();

    final epoch = _loadEpoch;
    final revision = _editRevision;
    final records = _records();
    try {
      await _db.saveAttendanceLocal(
        _selectedClassId!,
        _selectedClassName ?? '',
        _selectedDate,
        records,
        expectedSession: _sheetWriteSession,
        packetKind: 'draft',
      );
    } catch (error) {
      if (mounted && epoch == _loadEpoch) {
        setState(() {
          _error =
              _saveFailureMessage(error);
          _successMsg = null;
        });
      }
      return;
    }
    if (!mounted || epoch != _loadEpoch || !_sheetSessionCurrent) return;
    if (revision > _durableRevision) _durableRevision = revision;
    HapticFeedback.selectionClick(); // Telegram send-tick: saved on phone.
    showQuickConfirm(context, 'Saved');
    _dirty.value = _editRevision != revision;
    setState(() {
      _packetStatus = 'draft';
      _error = null;
      _successMsg = null;
      _returnNote = null;
    });
    // Fire-and-forget. The outbox coalesces, retries with backoff, and
    // updates the pending pill through its stream.
    unawaited(_sync.syncAll(force: true));
  }

  /// Instant submit — no confirmation dialog. A short UNDO window replaces
  /// it; undo refuses honestly if the outbox already delivered the packet.
  Future<void> _submitAttendance() async {
    if (!_dayAllowed) {
      final detail = _dayMessage;
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(detail), backgroundColor: AppTheme.warning),
        );
      }
      return;
    }
    if (_selectedClassId == null || _students.isEmpty) return;
    if (!_canEdit) return;
    // Cancel a delayed draft before creating the submitted generation. If an
    // autosave already entered LocalDb, its serialized write completes first.
    _autoSave.cancel();
    _submitting = true;

    final epoch = _loadEpoch;
    final records = _records();
    late final LegacyOperationRef operation;
    try {
      operation = await _db.saveAttendanceLocal(
        _selectedClassId!,
        _selectedClassName ?? '',
        _selectedDate,
        records,
        expectedSession: _sheetWriteSession,
        packetKind: 'submitted',
        notBefore: DateTime.now().add(const Duration(seconds: 5)),
      );
    } catch (error) {
      _submitting = false;
      if (mounted && epoch == _loadEpoch) {
        setState(() {
          _error =
              _saveFailureMessage(error);
          _successMsg = null;
        });
      }
      return;
    }
    _submitting = false;
    if (!mounted || epoch != _loadEpoch || !_sheetSessionCurrent) return;
    _submissionRevision++;
    _durableRevision = _editRevision;
    HapticFeedback.mediumImpact();
    _dirty.value = false;
    setState(() {
      _packetStatus = 'submitted';
      _error = null;
      _successMsg = null;
    });
    unawaited(_sync.syncAll(force: true));
    // 5-second decision window with a visible countdown; when the ring
    // empties the toast disappears and the sheet stays locked for Education.
    showUndoToast(context, message: 'Submitted', onUndo: () => _undoSubmit(operation, epoch));
  }

  Future<void> _undoSubmit(LegacyOperationRef operation, int epoch) async {
    final result = await _db.undoSubmittedLegacyOperation(operation);
    if (!mounted || epoch != _loadEpoch || !_sheetSessionCurrent) return;
    switch (result) {
      case SubmitUndoResult.applied:
        setState(() => _packetStatus = 'draft');
        _dirty.value = false;
        showQuickConfirm(context, 'Submission undone');
        break;
      case SubmitUndoResult.alreadyClaimed:
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          duration: Duration(seconds: 2),
          content: Text('Already being sent to Education'),
        ));
        break;
      case SubmitUndoResult.supersededLocal:
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          duration: Duration(seconds: 2),
          content: Text('This submission changed and cannot be undone.'),
        ));
        break;
      case SubmitUndoResult.supersededSession:
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          duration: Duration(seconds: 2),
          content: Text('Sign in again before changing this submission.'),
        ));
        break;
    }
  }

  List<Map<String, dynamic>> _records() {
    return _students
        .map((s) => <String, dynamic>{
              'member_id': s['member_id'],
              'student_name': s['student_name'],
              'father_name': s['father_name'],
              'member_code': s['member_code'],
              'status': _statusOf(s['status']),
              'notes': s['notes'] ?? s['note'] ?? '',
            })
        .toList();
  }

  // ── Phase 8: instant autosave + QR scan ──────────────────────
  void _scheduleAutoSave() {
    _editRevision++;
    _autoSave.run(const Duration(milliseconds: 700), _autoSaveNow);
  }

  /// Persist the currently-marked rows as a draft packet now. With
  /// default-absent the sheet is complete by construction — every
  /// student carries an explicit status. A dead battery after this
  /// point loses nothing; the outbox syncs.
  Future<bool> _autoSaveNow() async {
    final classId = _selectedClassId;
    if (classId == null || _students.isEmpty || !_canEdit) return false;
    final epoch = _loadEpoch;
    final revision = _editRevision;
    final records = _records();
    try {
      await _db.saveAttendanceLocal(
          classId, _selectedClassName ?? '', _selectedDate, records,
          expectedSession: _sheetWriteSession,
          packetKind: 'draft');
    } catch (error) {
      if (error is StaleLegacyWriteSession && mounted && epoch == _loadEpoch) {
        setState(() => _error = _saveFailureMessage(error));
      }
      return false; // caller must not discard an unsaved sheet
    }
    if (!mounted || epoch != _loadEpoch || !_sheetSessionCurrent) return false;
    if (revision > _durableRevision) _durableRevision = revision;
    _dirty.value = true;
    final now = DateTime.now();
    if (now.difference(_lastSyncPush) > const Duration(seconds: 10)) {
      _lastSyncPush = now;
      unawaited(_sync.syncAll(force: false));
    }
    return true;
  }

  Future<bool> _flushPendingEdits() async {
    _autoSave.cancel();
    while (_durableRevision < _editRevision && _canEdit) {
      if (!await _autoSaveNow()) {
        if (mounted && _error == null) {
          setState(() => _error = 'Phone storage refused the save. Free up space and try again.');
        }
        return false;
      }
    }
    return true;
  }

  void _openQrScan() {
    if (!_canEdit) return;
    QrScanSheet.open(context,
        onScan: handleQrScan,
        header: _selectedClassName ?? 'Attendance');
  }

  /// Apply the Education rules to one scan: roster membership for the
  /// open class, duplicates, lock; success marks Present and autosaves
  /// immediately. Mirrors the manual-tap path (last write wins).
  @visibleForTesting
  Future<QrFeedback> handleQrScan(String? raw) async {
    final code = QrAttendance.extractMemberCode(raw);
    if (code == null) return QrFeedback.invalid();
    if (!_canEdit) return QrFeedback.locked();
    if (_selectedClassId == null || _students.isEmpty) {
      return QrFeedback.notFound();
    }
    final idx =
        _students.indexWhere((s) => '${s['member_code'] ?? ''}' == code);
    if (idx < 0) {
      final known = await _db.findCachedMemberByCode(code);
      if (!mounted || !_canEdit) return QrFeedback.locked();
      if (known == null) return QrFeedback.notFound();
      final name = '${known['student_name'] ?? ''}';
      final st = '${known['status'] ?? ''}';
      if (st.isNotEmpty && st != 'active') {
        return QrFeedback.inactive(name: name);
      }
      final own = await _db.cachedClassNameOfMember(
          (known['id'] is int) ? known['id'] as int : int.tryParse('${known['id']}') ?? 0);
      return QrFeedback.wrongGroup(name: name, ownGroup: own ?? '—');
    }
    final s = _students[idx];
    final existing = _statusOf(s['status']);
    if (existing == 'present' || existing == 'late') {
      return QrFeedback.duplicate(
          name: '${s['student_name'] ?? ''}', status: existing);
    }
    // Default-absent: an absent (or excused) mark is not a check-in.
    // The scan is physical presence — it wins.
    setState(() => s['status'] = 'present');
    _dirty.value = true;
    final epoch = _loadEpoch;
    final revision = ++_editRevision;
    if (!await _autoSaveNow()) {
      if (mounted && epoch == _loadEpoch && revision == _editRevision) {
        setState(() => s['status'] = existing);
        _editRevision--;
      }
      return QrFeedback.storageError();
    }
    return QrFeedback.ok(name: '${s['student_name'] ?? ''}');
  }

  Future<void> _persistLocal() async {
    if (_selectedClassId == null || _students.isEmpty || !_canEdit) return;
    await _db.cacheAttendanceResponse(
      _selectedClassId!,
      _selectedDate,
      _students,
      submissionStatus: _packetStatus,
      locked: false,
    );
  }

  void _markAll(String status) {
    if (!_canEdit) return;
    setState(() {
      for (var s in _students) {
        s['status'] = status;
      }
    });
    _dirty.value = true;
    _persistLocal();
    _scheduleAutoSave();
  }

  Future<void> _pickDate() async {
    final picked = await showEthiopianDatePicker(
      context: context,
      initialGregorianDate: _selectedDate,
      firstDate: AttendanceCalendar.addisToday(DateTime.now()).subtract(const Duration(days: 45)),
      lastDate: AttendanceCalendar.addisToday(DateTime.now()),
    );
    if (picked != null) {
      if (!mounted || picked == _selectedDate) return;
      final epoch = _loadEpoch;
      if (!await _flushPendingEdits() || !mounted || epoch != _loadEpoch) return;
      _editRevision = _durableRevision = 0;
      _autoSave.cancel();
      setState(() {
        _selectedDate = picked; // Gregorian API form; Ethiopian display stays unchanged.
        _students = [];
        _packetStatus = '';
        _returnNote = null;
      });
      _dirty.value = false;
      _loadAttendance();
    }
  }

  /// Advisory (non-blocking) notices. The blocking `_dayMessage` banner stays
  /// always-visible at the call site; here we show the first advisory and
  /// fold any overflow behind a toggle so 3+ orange banners never stack.
  /// Redesign safety: every notice stays reachable, none removed.
  List<Widget> _advisoryNotices() {
    final extra = <Widget>[];
    if (_rosterNote != null && _error == null) {
      extra.add(StatusBanner.warning(_rosterNote!));
    }
    if (_returnNote != null && _returnNote!.isNotEmpty && !_locked) {
      extra.add(StatusBanner.warning(
          'Returned by Education — you can edit and resubmit. $_returnNote'));
    }
    if (_locked && _students.isNotEmpty && _error == null) {
      extra.add(StatusBanner.warning(PacketLock.viewOnlyHint(_packetStatus)));
    }
    if (extra.length <= 1) return extra;
    final hidden = extra.length - 1;
    if (_noticesExpanded) {
      return [
        ...extra,
        _noticesToggle(0, true),
      ];
    }
    return [
      extra.first,
      _noticesToggle(hidden, false),
    ];
  }

  Widget _noticesToggle(int hidden, bool expanded) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      child: InkWell(
        onTap: () => setState(() => _noticesExpanded = !expanded),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              expanded ? 'Show less' : '$hidden more notice${hidden == 1 ? '' : 's'}',
              style: const TextStyle(
                  fontSize: 11,
                  color: AppTheme.info,
                  fontWeight: FontWeight.w600),
            ),
            Icon(
              expanded ? Icons.expand_less_rounded : Icons.expand_more_rounded,
              size: 16,
              color: AppTheme.info,
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _manualSync() async {
    final result = await _sync.syncAll(force: true);
    if (!mounted) return;

    // Inventory-scoped truth: never success-green while work remains.
    final fb = SyncFeedback.describe(
      synced: result.synced,
      failed: result.failed,
      status: _sync.lastStatus,
      online: ConnectivityService().hasLink,
      drainsPaused: !_sync.drainsAllowed,
    );
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(fb.message),
        backgroundColor: fb.kind == SyncFeedbackKind.success
            ? AppTheme.success
            : fb.kind == SyncFeedbackKind.attention
                ? AppTheme.warning
                : AppTheme.info,
        action: fb.kind == SyncFeedbackKind.attention
            ? SnackBarAction(
                label: 'Review',
                textColor: Colors.white,
                onPressed: () => Navigator.of(context).pushNamed('/sync-center'),
              )
            : null,
      ),
    );
    await _updatePendingCount();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Attendance'),
        automaticallyImplyLeading: Navigator.canPop(context),
        actions: [
          // Scan lives in the top header (never covers roster data).
          if (_canEdit)
            TextButton.icon(
              onPressed: _openQrScan,
              icon: const Icon(Icons.qr_code_scanner, size: 22),
              label: const Text('Scan',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
              style: TextButton.styleFrom(foregroundColor: Colors.white),
            ),
          // Pending sync badge
          if (_pendingCount > 0)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: IconButton(
                onPressed: _manualSync,
                tooltip: '$_pendingCount pending sync',
                icon: Badge(
                  label: Text('$_pendingCount',
                      style: const TextStyle(fontSize: 9)),
                  backgroundColor: AppTheme.warning,
                  child: const Icon(Icons.sync, size: 22),
                ),
              ),
            ),
          if (_packetStatus.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: Center(
                child: Text(
                  PacketLock.label(_packetStatus).isEmpty
                      ? _packetStatus
                      : PacketLock.label(_packetStatus),
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 12),
                ),
              ),
            ),
        ],
      ),
      bottomNavigationBar: _students.isEmpty
          ? null
          : _locked
              ? const SubmittedBar()
              : TeacherActionBar(
                  saveLabel: 'Save',
                  submitLabel: 'Submit',
                  onSave: _canEdit ? _saveAttendance : null,
                  onSubmit: _canEdit ? _submitAttendance : null,
                  saveEnabled: _saveGate,
                ),
      body: Column(
        children: [
          // Offline indicator
          if (_isOffline)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              color: AppTheme.warning.withOpacity(0.15),
              child: Row(
                children: [
                  Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Waiting for network — kept on this phone until you are back online',
                      style: TextStyle(
                          fontSize: 11,
                          color: AppTheme.warning,
                          fontWeight: FontWeight.w500),
                    ),
                  ),
                  if (_pendingCount > 0)
                    GestureDetector(
                      onTap: _manualSync,
                      child: Text('Sync now',
                          style: TextStyle(
                              fontSize: 11,
                              color: AppTheme.primary,
                              fontWeight: FontWeight.w600)),
                    ),
                ],
              ),
            ),

          // F8: packets the school's workflow refused — kept on
          // this phone; Review explains and offers Discard.
          if (_rejectedCount > 0)
            SyncAttentionBanner(rejectedCount: _rejectedCount),

          // Silent offline: background auto-download, no scary banner for teachers.
          // Preparation status is still resolved for diagnostics (Settings → Diagnostics)
          // but never blocks the roster with a big orange warning.
          if (_prepResolution?.isReady == true && _prepResolution?.dataset?.classes.isEmpty == true)
            StatusBanner.info('No classes assigned for offline — contact Education.'),
          // No manual Refresh button: handled automatically after login/resume.

          // Class + Date selector
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Expanded(
                  flex: 3,
                  child: _loadingClasses
                      ? const LinearProgressIndicator()
                      : DropdownButtonFormField<int>(
                          value: _selectedClassId,
                          hint: const Text('Select class',
                              style: TextStyle(fontSize: 13)),
                          isExpanded: true,
                          decoration: InputDecoration(
                            contentPadding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 10),
                            border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(10)),
                          ),
                          items: _classes
                              .map<DropdownMenuItem<int>>((c) =>
                                  DropdownMenuItem(
                                    value: c['id'] is int
                                        ? c['id']
                                        : int.tryParse('${c['id']}'),
                                    child: Text('${c['class_name']}',
                                        style: const TextStyle(fontSize: 13),
                                        overflow: TextOverflow.ellipsis),
                                  ))
                              .toList(),
                            onChanged: (v) async {
                              if (v == null || v == _selectedClassId) return;
                              final epoch = _loadEpoch;
                              if (!await _flushPendingEdits() || !mounted || epoch != _loadEpoch) return;
                              _editRevision = _durableRevision = 0;
                              setState(() {
                                _selectedClassId = v;
                                _selectedClassName = _classes
                                    .firstWhere((c) =>
                                        (c['id'] is int
                                            ? c['id']
                                            : int.tryParse('${c['id']}')) ==
                                        v)['class_name'];
                                _students = [];
                                _rosterReady = false;
                                _loadingStudents = true;
                                _packetStatus = '';
                                _autoSave.cancel();
                              });
                              _dirty.value = false;
                              _returnNote = null;
                              _loadAttendance();
                            },
                        ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: InkWell(
                    onTap: _pickDate,
                    child: InputDecorator(
                      decoration: InputDecoration(
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 10),
                        border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10)),
                        suffixIcon: const Icon(Icons.calendar_today, size: 16),
                      ),
                      child: Text(formatGregorianAsEthiopian(_selectedDate),
                          style: const TextStyle(fontSize: 13)),
                    ),
                  ),
                ),
              ],
            ),
          ),

          // Saved-calendar provenance is a transient toast now (see
          // _maybeShowCalendarToast) — no permanent banner, small screens keep ~50px.
          if (_selectedClassId != null && !_dayAllowed)
            StatusBanner.warning(_dayMessage),
          if (_effectiveDateStatus?['ok'] == true &&
              _effectiveDateStatus?['is_makeup'] == true)
            StatusBanner.success('Makeup class day — attendance can be recorded.'),
          if (_students.isNotEmpty && _error == null) _progressStrip(),
          if (_error != null)
            StatusBanner.error(_error!, onRetry: _loadAttendance),
          if (_successMsg != null)
            StatusBanner.success(_successMsg!, onDismiss: () {
              if (mounted) setState(() => _successMsg = null);
            }),
          // Advisory notices: first visible, overflow folded (nothing removed).
          ..._advisoryNotices(),

          // Quick mark all
          if (_students.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              child: Row(
                children: [
                  Text(
                    '${_students.length} students · '
                    '${_countStatus('present')} present · '
                    '${_countStatus('absent')} absent',
                    style: TextStyle(fontSize: 12, color: AppTheme.textSecondary),
                  ),
                  const Spacer(),
                  if (_canEdit) ...[
                    _quickBtn(
                        'All Present', AppTheme.success, () => _markAll('present')),
                    const SizedBox(width: 6),
                    _quickBtn(
                        'All Absent', AppTheme.danger, () => _markAll('absent')),
                  ],
                ],
              ),
            ),

          // Student list
          Expanded(
            child: (_loadingStudents || (!_rosterReady && !_accessDenied && _selectedClassId != null))
                ? const StudentListSkeleton()
                : RefreshIndicator(
                    onRefresh: _loadAttendance,
                    child: _loadFailed && _students.isEmpty
                        ? ListView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            children: [
                              Padding(
                                padding: const EdgeInsets.all(16),
                                child: AppErrorCard(
                                  error: AppError.fromMessage(_error,
                                      isNetwork: (_error ?? '').toLowerCase().contains('internet') ||
                                          (_error ?? '').toLowerCase().contains('connection')),
                                  onRetry: _loadAttendance,
                                ),
                              ),
                            ],
                          )
                        : _students.isEmpty
                            ? ListView(
                                physics: const AlwaysScrollableScrollPhysics(),
                                children: [
                                  const SizedBox(height: 40),
                                  EmptyState(
                                    icon: Icons.people_outline,
                                    title: _selectedClassId == null
                                        ? 'Select a class'
                                        : 'No students in this class yet',
                                    subtitle: _selectedClassId == null
                                        ? 'Pick a class above to take attendance.'
                                        : 'If they were enrolled on the website, pull down to refresh. Education can also add them under Enrollment.',
                                    action: _selectedClassId == null
                                        ? null
                                        : TextButton.icon(
                                            onPressed: _loadAttendance,
                                            icon: const Icon(Icons.refresh, size: 18),
                                            label: const Text('Refresh'),
                                          ),
                                  ),
                                ],
                              )
                            : ListView.builder(
                                physics: const AlwaysScrollableScrollPhysics(),
                                itemCount: _students.length,
                                cacheExtent: kListCacheExtent,
                                padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                                itemBuilder: (_, i) =>
                                    RepaintBoundaryListItem(child: _studentRow(i)),

                              ),
                  ),
          ),
        ],
      ),
    );
  }

  Future<void> _editNote(int index) async {
    if (!_canEdit) return;
    final classId = _selectedClassId;
    final date = _selectedDate;
    final epoch = _loadEpoch;
    final ctrl = TextEditingController(text: '${_students[index]['notes'] ?? ''}');
    final note = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Note'),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          maxLines: 3,
          maxLength: 500,
          decoration: const InputDecoration(hintText: 'Optional note'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: const Text('Keep')),
        ],
      ),
    );
    if (!mounted || !_canEdit || classId != _selectedClassId ||
        date != _selectedDate || epoch != _loadEpoch || note == null) {
      return;
    }
    if (note == '${_students[index]['notes'] ?? ''}') return;
    setState(() => _students[index]['notes'] = note);
    _dirty.value = true;
    _persistLocal();
    _scheduleAutoSave();
  }

  Widget _quickBtn(String label, Color color, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(6),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: color.withOpacity(0.1),
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: color.withOpacity(0.3)),
        ),
        child: Text(label,
            style: TextStyle(
                fontSize: 11, color: color, fontWeight: FontWeight.w600)),
      ),
    );
  }

  Widget _studentRow(int index) {
    final s = _students[index];
    final status = _statusOf(s['status']);

    return FastListRow(
      index: index,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      child: Row(
        children: [
          SizedBox(
            width: 28,
            child: Text('${index + 1}',
                style:
                    TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          ),
          Expanded(
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: _canEdit ? () => _editNote(index) : null,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Flexible(
                      child: Text('${s['student_name']} ${s['father_name']}',
                          style: const TextStyle(
                              fontSize: 13.5,
                              fontWeight: FontWeight.w700),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                    ),
                    RowWarningMarker(warning: s['warning'] is Map ? s['warning'] as Map : null),
                  ]),
                  Text(
                    (s['notes'] != null && '${s['notes']}'.trim().isNotEmpty)
                        ? '${s['notes']}'
                        : (s['member_code'] != null &&
                                s['member_code'].toString().isNotEmpty)
                            ? '${s['member_code']}'
                            : 'Tap for note',
                    style: TextStyle(
                        fontSize: 10, color: AppTheme.textSecondary),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ),
          _statusBtn('P', 'present', status, AppTheme.success, index),
          const SizedBox(width: 4),
          _statusBtn('A', 'absent', status, AppTheme.danger, index),
          const SizedBox(width: 4),
          _statusBtn('L', 'late', status, AppTheme.warning, index),
          const SizedBox(width: 4),
          _statusBtn('E', 'excused', status, AppTheme.info, index),
        ],
      ),
    );
  }

  Widget _statusBtn(
      String label, String value, String current, Color color, int index) {
    final selected = current == value;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: !_canEdit
          ? null
          : () {
              if (!_canEdit) return;
              HapticFeedback.lightImpact();
              setState(() => _students[index]['status'] = value);
              _dirty.value = true;
              _persistLocal();
              _scheduleAutoSave();
            },
      child: AnimatedScale(
        scale: selected ? 1.08 : 1.0,
        duration: const Duration(milliseconds: 160),
        curve: Curves.easeOut,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          curve: Curves.easeOut,
          width: 40,
          height: 40,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected ? color : color.withOpacity(0.08),
            borderRadius: BorderRadius.circular(10),
            border:
                Border.all(color: selected ? color : color.withOpacity(0.2)),
            boxShadow: selected
                ? [
                    BoxShadow(
                        color: color.withOpacity(0.35),
                        blurRadius: 6,
                        offset: const Offset(0, 2))
                  ]
                : null,
          ),
          child: Text(label,
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w800,
                color: selected ? Colors.white : color,
              )),
        ),
      ),
    );
  }

  /// Animated presence strip (fills as present marks land — QR or P).
  Widget _progressStrip() {
    final total = _students.length;
    final present = _countStatus('present');
    final frac = total == 0 ? 0.0 : present / total;
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 220),
            child: Text(
              '$present / $total present',
              key: ValueKey(present),
              style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppTheme.textSecondary),
            ),
          ),
          const SizedBox(height: 4),
          TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: frac),
            duration: const Duration(milliseconds: 350),
            curve: Curves.easeOut,
            builder: (context, v, child) => ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(
                value: v,
                minHeight: 6,
                valueColor: AlwaysStoppedAnimation<Color>(
                    frac >= 1 ? AppTheme.success : AppTheme.primary),
                backgroundColor: AppTheme.primary.withOpacity(0.12),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
