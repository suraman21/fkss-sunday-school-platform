import 'api_service.dart';
import 'local_db.dart';
import 'legacy_outbox_models.dart';
import 'session_models.dart';

/// Pulls attendance changes from the server feed into the local cache.
///
/// This is the download half of synchronization. The upload half already
/// exists in SyncService (the outbox drain) and is not touched here.
///
/// Flow:
///   server feed → this coordinator → local SQLite → existing screens
///
/// Nothing in the UI calls this directly, and no screen gains a network
/// path: screens keep reading the local database, and this keeps the
/// local database honest.
///
/// Two invariants carry the correctness of the whole thing:
///
///  1. The cursor advances only inside the same SQLite transaction that
///     applies the page (see LocalDb.applyAttendanceDelta). A crash or a
///     dropped connection therefore re-pulls a page rather than skipping
///     it, and re-applying a page is harmless.
///
///  2. Unsynced local edits are never touched. They live in
///     pending_attendance and the attendance screen overlays them on top
///     of the cached sheet at read time, so a pull that rewrites the
///     cached (server) layer cannot discard a change the user has made
///     but not yet uploaded.
class AttendanceSyncCoordinator {
  static final AttendanceSyncCoordinator _instance =
      AttendanceSyncCoordinator._internal();
  factory AttendanceSyncCoordinator() => _instance;
  AttendanceSyncCoordinator._internal()
      : _api = ApiService(),
        _db = LocalDb();

  /// Synthetic collaborators exercise the same pull loop without network/data.
  AttendanceSyncCoordinator.withCollaborators({
    required ApiService api,
    required LocalDb db,
  })  : _api = api,
        _db = db;

  final ApiService _api;
  final LocalDb _db;

  /// Bounded so one pull cannot walk an unbounded feed on a bad link.
  /// A device further behind than this simply finishes on the next
  /// trigger; it never blocks startup.
  static const int maxPagesPerRun = 10;
  static const int pageLimit = 200;

  bool _running = false;

  /// True while a pull is in flight, so overlapping triggers (startup
  /// plus a foreground return, say) collapse into one.
  bool get isRunning => _running;

  /// Pull and apply everything currently available, up to [maxPagesPerRun].
  ///
  /// Never throws: a sync failure must not stop the app from opening or
  /// from working offline. Failures are recorded in sync_state so the UI
  /// can distinguish "up to date" from "could not reach the server".
  Future<AttendanceSyncOutcome> pull() async {
    if (_running) return AttendanceSyncOutcome.skipped();
    if (!_api.isLoggedIn) return AttendanceSyncOutcome.skipped();
    final session = LegacyWriteSession(
      ownerUserId: _api.userId,
      authorizationVersion: _api.authorizationVersion,
      runtimeGeneration: _api.sessionGenerationProvider?.call() ?? 0,
    );
    _running = true;
    try {
      var applied = 0;
      var pages = 0;
      var bootstrapped = false;

      while (pages < maxPagesPerRun) {
        pages++;
        await _requireCurrentSession(session);
        final cursor = await _db.getSyncCursor('attendance');

        await _requireCurrentSession(session);
        final response = await _api.getAttendanceChanges(
          cursor: cursor,
          limit: pageLimit,
        );

        await _requireCurrentSession(session);
        if (response.sessionSuperseded) {
          throw const StaleAttendanceSyncSession();
        }
        if (!response.success) {
          // Offline or server trouble. The cursor stays exactly where it
          // was, the local data stays usable, and the next trigger
          // retries. No backoff of our own: the existing SyncService
          // triggers already pace this.
          await _setSyncStatus(
            session,
            response.isNetworkError ? 'offline' : 'failed',
            error: response.message,
          );
          return AttendanceSyncOutcome(
            applied: applied,
            pages: pages - 1,
            bootstrapped: bootstrapped,
            failed: true,
            message: response.message,
          );
        }

        final data = response.data;
        if (data is! Map) {
          await _setSyncStatus(session, 'failed',
              error: 'Unexpected sync response');
          return AttendanceSyncOutcome(
            applied: applied,
            pages: pages - 1,
            bootstrapped: bootstrapped,
            failed: true,
            message: 'Unexpected sync response',
          );
        }

        if (data['bootstrap_required'] == true) {
          // The server is telling us a complete delta does not exist for
          // this cursor — first install, a purged cache, or a cursor
          // older than the feed's retention. Accepting a partial answer
          // here is exactly the silent staleness this phase exists to
          // remove.
          final ok = await _bootstrap(data, session);
          if (!ok) {
            return AttendanceSyncOutcome(
              applied: applied,
              pages: pages - 1,
              bootstrapped: bootstrapped,
              failed: true,
              message: 'Bootstrap incomplete',
            );
          }
          bootstrapped = true;
          // Do not loop again on the same trigger: the bootstrap has
          // already left the cache consistent at a known revision.
          return AttendanceSyncOutcome(
            applied: applied,
            pages: pages,
            bootstrapped: true,
            failed: false,
          );
        }

        final items = _asList(data['items']);
        final records = _asRecordMap(data['records']);
        final nextCursor = _asInt(data['next_cursor'], fallback: cursor);

        if (items.isEmpty) {
          // Already current. Record the check without moving anything.
          await _setSyncStatus(session, 'ok');
          return AttendanceSyncOutcome(
            applied: applied,
            pages: pages,
            bootstrapped: bootstrapped,
            failed: false,
          );
        }

        // Never move the cursor backwards, whatever the server said.
        if (nextCursor <= cursor) {
          await _setSyncStatus(session, 'failed',
              error: 'Cursor did not advance');
          return AttendanceSyncOutcome(
            applied: applied,
            pages: pages - 1,
            bootstrapped: bootstrapped,
            failed: true,
            message: 'Cursor did not advance',
          );
        }

        try {
          // Data and cursor commit together, or neither does.
          await _db.applyAttendanceDelta(
            expectedSession: session,
            items: items,
            records: records,
            nextCursor: nextCursor,
          );
        } on StaleAttendanceSyncSession {
          rethrow;
        } catch (e) {
          // Rolled back by sqflite. The cursor is untouched, so the same
          // page is re-pulled next time rather than being lost.
          await _setSyncStatus(session, 'failed', error: '$e');
          return AttendanceSyncOutcome(
            applied: applied,
            pages: pages - 1,
            bootstrapped: bootstrapped,
            failed: true,
            message: 'Could not apply changes',
          );
        }

        applied += items.length;

        if (data['has_more'] != true) {
          return AttendanceSyncOutcome(
            applied: applied,
            pages: pages,
            bootstrapped: bootstrapped,
            failed: false,
          );
        }
        // has_more: continue from the cursor we just committed.
      }

      return AttendanceSyncOutcome(
        applied: applied,
        pages: pages,
        bootstrapped: bootstrapped,
        failed: false,
        message: 'More changes remain; will continue on the next sync.',
      );
    } on StaleAttendanceSyncSession {
      return const AttendanceSyncOutcome(
        applied: 0,
        pages: 0,
        bootstrapped: false,
        failed: true,
        message: 'Attendance sync stopped because the session changed.',
      );
    } catch (e) {
      // Even error reporting must not write into a newly active account.
      try {
        await _setSyncStatus(session, 'failed', error: '$e');
      } catch (_) {
        // Storage unavailable or session superseded; never throw at startup.
      }
      return AttendanceSyncOutcome(
        applied: 0,
        pages: 0,
        bootstrapped: false,
        failed: true,
        message: 'Sync could not run',
      );
    } finally {
      _running = false;
    }
  }

