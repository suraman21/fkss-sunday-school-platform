import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/legacy_outbox_models.dart';
import '../../services/local_db.dart';
import '../../widgets/sync_attention.dart';
import '../../services/sync_service.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/packet.dart';
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

/// Mezmur attendance — VERBATIM clone of the teachers' attendance
/// workflow, keyed by (date, section) instead of (date, class):
///
///   [Section ▾] + [Ethiopian date] → that section's member list →
///   P/A/L/E chips + tap-for-note → Save (draft) / Submit (packet to
///   the Mezmur department) → department approves or returns it with
///   a note, exactly like Education reopens a teacher's sheet.
///
/// Offline parity: SQLite outbox (pending_mezmur), sheet cache keyed
/// by date+section, background sync with idempotency keys.
class MezmurAttendanceScreen extends StatefulWidget {
  final String? initialDate;
  final String? initialSection;
  const MezmurAttendanceScreen(
      {super.key, this.initialDate, this.initialSection})
      : _apiOverride = null,
        _dbOverride = null,
        _syncOverride = null;

  @visibleForTesting
  const MezmurAttendanceScreen.withCollaborators(
      {super.key,
      this.initialDate,
      this.initialSection,
      required ApiService api,
      required LocalDb db,
      required SyncService sync})
      : _apiOverride = api,
        _dbOverride = db,
        _syncOverride = sync;
  final ApiService? _apiOverride;
  final LocalDb? _dbOverride;
  final SyncService? _syncOverride;
  @override
  State<MezmurAttendanceScreen> createState() => MezmurAttendanceScreenState();
}

class MezmurAttendanceScreenState extends State<MezmurAttendanceScreen> {
  late final _api = widget._apiOverride ?? ApiService();
  late final _db = widget._dbOverride ?? LocalDb();
  late final _sync = widget._syncOverride ?? SyncService();

  String _selectedDate = DateFormat('yyyy-MM-dd').format(DateTime.now());

  /// [Section ▾] list — {section, members}
  List<Map<String, dynamic>> _sections = [];
  String? _selectedSection;
  bool _loadingSections = true;

  /// Roster of the selected section (teacher's _students analogue).
  List<Map<String, dynamic>> _members = [];

  bool _loading = true;

  bool _submitting = false;
  bool _switching = false;
  int _loadEpoch = 0;
  int _editRevision = 0;
  int _durableRevision = 0;
  int _submissionRevision = 0;
  final _sectionFieldKey = GlobalKey<FormFieldState<String>>();
  bool _rosterReady = false;
  bool _loadFailed = false;
  bool _isOffline = false;
  String? _error;
  String _packetStatus = '';
  String? _returnNote;
  int _pendingCount = 0;
  int _rejectedCount = 0;
  final ValueNotifier<bool> _dirty = ValueNotifier(false);

  // Phase 8: instant autosave (every mutation → durable local draft).
  final Debounce _autoSave = Debounce();
  DateTime _lastSyncPush = DateTime.fromMillisecondsSinceEpoch(0);
  StreamSubscription<bool>? _netSub;
  StreamSubscription<SyncStatus>? _syncSub;

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

  bool get _locked => _submitting || PacketLock.isLocked(_packetStatus);

  /// Default-absent (2026-10-07): every member loads as ABSENT and
  /// the taker (or a QR scan) marks presence. An unresolved status
  /// resolves to the conservative absent — presence is never assumed.
  String _orAbsent(String status) => status.isEmpty ? 'absent' : status;

  int _countStatus(String status) =>
      _members.where((m) => _statusOf(m['status']) == status).length;

  /// Display label for a section wire value: the server groups members
  /// without a manual section assignment under the em-dash placeholder;
  /// takers see a friendly Amharic label instead of a bare dash.
  String _sectionLabel(dynamic v) {
    final s = '${v ?? ''}'.trim();
    if (s.isEmpty || s == '\u2014' || s == '-') return 'ያልተመደቡ';
    return s;
  }

