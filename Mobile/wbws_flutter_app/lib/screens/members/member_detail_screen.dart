import '../../widgets/loading_skeleton.dart';
import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/photo_outbox_models.dart';
import '../../utils/config.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/theme.dart';
import '../../utils/transitions.dart';
import '../attendance_warnings/attendance_warning_widgets.dart';
import '../attendance_warnings/attendance_warnings_screen.dart';
import 'member_photo_capture_screen.dart' hide AppTheme;
import 'photo_batch_capture_screen.dart';

class MemberDetailScreen extends StatefulWidget {
  final int memberId;
  const MemberDetailScreen({super.key, required this.memberId});

  @override
  State<MemberDetailScreen> createState() => _MemberDetailScreenState();
}

class _MemberDetailScreenState extends State<MemberDetailScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  Map<String, dynamic>? _member;
  bool _loading = true; // nothing rendered yet (no cached row)
  bool _refreshing = false; // background refresh in flight
  bool _fromCache = false; // current view came from the local cache
  bool _isOffline = false; // OS radio down / network error
  String? _error;
  bool get _photoOnly => UserRoles.canManageMemberPhotos(_api.userRole);
  bool get _canUploadPhotos => UserRoles.canUploadMemberPhotos(_api.userRole);

  // Attendance alerting Phase 2: per-department streak / month / year.
  // Loaded independently; a 403 (role outside the warning readers) or
  // any failure simply hides the block — never an error on the profile.
  Map<String, dynamic>? _risk;

  @override
  void initState() {
    super.initState();
    _loadMember();
    _loadRisk();
  }

  Future<void> _loadRisk() async {
    if (_photoOnly) return;
    if (!ConnectivityService().hasLink) return;
    final res = await _api.getMemberAttendanceRisk(widget.memberId);
    if (!mounted) return;
    if (res.success &&
        res.data is Map &&
        (res.data as Map)['available'] == true &&
        (res.data as Map)['risk'] is Map) {
      setState(() =>
          _risk = Map<String, dynamic>.from((res.data as Map)['risk'] as Map));
    }
  }

  Widget _riskSection() {
    final risk = _risk;
    if (risk == null) return const SizedBox.shrink();
    final cards = <Widget>[];
    risk.forEach((dept, value) {
      if (value is! Map) return;
      cards.add(Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: WarningRiskCard(risk: value),
      ));
    });
    if (cards.isEmpty) return const SizedBox.shrink();
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Expanded(
                child: Text('Attendance risk & warnings',
                    style:
                        TextStyle(fontSize: 14, fontWeight: FontWeight.w600))),
            TextButton(
              style: TextButton.styleFrom(visualDensity: VisualDensity.compact),
              onPressed: () => Navigator.of(context).push(
                  SmoothPageRoute(page: const AttendanceWarningsScreen())),
              child: const Text('Warnings', style: TextStyle(fontSize: 12)),
            ),
          ]),
          Text('Ethiopian calendar · streak counts submitted sheets only',
              style: TextStyle(fontSize: 10.5, color: AppTheme.textSecondary)),
          const SizedBox(height: 10),
          ...cards,
        ]),
      ),
    );
  }

  /// P1-A local-first load:
  ///   getCachedMemberById → render immediately (offline works)
  ///   → GET /members/{id} independently
  ///   → upsert the response into cached_members (enriches the local
  ///     row with this role's full projection)
  ///   → update the UI from that data.
  /// Nothing is invented: no cached row + failed fetch = honest
  /// unavailable state.
  Future<void> _loadMember() async {
    setState(() => _error = null);

    // ── 1. LOCAL READ (instant; offline-capable) ───────────────────
    final cachedRaw = await _db.getCachedMemberById(widget.memberId);
    final cached = cachedRaw == null
        ? null
        : (_photoOnly ? _photoMemberProjection(cachedRaw) : cachedRaw);
    if (!mounted) return;
    if (cached != null) {
      setState(() {
        _member = cached;
        _loading = false;
        _fromCache = true;
      });
    } else {
      setState(() => _loading = true);
    }

    // ── 2. SERVER REFRESH (independent, non-blocking) ──────────────
    if (!ConnectivityService().hasLink) {
      setState(() {
        _refreshing = false;
        _isOffline = true;
        if (cached == null) {
          _loading = false;
          _error =
              'Waiting for network — this member is not saved on this phone';
        }
      });
      return;
    }
    setState(() {
      _refreshing = true;
      _isOffline = false;
    });
    final res = await _api.getMember(widget.memberId);
    if (!mounted) return;
    setState(() => _refreshing = false);
    if (res.success && res.data != null) {
      final member = _photoOnly
          ? _photoMemberProjection(res.data!)
          : Map<String, dynamic>.from(res.data!);
      // Upsert the full detail response — the cached row keeps the
      // richest projection this role is allowed to see (merge/upsert
      // semantics unchanged; no deletion/eviction work here).
      await _db.cacheMembers([member]);
      if (!mounted) return;
      setState(() {
        _member = member;
        _fromCache = false;
        _loading = false;
        _error = null;
      });
    } else if (cached == null) {
      // No local copy and the fetch failed — honest unavailable state.
      setState(() {
        _loading = false;
        _isOffline = res.isNetworkError;
        _error = res.isNetworkError
            ? 'Waiting for network — this member is not saved on this phone'
            : (res.message ?? 'Member not found');
      });
    }
    // else: fetch failed but a cached row is on screen — keep it and
    // keep the cached banner up (stale, honestly labeled).
  }

  Map<String, dynamic> _photoMemberProjection(Map<String, dynamic> member) {
    const allowed = {
      'id',
      'member_code',
      'student_name',
      'father_name',
      'grandfather_name',
      'photo_url',
      'local_updated_at',
    };
    return Map<String, dynamic>.fromEntries(
      member.entries.where((entry) => allowed.contains(entry.key)),
    );
  }

  /// '… · updated 14:32' — from the cached row's local stamp.
  String? _fmtUpdated(String? iso) {
    if (iso == null || iso.isEmpty) return null;
    final dt = DateTime.tryParse(iso);
    if (dt == null) return null;
    final local = dt.toLocal();
    final now = DateTime.now();
    final hh = local.hour.toString().padLeft(2, '0');
    final mm = local.minute.toString().padLeft(2, '0');
    final sameDay = local.year == now.year &&
        local.month == now.month &&
        local.day == now.day;
    return sameDay
        ? '$hh:$mm'
        : '${local.year}-${local.month.toString().padLeft(2, '0')}-${local.day.toString().padLeft(2, '0')}';
  }

  Future<void> _openSingleCapture() async {
    if (_member == null) return;
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => const MemberPhotoCaptureScreen(),
        settings: RouteSettings(arguments: {
          'memberId': widget.memberId,
          'memberCode': _member!['member_code'] as String?,
          'memberName':
              '${_member!['student_name'] ?? ''} ${_member!['father_name'] ?? ''}'
                  .trim(),
          'field': PhotoField.student,
        }),
      ),
    );
    // After returning, let the sync badge (on the list) refresh on its timer;
    // nothing cached on the detail screen depends on photo state.
  }

  Future<void> _openBatchCapture() async {
    if (_member == null) return;
    await Navigator.of(context).push(
      SmoothPageRoute(
        page: const PhotoBatchCaptureScreen(),
        settings:
            RouteSettings(arguments: {'startFromMemberId': widget.memberId}),
      ),
    );
  }

  String get _cachedBannerText {
    final base = 'Showing cached data';
    if (_refreshing) return '$base — updating…';
    final updated = _fmtUpdated(_member?['local_updated_at']);
    return updated == null ? base : '$base · updated $updated';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_member?['student_name'] ?? 'Member Details'),
        actions: _canUploadPhotos
            ? [
                IconButton(
                  tooltip: 'Take photo',
                  icon: const Icon(Icons.camera_alt_outlined),
                  onPressed: _member == null ? null : _openSingleCapture,
                ),
                IconButton(
                  tooltip: 'Batch capture roster',
                  icon: const Icon(Icons.photo_library_outlined),
                  onPressed: _member == null ? null : _openBatchCapture,
                ),
              ]
            : const [],
      ),
      body: _loading
          ? const MemberDetailSkeleton()
          : _error != null
              ? Center(
                  child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(_isOffline ? Icons.cloud_off : Icons.error_outline,
                        size: 48,
                        color: _isOffline ? AppTheme.warning : AppTheme.danger),
                    const SizedBox(height: 12),
                    Text(_error!,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                            color:
                                _isOffline ? AppTheme.warning : AppTheme.danger,
                            fontSize: 14)),
                    TextButton(
                        onPressed: _loadMember, child: const Text('Retry')),
                  ],
                ))
              : RefreshIndicator(
                  onRefresh: () async {
                    await Future.wait([_loadMember(), _loadRisk()]);
                  },
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      if (_fromCache)
                        Container(
                          margin: const EdgeInsets.only(bottom: 12),
                          padding: const EdgeInsets.symmetric(
                              horizontal: 14, vertical: 10),
                          decoration: BoxDecoration(
                            color: AppTheme.warning.withOpacity(0.12),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                                color: AppTheme.warning.withOpacity(0.25)),
                          ),
                          child: Row(children: [
                            Icon(Icons.cloud_off,
                                size: 16, color: AppTheme.warning),
                            const SizedBox(width: 8),
                            Expanded(
                                child: Text(_cachedBannerText,
                                    style: TextStyle(
                                        fontSize: 11,
                                        color: AppTheme.warning,
                                        fontWeight: FontWeight.w500))),
                          ]),
                        ),
                      _buildHeader(),
                      const SizedBox(height: 16),
                      if (_photoOnly)
                        _buildSection('Photo', [
                          _field(
                            'Photo',
                            _member?['photo_url'] == null
                                ? 'No photo uploaded'
                                : 'Photo on file',
                          ),
                        ])
                      else ...[
                        _buildSection('Personal Information', [
                          _field(
                              'Full Name (Amharic)', _member?['full_name_am']),
                          _field('Student Name', _member?['student_name']),
                          _field('Father Name', _member?['father_name']),
                          _field('Grandfather', _member?['grandfather_name']),
                          _field('Gender', _member?['gender']),
                          _field('Date of Birth',
                              _formatEcDate(_member?['date_of_birth'])),
                          _field('Age Group', _member?['age_group']),
                          _field('Member Code', _member?['member_code']),
                          _field('Member Type', _member?['member_type']),
                          _field('Registration', _member?['registration_type']),
                        ]),
                        const SizedBox(height: 12),
                        _buildSection('Contact', [
                          _field('Phone', _member?['phone_number']),
                          _field('Alt Phone', _member?['alt_phone_number']),
                          _field('Guardian', _member?['guardian_name']),
                          _field('Guardian Phone', _member?['guardian_phone1']),
                        ]),
                        const SizedBox(height: 12),
                        _buildSection('Education', [
                          _field('Section', _member?['current_section']),
                          _field(
                              'Education Level', _member?['education_level']),
                          if (_member?['current_class'] != null) ...[
                            _field('Class',
                                _member?['current_class']?['class_name']),
                            _field(
                                'Enrolled At',
                                _formatEcDate(
                                    _member?['current_class']?['enrolled_at'])),
                          ],
                        ]),
                        const SizedBox(height: 12),
                        _riskSection(),
                        if (_risk != null) const SizedBox(height: 12),
                        _buildSection('Address', [
                          _field('City', _member?['city']),
                          _field('Sub City', _member?['sub_city']),
                          _field('Woreda', _member?['woreda']),
                          _field('Address', _member?['address']),
                        ]),
                      ],
                      const SizedBox(height: 20),
                    ],
                  ),
                ),
    );
  }

  Widget _buildHeader() {
    final status = _member?['status'] ?? 'active';
    final statusColor = status == 'active'
        ? AppTheme.success
        : status == 'warning'
            ? AppTheme.warning
            : AppTheme.danger;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            CircleAvatar(
              radius: 36,
              backgroundColor: AppTheme.primary.withOpacity(0.15),
              child: Text(
                (_member?['student_name'] ?? '?')[0].toUpperCase(),
                style: const TextStyle(
                    color: AppTheme.primary,
                    fontWeight: FontWeight.w700,
                    fontSize: 28),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              '${_member?['student_name'] ?? ''} ${_member?['father_name'] ?? ''}',
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              textAlign: TextAlign.center,
            ),
            if (!_photoOnly && _member?['full_name_am'] != null) ...[
              const SizedBox(height: 4),
              Text(_member!['full_name_am'],
                  style:
                      TextStyle(fontSize: 14, color: AppTheme.textSecondary)),
            ],
            const SizedBox(height: 8),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (!_photoOnly)
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: statusColor.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(status,
                        style: TextStyle(
                            color: statusColor,
                            fontWeight: FontWeight.w600,
                            fontSize: 12)),
                  ),
                if (_member?['member_code'] != null) ...[
                  const SizedBox(width: 8),
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: AppTheme.info.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(_member!['member_code'],
                        style: TextStyle(
                            color: AppTheme.info,
                            fontWeight: FontWeight.w600,
                            fontSize: 12)),
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSection(String title, List<Widget> children) {
    // Filter out empty fields
    final nonEmpty = children.where((w) => w is! SizedBox).toList();
    if (nonEmpty.isEmpty) return const SizedBox.shrink();

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title,
                style:
                    const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            const SizedBox(height: 10),
            ...children,
          ],
        ),
      ),
    );
  }

  /// Convert Gregorian date string to Ethiopian display
  String? _formatEcDate(dynamic value) {
    if (value == null || value.toString().trim().isEmpty) return null;
    try {
      return formatGregorianAsEthiopian(value.toString().substring(0, 10));
    } catch (_) {
      return value.toString();
    }
  }

  Widget _field(String label, dynamic value) {
    if (value == null || value.toString().trim().isEmpty)
      return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(label,
                style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          ),
          Expanded(
            child: Text(value.toString(),
                style:
                    const TextStyle(fontSize: 13, fontWeight: FontWeight.w500)),
          ),
        ],
      ),
    );
  }
}
