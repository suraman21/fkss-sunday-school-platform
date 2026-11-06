import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/timetable_cache.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/theme.dart';

/// ============================================================
/// Teacher — My Timetable (read-only)
/// ============================================================
/// Layout and options follow the eduid schedule screen (Weekly · Daily ·
/// Monthly views, Today button, week navigation with a Mon–Sun day strip,
/// period list with a "Now" highlight, subject icons/colours, offline and
/// empty states) — restyled with the FKSS palette and showing ONLY the
/// Ethiopian calendar. The timetable itself is built by the Education
/// department on the web (Education → Timetable); the phone never edits it.
///
/// Data: `GET /timetable/week` → `days` {iso weekday → lessons},
/// `makeup_classes` (dated, from today on). Cached per user with
/// [TimetableCache] so the last schedule still opens without network.
class TeacherTimetableScreen extends StatefulWidget {
  const TeacherTimetableScreen({super.key});

  @override
  State<TeacherTimetableScreen> createState() => _TeacherTimetableScreenState();
}

enum _View { weekly, daily, monthly }

/// One row of the period list — either a recurring lesson from the weekly
/// grid or a dated makeup class.
class _Slot {
  final String startTime; // HH:MM
  final String endTime; // HH:MM
  final String subject;
  final String? subjectEn;
  final String className;
  final String? room;
  final String periodLabel;
  final bool isMakeup;
  final String? reason;

  const _Slot({
    required this.startTime,
    required this.endTime,
    required this.subject,
    this.subjectEn,
    required this.className,
    this.room,
    required this.periodLabel,
    this.isMakeup = false,
    this.reason,
  });

  int get startMinutes => _toMinutes(startTime);
  int get endMinutes => _toMinutes(endTime);

  static int _toMinutes(String hhmm) {
    final parts = hhmm.split(':');
    if (parts.length < 2) return 0;
    return (int.tryParse(parts[0]) ?? 0) * 60 + (int.tryParse(parts[1]) ?? 0);
  }

  static String _s(dynamic v) => v == null ? '' : v.toString();
  static String _hhmm(String t) => t.length > 5 ? t.substring(0, 5) : t;

  factory _Slot.lesson(Map<String, dynamic> l) {
    final amh = _s(l['subject_name']).trim();
    final en = _s(l['subject_name_en']).trim();
    return _Slot(
      startTime: _hhmm(_s(l['start_time'])),
      endTime: _hhmm(_s(l['end_time'])),
      subject: amh.isNotEmpty ? amh : (en.isNotEmpty ? en : 'Lesson'),
      subjectEn: en.isNotEmpty && en != amh ? en : null,
      className: _s(l['class_name_en']).trim().isNotEmpty
          ? _s(l['class_name_en']).trim()
          : _s(l['class_name']).trim(),
      room: _s(l['room']).trim().isEmpty ? null : _s(l['room']).trim(),
      periodLabel: _s(l['period_label']),
    );
  }

  factory _Slot.makeup(Map<String, dynamic> m) {
    final amh = _s(m['subject_name']).trim();
    final en = _s(m['subject_name_en']).trim();
    return _Slot(
      startTime: _hhmm(_s(m['start_time'])),
      endTime: _hhmm(_s(m['end_time'])),
      subject: amh.isNotEmpty ? amh : (en.isNotEmpty ? en : 'Makeup class'),
      subjectEn: en.isNotEmpty && en != amh ? en : null,
      className: _s(m['class_name_en']).trim().isNotEmpty
          ? _s(m['class_name_en']).trim()
          : _s(m['class_name']).trim(),
      room: _s(m['room']).trim().isEmpty ? null : _s(m['room']).trim(),
      periodLabel: 'Makeup',
      isMakeup: true,
      reason: _s(m['reason']).trim().isEmpty ? null : _s(m['reason']).trim(),
    );
  }
}

class _TeacherTimetableScreenState extends State<TeacherTimetableScreen> {
  final _api = ApiService();

  /// ISO weekday (1 = Monday … 7 = Sunday) → recurring lessons.
  final Map<int, List<_Slot>> _week = {};