  @override
  void initState() {
    super.initState();
    if (widget.initialDate != null && widget.initialDate!.isNotEmpty) {
      _selectedDate = widget.initialDate!;
    }
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (!mounted || _isOffline == !hasLink) return;
      setState(() => _isOffline = !hasLink);
    });
    _loadSections();
    _updatePendingCount();
    _syncSub = _sync.syncStream.listen((status) {
      if (mounted) {
        setState(() {
          _pendingCount = status.totalPending;
          _rejectedCount = status.rejected;
        });
      }
    });
  }

  @override
  void dispose() {
    if (_durableRevision < _editRevision && !_locked) {
      unawaited(_autoSaveNow());
    }
    _loadEpoch++;
    _netSub?.cancel();
    _syncSub?.cancel();
    _autoSave.dispose();
    _dirty.dispose();
    super.dispose();
  }

  void refresh() => _loadSections();

  Future<void> _updatePendingCount() async {
    final count = await _db.getPendingMezmurCount();
    final rejected = (await _db.getRejectedBatches())
        .where((b) => b['kind'] == 'mezmur')
        .length;
    if (mounted) {
      setState(() {
        _pendingCount = count;
        _rejectedCount = rejected;
      });
    }
  }

  // ── [Section ▾] like teachers' [Class ▾] ─────────────────────
  Future<void> _loadSections() async {
    final cached = await _db.getCachedMezmurSections();
    if (cached != null && cached.isNotEmpty && mounted) {
      setState(() {
        _sections = cached;
        _loadingSections = false;
      });
    }
    final res = await _api.getMezmurSections();
    if (!mounted) return;
    if (res.success && res.data != null) {
      final raw = res.data!['items'] ?? res.data!['sections'];
      final list = raw is List
          ? raw
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList()
          : <Map<String, dynamic>>[];
      if (list.isNotEmpty) {
        _sections = list;
        await _db.cacheMezmurSections(list);
        setState(() {
          _loadingSections = false;
          _isOffline = !ConnectivityService().hasLink;
        });
      } else if (_sections.isEmpty) {
        setState(() {
          _loadingSections = false;
          _error =
              'No sections found. Assign sections to members on the website.';
        });
        return;
      }
    } else if (_sections.isEmpty) {
      setState(() {
        _loadingSections = false;
        _error = res.message ?? 'Could not load sections.';
      });
      return;
    }
    if (_selectedSection == null && _sections.isNotEmpty) {
      final wanted = widget.initialSection;
      final match =
          wanted != null && _sections.any((s) => '${s['section']}' == wanted)
              ? wanted
              : '${_sections.first['section']}';
      setState(() => _selectedSection = match);
      _loadSheet();
    }
  }

  // ── sheet load (cache → pending → network, teacher order) ────
  Future<void> _loadSheet() async {
    if (_submitting || _switching) return;
    final before = _loadEpoch;
    if (!await _flushPendingEdits() || !mounted || before != _loadEpoch) {
      // A refused save must not leave the skeleton up: the roster the taker
      // can already see stays the truth, and the banner says why the sheet
      // did not move.
      if (mounted && before == _loadEpoch && _loading) {
        setState(() {
          _loading = false;
          _rosterReady = _members.isNotEmpty;
        });
      }
      return;
    }
    final section = _selectedSection;
    if (section == null || section.isEmpty) return;
    final date = _selectedDate;
    final epoch = ++_loadEpoch;
    final owner = _api.userId;
    final authorizationVersion = _api.authorizationVersion;
    final generation = _api.sessionGenerationProvider?.call() ?? 0;
    bool current() =>
        mounted &&
        epoch == _loadEpoch &&
        section == _selectedSection &&
        date == _selectedDate &&
        owner == _api.userId &&
        authorizationVersion == _api.authorizationVersion &&
        generation == (_api.sessionGenerationProvider?.call() ?? 0);
    final keepSheet = _members.isNotEmpty;
    setState(() {
      _error = null;
      _loadFailed = false;
      if (!keepSheet) {
        _loading = true;
        _rosterReady = false;
      }
    });

    final cached = await _db.getCachedMezmurSheet(date, section);
    if (!current()) return;
    final cachePacket = '${cached?['submission_status'] ?? ''}';
    final cacheLocked =
        PacketLock.isLocked(cachePacket, flagged: cached?['locked'] == true);
    if (cacheLocked) {
      setState(() =>
          _packetStatus = cachePacket.isEmpty ? 'submitted' : cachePacket);
    }
    // A local queued submission must stay locked even if a stale server
    // response still says draft. Never discard an operation because of a lock.
    final pending = await _db.getPendingMezmurRecords(date, section);
    if (!current()) return;
    final pendingSubmission = pending.any((p) =>
        p['packet_kind'] == 'submitted' &&
        const {'pending', 'retry_wait', 'in_flight'}.contains(p['sync_state']));
    final pendingMap = <int, String>{};
    final pendingNotes = <int, String>{};
    for (final p in pending) {
      final mid = p['member_id'] is int
          ? p['member_id'] as int
          : int.tryParse('${p['member_id']}');
      if (mid == null) continue;
      final st = _statusOf(p['status']);
      if (st.isNotEmpty) pendingMap[mid] = st;
      pendingNotes[mid] = '${p['notes'] ?? ''}';
    }
    if (pendingSubmission) {
      setState(() => _packetStatus = 'submitted');
    } else if (!cacheLocked && pending.isNotEmpty) {
      setState(
          () => _packetStatus = '${pending.first['packet_kind'] ?? 'draft'}');
    }
    if (cached != null && _members.isEmpty) {
      _applySheet(
          cached, pendingMap, pendingNotes, cacheLocked && !pendingSubmission);
    }
    final requestEditRevision = _editRevision;
    final requestSubmissionRevision = _submissionRevision;
    final res = await _api.getMezmurSheet(date, section: section);
    if (!current()) return;
    if (res.success && res.data != null) {
      final data = res.data!;
      final packet = '${data['submission_status'] ?? ''}';
      final serverLocked =
          PacketLock.isLocked(packet, flagged: data['locked'] == true);
      if (!serverLocked &&
          (pendingSubmission ||
              _submitting ||
              (_submissionRevision != requestSubmissionRevision &&
                  PacketLock.isLocked(_packetStatus)))) {
        // The older server response cannot reopen a local submission. If no
        // cached roster was available, show the pending marks read-only.
        if (_members.isEmpty && pendingSubmission) {
          _applySheet(data, pendingMap, pendingNotes, false);
          setState(() => _packetStatus = 'submitted');
        }
        setState(() {
          _loading = false;
          _rosterReady = true;
        });
        return;
      }
      final live = _editRevision == requestEditRevision
          ? <int, Map<String, dynamic>>{}
          : {for (final row in _members) row['member_id'] as int: row};
      final mergedMarks = {
        ...pendingMap,
        for (final e in live.entries) e.key: '${e.value['status'] ?? ''}'
      };
      final mergedNotes = {
        ...pendingNotes,
        for (final e in live.entries) e.key: '${e.value['notes'] ?? ''}'
      };
      _applySheet(data, serverLocked ? {} : mergedMarks,
          serverLocked ? {} : mergedNotes, serverLocked,
          preserveDirty: live.isNotEmpty);
      await _db.cacheMezmurSheet(date, section, data);
      if (!current()) return;
      // An awaited cache write must not reopen a submission made meanwhile.
      if (_submitting || _submissionRevision != requestSubmissionRevision) {
        return;
      }
      setState(() {
        _loading = false;
        _rosterReady = true;
        _loadFailed = false;
        _isOffline = !ConnectivityService().hasLink;
        _packetStatus = serverLocked && packet.isEmpty ? 'submitted' : packet;
        _returnNote = _mezmurReturnNote(data);
      });
    } else if (_members.isEmpty) {
      var msg = res.message ?? 'Could not load the sheet.';
      if (RegExp(r'server error', caseSensitive: false).hasMatch(msg)) {
        msg +=
            ' The server may be outdated — ask the administrator to pull the latest code and run sql/024_mezmur_submissions.sql.';
      }
      setState(() {
        _loading = false;
        _rosterReady = true;
        _loadFailed = true;
        _error = msg;
      });
    } else {
      setState(() {
        _loading = false;
        _rosterReady = true;
      });
    }
  }

  /// Department's written reason while the packet is returned for
  /// correction — the taker's key back to editing (mezmur wording).
  String? _mezmurReturnNote(Map<String, dynamic> data) {
    final status = '${data['submission_status'] ?? ''}'.toLowerCase().trim();
    if (status != 'revision_needed') return null;
    final note = '${data['review_notes'] ?? ''}'.trim();
    final reviewer = '${data['reviewer_name'] ?? ''}'.trim();
    final by = reviewer.isEmpty ? 'The Mezmur department' : reviewer;
    if (note.isEmpty) return '$by asked for corrections.';
    return '$by: $note';
  }

  void _applySheet(Map<String, dynamic> data, Map<int, String> pendingMap,
      Map<int, String> pendingNotes, bool locked,
      {bool preserveDirty = false}) {
    final raw = data['members'];
    if (raw is! List) return;
    final members = <Map<String, dynamic>>[];
    for (final m in raw) {
      if (m is! Map) continue;
      final row = Map<String, dynamic>.from(m);
      final mid =
          row['id'] is int ? row['id'] as int : int.tryParse('${row['id']}');
      if (mid == null) continue;
      row['member_id'] = mid;
      // Server payloads carry 'mark'; the local offline cache carries
      // 'status' — accept both so cached marks survive a reopen.
      row['status'] = locked
          ? _orAbsent(_statusOf(row['mark'] ?? row['status']))
          : _orAbsent(
              _firstStatus([pendingMap[mid], row['mark'], row['status']]));
      row['notes'] = locked
          ? '${row['notes'] ?? ''}'
          : (pendingNotes[mid] ?? '${row['notes'] ?? ''}');
      members.add(row);
    }
    if (!locked && pendingMap.isNotEmpty) {
      // Phone draft wins over the server baseline when one exists.
      for (final m in members) {
        final id = m['member_id'] as int;
        if (pendingMap.containsKey(id)) m['status'] = pendingMap[id];
        if (pendingNotes.containsKey(id)) m['notes'] = pendingNotes[id];
      }
    }
    _members = members;
    if (!preserveDirty) _dirty.value = false;
    if (mounted) setState(() {});
  }

  List<Map<String, dynamic>> _records() {
    return _members
        .map((m) => <String, dynamic>{
              'member_id': m['member_id'],
              'status': _statusOf(m['status']),
              'notes': m['notes'] ?? '',
            })
        .toList();
  }

  // Default-absent (2026-10-07): the sheet is complete by construction —
  // every member carries an explicit status (absent unless marked), so
  // Save and Submit are always available. The old "mark every member"
  // gate is gone; the server still enforces the complete roster on
  // submit (roster-drift protection) and merge-upserts drafts.

  Future<void> _persistLocal() async {
    final section = _selectedSection;
    if (section == null || _members.isEmpty || _locked) return;
    await _db.cacheMezmurSheet(_selectedDate, section, {
      'date': _selectedDate,
      'section': section,
      'members': _members.map((m) => Map<String, dynamic>.from(m)).toList(),
      'submission_status': _packetStatus.isEmpty ? null : _packetStatus,
      'locked': false,
    });
  }

  // ── Phase 8: instant autosave + QR scan ──────────────────────
  void _scheduleAutoSave() {
    _editRevision++;
    _autoSave.run(const Duration(milliseconds: 700), _autoSaveNow);
  }

  /// Persist the currently-marked rows as a draft packet right now.
  /// With default-absent the sheet is complete by construction —
  /// every member carries an explicit status. Dead battery after
  /// this point loses nothing; the outbox delivers when able.
  Future<bool> _autoSaveNow() async {
    final section = _selectedSection;
    if (section == null || _members.isEmpty || _locked) return false;
    final date = _selectedDate;
    final epoch = _loadEpoch;
    final revision = _editRevision;
    final submissionRevision = _submissionRevision;
    final records = _records();
    try {
      await _db.saveMezmurLocal(date, section, records, packetKind: 'draft');
    } catch (_) {
      return false;
    }
    if (!mounted ||
        epoch != _loadEpoch ||
        _submitting ||
        submissionRevision != _submissionRevision) {
      return false;
    }
    if (revision > _durableRevision) _durableRevision = revision;
    _dirty.value = true;
    final now = DateTime.now();
    if (now.difference(_lastSyncPush) > const Duration(seconds: 10)) {
      _lastSyncPush = now;
      unawaited(_sync.syncAll(force: false));
    }
    return true;
  }

  /// Leaves the open sheet before the taker moves to another section or date.
  ///
  /// A locked sheet (submitted, approved, rejected) cannot be written any
  /// more, so there is nothing to flush: refusing to leave dead-ended the
  /// section selector, which is what stopped a teacher from taking the other
  /// sections of the same day. The marks stay on screen and were already
  /// mirrored into the local packet store when they were made.
  Future<bool> _flushPendingEdits() async {
    _autoSave.cancel();
    if (_locked) {
      _durableRevision = _editRevision;
      _dirty.value = false;
      return true;
    }
    while (_durableRevision < _editRevision) {
      if (!await _autoSaveNow()) {
        if (mounted) {
          setState(() => _error =
              'Phone storage refused the save. Your changes are still on screen; resolve this before switching.');
        }
        return false;
      }
    }
    return true;
  }

  void _openQrScan() {
    QrScanSheet.open(context,
        onScan: _handleQrScan, header: _selectedSection ?? 'Mezmur');
  }

  /// Mezmur rules for one scan: member must belong to the open
  /// section; duplicates and the packet lock are refused with big
  /// Amharic feedback; success marks Present and autosaves at once.
  Future<QrFeedback> _handleQrScan(String? raw) async {
    final code = QrAttendance.extractMemberCode(raw);
    if (code == null) return QrFeedback.invalid();
    if (_locked) return QrFeedback.locked();
    if (_selectedSection == null || _members.isEmpty) {
      return QrFeedback.notFound();
    }
    final idx = _members.indexWhere((m) => '${m['member_code'] ?? ''}' == code);
    if (idx < 0) {
      final known = await _db.findCachedMemberByCode(code);
      if (known == null) return QrFeedback.notFound();
      final name = '${known['student_name'] ?? ''}';
      final st = '${known['status'] ?? ''}';
      if (st.isNotEmpty && st != 'active') {
        return QrFeedback.inactive(name: name);
      }
      return QrFeedback.wrongGroup(
          name: name, ownGroup: '${known['current_section'] ?? '—'}');
    }
    final m = _members[idx];
    final existing = _statusOf(m['status']);
    if (existing == 'present' || existing == 'late') {
      return QrFeedback.duplicate(
          name: '${m['student_name'] ?? ''}', status: existing);
    }
    // Default-absent: an absent (or excused) mark is not a check-in.
    // The scan is physical presence — it wins.
    setState(() => m['status'] = 'present');
    _dirty.value = true;
    final epoch = _loadEpoch;
    final revision = ++_editRevision;
    if (!await _autoSaveNow()) {
      if (mounted && epoch == _loadEpoch && revision == _editRevision) {
        setState(() => m['status'] = existing);
        _editRevision--;
      }
      return QrFeedback.storageError();
    }
    return QrFeedback.ok(name: '${m['student_name'] ?? ''}');
  }

  // ── Telegram-send model: SQLite write IS the save ─────────────
  Future<void> _save() async {
    final section = _selectedSection;
    if (section == null || _members.isEmpty || _locked || _switching) return;
    _autoSave.cancel();
    final epoch = _loadEpoch;
    final revision = _editRevision;
    final submissionRevision = _submissionRevision;
    try {
      await _db.saveMezmurLocal(_selectedDate, section, _records(),
          packetKind: 'draft');
    } catch (_) {
      if (mounted && epoch == _loadEpoch) {
        setState(() => _error =
            'Phone storage refused the save. Free up space and try again.');
      }
      return;
    }
    if (!mounted ||
        epoch != _loadEpoch ||
        _submitting ||
        submissionRevision != _submissionRevision) {
      return;
    }
    if (revision > _durableRevision) _durableRevision = revision;
    HapticFeedback.selectionClick();
    showQuickConfirm(context, 'Saved');
    _dirty.value = revision != _editRevision;
    setState(() {
      _packetStatus = 'draft';
      _error = null;
      _returnNote = null;
    });
    unawaited(_sync.syncAll(force: true));
  }

  Future<void> _submit() async {
    final section = _selectedSection;
    if (section == null || _members.isEmpty || _locked || _switching) return;
    _autoSave.cancel();
    final epoch = _loadEpoch;
    setState(() => _submitting = true);
    late final LegacyOperationRef operation;
    try {
      operation = await _db.saveMezmurLocal(
        _selectedDate,
        section,
        _records(),
        packetKind: 'submitted',
        notBefore: DateTime.now().add(const Duration(seconds: 5)),
      );
    } catch (_) {
      // Both branches clear _submitting before returning, so the section
      // selector and the date chip come back immediately: a refused
      // submission must never lock the taker out of the next section.
      if (mounted && epoch == _loadEpoch) {
        setState(() {
          _submitting = false;
          _error =
              'Phone storage refused the save. Free up space and try again.';
        });
      } else {
        _submitting = false;
      }
      return;
    }
    _submitting = false;
    if (!mounted || epoch != _loadEpoch) return;
    _submissionRevision++;
    _durableRevision = _editRevision;
    HapticFeedback.mediumImpact();
    _dirty.value = false;
    setState(() {
      _packetStatus = 'submitted';
      _error = null;
    });
    unawaited(_sync.syncAll(force: true));
    showUndoToast(context,
        message: 'Submitted', onUndo: () => _undoSubmit(operation, epoch));
  }

  Future<void> _undoSubmit(LegacyOperationRef operation, int epoch) async {
    final result = await _db.undoSubmittedLegacyOperation(operation);
    if (!mounted || epoch != _loadEpoch) return;
    switch (result) {
      case SubmitUndoResult.applied:
        setState(() => _packetStatus = 'draft');
        _dirty.value = false;
        showQuickConfirm(context, 'Submission undone');
        break;
      case SubmitUndoResult.alreadyClaimed:
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          duration: Duration(seconds: 2),
          content: Text('Already being sent to the Mezmur department'),
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

  void _setMark(int id, String status) {
    if (_locked) return;
    setState(() {
      for (final m in _members) {
        if (m['member_id'] == id) m['status'] = status;
      }
    });
    _dirty.value = true;
    _persistLocal();
    _scheduleAutoSave();
  }

  void _markAll(String status) {
    if (_locked) return;
    setState(() {
      for (final m in _members) {
        m['status'] = status;
      }
    });
    _dirty.value = true;
    _persistLocal();
    _scheduleAutoSave();
  }

  Future<void> _editNote(int index) async {
    final epoch = _loadEpoch;
    final memberId = _members[index]['member_id'];
    final ctrl =
        TextEditingController(text: '${_members[index]['notes'] ?? ''}');
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
          TextButton(
              onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(
              onPressed: () => Navigator.pop(ctx, ctrl.text.trim()),
              child: const Text('Keep')),
        ],
      ),
    );
    if (!mounted ||
        epoch != _loadEpoch ||
        _locked ||
        note == null ||
        index >= _members.length ||
        _members[index]['member_id'] != memberId) {
      return;
    }
    if (note == '${_members[index]['notes'] ?? ''}') return;
    setState(() => _members[index]['notes'] = note);
    _dirty.value = true;
    _persistLocal();
    _scheduleAutoSave();
  }

  Future<void> _pickDate() async {
    if (_submitting || _switching) return;
    final epoch = _loadEpoch;
    final picked = await showEthiopianDatePicker(
      context: context,
      initialGregorianDate: _selectedDate,
      firstDate: DateTime.now().subtract(const Duration(days: 730)),
      lastDate: DateTime.now(),
    );
    if (!mounted ||
        epoch != _loadEpoch ||
        picked == null ||
        picked == _selectedDate ||
        _submitting ||
        _switching) {
      return;
    }
    setState(() => _switching = true);
    var saved = false;
    try {
      saved = await _flushPendingEdits();
    } catch (_) {
      // A throwing save must not pin _switching true, or the date chip and
      // the section selector stay inert until the app is restarted.
      saved = false;
    }
    if (!mounted) return;
    if (!saved || epoch != _loadEpoch || _submitting) {
      setState(() => _switching = false);
      return;
    }
    setState(() {
      _selectedDate = picked;
      _members = [];
      _packetStatus = '';
      _returnNote = null;
      _rosterReady = false;
      _loading = true;
      _editRevision = _durableRevision = 0;
      _loadEpoch++;
      _switching = false;
    });
    _dirty.value = false;
    _loadSheet();
  }

  Future<void> _changeSection(String? value) async {
    if (value == null ||
        value == _selectedSection ||
        _submitting ||
        _switching) {
      return;
    }
    final epoch = _loadEpoch;
    setState(() => _switching = true);
    var saved = false;
    try {
      saved = await _flushPendingEdits();
    } catch (_) {
      saved = false;
    }
    if (!mounted) return;
    if (!saved || epoch != _loadEpoch || _submitting) {
      // Snap the field back to the section that is really open, and always
      // release the switch gate so the next attempt is possible.
      _sectionFieldKey.currentState?.didChange(_selectedSection);
      setState(() => _switching = false);
      return;
    }
    setState(() {
      _selectedSection = value;
      _members = [];
      _packetStatus = '';
      _returnNote = null;
      _rosterReady = false;
      _loading = true;
      _editRevision = _durableRevision = 0;
      _loadEpoch++;
      _switching = false;
    });
    _dirty.value = false;
    _loadSheet();
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
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
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
    ));
    await _updatePendingCount();
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

  Widget _memberRow(Map<String, dynamic> m, int number) {
    final id = m['member_id'] as int;
    final status = _statusOf(m['status']);
    final note = '${m['notes'] ?? ''}'.trim();
    return FastListRow(
      index: id,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      child: Row(
        children: [
          SizedBox(
            width: 28,
            child: Text('$number',
                style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          ),
          Expanded(
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: _locked ? null : () => _editNote(number - 1),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Flexible(
                      child: Text(
                          '${m['student_name'] ?? ''} ${m['father_name'] ?? ''}',
                          style: const TextStyle(
                              fontSize: 13.5, fontWeight: FontWeight.w700),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                    ),
                    // Attendance alerting Phase 2: read-only open-warning marker.
                    RowWarningMarker(
                        warning:
                            m['warning'] is Map ? m['warning'] as Map : null),
                  ]),
                  Text(
                    note.isNotEmpty
                        ? note
                        : ('${m['member_code'] ?? ''}'.isNotEmpty
                            ? '${m['member_code']}'
                            : 'Tap for note'),
                    style:
                        TextStyle(fontSize: 10, color: AppTheme.textSecondary),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ),
          _statusBtn('P', 'present', status, AppTheme.success, id),
          const SizedBox(width: 4),
          _statusBtn('A', 'absent', status, AppTheme.danger, id),
          const SizedBox(width: 4),
          _statusBtn('L', 'late', status, AppTheme.warning, id),
          const SizedBox(width: 4),
          _statusBtn('E', 'excused', status, AppTheme.info, id),
        ],
      ),
    );
  }

  /// Animated P/A/L/E chip: 40dp target, spring-like scale + colour
  /// tween on selection (implicit animations only — no controllers).
  Widget _statusBtn(
      String label, String value, String current, Color color, int id) {
    final selected = current == value;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: _locked
          ? null
          : () {
              HapticFeedback.lightImpact();
              _setMark(id, value);
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
    final total = _members.length;
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mezmur Attendance'),
        automaticallyImplyLeading: Navigator.canPop(context),
        actions: [
          // Scan lives in the top header (never covers roster data).
          if (!_locked)
            TextButton.icon(
              onPressed: _openQrScan,
              icon: const Icon(Icons.qr_code_scanner, size: 22),
              label: const Text('Scan',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
              style: TextButton.styleFrom(foregroundColor: Colors.white),
            ),
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
                  style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                      fontSize: 12),
                ),
              ),
            ),
        ],
      ),
      bottomNavigationBar: _members.isEmpty
          ? null
          : _locked
              ? const SubmittedBar()
              : TeacherActionBar(
                  saveLabel: 'Save',
                  submitLabel: 'Submit',
                  onSave: _save,
                  onSubmit: _submit,
                  saveEnabled: _dirty,
                ),
      body: Column(
        children: [
          // Global AppStatusStrip is the single offline source — per-screen
          // duplicate removed (space saver). One-tap sync lives in the
          // AppBar badge; details in Sync Center.

          // F8: packets the school's workflow refused — kept on
          // this phone; Review explains and offers Discard.
          if (_rejectedCount > 0)
            SyncAttentionBanner(rejectedCount: _rejectedCount),

          // [Section ▾] + Ethiopian date selector (teacher layout)
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Expanded(
                  flex: 3,
                  child: _loadingSections
                      ? const LinearProgressIndicator()
                      : DropdownButtonFormField<String>(
                          key: _sectionFieldKey,
                          value: _selectedSection != null &&
                                  _sections.any((s) =>
                                      '${s['section']}' == _selectedSection)
                              ? _selectedSection
                              : null,
                          hint: const Text('Select section',
                              style: TextStyle(fontSize: 13)),
                          isExpanded: true,
                          decoration: InputDecoration(
                            contentPadding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 10),
                            border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(10)),
                          ),
                          items: _sections
                              .map<DropdownMenuItem<String>>((s) =>
                                  DropdownMenuItem(
                                    value: '${s['section']}',
                                    child: Text(
                                        '${_sectionLabel(s['section'])} · ${s['members'] ?? ''}',
                                        style: const TextStyle(fontSize: 13),
                                        overflow: TextOverflow.ellipsis),
                                  ))
                              .toList(),
                          onChanged:
                              _submitting || _switching ? null : _changeSection,
                        ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: InkWell(
                    onTap: _submitting || _switching ? null : _pickDate,
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

          if (_members.isNotEmpty && _error == null) _progressStrip(),
          if (_error != null) StatusBanner.error(_error!, onRetry: _loadSheet),
          if (_returnNote != null &&
              _returnNote!.isNotEmpty &&
              !_locked &&
              _error == null)
            StatusBanner.warning(
                'Returned by the Mezmur department — you can edit and resubmit. $_returnNote'),
          if (_locked && _members.isNotEmpty && _error == null)
            StatusBanner.warning(
                '${PacketLock.label(_packetStatus).isEmpty ? _packetStatus : PacketLock.label(_packetStatus)} — view only. Only administrators can change this.'),

          if (_members.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              child: Row(
                children: [
                  Text(
                    '${_members.length} members · '
                    '${_countStatus('present')} present · '
                    '${_countStatus('absent')} absent',
                    style:
                        TextStyle(fontSize: 12, color: AppTheme.textSecondary),
                  ),
                  const Spacer(),
                  if (!_locked) ...[
                    _quickBtn('All Present', AppTheme.success,
                        () => _markAll('present')),
                    const SizedBox(width: 6),
                    _quickBtn('All Absent', AppTheme.danger,
                        () => _markAll('absent')),
                  ],
                ],
              ),
            ),

          Expanded(
            child: (_loading || (!_rosterReady && _selectedSection != null))
                ? const StudentListSkeleton()
                : RefreshIndicator(
                    onRefresh: _loadSheet,
                    child: _loadFailed && _members.isEmpty
                        ? ListView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            children: [
                              Padding(
                                padding: const EdgeInsets.all(16),
                                child: AppErrorCard(
                                  error: AppError.fromMessage(_error),
                                  onRetry: _loadSheet,
                                ),
                              ),
                            ],
                          )
                        : _members.isEmpty
                            ? ListView(
                                physics: const AlwaysScrollableScrollPhysics(),
                                children: [
                                  const SizedBox(height: 40),
                                  EmptyState(
                                    icon: Icons.people_outline,
                                    title: _selectedSection == null
                                        ? 'Select a section'
                                        : 'No members in this section',
                                    subtitle: _selectedSection == null
                                        ? 'Pick a section above to take attendance.'
                                        : 'Assign members to this section on the website, then pull down to refresh.',
                                    action: _selectedSection == null
                                        ? null
                                        : TextButton.icon(
                                            onPressed: _loadSheet,
                                            icon: const Icon(Icons.refresh,
                                                size: 18),
                                            label: const Text('Refresh'),
                                          ),
                                  ),
                                ],
                              )
                            : ListView.builder(
                                physics: const AlwaysScrollableScrollPhysics(),
                                itemCount: _members.length,
                                cacheExtent: kListCacheExtent,
                                padding:
                                    const EdgeInsets.fromLTRB(12, 0, 12, 24),
                                itemBuilder: (_, i) => RepaintBoundaryListItem(
                                    child: _memberRow(_members[i], i + 1)),
                              ),
                  ),
          ),
        ],
      ),
    );
  }
}