  /// Smallest honest bootstrap for this cache shape.
  ///
  /// `cached_attendance` holds whole class/day sheets that the user has
  /// actually opened; there is no global attendance dataset to download,
  /// and inventing one would mean guessing which days matter. So the
  /// consistent state is: drop the stale sheets and adopt the server's
  /// current revision. Screens refill the cache through the existing
  /// local-first load path the next time each day is opened, and from
  /// then on deltas keep it current.
  ///
  /// Order matters. The cache is cleared and the cursor reset together,
  /// and the new cursor is only stored after that succeeded, so a crash
  /// mid-bootstrap leaves cursor 0 and the server asks for another
  /// bootstrap rather than resuming over a half-cleared cache.
  Future<bool> _bootstrap(
      Map<dynamic, dynamic> data, LegacyWriteSession session) async {
    try {
      await _db.resetAttendanceSyncForBootstrap(expectedSession: session);
      final cursor = _asInt(data['bootstrap_cursor'], fallback: 0);
      if (cursor > 0) {
        await _db.completeAttendanceBootstrap(cursor, expectedSession: session);
      } else {
        // No revisions exist yet on the server; staying at 0 is correct
        // and the next pull will simply find nothing to do.
        await _setSyncStatus(session, 'ok');
      }
      return true;
    } on StaleAttendanceSyncSession {
      rethrow;
    } catch (e) {
      await _setSyncStatus(session, 'failed', error: '$e');
      return false;
    }
  }

  bool _runtimeMatches(LegacyWriteSession session) =>
      _api.isLoggedIn &&
      session.isValid &&
      _api.userId == session.ownerUserId &&
      _api.authorizationVersion == session.authorizationVersion &&
      (_api.sessionGenerationProvider?.call() ?? 0) ==
          session.runtimeGeneration;

  Future<void> _requireCurrentSession(LegacyWriteSession session) async {
    if (!_runtimeMatches(session) ||
        !await _db.activeSessionMatches(
          runtimeGeneration: session.runtimeGeneration,
          ownerUserId: session.ownerUserId,
          authorizationVersion: session.authorizationVersion,
        ) ||
        !_runtimeMatches(session)) {
      throw const StaleAttendanceSyncSession();
    }
  }

  Future<void> _setSyncStatus(LegacyWriteSession session, String status,
      {String? error}) async {
    await _requireCurrentSession(session);
    await _db.setSyncStatus('attendance', status,
        error: error, expectedSession: session);
  }

  List<Map<String, dynamic>> _asList(dynamic value) {
    if (value is! List) return const [];
    return value
        .whereType<Map>()
        .map((e) => Map<String, dynamic>.from(e))
        .toList(growable: false);
  }

  /// `records` is a JSON object keyed by entity id. PHP emits `[]` for an
  /// empty object, so a list is a legitimate "nothing here" encoding.
  Map<String, dynamic> _asRecordMap(dynamic value) {
    if (value is Map) {
      return value.map((k, v) => MapEntry('$k', v));
    }
    return <String, dynamic>{};
  }

  int _asInt(dynamic value, {int fallback = 0}) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value') ?? fallback;
  }
}

class AttendanceSyncOutcome {
  final int applied;
  final int pages;
  final bool bootstrapped;
  final bool failed;
  final String? message;

  const AttendanceSyncOutcome({
    required this.applied,
    required this.pages,
    required this.bootstrapped,
    required this.failed,
    this.message,
  });

  factory AttendanceSyncOutcome.skipped() => const AttendanceSyncOutcome(
        applied: 0,
        pages: 0,
        bootstrapped: false,
        failed: false,
        message: 'Skipped',
      );

  bool get changedLocalData => applied > 0 || bootstrapped;
}