  /// Gregorian yyyy-MM-dd → makeup classes on that date (display is EC).
  final Map<String, List<_Slot>> _makeups = {};

  String _yearName = '';
  bool _loading = true;
  bool _fromCache = false;
  bool _offline = false;
  String? _error;
  StreamSubscription<bool>? _netSub;
  Timer? _clock;

  _View _view = _View.daily;
  DateTime _selected = _dateOnly(DateTime.now());

  /// Ethiopian month currently shown in the monthly view.
  late int _ecMonth;
  late int _ecYear;

  static DateTime _dateOnly(DateTime d) => DateTime(d.year, d.month, d.day);

  @override
  void initState() {
    super.initState();
    final today = EthiopianDate.today();
    _ecMonth = today.month;
    _ecYear = today.year;
    _offline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (!mounted || _offline == !hasLink) return;
      setState(() => _offline = !hasLink);
      if (hasLink && (_fromCache || _error != null)) _load();
    });
    // Re-evaluate the "Now" highlight once a minute.
    _clock = Timer.periodic(const Duration(minutes: 1), (_) {
      if (mounted) setState(() {});
    });
    _load();
  }

  @override
  void dispose() {
    _netSub?.cancel();
    _clock?.cancel();
    super.dispose();
  }

  // ── data ───────────────────────────────────────────────────────

  Future<void> _load() async {
    final uid = _api.userId;
    // 1. Show the last saved copy at once (also the offline answer).
    if (_week.isEmpty && _makeups.isEmpty) {
      final cached = await TimetableCache.instance.read(uid);
      if (cached != null && mounted) {
        setState(() {
          _apply(cached);
          _fromCache = true;
          _loading = false;
        });
      }
    }
    // 2. Refresh from the server.
    final res = await _api.getMyTimetableWeek();
    if (!mounted) return;
    if (res.success && res.data is Map) {
      final data = Map<String, dynamic>.from(res.data as Map);
      await TimetableCache.instance.save(uid, data);
      if (!mounted) return;
      setState(() {
        _apply(data);
        _fromCache = false;
        _error = null;
        _loading = false;
      });
      return;
    }
    setState(() {
      _loading = false;
      if (_week.isEmpty && _makeups.isEmpty) {
        _error = res.isNetworkError
            ? 'offline'
            : ((res.message ?? '').isNotEmpty ? res.message! : 'Could not load your timetable.');
      }
    });
  }

  void _apply(Map<String, dynamic> data) {
    _week.clear();
    _makeups.clear();
    _yearName = (data['year_name'] ?? '').toString();
    final days = data['days'];
    if (days is Map) {
      days.forEach((k, v) {
        final iso = int.tryParse(k.toString()) ?? 0;
        if (iso < 1 || iso > 7 || v is! List) return;
        _week[iso] = v
            .whereType<Map>()
            .map((e) => _Slot.lesson(Map<String, dynamic>.from(e)))
            .toList()
          ..sort((a, b) => a.startMinutes.compareTo(b.startMinutes));
      });
    }
    final mk = data['makeup_classes'];
    if (mk is List) {
      for (final e in mk.whereType<Map>()) {
        final m = Map<String, dynamic>.from(e);
        final date = (m['lesson_date'] ?? '').toString();
        if (date.isEmpty) continue;
        (_makeups[date] ??= []).add(_Slot.makeup(m));
      }
      for (final list in _makeups.values) {
        list.sort((a, b) => a.startMinutes.compareTo(b.startMinutes));
      }
    }
  }

  // ── helpers ───────────────────────────────────────────────────

  static String _ymd(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  List<_Slot> _slotsFor(DateTime date) {
    final list = <_Slot>[
      ...(_week[date.weekday] ?? const []),
      ...(_makeups[_ymd(date)] ?? const []),
    ];
    list.sort((a, b) => a.startMinutes.compareTo(b.startMinutes));
    return list;
  }

  bool _hasClasses(DateTime date) =>
      (_week[date.weekday]?.isNotEmpty ?? false) ||
      (_makeups[_ymd(date)]?.isNotEmpty ?? false);

  bool _isToday(DateTime d) => _sameDay(d, DateTime.now());

  bool _sameDay(DateTime a, DateTime b) =>
      a.year == b.year && a.month == b.month && a.day == b.day;

  bool _isNow(_Slot s, DateTime date) {
    if (!_isToday(date)) return false;
    final now = TimeOfDay.now();
    final m = now.hour * 60 + now.minute;
    return m >= s.startMinutes && m < s.endMinutes;
  }

  DateTime get _weekStart =>
      _selected.subtract(Duration(days: _selected.weekday - 1));

  static const _dayShort = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  static const _dayLong = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
  ];

  /// "ጥቅምት 10, 2019" — Ethiopian only.
  String _ecLabel(DateTime d) {
    final e = EthiopianDate.fromGregorian(d);
    return '${monthNameAmharic(e.month)} ${e.day}, ${e.year}';
  }

  /// Range label for the week strip, e.g. "ጥቅምት 8 – 14, 2019" or
  /// "ጥቅምት 28 – ኅዳር 4, 2019".
  String _weekRange() {
    final a = EthiopianDate.fromGregorian(_weekStart);
    final b = EthiopianDate.fromGregorian(_weekStart.add(const Duration(days: 6)));
    if (a.month == b.month && a.year == b.year) {
      return '${monthNameAmharic(a.month)} ${a.day} – ${b.day}, ${a.year}';
    }
    final yr = a.year == b.year ? '${a.year}' : '${a.year}/${b.year}';
    return '${monthNameAmharic(a.month)} ${a.day} – ${monthNameAmharic(b.month)} ${b.day}, $yr';
  }

  /// Week number inside the Ethiopian year (1 = week containing መስከረም 1).
  int _ecWeekNumber() {
    final e = EthiopianDate.fromGregorian(_weekStart);
    final newYear = ethiopianToGregorian(e.year, 1, 1);
    final newYearWeekStart = newYear.subtract(Duration(days: newYear.weekday - 1));
    return (_weekStart.difference(newYearWeekStart).inDays ~/ 7) + 1;
  }

  /// Keeps the monthly grid on the Ethiopian month of [_selected] so a day
  /// picked in the weekly/daily view is visible when switching to Monthly.
  void _syncMonthToSelected() {
    final e = EthiopianDate.fromGregorian(_selected);
    _ecMonth = e.month;
    _ecYear = e.year;
  }

  void _select(DateTime d) {
    setState(() {
      _selected = _dateOnly(d);
      _syncMonthToSelected();
    });
  }

  /// Header calendar button: jump to the Monthly grid (the app's Ethiopian
  /// date picker) for the selected day; from Monthly it returns to Daily on
  /// the day picked there.
  void _openMonthPicker() {
    setState(() {
      if (_view == _View.monthly) {
        _view = _View.daily;
      } else {
        _view = _View.monthly;
        _syncMonthToSelected();
      }
    });
  }

  void _goToday() {
    final t = EthiopianDate.today();
    setState(() {
      _selected = _dateOnly(DateTime.now());
      _ecMonth = t.month;
      _ecYear = t.year;
    });
  }

  static const _palette = <Color>[
    AppTheme.primary,
    Color(0xFF0F766E), // teal
    Color(0xFF1D4ED8), // blue
    Color(0xFFB45309), // amber-brown
    Color(0xFF6D28D9), // violet
    Color(0xFF15803D), // green
    Color(0xFFBE123C), // rose
    Color(0xFF0369A1), // sky
  ];

  Color _subjectColor(_Slot s) {
    if (s.isMakeup) return AppTheme.info;
    var h = 0;
    for (final c in s.subject.codeUnits) {
      h = (h * 31 + c) & 0x7fffffff;
    }
    return _palette[h % _palette.length];
  }

  IconData _subjectIcon(_Slot s) {
    if (s.isMakeup) return Icons.event_repeat_rounded;
    final n = '${s.subject} ${s.subjectEn ?? ''}'.toLowerCase();
    if (n.contains('bible') || n.contains('መጽሐፍ')) return Icons.menu_book_rounded;
    if (n.contains('liturg') || n.contains('ቅዳሴ')) return Icons.church_rounded;
    if (n.contains('hymn') || n.contains('mezmur') || n.contains('መዝሙር') || n.contains('music')) {
      return Icons.music_note_rounded;
    }
    if (n.contains('histor') || n.contains('ታሪክ')) return Icons.account_balance_rounded;
    if (n.contains('doctrin') || n.contains('faith') || n.contains('ሃይማኖት') || n.contains('ዶግማ')) {
      return Icons.auto_stories_rounded;
    }
    if (n.contains('ge\'ez') || n.contains('geez') || n.contains('ግዕዝ') || n.contains('language') || n.contains('ቋንቋ')) {
      return Icons.translate_rounded;
    }
    if (n.contains('ethic') || n.contains('ሥነ') || n.contains('moral')) return Icons.favorite_rounded;
    if (n.contains('math') || n.contains('ሒሳብ')) return Icons.calculate_rounded;
    if (n.contains('science') || n.contains('ሳይንስ')) return Icons.science_rounded;
    if (n.contains('art') || n.contains('ሥዕል')) return Icons.palette_rounded;
    if (n.contains('computer') || n.contains('ኮምፒ')) return Icons.computer_rounded;
    if (n.contains('sport') || n.contains('ስፖርት')) return Icons.directions_run_rounded;
    return Icons.school_rounded;
  }

  // ── build ─────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    // One continuous maroon block: title row + greeting/date share a single
    // gradient (no AppBar seam), rounded at the bottom so the view switcher
    // card sits on the page like the teacher home. Works both pushed from
    // the Home card (back arrow) and as the "Timetable" bottom-nav tab.
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: AppTheme.bgLight,
        body: Column(
          children: [
            _header(context),
            _viewSwitcher(),
            if (_offline || _fromCache) _offlineBanner(),
            Expanded(child: _body()),
          ],
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    final e = EthiopianDate.today();
    final canPop = Navigator.of(context).canPop();
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [AppTheme.primaryDark, AppTheme.primary, AppTheme.primaryLight],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(22)),
      ),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: EdgeInsets.fromLTRB(canPop ? 4 : 16, 6, 12, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  if (canPop)
                    IconButton(
                      onPressed: () => Navigator.of(context).maybePop(),
                      icon: const Icon(Icons.arrow_back, color: Colors.white),
                      tooltip: 'Back',
                    ),
                  const Expanded(
                    child: Text('My Timetable',
                        style: TextStyle(
                            fontSize: 19,
                            fontWeight: FontWeight.w700,
                            color: Colors.white)),
                  ),
                  TextButton.icon(
                    onPressed: _goToday,
                    style: TextButton.styleFrom(
                      foregroundColor: Colors.white,
                      side: const BorderSide(color: Colors.white54),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      visualDensity: VisualDensity.compact,
                    ),
                    icon: const Icon(Icons.today_rounded, size: 16),
                    label: const Text('Today',
                        style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
                  ),
                ],
              ),
              const SizedBox(height: 6),
              Padding(
                padding: EdgeInsets.only(left: canPop ? 12 : 0, right: 4),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('የእኔ የጊዜ ሰሌዳ',
                              style: TextStyle(
                                  fontSize: 12.5,
                                  color: Colors.white70,
                                  fontFamily: 'NotoSansEthiopic')),
                          const SizedBox(height: 2),
                          const Text('Stay organized, teach better.',
                              style: TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w700,
                                  color: Colors.white)),
                          const SizedBox(height: 6),
                          Text(
                            '${_dayLong[e.toGregorian().weekday - 1]} · ${monthNameAmharic(e.month)} ${e.day}, ${e.year}'
                            '${_yearName.isNotEmpty ? '  ·  $_yearName' : ''}',
                            style: const TextStyle(fontSize: 12, color: Colors.white70),
                          ),
                        ],
                      ),
                    ),
                    // Calendar button = Ethiopian date picker: opens the
                    // Monthly grid on the selected day's month (EC-only, no
                    // Gregorian Material date picker).
                    Material(
                      color: AppTheme.accent.withOpacity(0.18),
                      borderRadius: BorderRadius.circular(12),
                      child: InkWell(
                        key: const Key('timetable_calendar_button'),
                        onTap: _openMonthPicker,
                        borderRadius: BorderRadius.circular(12),
                        child: Container(
                          width: 44,
                          height: 44,
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: AppTheme.accent.withOpacity(0.6)),
                          ),
                          child: Tooltip(
                            message: 'Pick a date',
                            child: Icon(
                              _view == _View.monthly
                                  ? Icons.view_day_rounded
                                  : Icons.calendar_month_rounded,
                              color: AppTheme.accent,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _viewSwitcher() {
    Widget seg(_View v, String label, IconData icon) {
      final on = _view == v;
      return Expanded(
        child: GestureDetector(
          onTap: () => setState(() {
            _view = v;
            _syncMonthToSelected();
          }),
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 160),
            padding: const EdgeInsets.symmetric(vertical: 9),
            decoration: BoxDecoration(
              color: on ? AppTheme.primary : Colors.transparent,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon, size: 15, color: on ? Colors.white : AppTheme.textSecondary),
                const SizedBox(width: 6),
                Text(label,
                    style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        color: on ? Colors.white : AppTheme.textSecondary)),
              ],
            ),
          ),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(
          color: AppTheme.cardLight,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppTheme.borderLight),
        ),
        child: Row(children: [
          seg(_View.daily, 'Daily', Icons.view_day_rounded),
          seg(_View.weekly, 'Weekly', Icons.view_week_rounded),
          seg(_View.monthly, 'Monthly', Icons.calendar_view_month_rounded),
        ]),
      ),
    );
  }

  Widget _offlineBanner() {
    final text = _offline
        ? 'Offline — showing the timetable last saved on this phone'
        : 'Showing the copy saved on this phone — pull down to refresh';
    // Space-saver: timetable is read-only cached — tiny muted line, not loud warning. String kept for contract.
    if (!_offline && !_fromCache) return const SizedBox.shrink();
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 6, 16, 0),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: AppTheme.surfaceLight,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppTheme.borderLight),
      ),
      child: Row(children: [
        Icon(_offline ? Icons.cloud_off_rounded : Icons.cached_rounded, size: 13, color: AppTheme.textSecondary),
        const SizedBox(width: 6),
        Expanded(
            child: Text(text,
                style: const TextStyle(
                    fontSize: 10.5,
                    color: AppTheme.textSecondary,
                    fontWeight: FontWeight.w500))),
      ]),
    );
  }

  Widget _body() {
    if (_loading && _week.isEmpty && _makeups.isEmpty) return _skeleton();
    if (_error != null && _week.isEmpty && _makeups.isEmpty) {
      final offline = _error == 'offline';
      return _stateMessage(
        icon: offline ? Icons.cloud_off_rounded : Icons.error_outline_rounded,
        title: offline ? "Can't load your timetable" : 'Something went wrong',
        subtitle: offline
            ? 'Connect to the internet once; after that the timetable opens offline too.'
            : _error!,
        action: OutlinedButton.icon(
          onPressed: () {
            setState(() {
              _loading = true;
              _error = null;
            });
            _load();
          },
          icon: const Icon(Icons.refresh_rounded, size: 16),
          label: const Text('Try again'),
        ),
      );
    }
    if (_week.isEmpty && _makeups.isEmpty) {
      return _stateMessage(
        icon: Icons.calendar_today_outlined,
        title: 'No timetable yet',
        subtitle:
            'The Education department has not placed you on the timetable for this year. '
            'It is set up on the web (Education → Timetable).',
        action: OutlinedButton.icon(
          onPressed: _load,
          icon: const Icon(Icons.refresh_rounded, size: 16),
          label: const Text('Refresh'),
        ),
      );
    }
    if (_view == _View.daily) return _dailyView();
    if (_view == _View.monthly) return _monthlyView();
    return _weeklyView();
  }

  // ── weekly ────────────────────────────────────────────────────

  Widget _weeklyView() {
    final start = _weekStart;
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
          child: Row(
            children: [
              _navArrow(Icons.chevron_left_rounded,
                  () => _select(_selected.subtract(const Duration(days: 7)))),
              Expanded(
                child: Column(
                  children: [
                    Text(_weekRange(),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                            fontSize: 13.5,
                            fontWeight: FontWeight.w800,
                            color: AppTheme.textPrimary,
                            fontFamily: 'NotoSansEthiopic')),
                    Text('Week ${_ecWeekNumber()}',
                        style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                  ],
                ),
              ),
              _navArrow(Icons.chevron_right_rounded,
                  () => _select(_selected.add(const Duration(days: 7)))),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Row(
            children: [
              for (int i = 0; i < 7; i++)
                Expanded(child: _weekDayCell(start.add(Duration(days: i)))),
            ],
          ),
        ),
        const SizedBox(height: 6),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('${_dayLong[_selected.weekday - 1]} · ${_ecLabel(_selected)}',
                  style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: AppTheme.textPrimary,
                      fontFamily: 'NotoSansEthiopic')),
              Text('${_slotsFor(_selected).length} lessons',
                  style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
            ],
          ),
        ),
        Expanded(child: _periodList(_selected, _slotsFor(_selected))),
      ],
    );
  }

  Widget _weekDayCell(DateTime date) {
    final on = _sameDay(date, _selected);
    final isToday = _isToday(date);
    final has = _hasClasses(date);
    final ec = EthiopianDate.fromGregorian(date);
    return GestureDetector(
      onTap: () => _select(date),
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 3),
        padding: const EdgeInsets.symmetric(vertical: 8),
        decoration: BoxDecoration(
          color: on ? AppTheme.primary : AppTheme.cardLight,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: on
                ? AppTheme.primary
                : (isToday ? AppTheme.accent : AppTheme.borderLight),
            width: isToday && !on ? 1.6 : 1,
          ),
        ),
        child: Column(
          children: [
            Text(_dayShort[date.weekday - 1],
                style: TextStyle(
                    fontSize: 10.5,
                    fontWeight: FontWeight.w700,
                    color: on ? Colors.white70 : AppTheme.textSecondary)),
            const SizedBox(height: 3),
            Text('${ec.day}',
                style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: on
                        ? Colors.white
                        : (isToday ? AppTheme.primary : AppTheme.textPrimary))),
            const SizedBox(height: 4),
            Container(
              width: 5,
              height: 5,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: has
                    ? (on ? AppTheme.accent : AppTheme.primary)
                    : Colors.transparent,
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── daily ─────────────────────────────────────────────────────

  Widget _dailyView() {
    final slots = _slotsFor(_selected);
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
          child: Row(
            children: [
              _navArrow(Icons.chevron_left_rounded,
                  () => _select(_selected.subtract(const Duration(days: 1)))),
              Expanded(
                child: Column(
                  children: [
                    Text(_dayLong[_selected.weekday - 1],
                        style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w800,
                            color: AppTheme.textPrimary)),
                    Text(_ecLabel(_selected),
                        style: const TextStyle(
                            fontSize: 12,
                            color: AppTheme.textSecondary,
                            fontFamily: 'NotoSansEthiopic')),
                  ],
                ),
              ),
              _navArrow(Icons.chevron_right_rounded,
                  () => _select(_selected.add(const Duration(days: 1)))),
            ],
          ),
        ),
        if (!_isToday(_selected))
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: OutlinedButton.icon(
              onPressed: _goToday,
              style: OutlinedButton.styleFrom(
                foregroundColor: AppTheme.primary,
                side: const BorderSide(color: AppTheme.primary),
                visualDensity: VisualDensity.compact,
              ),
              icon: const Icon(Icons.undo_rounded, size: 15),
              label: const Text('Back to Today', style: TextStyle(fontSize: 12.5)),
            ),
          ),
        Expanded(child: _periodList(_selected, slots)),
      ],
    );
  }

  // ── monthly (Ethiopian month grid) ────────────────────────────

  void _shiftMonth(int delta) {
    var m = _ecMonth + delta;
    var y = _ecYear;
    if (m < 1) {
      m = 13;
      y -= 1;
    } else if (m > 13) {
      m = 1;
      y += 1;
    }
    setState(() {
      _ecMonth = m;
      _ecYear = y;
      final sel = EthiopianDate.fromGregorian(_selected);
      if (sel.month != m || sel.year != y) {
        _selected = _dateOnly(ethiopianToGregorian(y, m, 1));
      }
    });
  }

  Widget _monthlyView() {
    final first = _dateOnly(ethiopianToGregorian(_ecYear, _ecMonth, 1));
    final days = daysInEthiopianMonth(_ecMonth, _ecYear);
    final lead = first.weekday - 1; // Monday-first grid
    final rows = ((lead + days) / 7).ceil();
    final slots = _slotsFor(_selected);
    final selEc = EthiopianDate.fromGregorian(_selected);
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 20),
      children: [
        Row(
          children: [
            _navArrow(Icons.chevron_left_rounded, () => _shiftMonth(-1)),
            Expanded(
              child: Column(
                children: [
                  Text('${monthNameAmharic(_ecMonth)} $_ecYear',
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                          color: AppTheme.textPrimary,
                          fontFamily: 'NotoSansEthiopic')),
                  Text(monthNameEnglish(_ecMonth),
                      style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                ],
              ),
            ),
            _navArrow(Icons.chevron_right_rounded, () => _shiftMonth(1)),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            for (final d in _dayShort)
              Expanded(
                child: Center(
                  child: Text(d,
                      style: const TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: AppTheme.textSecondary)),
                ),
              ),
          ],
        ),
        const SizedBox(height: 4),
        for (int w = 0; w < rows; w++)
          Row(
            children: [
              for (int d = 0; d < 7; d++)
                Expanded(
                  child: () {
                    final idx = w * 7 + d - lead; // 0-based day in EC month
                    if (idx < 0 || idx >= days) return const SizedBox(height: 44);
                    return _monthCell(first.add(Duration(days: idx)), idx + 1);
                  }(),
                ),
            ],
          ),
        const SizedBox(height: 16),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text('${_dayLong[_selected.weekday - 1]}, ${monthNameAmharic(selEc.month)} ${selEc.day}',
                style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                    color: AppTheme.textPrimary,
                    fontFamily: 'NotoSansEthiopic')),
            Text('${slots.length} lessons',
                style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          ],
        ),
        const SizedBox(height: 8),
        if (slots.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 20),
            child: Text('No lessons this day.',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppTheme.textSecondary, fontSize: 13)),
          )
        else
          for (final s in slots) _periodCard(s, _selected),
      ],
    );
  }

  Widget _monthCell(DateTime date, int ecDay) {
    final on = _sameDay(date, _selected);
    final isToday = _isToday(date);
    final has = _hasClasses(date);
    return GestureDetector(
      onTap: () => _select(date),
      child: Container(
        height: 44,
        margin: const EdgeInsets.all(2),
        decoration: BoxDecoration(
          color: on ? AppTheme.primary : Colors.transparent,
          shape: BoxShape.circle,
          border: isToday && !on ? Border.all(color: AppTheme.accent, width: 1.5) : null,
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text('$ecDay',
                style: TextStyle(
                    fontSize: 13,
                    fontWeight: isToday ? FontWeight.w800 : FontWeight.w600,
                    color: on
                        ? Colors.white
                        : (isToday ? AppTheme.primary : AppTheme.textPrimary))),
            const SizedBox(height: 2),
            Container(
              width: 4,
              height: 4,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: has ? (on ? AppTheme.accent : AppTheme.primary) : Colors.transparent,
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── shared period rendering ───────────────────────────────────

  Widget _periodList(DateTime date, List<_Slot> slots) {
    if (slots.isEmpty) {
      return RefreshIndicator(
        color: AppTheme.primary,
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            SizedBox(
              height: 260,
              child: _stateMessage(
                icon: Icons.beach_access_outlined,
                title: 'Nothing on ${_dayLong[date.weekday - 1]}',
                subtitle: 'No lessons scheduled for you this day.',
              ),
            ),
          ],
        ),
      );
    }
    return RefreshIndicator(
      color: AppTheme.primary,
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
        children: [for (final s in slots) _periodCard(s, date)],
      ),
    );
  }

  /// One timetable row: stacked time on the left, then a tinted card with a
  /// circular subject icon, subject name, class and room (eduid card design).
  Widget _periodCard(_Slot s, DateTime date) {
    final color = _subjectColor(s);
    final now = _isNow(s, date);
    final meta = <String>[
      if (s.className.isNotEmpty) s.className,
      if (s.subjectEn != null) s.subjectEn!,
      if (s.isMakeup && s.reason != null) s.reason!,
    ].join(' · ');

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          SizedBox(
            width: 48,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(s.startTime,
                    style: const TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        color: AppTheme.textPrimary)),
                Text(s.endTime,
                    style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: color.withOpacity(0.10),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                    color: now ? color : color.withOpacity(0.18),
                    width: now ? 1.6 : 1),
              ),
              child: Row(
                children: [
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(color: color, shape: BoxShape.circle),
                    child: Icon(_subjectIcon(s), color: Colors.white, size: 20),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Flexible(
                              child: Text(s.subject,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                      fontSize: 14.5,
                                      fontWeight: FontWeight.w800,
                                      color: AppTheme.textPrimary,
                                      fontFamily: 'NotoSansEthiopic')),
                            ),
                            if (now) ...[
                              const SizedBox(width: 6),
                              _pill('Now', AppTheme.primary),
                            ],
                            if (s.isMakeup) ...[
                              const SizedBox(width: 6),
                              _pill('Makeup', AppTheme.info),
                            ],
                          ],
                        ),
                        const SizedBox(height: 2),
                        Text(meta.isEmpty ? s.periodLabel : meta,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                                fontSize: 12, color: AppTheme.textSecondary)),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 88),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        if (s.room != null)
                          Text(s.room!,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w700,
                                  color: color)),
                        if (!s.isMakeup && s.periodLabel.isNotEmpty)
                          Text(s.periodLabel,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                  fontSize: 10.5, color: AppTheme.textSecondary)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _pill(String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(text,
          style: const TextStyle(
              fontSize: 10, fontWeight: FontWeight.w800, color: Colors.white)),
    );
  }

  Widget _navArrow(IconData icon, VoidCallback onTap) {
    return Material(
      color: AppTheme.cardLight,
      shape: const CircleBorder(side: BorderSide(color: AppTheme.borderLight)),
      child: InkWell(
        onTap: onTap,
        customBorder: const CircleBorder(),
        child: SizedBox(
          width: 38,
          height: 38,
          child: Icon(icon, size: 22, color: AppTheme.textPrimary),
        ),
      ),
    );
  }

  Widget _stateMessage({
    required IconData icon,
    required String title,
    required String subtitle,
    Widget? action,
  }) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: AppTheme.textSecondary),
            const SizedBox(height: 12),
            Text(title,
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: AppTheme.textPrimary)),
            const SizedBox(height: 6),
            Text(subtitle,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 13, color: AppTheme.textSecondary)),
            if (action != null) ...[
              const SizedBox(height: 14),
              action,
            ],
          ],
        ),
      ),
    );
  }

  Widget _skeleton() {
    Widget box(double h, {double? w}) => Container(
          height: h,
          width: w,
          margin: const EdgeInsets.only(bottom: 10),
          decoration: BoxDecoration(
            color: AppTheme.borderLight.withOpacity(0.6),
            borderRadius: BorderRadius.circular(12),
          ),
        );
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          box(38),
          box(64),
          box(72),
          box(72),
          box(72),
        ],
      ),
    );
  }
}
