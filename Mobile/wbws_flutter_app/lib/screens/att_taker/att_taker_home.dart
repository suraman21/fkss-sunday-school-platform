import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/catalog_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/sync_service.dart';
import '../../utils/theme.dart';
import '../../widgets/stat_card.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import '../attendance/attendance_screen.dart';
import '../../widgets/notification_bell_button.dart';

class AttTakerHomeScreen extends StatefulWidget {
  const AttTakerHomeScreen({super.key});
  @override
  State<AttTakerHomeScreen> createState() => AttTakerHomeScreenState();
}

class AttTakerHomeScreenState extends State<AttTakerHomeScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  final _sync = SyncService();
  bool _loading = true;
  bool _isOffline = false;
  String? _error;
  Map<String, dynamic> _stats = {};
  List<dynamic> _classes = [];
  int _pendingCount = 0;

  @override
  void initState() { super.initState(); _load(); _loadPending(); }
  void refresh() { _load(); _loadPending(); }

  Future<void> _loadPending() async {
    await _sync.emitCurrentStatus();
    final status = _sync.lastStatus;
    final count = status.privateUnresolvedTotal +
        status.sharedHymnUnresolvedTotal +
        status.communicationDraftCount;
    if (mounted) setState(() => _pendingCount = count);
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; _isOffline = false; });
    late ApiResponse statsRes;
    late List<dynamic> classList;
    await Future.wait([
      _api.getDashboardStats().then((v) => statsRes = v),
      CatalogService().classes().then((v) => classList = v),
    ]);
    if (!mounted) return;
    bool gotData = false;

    if (statsRes.success && statsRes.data != null) {
      _stats = statsRes.data['stats'] ?? {};
      gotData = true;
      _db.cacheDashboardStats(statsRes.data, _api.userRole);
    }
    if (classList.isNotEmpty) {
      _classes = classList;
      gotData = true;
    }

    if (!gotData) {
      // Fallback to cached data
      final cached = await _db.getCachedDashboardStats();
      final cachedClasses = await _db.getCachedClasses();
      if (cached != null || cachedClasses.isNotEmpty) {
        if (cached != null) _stats = cached['stats'] ?? {};
        if (cachedClasses.isNotEmpty) _classes = cachedClasses;
        setState(() { _loading = false; _isOffline = !ConnectivityService().hasLink; });
        return;
      }
      setState(() { _loading = false; _error = statsRes.message; _isOffline = statsRes.isNetworkError; });
      return;
    }

    setState(() { _loading = false; });
  }

  @override
  Widget build(BuildContext context) {
    final att = _stats['today_attendance'] ?? {};
    final recorded = att['recorded'] ?? 0;
    final present = att['present'] ?? 0;
    final rate = recorded > 0 ? (present / recorded * 100).toStringAsFixed(0) : '--';

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        child: CustomScrollView(
          slivers: [
            SliverAppBar(floating: true, automaticallyImplyLeading: false,
              title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('FKSS', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                Text('ቅጥረት ያዥ • Attendance Taker', style: TextStyle(fontSize: 11, color: Colors.white70)),
              ]),
              actions: [
                const NotificationBellButton(color: Colors.white),
                if (_pendingCount > 0)
                  IconButton(
                    tooltip: 'Sync Center',
                    onPressed: () async {
                      await Navigator.of(context).pushNamed('/sync-center');
                      _loadPending();
                    },
                    icon: Badge(label: Text('$_pendingCount', style: const TextStyle(fontSize: 9)), backgroundColor: AppTheme.warning, child: const Icon(Icons.sync, size: 22)),
                  ),
                IconButton(icon: const Icon(Icons.refresh_rounded, size: 22), onPressed: refresh),
              ],
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              sliver: SliverList(delegate: SliverChildListDelegate(
                _loading ? [const DashboardSkeleton()]
                : _error != null ? [// per-screen generic offline removed — global AppStatusStrip covers it
 AppErrorCard(error: AppError.fromMessage(_error, isNetwork: _isOffline), onRetry: _load, autoRetry: true)]
                : [
                    // per-screen generic offline removed — global AppStatusStrip covers it


                    // Focused banner
                    Container(
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        gradient: const LinearGradient(
                          colors: [AppTheme.primaryDark, AppTheme.primary, AppTheme.primaryLight],
                          begin: Alignment.topLeft, end: Alignment.bottomRight),
                        borderRadius: BorderRadius.circular(16)),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('ቅጥረት ያዥ', style: TextStyle(fontSize: 13, color: Colors.white70, fontFamily: 'NotoSansEthiopic')),
                        const SizedBox(height: 2),
                        Text('Hello, ${_api.userName.split(' ').first}!',
                            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white)),
                        const SizedBox(height: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          decoration: BoxDecoration(color: AppTheme.primary.withOpacity(0.2), borderRadius: BorderRadius.circular(8)),
                          child: Text('${_classes.length} class${_classes.length != 1 ? 'es' : ''} assigned',
                              style: const TextStyle(fontSize: 12, color: AppTheme.primary, fontWeight: FontWeight.w600)),
                        ),
                      ]),
                    ),
                    const SizedBox(height: 16),

                    // Today's stats
                    Row(children: [
                      Expanded(child: StatCard(label: 'Present', value: '$present', icon: Icons.check_circle, color: AppTheme.success)),
                      const SizedBox(width: 10),
                      Expanded(child: StatCard(label: 'Absent', value: '${att['absent'] ?? 0}', icon: Icons.cancel, color: AppTheme.danger)),
                      const SizedBox(width: 10),
                      Expanded(child: StatCard(label: 'Rate', value: '$rate%', icon: Icons.trending_up, color: AppTheme.info)),
                    ]),
                    const SizedBox(height: 20),

                    // Classes
                    Text('Tap a class to take attendance',
                        style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: Colors.white70)),
                    const SizedBox(height: 10),
                    ..._classes.map((c) => Card(
                      margin: const EdgeInsets.only(bottom: 8),
                      child: ListTile(
                        onTap: () {
                          final id = c['id'] is int ? c['id'] as int : int.tryParse('${c['id']}');
                          Navigator.of(context).push(MaterialPageRoute(
                            builder: (_) => AttendanceScreen(initialClassId: id),
                          ));
                        },
                        leading: Container(
                          width: 44, height: 44,
                          decoration: BoxDecoration(color: AppTheme.primary.withOpacity(0.12), borderRadius: BorderRadius.circular(12)),
                          child: const Icon(Icons.class_rounded, color: AppTheme.primary, size: 22)),
                        title: Text(c['class_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                        subtitle: Text('${c['student_count'] ?? 0} students', style: TextStyle(fontSize: 12, color: Colors.white70)),
                        trailing: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                          decoration: BoxDecoration(
                            color: (c['attendance_taken_today'] == true ? AppTheme.success : AppTheme.warning).withOpacity(0.12),
                            borderRadius: BorderRadius.circular(8)),
                          child: Text(c['attendance_taken_today'] == true ? 'Done' : 'Pending',
                              style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600,
                                  color: c['attendance_taken_today'] == true ? AppTheme.success : AppTheme.warning)),
                        ),
                      ),
                    )),
                  ],
              )),
            ),
          ],
        ),
      ),
    );
  }

  Widget _offlineBanner() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.12), borderRadius: BorderRadius.circular(8)),
      child: Row(children: [
        Icon(Icons.cloud_off, size: 16, color: AppTheme.warning), const SizedBox(width: 8),
        Expanded(child: Text('Waiting for network — showing the last numbers saved on this phone.',
            style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
      ]),
    );
  }
}





