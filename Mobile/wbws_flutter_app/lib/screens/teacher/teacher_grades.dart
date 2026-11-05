import 'dart:async';
import 'package:flutter/material.dart';
import '../../utils/transitions.dart';
import 'package:flutter/services.dart';
import '../../services/api_service.dart';
import '../../services/app_nav.dart';
import '../../services/catalog_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/grades_preparation_adapter.dart';
import '../../services/grades_preparation_fetcher.dart';
import '../../services/grades_preparation_readiness.dart';
import '../../services/grades_preparation_store.dart';
import '../../services/legacy_outbox_models.dart';
import '../../services/local_db.dart';
import '../../widgets/sync_attention.dart';
import '../../services/sync_service.dart';
import '../../utils/config.dart';
import '../../utils/packet.dart';
import '../../utils/roster.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/action_bar.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/app_error.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/quick_confirm.dart';
import '../../widgets/status_banner.dart';
import '../../utils/score_limit.dart';
import '../../widgets/score_state_chip.dart';
import '../../utils/view_only_lock.dart';
import '../../widgets/submit_summary_sheet.dart';
import 'student_result_card.dart';

class TeacherGradesScreen extends StatefulWidget {
  const TeacherGradesScreen({super.key});

  @override
  State<TeacherGradesScreen> createState() => TeacherGradesScreenState();
}

class TeacherGradesScreenState extends State<TeacherGradesScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  final _sync = SyncService();

  List<dynamic> _classes = [];
  List<dynamic> _subjects = [];
  // Honest empty-state guidance from the server (grades/bootstrap sends
  // 'notice' when the teacher has the class but no subject assignments —
  // including the class-teacher variant). Null when there is nothing to
  // explain; falls back to a generic line offline.
  String? _subjectsNotice;
  List<dynamic> _assessments = [];
  // 1.6.5 term-close model: the year's semesters (switcher) + which one is
  // current. _selectedTermId == null → current semester (default working
  // view); an explicit id → viewing that semester (read-only unless the
  // Education Department reopened it for corrections).
  List<dynamic> _terms = [];
  Map<String, dynamic>? _currentTerm;
  int? _selectedTermId;
  List<dynamic> _bootAssessments = [];
  bool _didBootstrap = false;

  int? _selectedClassId;
  int? _selectedSubjectId;
  String? _selectedClassName;

  bool _loadingClasses = true;
  bool _loadingSubjects = false;
  bool _loadingAssessments = false;
  bool _isOffline = false;
  String? _error;
  int _pendingGrades = 0;
  int _rejectedCount = 0;
  StreamSubscription<bool>? _netSub;
  GradesPreparationResolution? _prepResolution;
  final _prepAdapter = GradesPreparationAdapter();
  bool _refreshingPrep = false;
  String? get _prepBanner {
    final r = _prepResolution;
    if (r == null) return null;
    switch (r.reason) {
      case GradesReadinessReason.ready: return null;
      case GradesReadinessReason.missing: return 'No offline preparation yet — connect and refresh to download your classes, subjects and roster.';
      case GradesReadinessReason.staleSession: return 'Your sign-in changed — refresh your preparation before entering grades offline.';
      case GradesReadinessReason.expired: return 'Your offline preparation expired — connect and refresh to renew it.';
      case GradesReadinessReason.clockSkew: return 'Your phone clock looks wrong — fix the date/time, then refresh.';
    }
  }

  @override
  void initState() {
    super.initState();
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (!mounted || _isOffline == !hasLink) return;
      setState(() => _isOffline = !hasLink);
    });
    _loadPreparationStatus();
    _loadClasses();
    _updatePendingCount();
    _sync.syncStream.listen((s) {
      if (mounted) {
        setState(() {
          _pendingGrades = s.pendingGrades;
          _rejectedCount = s.rejected;
        });
      }
    });
  }

  @override
  void dispose() {
    _netSub?.cancel();
    super.dispose();
  }

  void refresh() {
    _loadClasses();
    _updatePendingCount();
  }

  Future<void> _updatePendingCount() async {
    final c = await _db.getPendingGradesCount();
    final rejected = (await _db.getRejectedBatches())
        .where((b) => b['kind'] == 'grades')
        .length;
    if (mounted) {
      setState(() {
        _pendingGrades = c;
        _rejectedCount = rejected;
      });
    }
  }

  Future<void> _loadClasses() async {
    setState(() { _error = null; });
    final warm = CatalogService().cached;
    if (warm.isNotEmpty && mounted) {
      setState(() { _classes = warm; _loadingClasses = false; _isOffline = !ConnectivityService().hasLink; });
    } else {
      setState(() => _loadingClasses = true);
    }

    final classes = await CatalogService().classes();
    if (!mounted) return;
    setState(() {
      _classes = classes.isNotEmpty ? classes : _classes;
      _loadingClasses = false;
      _isOffline = !ConnectivityService().hasLink;
    });
    AppNav().markGradesLoaded();
    if (classes.length == 1) {
      final id = classes[0]['id'] is int ? classes[0]['id'] as int : int.tryParse('${classes[0]['id']}');
      _selectedClassId = id;
      _selectedClassName = classes[0]['class_name'];
      _loadSubjects();
    }
  }

  Future<void> _loadPreparationStatus() async {
    try {
      final res = await _prepAdapter.resolve();
      if (!mounted) return;
      setState(() => _prepResolution = res);
    } catch (_) {}
  }

  Future<void> _refreshPreparation() async {
    if (_refreshingPrep) return;
    setState(() => _refreshingPrep = true);
    try {
      final rec = await _db.getLocalSession();
      final session = LegacyWriteSession(ownerUserId: rec.ownerUserId ?? _api.userId, authorizationVersion: rec.authorizationVersion ?? _api.authorizationVersion, runtimeGeneration: rec.generation);
      final fid = _api.familyId;
      if (fid == null || fid.isEmpty) throw const GradesPreparationFetchException('AUTH_FAMILY_MISSING', 'Sign in again — refresh family is unavailable.');
      final expectedScope = GradesPreparationScope(ownerUserId: session.ownerUserId, authorizationVersion: session.authorizationVersion, role: _api.userRole, familyId: fid, serverIdentity: AppConfig.apiBaseUrl);
      final fetcher = GradesPreparationFetcher(store: GradesPreparationStore(db: _db));
      await fetcher.fetchAndPublish(session: session, get: (p) => _api.get(p), expectedScope: expectedScope);
      await _loadPreparationStatus();
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Preparation refreshed'), backgroundColor: AppTheme.success));
    } on GradesPreparationFetchException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message), backgroundColor: AppTheme.warning));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Refresh failed: $e'), backgroundColor: AppTheme.warning));
    } finally {
      if (mounted) setState(() => _refreshingPrep = false);
    }
  }

  Future<void> _loadSubjects() async {
    if (_selectedClassId == null) return;
    final cached = await _db.getCachedSubjects(_selectedClassId!);
    if (!mounted) return;
    setState(() {
      _assessments = [];
      _bootAssessments = [];
      _didBootstrap = false;
      _selectedSubjectId = null;
      _subjectsNotice = null;
      if (cached.isNotEmpty) {
        _subjects = cached;
        _loadingSubjects = false;
      } else {
        _subjects = [];
        _loadingSubjects = true;
      }
    });

    final res = await _api.getGradeBootstrap(_selectedClassId!);
    if (!mounted) return;

    if (res.success && res.data != null) {
      final subjects = res.data['subjects'] ?? [];
      final allA = res.data['assessments'] ?? [];
      await _db.cacheSubjects(_selectedClassId!, subjects);
      setState(() {
        _subjects = subjects;
        _bootAssessments = allA;
        _terms = (res.data['terms'] as List?) ?? _terms;
        _currentTerm = (res.data['current_term'] as Map?)?.cast<String, dynamic>();
        // keep the selected semester if it still exists, else snap back to current
        if (_selectedTermId != null &&
            !_terms.any((t) => (t['id'] is int ? t['id'] : int.tryParse('${t['id']}')) == _selectedTermId)) {
          _selectedTermId = null;
        }
        _didBootstrap = true;
        _loadingSubjects = false;
        _isOffline = false;
        _subjectsNotice = res.data['notice'] as String?;
      });
      return;
    }

    setState(() {
      if (_subjects.isEmpty) _subjects = cached;
      _loadingSubjects = false;
      if (_subjects.isNotEmpty) {
        _isOffline = !ConnectivityService().hasLink;
      } else if (!res.success) {
        _error = res.message ?? 'Could not load subjects. Pull to refresh.';
      }
    });
  }

  int? _termIdOf(dynamic t) => t['id'] is int ? t['id'] as int : int.tryParse('${t['id']}');

  /// The semester currently being viewed (defaults to the current one).
  Map<String, dynamic>? get _viewedTerm {
    if (_selectedTermId == null) return _currentTerm;
    for (final t in _terms) {
      if (_termIdOf(t) == _selectedTermId) return t;
    }
    return null;
  }

  /// Viewing a closed semester that the department has NOT reopened:
  /// read-only analysis mode.
  bool get _termViewLocked {
    final t = _viewedTerm;
    if (t == null) return false;
    final isCur = t['is_current'] == true;
    final reop = t['is_reopened'] == true;
    return !isCur && !reop;
  }

  Future<void> _loadAssessments() async {
    if (_selectedClassId == null || _selectedSubjectId == null) return;
    final sid = _selectedSubjectId!;
    final cached = _selectedTermId == null ? await _db.getCachedAssessments(_selectedClassId!, sid) : <dynamic>[];
    if (!mounted) return;
    if (cached.isNotEmpty) {
      setState(() { _assessments = cached; _loadingAssessments = false; });
    }

    // Term scoping: the working view (no explicit semester selected) keeps
    // only current-semester + legacy rows; an explicit semester shows that
    // semester's tests. Boot data may mix terms, so filter client-side.
    bool termMatch(dynamic a) {
      final atid = a['term_id'] is int ? a['term_id'] as int : int.tryParse('${a['term_id'] ?? ''}');
      if (_selectedTermId == null) {
        return atid == null || (_currentTerm != null && atid == _currentTerm!['id']);
      }
      return atid == _selectedTermId;
    }

    final fromBoot = _bootAssessments.where((a) =>
      (a['subject_id'] is int ? a['subject_id'] : int.tryParse('${a['subject_id']}')) == sid && termMatch(a)
    ).toList();
    if (_didBootstrap && fromBoot.isNotEmpty && _selectedTermId == null) {
      setState(() { _assessments = fromBoot; _loadingAssessments = false; });
      await _db.cacheAssessments(_selectedClassId!, sid, fromBoot);
      return;
    }

    if (cached.isEmpty) {
      setState(() => _loadingAssessments = true);
    }
    final res = await _api.getAssessments(_selectedClassId!, sid,
        termId: _selectedTermId);
    if (!mounted) return;

    if (res.success && res.data != null) {
      final assessments = res.data['assessments'] ?? [];
      if (res.data['terms'] != null) {
        _terms = res.data['terms'] as List;
        _currentTerm = (res.data['current_term'] as Map?)?.cast<String, dynamic>();
      }
      // Only the default (current-semester) view is cached; a closed
      // semester is a view-only analysis path, not the working set.
      if (_selectedTermId == null) {
        await _db.cacheAssessments(_selectedClassId!, sid, assessments);
      }
      setState(() { _assessments = assessments; _loadingAssessments = false; _isOffline = false; });
    } else {
      setState(() {
        if (_assessments.isEmpty) _assessments = cached;
        _loadingAssessments = false;
        if (_assessments.isNotEmpty) _isOffline = !ConnectivityService().hasLink;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Grades'),
        automaticallyImplyLeading: Navigator.canPop(context),
        actions: [
          if (_pendingGrades > 0)
            IconButton(
              onPressed: () async {
                final r = await _sync.syncAll(force: true);
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(r.message), backgroundColor: r.failed > 0 ? AppTheme.warning : AppTheme.success));
                  _updatePendingCount();
                }
              },
              icon: Badge(
                label: Text('$_pendingGrades', style: const TextStyle(fontSize: 9)),
                backgroundColor: AppTheme.warning,
                child: const Icon(Icons.sync, size: 22),
              ),
            ),
          TextButton(onPressed: _refreshingPrep ? null : _refreshPreparation, child: Text(_refreshingPrep ? 'Refreshing…' : 'Refresh preparation', style: const TextStyle(fontSize: 12, color: Colors.white))),
          IconButton(icon: const Icon(Icons.refresh, size: 20), onPressed: refresh),
        ],
      ),
      body: _loadingClasses
          ? const DashboardSkeleton()
          : _error != null
              ? Padding(padding: const EdgeInsets.all(16), child: AppErrorCard(error: AppError.fromMessage(_error), onRetry: refresh, autoRetry: true))
              : _classes.isEmpty
                  ? const EmptyState(icon: Icons.grading_rounded, title: 'No classes assigned',
                      subtitle: 'You need to be assigned to classes to enter grades.')
                  : RefreshIndicator(
                      onRefresh: _loadClasses,
                      child: ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          if (_isOffline) _offlineBanner(),
                          if (_prepBanner != null)
                            Container(
                              margin: const EdgeInsets.only(bottom: 12),
                              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                              decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.12), borderRadius: BorderRadius.circular(8)),
                              child: Row(children: [Icon(Icons.info_outline, size: 16, color: AppTheme.warning), const SizedBox(width: 8), Expanded(child: Text(_prepBanner!, style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500)))]),
                            ),
                          if (_rejectedCount > 0)
                            SyncAttentionBanner(rejectedCount: _rejectedCount),
                          _buildClassSelector(),
                          const SizedBox(height: 12),
                          if (_selectedClassId != null) ...[
                            _buildSubjectSelector(),
                            const SizedBox(height: 12),
                          ],
                          if (_selectedSubjectId != null) _buildAssessmentSection(),
                        ],
                      ),
                    ),
    );
  }

  Widget _offlineBanner() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(
        color: AppTheme.warning.withOpacity(0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(children: [
        Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
        const SizedBox(width: 8),
        Expanded(child: Text('Waiting for network — grades stay on this phone until you are back online.',
            style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
      ]),
    );
  }

  Widget _buildClassSelector() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Class', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.textSecondary)),
        const SizedBox(height: 6),
        DropdownButtonFormField<int>(
          value: _selectedClassId,
          hint: const Text('Select class', style: TextStyle(fontSize: 13)),
          isExpanded: true,
          decoration: InputDecoration(
            contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
          ),
          items: _classes.map<DropdownMenuItem<int>>((c) => DropdownMenuItem(
            value: c['id'] is int ? c['id'] : int.tryParse('${c['id']}'),
            child: Text('${c['class_name']}', style: const TextStyle(fontSize: 13)),
          )).toList(),
          onChanged: (v) {
            setState(() {
              _selectedClassId = v;
              _selectedClassName = _classes.firstWhere((c) => (c['id'] is int ? c['id'] : int.tryParse('${c['id']}')) == v)['class_name'];
              _selectedSubjectId = null;
              _assessments = [];
            });
            _loadSubjects();
          },
        ),
      ],
    );
  }

  Widget _buildSubjectSelector() {
    if (_loadingSubjects) return const Padding(padding: EdgeInsets.all(16), child: StudentListSkeleton(count: 2));
    if (_subjects.isEmpty) {
      return Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.08), borderRadius: BorderRadius.circular(10)),
        child: Row(children: [
          const Icon(Icons.info_outline, color: AppTheme.warning, size: 18),
          const SizedBox(width: 10),
          Expanded(child: Text(_subjectsNotice ?? 'No subjects assigned to you for this class.', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary))),
        ]),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Subject', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.textSecondary)),
        const SizedBox(height: 6),
        Wrap(spacing: 8, runSpacing: 8, children: _subjects.map((s) {
          final selected = _selectedSubjectId == (s['id'] is int ? s['id'] : int.tryParse('${s['id']}'));
          return ChoiceChip(
            label: Text(s['subject_name'] ?? '', style: TextStyle(fontSize: 12, color: selected ? Colors.white : null, fontWeight: selected ? FontWeight.w600 : null)),
            selected: selected,
            selectedColor: AppTheme.primary,
            onSelected: (_) {
              setState(() => _selectedSubjectId = s['id'] is int ? s['id'] : int.tryParse('${s['id']}'));
              _loadAssessments();
            },
            side: BorderSide(color: selected ? AppTheme.primary : AppTheme.borderLight),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          );
        }).toList()),
      ],
    );
  }

  Widget _buildTermSwitcher() {
    if (_terms.length < 2) return const SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text('Semester', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.textSecondary)),
      const SizedBox(height: 6),
      Wrap(spacing: 8, runSpacing: 8, children: _terms.map((t) {
        final id = _termIdOf(t);
        final selected = _selectedTermId == null
            ? (t['is_current'] == true)
            : (_selectedTermId == id);
        final reop = t['is_reopened'] == true && t['is_current'] != true;
        return ChoiceChip(
          label: Text(
            '${t['term_name'] ?? 'Semester'}${t['is_current'] == true ? ' (current)' : (reop ? ' (corrections open)' : '')}',
            style: TextStyle(fontSize: 12, color: selected ? Colors.white : null, fontWeight: selected ? FontWeight.w600 : null)),
          selected: selected,
          selectedColor: AppTheme.primary,
          onSelected: (_) {
            setState(() => _selectedTermId = t['is_current'] == true ? null : id);
            _loadAssessments();
          },
          side: BorderSide(color: selected ? AppTheme.primary : (reop ? AppTheme.warning : AppTheme.borderLight)),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        );
      }).toList()),
      if (_termViewLocked && _selectedTermId != null)
        Container(
          margin: const EdgeInsets.only(top: 10),
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: AppTheme.danger.withOpacity(0.08),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: AppTheme.danger.withOpacity(0.4)),
          ),
          child: Row(children: [
            Icon(Icons.lock_outline, size: 16, color: AppTheme.danger),
            const SizedBox(width: 8),
            Expanded(child: Text(
              'Viewing a closed semester — read-only. You can analyze these marks; ask the Education Department to reopen the semester for corrections.',
              style: TextStyle(fontSize: 11, color: AppTheme.danger, height: 1.4))),
          ])),
      const SizedBox(height: 10),
    ]);
  }

  Widget _buildAssessmentSection() {
    if (_loadingAssessments) return const Padding(padding: EdgeInsets.all(24), child: StudentListSkeleton(count: 3));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          const Text('Department Assessments', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
        ]),
        const SizedBox(height: 8),
        _buildTermSwitcher(),
        if (_assessments.isEmpty)
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(color: AppTheme.surfaceLight, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppTheme.borderLight)),
            child: Column(children: [
              Icon(Icons.assignment_outlined, size: 36, color: AppTheme.textSecondary),
              const SizedBox(height: 10),
              const Text('No assessments configured yet', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w500)),
              const SizedBox(height: 4),
              Text('Assessments (tests, midterms, finals) are configured by the Education Department. Once set up, you can tap to enter student marks.',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
            ]),
          )
        else
          ..._assessments.map((a) => _assessmentCard(a)),
      ],
    );
  }

  Widget _assessmentCard(dynamic a) {
    final name = a['assessment_name'] ?? '';
    final type = a['assessment_type'] ?? 'test';
    final maxScore = a['max_score'] ?? 100;
    final weight = a['weight_percentage'] ?? 100;
    final totalStudents = a['total_students'] ?? a['student_count'] ?? 0;
    final gradesEntered = a['grades_entered'] ?? 0;
    final isLocked = PacketLock.isLocked('${a['submission_status'] ?? ''}', flagged: a['locked'] == true);
    final termLocked = a['term_locked'] == true;
    final typeColors = {'test': AppTheme.primary, 'quiz': AppTheme.info, 'midterm': AppTheme.warning, 'final': AppTheme.danger, 'assignment': AppTheme.accent};
    final color = typeColors[type] ?? AppTheme.primary;

    String statusText;
    Color statusBg;
    Color statusFg;

    if (termLocked) {
      statusText = 'Closed';
      statusBg = AppTheme.danger.withOpacity(0.10);
      statusFg = AppTheme.danger;
    } else if (isLocked) {
      statusText = 'Submitted';
      statusBg = AppTheme.primary.withOpacity(0.12);
      statusFg = AppTheme.primary;
    } else if (totalStudents > 0) {
      if (gradesEntered >= totalStudents) {
        statusText = '$gradesEntered/$totalStudents (Done)';
        statusBg = AppTheme.success.withOpacity(0.15);
        statusFg = AppTheme.success;
      } else if (gradesEntered > 0) {
        statusText = '$gradesEntered/$totalStudents graded';
        statusBg = AppTheme.info.withOpacity(0.12);
        statusFg = AppTheme.info;
      } else {
        statusText = '0/$totalStudents graded';
        statusBg = AppTheme.surfaceLight;
        statusFg = AppTheme.textSecondary;
      }
    } else {
      statusText = gradesEntered > 0 ? '$gradesEntered graded' : 'Enter';
      statusBg = gradesEntered > 0 ? AppTheme.success.withOpacity(0.12) : AppTheme.surfaceLight;
      statusFg = gradesEntered > 0 ? AppTheme.success : AppTheme.textSecondary;
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => _openGradeEntry(a),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(width: 44, height: 44,
              decoration: BoxDecoration(color: color.withOpacity(0.12), borderRadius: BorderRadius.circular(12)),
              child: Icon(Icons.assignment, color: color, size: 22)),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(name, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
              const SizedBox(height: 3),
              Row(children: [
                Container(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(color: color.withOpacity(0.12), borderRadius: BorderRadius.circular(4)),
                  child: Text(type, style: TextStyle(fontSize: 9, color: color, fontWeight: FontWeight.w600))),
                const SizedBox(width: 6),
                Text('Max: $maxScore', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                const SizedBox(width: 6),
                Text('Wt: $weight%', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                if (a['term_name'] != null && a['is_current_term'] != true) ...[
                  const SizedBox(width: 6),
                  Container(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.12), borderRadius: BorderRadius.circular(4)),
                    child: Text('${a['term_name']}', style: TextStyle(fontSize: 9, color: AppTheme.warning, fontWeight: FontWeight.w600))),
                ],
              ]),
            ])),
            Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: statusBg,
                borderRadius: BorderRadius.circular(8)),
              child: Text(
                statusText,
                style: TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: statusFg))),
            const SizedBox(width: 4),
            Icon(Icons.chevron_right, size: 18, color: AppTheme.textSecondary),
          ]),
        ),
      ),
    );
  }

  // 1.6.6: assessment creation was removed from the teacher app —
  // assessments are created and managed by the Education Department
  // (the server already refused teacher creation with 403; the dialog
  // that used to live here was dead code).

  void _openGradeEntry(dynamic assessment) {
    final subjectName = _subjects.firstWhere(
      (s) => (s['id'] is int ? s['id'] : int.tryParse('${s['id']}')) == _selectedSubjectId,
      orElse: () => {'subject_name': ''},
    )['subject_name'] ?? '';

    Navigator.push(context, SmoothPageRoute(page:
      _GradeEntryScreen(
        assessmentId: assessment['id'] is int ? assessment['id'] : int.tryParse('${assessment['id']}') ?? 0,
        assessmentName: assessment['assessment_name'] ?? '',
        maxScore: (assessment['max_score'] is num ? assessment['max_score'] : double.tryParse('${assessment['max_score']}') ?? 100).toDouble(),
        className: _selectedClassName ?? '',
        classId: _selectedClassId ?? 0,
        subjectId: _selectedSubjectId ?? 0,
        subjectName: subjectName,
        initialStatus: '${assessment['submission_status'] ?? ''}',
        initialLocked: assessment['locked'] == true,
        initialTermLocked: assessment['term_locked'] == true,
      ),
    )).then((_) { _loadAssessments(); _updatePendingCount(); });
  }
}

// ============================================================
// GRADE ENTRY SCREEN — offline-first score input
// ============================================================

class _GradeEntryScreen extends StatefulWidget {
  final int assessmentId;
  final String assessmentName;
  final double maxScore;
  final String className;
  final int classId;
  final int subjectId;
  final String subjectName;
  final String initialStatus;
  final bool initialLocked;
  /// 1.6.5: the test belongs to a semester that is neither current nor
  /// reopened — read-only analysis for teachers.
  final bool initialTermLocked;

  const _GradeEntryScreen({
    required this.assessmentId, required this.assessmentName,
    required this.maxScore, required this.className,
    required this.classId, required this.subjectId, required this.subjectName,
    this.initialStatus = '',
    this.initialLocked = false,
    this.initialTermLocked = false,
  });

  @override
  State<_GradeEntryScreen> createState() => _GradeEntryScreenState();
}

class _GradeEntryScreenState extends State<_GradeEntryScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  List<Map<String, dynamic>> _students = [];
  Map<int, TextEditingController> _scoreCtrl = {};
  Map<int, TextEditingController> _remarkCtrl = {};
  bool _loading = true;
  bool _isOffline = false;
  /// Grayed-out Save until a score/remark changes (see TeacherActionBar).
  final ValueNotifier<bool> _dirty = ValueNotifier(false);
  String _packetStatus = '';
  /// 1.6.5: this test's semester is closed (not current, not reopened) —
  /// read-only for teachers. Refreshed from /grades/students on load.
  bool _termLocked = false;
  String? _termName;
  /// Education's reason when this mark list was returned for correction.
  String? _returnNote;
  String? _error;
  String? _rosterNote;
  int _gradedCount = 0;
  int _pendingSync = 0;
  int _rejectedCount = 0;
  StreamSubscription<bool>? _netSub;
  StreamSubscription<dynamic>? _syncSub;
  Timer? _autosaveTimer;
  Future<void> _autosaveTail = Future<void>.value();
  bool _commitInProgress = false;
  LegacyOperationRef? _submittedUndoRef;

  /// 1.7.3 View-only lock (owner 2026-10-22): the teacher locks the list to
  /// cross-check without changing or erasing marks, and taps a student to
  /// show them their result. Remembered per test on this phone.
  bool _viewOnly = false;
  final TextEditingController _search = TextEditingController();
  String _query = '';

  /// Live score-limit hint ("Max is 10") under the box that refused a key.
  int? _hintMid;
  String _hintText = '';
  Timer? _hintTimer;

  Future<void> _restoreViewOnly() async {
    final v = await ViewOnlyLock.isOn(widget.assessmentId);
    if (mounted && v != _viewOnly) setState(() => _viewOnly = v);
  }

  Future<void> _setViewOnly(bool on) async {
    FocusScope.of(context).unfocus();
    setState(() {
      _viewOnly = on;
      if (!on) {
        _query = '';
        _search.clear();
      }
    });
    HapticFeedback.selectionClick();
    await ViewOnlyLock.set(widget.assessmentId, on);
  }

  void _showHint(int mid, String text) {
    _hintTimer?.cancel();
    HapticFeedback.lightImpact();
    setState(() {
      _hintMid = mid;
      _hintText = text;
    });
    _hintTimer = Timer(const Duration(milliseconds: 1800), () {
      if (mounted) setState(() => _hintMid = null);
    });
  }

  /// Read-only for any reason: submitted/approved, closed semester, or the
  /// teacher's own View-only lock.
  bool get _readOnly => _locked || _viewOnly;

  /// A blank on a submitted/approved list means "Did not take".
  bool get _blankIsNotTaken => PacketLock.isLocked(_packetStatus);

  @override
  void initState() {
    super.initState();
    _restoreViewOnly();
    _isOffline = !ConnectivityService().hasLink;
    _packetStatus = widget.initialStatus;
    _termLocked = widget.initialTermLocked;
    if (widget.initialLocked && _packetStatus.isEmpty) _packetStatus = 'submitted';
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (!mounted || _isOffline == !hasLink) return;
      setState(() => _isOffline = !hasLink);
    });
    // Icon-only delivery state (WhatsApp tick analogue): a tiny cloud while
    // the packet is still on this phone, nothing once the outbox delivers.
    _syncSub = SyncService().syncStream.listen((s) {
      if (!mounted ||
          (s.pendingGrades == _pendingSync && s.rejected == _rejectedCount)) {
        return;
      }
      setState(() {
        _pendingSync = s.pendingGrades;
        _rejectedCount = s.rejected;
      });
    });
    SyncService().emitCurrentStatus();
    _loadStudents();
  }

  @override
  void dispose() {
    _netSub?.cancel();
    _syncSub?.cancel();
    _autosaveTimer?.cancel();
    _hintTimer?.cancel();
    _search.dispose();
    _dirty.dispose();
    _scoreCtrl.values.forEach((c) => c.dispose());
    _remarkCtrl.values.forEach((c) => c.dispose());
    super.dispose();
  }

  Future<void> _loadStudents({bool silent = false}) async {
    if (!silent) {
      setState(() { _error = null; _rosterNote = null; });
      await _paintCache();
    }

    final res = await _api.getGradeStudents(widget.assessmentId);
    if (!mounted) return;

    if (res.success && res.data != null) {
      final parsed = RosterParse.students(res.data);
      if (parsed.isEmpty && RosterParse.reportedCount(res.data) > 0) {
        if (_students.isEmpty) {
          setState(() {
            _error = 'The server sent students but this phone could not read them.';
            _loading = false;
          });
        }
        return;
      }
      final packetNow = '${res.data['submission_status'] ?? ''}';
      final lockedNow = PacketLock.isLocked(packetNow, flagged: res.data['locked'] == true);
      final merged = lockedNow ? parsed : await _applyPending(parsed);
      if (_students.isEmpty) {
        _setupStudents(merged);
      } else {
        _mergeServer(merged);
      }
      String? note;
      if (RosterParse.fallback(res.data)) {
        final year = RosterParse.yearName(res.data);
        note = year == null
            ? 'Showing students from a previous year.'
            : 'Showing the $year roster.';
      }
      final packet = '${res.data['submission_status'] ?? ''}';
      final locked = PacketLock.isLocked(packet, flagged: res.data['locked'] == true);
      final termInfo = (res.data['assessment'] is Map) ? res.data['assessment'] as Map : null;
      final termLockedNow = termInfo?['term_locked'] == true;
      // Preserve pending grades: this packet lock does not settle their intent.
      setState(() {
        _isOffline = !ConnectivityService().hasLink;
        _loading = false;
        _rosterNote = note;
        _packetStatus = locked && packet.isEmpty ? 'submitted' : (packet.isNotEmpty ? packet : _packetStatus);
        _returnNote = PacketLock.returnNote(res.data);
        _termLocked = termLockedNow;
        final t = termInfo?['term'];
        _termName = (t is Map) ? (t['term_name'] as String?) : _termName;
      });
      _recountGraded();
      await _db.cacheGradeSheet(
        widget.assessmentId,
        widget.classId,
        merged,
        submissionStatus: _packetStatus,
        locked: locked,
      );
      await _db.cacheStudents(widget.classId, merged);
      return;
    }

    if (_students.isEmpty) {
      setState(() {
        _error = res.message ?? 'Could not load students. Check your connection and try again.';
        _loading = false;
        _isOffline = res.isNetworkError;
      });
    }
  }

  Future<void> _paintCache() async {
    final meta = await _db.getCachedGradeSheetMeta(widget.assessmentId);
    final cacheLocked = PacketLock.isLocked(
      '${meta?['submission_status'] ?? widget.initialStatus}',
      flagged: meta?['locked'] == true || widget.initialLocked,
    );
    if (cacheLocked) {
      _packetStatus = '${meta?['submission_status'] ?? widget.initialStatus}';
      if (_packetStatus.isEmpty) _packetStatus = 'submitted';
      // Cached locks cannot acknowledge or delete this phone's pending work.
    }
    final pending = cacheLocked ? <int, Map<String, dynamic>>{} : await _pendingByMember();
    final sheet = meta == null ? null : List<Map<String, dynamic>>.from(meta['students'] as List);
    List<Map<String, dynamic>> source = const [];
    if (sheet != null && sheet.isNotEmpty) {
      source = sheet;
    } else {
      source = await _db.getCachedStudents(widget.classId);
    }
    if (source.isEmpty) return;
    final students = <Map<String, dynamic>>[];
    for (final s in source) {
      final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
      if (mid == null) continue;
      final pg = pending[mid];
      students.add({
        'member_id': mid,
        'student_name': s['student_name'] ?? '',
        'father_name': s['father_name'] ?? '',
        'member_code': s['member_code'] ?? '',
        'gender': s['gender'] ?? '',
        'score': pg?['score'] ?? s['score'],
        'record_id': pg?['record_id'] ?? s['record_id'],
        'remarks': pg?['remark'] ?? s['remarks'] ?? s['remark'] ?? '',
      });
    }
    if (students.isEmpty) return;
    _setupStudents(students);
    if (mounted) setState(() { _loading = false; });
  }

  Future<Map<int, Map<String, dynamic>>> _pendingByMember() async {
    final pending = await _db.getPendingGradeRecords(widget.assessmentId);
    final map = <int, Map<String, dynamic>>{};
    for (final p in pending) {
      final mid = RosterParse.asInt(p['member_id']);
      if (mid != null) map[mid] = p;
    }
    return map;
  }

  Future<List<Map<String, dynamic>>> _applyPending(List<Map<String, dynamic>> parsed) async {
    final pending = await _pendingByMember();
    return parsed.map((s) {
      final mid = s['member_id'] as int;
      final pg = pending[mid];
      return <String, dynamic>{
        ...s,
        'member_id': mid,
        'score': pg?['score'] ?? s['score'],
        'record_id': pg?['record_id'] ?? s['record_id'],
        'remarks': pg?['remark'] ?? s['remarks'] ?? s['remark'] ?? '',
      };
    }).toList();
  }

  void _mergeServer(List<Map<String, dynamic>> incoming) {
    for (final s in incoming) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      final existing = _students.indexWhere((e) => RosterParse.asInt(e['member_id']) == mid);
      if (existing >= 0) {
        _students[existing]['record_id'] = s['record_id'] ?? _students[existing]['record_id'];
        final ctrl = _scoreCtrl[mid];
        if (ctrl != null && ctrl.text.trim().isEmpty && s['score'] != null) {
          ctrl.text = '${s['score']}';
        }
        final remark = _remarkCtrl[mid];
        if (remark != null && remark.text.trim().isEmpty) {
          final text = '${s['remarks'] ?? s['remark'] ?? ''}';
          if (text.isNotEmpty) remark.text = text;
        }
      } else {
        _scoreCtrl[mid] = TextEditingController(text: s['score'] != null ? '${s['score']}' : '');
        _remarkCtrl[mid] = TextEditingController(text: '${s['remarks'] ?? s['remark'] ?? ''}');
        _students.add({...s, 'member_id': mid});
      }
    }
  }

  void _setupStudents(List<Map<String, dynamic>> students) {
    _scoreCtrl.values.forEach((c) => c.dispose());
    _remarkCtrl.values.forEach((c) => c.dispose());
    _scoreCtrl.clear();
    _remarkCtrl.clear();
    int graded = 0;
    final clean = <Map<String, dynamic>>[];
    for (final s in students) {
      final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
      if (mid == null) continue;
      final score = s['score'];
      _scoreCtrl[mid] = TextEditingController(text: score != null ? '$score' : '');
      _remarkCtrl[mid] = TextEditingController(text: '${s['remarks'] ?? s['remark'] ?? ''}');
      if (score != null && ScoreLimit.isValid('$score', widget.maxScore)) graded++;
      clean.add({...s, 'member_id': mid});
    }
    _students = clean;
    _gradedCount = graded;
    // Fresh sheet from cache/server — nothing unsaved yet.
    _dirty.value = false;
  }

  void _recountGraded() {
    int n = 0;
    for (final s in _students) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      final t = _scoreCtrl[mid]?.text.trim() ?? '';
      // 1.7.3: only VALID marks count as graded.
      if (t.isNotEmpty && ScoreLimit.isValid(t, widget.maxScore)) n++;
    }
    if (n != _gradedCount) setState(() => _gradedCount = n);
  }

  /// Boxes that may not be saved (e.g. "9." or an old 15 on a /10 test).
  /// The server would refuse them (422 INVALID_SCORES) — tell the teacher now.
  List<String> _problems() {
    final out = <String>[];
    for (final s in _students) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      final t = _scoreCtrl[mid]?.text.trim() ?? '';
      if (t.isEmpty || ScoreLimit.isValid(t, widget.maxScore)) continue;
      final name = '${s['student_name'] ?? ''} ${s['father_name'] ?? ''}'.trim();
      final v = double.tryParse(t);
      out.add(v != null && v > widget.maxScore
          ? '$name: $t is more than ${ScoreLimit.label(widget.maxScore)}'
          : '$name: "$t" is not a valid mark');
    }
    return out;
  }

  bool _refuseIfProblems() {
    final bad = _problems();
    if (bad.isEmpty) return false;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      duration: const Duration(seconds: 5),
      backgroundColor: AppTheme.danger,
      content: Text(
        'Fix ${bad.length} mark${bad.length == 1 ? '' : 's'} first (max ${ScoreLimit.label(widget.maxScore)}). '
        '${bad.take(3).join('; ')}${bad.length > 3 ? '; and ${bad.length - 3} more' : ''}',
      ),
    ));
    return true;
  }

  /// Students left blank — after Submit they are "Did not take".
  List<String> _blankNames() {
    final out = <String>[];
    for (final s in _students) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      if ((_scoreCtrl[mid]?.text.trim() ?? '').isEmpty) {
        out.add('${s['student_name'] ?? ''} ${s['father_name'] ?? ''}'.trim());
      }
    }
    return out;
  }

  /// 1.7.3 submit summary: "47 scored · 3 Did not take: names…".
  Future<bool> _confirmSubmitSummary(int scored) => showSubmitSummarySheet(
        context,
        assessmentName: widget.assessmentName,
        scored: scored,
        blankNames: _blankNames(),
      );

  Future<void> _persistDraftSnapshot(
      List<Map<String, dynamic>> grades) async {
    if (_commitInProgress || _locked || grades.isEmpty) return;
    await _db.saveGradesLocal(
      widget.assessmentId, widget.assessmentName,
      widget.classId, widget.className,
      widget.subjectId, widget.subjectName,
      widget.maxScore, grades,
      packetKind: 'draft',
    );
  }

  void _scheduleAutosave() {
    if (_commitInProgress || _locked) return;
    // Capture the typed values now. A delayed timer must never read a newer
    // submitted generation from live controllers and relabel it as a draft.
    final snapshot = List<Map<String, dynamic>>.unmodifiable(
      _collectGrades().map(
        (row) => Map<String, dynamic>.unmodifiable(
          Map<String, dynamic>.from(row),
        ),
      ),
    );
    _autosaveTimer?.cancel();
    _autosaveTimer = Timer(const Duration(milliseconds: 450), () {
      _autosaveTail = _autosaveTail
          .catchError((_) {})
          .then((_) => _persistDraftSnapshot(snapshot));
    });
  }

  Future<void> _beginOrderedCommit() async {
    if (mounted) setState(() => _commitInProgress = true);
    _autosaveTimer?.cancel();
    _autosaveTimer = null;
    await _autosaveTail.catchError((_) {});
  }

  void _endOrderedCommit() {
    if (mounted) setState(() => _commitInProgress = false);
  }

  bool get _locked => _commitInProgress || _termLocked ||
      PacketLock.isLocked(_packetStatus,
          flagged: widget.initialLocked && _packetStatus.isEmpty);

  /// Valid score rows currently on the form. Shared by Save, Submit, Undo.
  List<Map<String, dynamic>> _collectGrades() {
    final grades = <Map<String, dynamic>>[];
    for (final s in _students) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      final text = _scoreCtrl[mid]?.text.trim() ?? '';
      if (text.isEmpty) continue;
      final score = double.tryParse(text);
      if (score == null || score < 0 || score > widget.maxScore) continue;
      grades.add({
        'member_id': mid,
        'score': score,
        'remark': _remarkCtrl[mid]?.text.trim() ?? '',
        'record_id': s['record_id'],
        'student_name': s['student_name'],
      });
    }
    return grades;
  }

  /// Telegram-send model: the SQLite write IS the save (~5 ms). The outbox
  /// worker owns delivery — never awaited from a button tap.
  Future<void> _saveGrades() async {
    if (_locked) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Already submitted. Only Education can change this.'),
          backgroundColor: AppTheme.warning));
      return;
    }

    if (_refuseIfProblems()) return;
    final grades = _collectGrades();
    if (grades.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('No valid grades to save'), backgroundColor: AppTheme.warning));
      return;
    }

    await _beginOrderedCommit();
    try {
      await _db.saveGradesLocal(
        widget.assessmentId, widget.assessmentName,
        widget.classId, widget.className,
        widget.subjectId, widget.subjectName,
        widget.maxScore, grades,
        packetKind: 'draft',
      );
    } catch (_) {
      _endOrderedCommit();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Phone storage refused the save'),
            backgroundColor: AppTheme.danger));
      }
      return;
    }
    if (!mounted) return;
    HapticFeedback.selectionClick(); // Telegram send-tick: saved on phone.
    showQuickConfirm(context, 'Saved');
    _dirty.value = false;
    setState(() {
      _commitInProgress = false;
      _packetStatus = _packetStatus.isEmpty ? 'draft' : _packetStatus;
      if (_packetStatus == 'draft') _returnNote = null;
    });
    // Fire-and-forget: retries/backoff/pending-pill are SyncService's job.
    unawaited(SyncService().syncAll(force: true));
  }

  /// Instant submit with a 5-second UNDO window instead of a confirmation
  /// dialog. Undo refuses honestly once the outbox has delivered.
  Future<void> _submitGrades() async {
    if (_locked) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Already submitted. Only Education can change this.'),
          backgroundColor: AppTheme.warning));
      return;
    }
    if (_refuseIfProblems()) return;
    final grades = _collectGrades();
    if (grades.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Enter at least one valid score first'), backgroundColor: AppTheme.warning));
      return;
    }
    FocusScope.of(context).unfocus();
    if (!await _confirmSubmitSummary(grades.length)) return;
    if (!mounted || _locked) return;
    await _beginOrderedCommit();
    try {
      _submittedUndoRef = await _db.saveGradesLocal(
        widget.assessmentId, widget.assessmentName,
        widget.classId, widget.className,
        widget.subjectId, widget.subjectName,
        widget.maxScore, grades,
        packetKind: 'submitted',
        notBefore: DateTime.now().add(const Duration(seconds: 5)),
      );
    } catch (_) {
      _endOrderedCommit();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Phone storage refused the save'),
            backgroundColor: AppTheme.danger));
      }
      return;
    }
    if (!mounted) return;
    HapticFeedback.mediumImpact();
    _dirty.value = false;
    setState(() {
      _commitInProgress = false;
      _packetStatus = 'submitted';
      _returnNote = null;
    });
    unawaited(SyncService().syncAll(force: true));
    // 5-second decision window with a visible countdown; when the ring
    // empties the toast disappears and the mark list stays locked for
    // Education.
    showUndoToast(context, message: 'Submitted', onUndo: _undoSubmit);
  }

  Future<void> _undoSubmit() async {
    final operation = _submittedUndoRef;
    if (operation == null) return;
    final result = await _db.undoSubmittedLegacyOperation(operation);
    if (!mounted) return;
    switch (result) {
      case SubmitUndoResult.applied:
        _submittedUndoRef = null;
        setState(() {
          _packetStatus = 'draft';
          _returnNote = null;
        });
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(widget.assessmentName, style: const TextStyle(fontSize: 16)),
          Text('${widget.className} · Max: ${ScoreLimit.label(widget.maxScore)}',
              style: const TextStyle(fontSize: 11, color: Colors.white70)),
        ]),
        actions: [
          // 1.7.3 View-only lock — not needed when the list is already locked.
          if (_students.isNotEmpty && !_locked)
            IconButton(
              key: const Key('view-only-toggle'),
              tooltip: _viewOnly ? 'View only — tap to edit' : 'Lock to view only',
              icon: Icon(_viewOnly ? Icons.lock : Icons.visibility_outlined),
              onPressed: () => _setViewOnly(!_viewOnly),
            ),
        ],
      ),
      bottomNavigationBar: _students.isEmpty
          ? null
          : _termLocked
              ? Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(14),
                  color: AppTheme.danger.withOpacity(0.10),
                  child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                    Icon(Icons.lock_outline, size: 16, color: AppTheme.danger),
                    const SizedBox(width: 8),
                    Text('Closed semester — read only',
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.danger)),
                  ]))
              : _locked
              ? const SubmittedBar()
              : _viewOnly
              ? Material(
                  color: Colors.white,
                  child: SafeArea(
                    top: false,
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
                      child: OutlinedButton.icon(
                        key: const Key('view-only-edit'),
                        onPressed: () => _setViewOnly(false),
                        icon: const Icon(Icons.lock_open, size: 18),
                        label: const Text('Edit marks'),
                      ),
                    ),
                  ),
                )
              : TeacherActionBar(
                  saveLabel: 'Save',
                  submitLabel: 'Submit',
                  onSave: _saveGrades,
                  onSubmit: _submitGrades,
                  saveEnabled: _dirty,
                ),
      body: Column(children: [
        if (_termLocked)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(10),
            color: AppTheme.danger.withOpacity(0.08),
            child: Row(children: [
              Icon(Icons.lock_outline, size: 16, color: AppTheme.danger),
              const SizedBox(width: 8),
              Expanded(child: Text(
                'This semester${_termName != null ? ' ($_termName)' : ''} is closed for editing — view only. Ask the Education Department to reopen it for corrections.',
                style: TextStyle(fontSize: 11, color: AppTheme.danger, height: 1.35))),
            ])),
        Expanded(child: _loading
          ? const StudentListSkeleton()
          : _error != null
              ? Padding(padding: const EdgeInsets.all(16), child: AppErrorCard(error: AppError.fromMessage(_error), onRetry: _loadStudents, autoRetry: true))
              : _students.isEmpty
                  ? EmptyState(
                      icon: Icons.people_outline,
                      title: 'No students in this class yet',
                      subtitle: 'If they were enrolled on the website, tap Refresh. Education can add them under Enrollment.',
                      action: TextButton.icon(
                        onPressed: _loadStudents,
                        icon: const Icon(Icons.refresh, size: 18),
                        label: const Text('Refresh'),
                      ),
                    )
                  : Column(children: [
                      if (_isOffline)
                        Container(width: double.infinity, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                          color: AppTheme.warning.withOpacity(0.15),
                          child: Row(children: [
                            Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
                            const SizedBox(width: 8),
                            Expanded(child: Text('Waiting for network — scores stay on this phone until you are back online',
                                style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
                          ])),
                      if (_rejectedCount > 0)
                        SyncAttentionBanner(rejectedCount: _rejectedCount),
                      if (_rosterNote != null)
                        StatusBanner.warning(_rosterNote!),
                      if (_returnNote != null &&
                          _returnNote!.isNotEmpty &&
                          !_locked)
                        StatusBanner.warning(
                            'Returned by Education — you can edit and resubmit. $_returnNote'),
                      if (_viewOnly && !_locked)
                        Container(
                          key: const Key('view-only-banner'),
                          width: double.infinity,
                          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 9),
                          color: AppTheme.info.withOpacity(0.10),
                          child: Row(children: [
                            Icon(Icons.lock, size: 16, color: AppTheme.info),
                            const SizedBox(width: 8),
                            Expanded(child: Text('View only. Tap 🔒 to edit. Tap a student to show their result.',
                                style: TextStyle(fontSize: 12, color: AppTheme.info, fontWeight: FontWeight.w600))),
                          ])),
                      if (_readOnly)
                        Padding(
                          padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                          child: TextField(
                            key: const Key('grades-search'),
                            controller: _search,
                            onChanged: (v) => setState(() => _query = v.trim().toLowerCase()),
                            decoration: InputDecoration(
                              isDense: true,
                              prefixIcon: const Icon(Icons.search, size: 20),
                              hintText: 'Find a student by name or code',
                              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                              suffixIcon: _query.isEmpty
                                  ? null
                                  : IconButton(
                                      icon: const Icon(Icons.close, size: 18),
                                      onPressed: () => setState(() {
                                        _query = '';
                                        _search.clear();
                                      })),
                            ),
                          ),
                        ),
                      Container(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), color: AppTheme.surfaceLight,
                        child: Row(children: [
                          Text('${_students.length} students', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                          const Spacer(),
                          Text('$_gradedCount of ${_students.length} graded', style: TextStyle(fontSize: 12,
                              color: _gradedCount > 0 ? AppTheme.success : AppTheme.textSecondary, fontWeight: FontWeight.w600)),
                          if (_pendingSync > 0) ...[
                            const SizedBox(width: 10),
                            Tooltip(
                              message: 'On this phone — sending in background',
                              child: Icon(Icons.cloud_upload_outlined, size: 15, color: AppTheme.textSecondary),
                            ),
                          ],
                        ])),
                      Expanded(child: Builder(builder: (_) {
                        final rows = _visibleIndexes();
                        if (rows.isEmpty) {
                          return Center(child: Text('No student matches "$_query"',
                              style: TextStyle(color: AppTheme.textSecondary)));
                        }
                        return ListView.builder(
                          itemCount: rows.length,
                          cacheExtent: kListCacheExtent,
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          itemBuilder: (_, i) => _studentRow(rows[i]),
                        );
                      })),
                    ]),
              ),
            ],
          ),
    );
  }

  /// Rows to show — all, or the search matches (name, father, code).
  List<int> _visibleIndexes() {
    final all = List<int>.generate(_students.length, (i) => i);
    if (!_readOnly || _query.isEmpty) return all;
    return all.where((i) {
      final s = _students[i];
      final hay = '${s['student_name'] ?? ''} ${s['father_name'] ?? ''} ${s['member_code'] ?? ''}'.toLowerCase();
      return hay.contains(_query);
    }).toList();
  }

  void _openResultCard(Map<String, dynamic> s, int mid) {
    FocusScope.of(context).unfocus();
    final text = _scoreCtrl[mid]?.text.trim() ?? '';
    Navigator.push(context, SmoothPageRoute(page: StudentResultCard(
      assessmentId: widget.assessmentId,
      memberId: mid,
      studentName: '${s['student_name'] ?? ''} ${s['father_name'] ?? ''}'.trim(),
      memberCode: '${s['member_code'] ?? ''}',
      assessmentName: widget.assessmentName,
      maxScore: widget.maxScore,
      scoreText: text,
      notTaken: text.isEmpty && _blankIsNotTaken,
      subjectName: widget.subjectName,
      className: widget.className,
    )));
  }

  /// Read-only row (View only / submitted / closed semester): no box, no
  /// keyboard — the mark big and clear, or "Did not take". Tap = result card.
  Widget _readOnlyRow(int index, Map<String, dynamic> s, int mid) {
    final text = _scoreCtrl[mid]?.text.trim() ?? '';
    final remark = _remarkCtrl[mid]?.text.trim() ?? '';
    final valid = text.isEmpty || ScoreLimit.isValid(text, widget.maxScore);
    return InkWell(
      key: Key('ro-row-$mid'),
      onTap: () => _openResultCard(s, mid),
      child: FastListRow(
        index: index,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        child: Row(children: [
          SizedBox(width: 28, child: Text('${index + 1}', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary, fontWeight: FontWeight.w600))),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${s['student_name'] ?? ''} ${s['father_name'] ?? ''}',
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600), maxLines: 1, overflow: TextOverflow.ellipsis),
            if ('${s['member_code'] ?? ''}'.isNotEmpty)
              Text('${s['member_code']}', style: TextStyle(fontSize: 10, color: AppTheme.textSecondary)),
            if (remark.isNotEmpty)
              Text(remark, style: TextStyle(fontSize: 11, color: AppTheme.textSecondary), maxLines: 1, overflow: TextOverflow.ellipsis),
          ])),
          if (text.isEmpty)
            ScoreStateChip(key: Key('ro-state-$mid'), notTaken: _blankIsNotTaken)
          else
            Text('$text / ${ScoreLimit.label(widget.maxScore)}',
                key: Key('ro-score-$mid'),
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800,
                    color: valid ? AppTheme.primary : AppTheme.danger)),
          const SizedBox(width: 4),
          Icon(Icons.chevron_right, size: 18, color: AppTheme.textSecondary),
        ]),
      ),
    );
  }

  Widget _studentRow(int index) {
    final s = _students[index];
    final mid = RosterParse.asInt(s['member_id']);
    if (mid == null) return const SizedBox.shrink();
    if (_readOnly) return _readOnlyRow(index, s, mid);
    final hasScore = _scoreCtrl[mid]?.text.isNotEmpty ?? false;
    final scoreText = _scoreCtrl[mid]?.text.trim() ?? '';
    final scoreBad = scoreText.isNotEmpty && !ScoreLimit.isValid(scoreText, widget.maxScore, finalCheck: false);

    return FastListRow(
      index: index,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              SizedBox(width: 28, child: Text('${index + 1}', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary, fontWeight: FontWeight.w600))),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${s['student_name'] ?? ''} ${s['father_name'] ?? ''}',
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500), maxLines: 1, overflow: TextOverflow.ellipsis),
                if (s['member_code'] != null && s['member_code'].toString().isNotEmpty)
                  Text(s['member_code'], style: TextStyle(fontSize: 10, color: AppTheme.textSecondary)),
              ])),
              SizedBox(width: 72, height: 38,
                child: TextField(
                  key: Key('score-$mid'),
                  controller: _scoreCtrl[mid],
                  readOnly: _locked,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  // 1.7.3 live limit: a key that would go over the max (or a
                  // 3rd decimal, a 2nd dot, a letter) is refused on the spot.
                  inputFormatters: [
                    ScoreLimitFormatter(widget.maxScore, onRefused: (why) => _showHint(mid, why)),
                  ],
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: hasScore ? AppTheme.primary : null),
                  decoration: InputDecoration(
                    hintText: '—', hintStyle: TextStyle(color: AppTheme.textSecondary),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                    enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(8),
                      borderSide: BorderSide(
                          color: scoreBad ? AppTheme.danger : (hasScore ? AppTheme.primary.withOpacity(0.3) : AppTheme.borderLight),
                          width: scoreBad ? 1.6 : 1)),
                  ),
                  textInputAction: TextInputAction.next,
                  onChanged: (_) {
                    _dirty.value = true;
                    _recountGraded();
                    _scheduleAutosave();
                  },
                ),
              ),
              const SizedBox(width: 6),
              Text('/ ${ScoreLimit.label(widget.maxScore)}',
                  style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
            ]),
            if (_hintMid == mid || scoreBad)
              Padding(
                padding: const EdgeInsets.only(top: 4, left: 28),
                child: Text(
                  _hintMid == mid ? _hintText : 'Max is ${ScoreLimit.label(widget.maxScore)} — fix this mark',
                  key: Key('score-hint-$mid'),
                  style: TextStyle(fontSize: 11, color: AppTheme.danger, fontWeight: FontWeight.w700),
                ),
              ),
            const SizedBox(height: 6),
            TextField(
              controller: _remarkCtrl[mid],
              readOnly: _locked,
              style: const TextStyle(fontSize: 12),
              decoration: InputDecoration(
                hintText: 'Remark (optional)',
                hintStyle: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
                isDense: true,
                contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
              ),
              onChanged: (_) {
                _dirty.value = true;
                _scheduleAutosave();
              },
            ),
          ],
      ),
    );
  }
}


