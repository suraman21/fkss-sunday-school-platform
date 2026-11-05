import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../../models/user_profile.dart';
import '../update/app_updates_tile.dart';
import '../../services/app_lock_service.dart';
import '../../services/crash_log_service.dart';
import '../../services/device_tier_service.dart';
import '../../services/profile_service.dart';
import '../../services/sync_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/session_logout_dialog.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen>
    with WidgetsBindingObserver {
  final _profiles = MobileProfileService.instance;
  final _imagePicker = ImagePicker();
  final _sync = SyncService();
  final _appLock = AppLockService();
  SyncStatus? _syncStatus;
  bool _lockConfigured = false;
  int _autoLockSecs = 300;
  bool _biometricOn = false;
  StreamSubscription<SyncStatus>? _syncSub;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _profiles.addListener(_profileChanged);
    unawaited(_profiles.open());
    _loadPendingCount();
    _appLock.addListener(_refreshLockState);
    _refreshLockState();
    _syncSub = _sync.syncStream.listen((s) {
      if (!mounted) return;
      setState(() => _syncStatus = s);
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _profiles.removeListener(_profileChanged);
    _appLock.removeListener(_refreshLockState);
    _syncSub?.cancel();
    super.dispose();
  }

  void _profileChanged() {
    if (mounted) setState(() {});
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _profiles.isOnline) {
      unawaited(_profiles.refresh());
    }
  }

  Future<void> _refreshLockState() async {
    final configured = await _appLock.isConfigured();
    final secs = await _appLock.autoLockSeconds();
    final bio = await _appLock.biometricEnabled();
    if (!mounted) return;
    setState(() {
      _lockConfigured = configured;
      _autoLockSecs = secs;
      _biometricOn = bio;
    });
  }

  Future<void> _loadPendingCount() async {
    await _sync.emitCurrentStatus();
    final s = _sync.lastStatus;
    if (mounted) setState(() => _syncStatus = s);
  }

  Future<void> _logout() => showSessionLogoutDialog(context);

  Future<void> _showChangePassword() async {
    if (!_profiles.isOnline) {
      _toast('Password changes require an internet connection.');
      return;
    }
    final currentController = TextEditingController();
    final newController = TextEditingController();
    final confirmController = TextEditingController();
    var loading = false;
    var obscureCurrent = true;
    var obscureNew = true;
    var obscureConfirm = true;
    String? currentError;
    String? newError;
    String? confirmationError;
    String? error;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppTheme.cardLight,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetContext) => StatefulBuilder(
        builder: (sheetContext, setSheetState) => AnimatedBuilder(
          animation: _profiles,
          builder: (sheetContext, _) => Padding(
            padding: EdgeInsets.only(
              left: 20,
              right: 20,
              top: 20,
              bottom: MediaQuery.of(sheetContext).viewInsets.bottom + 20,
            ),
            child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Change Password',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 6),
                const Text(
                  'You will sign in again after a successful password change.',
                  style: TextStyle(fontSize: 12, color: AppTheme.textSecondary),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: currentController,
                  enabled: !loading && _profiles.isOnline,
                  obscureText: obscureCurrent,
                  autofillHints: const [AutofillHints.password],
                  decoration: InputDecoration(
                    labelText: 'Current password',
                    errorText: currentError,
                    prefixIcon: const Icon(Icons.lock_outline, size: 18),
                    suffixIcon: IconButton(
                      tooltip: obscureCurrent ? 'Show password' : 'Hide password',
                      icon: Icon(
                        obscureCurrent ? Icons.visibility_off : Icons.visibility,
                        size: 18,
                      ),
                      onPressed: loading
                          ? null
                          : () => setSheetState(
                                () => obscureCurrent = !obscureCurrent,
                              ),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: newController,
                  enabled: !loading && _profiles.isOnline,
                  obscureText: obscureNew,
                  autofillHints: const [AutofillHints.newPassword],
                  decoration: InputDecoration(
                    labelText: 'New password',
                    helperText: '12+ characters, at most 72 UTF-8 bytes',
                    errorText: newError,
                    prefixIcon: const Icon(Icons.lock_rounded, size: 18),
                    suffixIcon: IconButton(
                      tooltip: obscureNew ? 'Show password' : 'Hide password',
                      icon: Icon(
                        obscureNew ? Icons.visibility_off : Icons.visibility,
                        size: 18,
                      ),
                      onPressed: loading
                          ? null
                          : () => setSheetState(() => obscureNew = !obscureNew),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: confirmController,
                  enabled: !loading && _profiles.isOnline,
                  obscureText: obscureConfirm,
                  autofillHints: const [AutofillHints.newPassword],
                  decoration: InputDecoration(
                    labelText: 'Confirm new password',
                    errorText: confirmationError,
                    prefixIcon: const Icon(Icons.lock_rounded, size: 18),
                    suffixIcon: IconButton(
                      tooltip: obscureConfirm ? 'Show password' : 'Hide password',
                      icon: Icon(
                        obscureConfirm ? Icons.visibility_off : Icons.visibility,
                        size: 18,
                      ),
                      onPressed: loading
                          ? null
                          : () => setSheetState(
                                () => obscureConfirm = !obscureConfirm,
                              ),
                    ),
                  ),
                ),
                if (!_profiles.isOnline) ...[
                  const SizedBox(height: 12),
                  const Text(
                    'Connect to the internet to change your password.',
                    style: TextStyle(color: AppTheme.warning, fontSize: 12),
                  ),
                ],
                if (error != null) ...[
                  const SizedBox(height: 12),
                  Semantics(
                    liveRegion: true,
                    child: Text(
                      error!,
                      style: const TextStyle(color: AppTheme.danger, fontSize: 12),
                    ),
                  ),
                ],
                const SizedBox(height: 20),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: loading || !_profiles.isOnline
                        ? null
                        : () async {
                            final currentPassword = currentController.text;
                            final newPwd = newController.text;
                            final confirmation = confirmController.text;
                            if (currentPassword.isEmpty) {
                              setSheetState(() {
                                currentError = 'Current password is required.';
                                newError = null;
                                confirmationError = null;
                                error = null;
                              });
                              return;
                            }
                            if (newPwd.runes.length < 12 ||
                                utf8.encode(newPwd).length > 72 ||
                                newPwd == currentPassword) {
                              setSheetState(() {
                                currentError = null;
                                newError = newPwd == currentPassword
                                    ? 'New password must differ from the current password.'
                                    : 'Use at least 12 characters and at most 72 UTF-8 bytes.';
                                confirmationError = null;
                                error = null;
                              });
                              return;
                            }
                            if (confirmation.isEmpty || newPwd != confirmation) {
                              setSheetState(() {
                                currentError = null;
                                newError = null;
                                confirmationError = confirmation.isEmpty
                                    ? 'Confirm the new password.'
                                    : 'New passwords do not match.';
                                error = null;
                              });
                              return;
                            }
                            setSheetState(() {
                              loading = true;
                              currentError = null;
                              newError = null;
                              confirmationError = null;
                              error = null;
                            });
                            // Keep secrets only in stack values for the request;
                            // clear editable fields before session transition can
                            // unmount this sheet.
                            currentController.clear();
                            newController.clear();
                            confirmController.clear();
                            final result = await _profiles.changePassword(
                              currentPassword: currentPassword,
                              newPassword: newPwd,
                              confirmation: confirmation,
                            );
                            if (!sheetContext.mounted) return;
                            if (result.success) {
                              Navigator.of(sheetContext).pop();
                              return;
                            }
                            setSheetState(() {
                              loading = false;
                              currentError =
                                  result.code == 'CURRENT_PASSWORD_INCORRECT'
                                      ? result.message
                                      : null;
                              newError = const {
                                'PASSWORD_POLICY_FAILED',
                                'NEW_PASSWORD_MUST_DIFFER',
                              }.contains(result.code)
                                  ? result.message
                                  : null;
                              confirmationError =
                                  result.code == 'PASSWORD_CONFIRMATION_MISMATCH'
                                      ? result.message
                                      : null;
                              error = currentError == null &&
                                      newError == null &&
                                      confirmationError == null
                                  ? result.message
                                  : null;
                            });
                          },
                    child: loading
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Change Password'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      ),
    );
    currentController.dispose();
    newController.dispose();
    confirmController.dispose();
  }

  Future<void> _editFullName(UserProfile profile) => _showProfileEdit(
        title: 'Edit full name',
        label: 'Full name',
        initialValue: profile.fullName,
        keyboardType: TextInputType.name,
        validate: ProfileInputPolicy.validateFullName,
        submit: (value, _) => _profiles.updateFullName(value),
      );

  Future<void> _editEmail(UserProfile profile) => _showProfileEdit(
        title: 'Edit email',
        label: 'Email',
        initialValue: profile.email ?? '',
        keyboardType: TextInputType.emailAddress,
        passwordRequired: true,
        validate: ProfileInputPolicy.validateEmail,
        submit: _profiles.updateEmail,
      );

  Future<void> _editUsername(UserProfile profile) => _showProfileEdit(
        title: 'Edit username',
        label: 'Username',
        initialValue: profile.username,
        keyboardType: TextInputType.text,
        passwordRequired: true,
        validate: ProfileInputPolicy.validateUsername,
        normalize: ProfileInputPolicy.normalizeUsername,
        submit: _profiles.updateUsername,
      );

  Future<void> _showProfileEdit({
    required String title,
    required String label,
    required String initialValue,
    required TextInputType keyboardType,
    required String? Function(String) validate,
    required Future<ProfileActionResult> Function(String, String) submit,
    String Function(String)? normalize,
    bool passwordRequired = false,
  }) async {
    if (!_profiles.isOnline) {
      _toast('Profile changes require an internet connection.');
      return;
    }
    final valueController = TextEditingController(text: initialValue);
    final passwordController = TextEditingController();
    var loading = false;
    var obscurePassword = true;
    String? valueError;
    String? passwordError;
    String? error;
    var serverReloaded = false;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppTheme.cardLight,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetContext) => StatefulBuilder(
        builder: (sheetContext, setSheetState) => AnimatedBuilder(
          animation: _profiles,
          builder: (sheetContext, _) => Padding(
            padding: EdgeInsets.only(
              left: 20,
              right: 20,
              top: 20,
              bottom: MediaQuery.of(sheetContext).viewInsets.bottom + 20,
            ),
            child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: valueController,
                  keyboardType: keyboardType,
                  enabled: !loading && _profiles.isOnline,
                  autocorrect: label == 'Full name',
                  enableSuggestions: label == 'Full name',
                  decoration: InputDecoration(
                    labelText: label,
                    errorText: valueError,
                  ),
                ),
                if (passwordRequired) ...[
                  const SizedBox(height: 12),
                  TextField(
                    controller: passwordController,
                    obscureText: obscurePassword,
                    enabled: !loading && _profiles.isOnline,
                    decoration: InputDecoration(
                      labelText: 'Current password',
                      helperText: 'Required to confirm this account change',
                      errorText: passwordError,
                      suffixIcon: IconButton(
                        tooltip: obscurePassword ? 'Show password' : 'Hide password',
                        icon: Icon(
                          obscurePassword ? Icons.visibility_off : Icons.visibility,
                        ),
                        onPressed: loading || !_profiles.isOnline
                            ? null
                            : () => setSheetState(
                                  () => obscurePassword = !obscurePassword,
                                ),
                      ),
                    ),
                  ),
                ],
                if (!_profiles.isOnline) ...[
                  const SizedBox(height: 12),
                  const Text(
                    'Connect to the internet to save profile changes.',
                    style: TextStyle(color: AppTheme.warning, fontSize: 12),
                  ),
                ],
                if (serverReloaded) ...[
                  const SizedBox(height: 12),
                  const Text(
                    'Newer server values were loaded. Your draft is still here; review it and save again.',
                    style: TextStyle(color: AppTheme.warning, fontSize: 12),
                  ),
                ],
                if (error != null) ...[
                  const SizedBox(height: 12),
                  Semantics(
                    liveRegion: true,
                    child: Text(
                      error!,
                      style: const TextStyle(color: AppTheme.danger, fontSize: 12),
                    ),
                  ),
                ],
                const SizedBox(height: 20),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: loading
                            ? null
                            : () => Navigator.of(sheetContext).pop(),
                        child: const Text('Cancel'),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: ElevatedButton(
                        onPressed: loading || !_profiles.isOnline
                            ? null
                            : () async {
                                final raw = valueController.text;
                                final localError = validate(raw);
                                if (localError != null) {
                                  setSheetState(() {
                                    valueError = localError;
                                    passwordError = null;
                                    error = null;
                                  });
                                  return;
                                }
                                if (passwordRequired &&
                                    passwordController.text.isEmpty) {
                                  setSheetState(() {
                                    valueError = null;
                                    passwordError = 'Current password is required.';
                                    error = null;
                                  });
                                  return;
                                }
                                setSheetState(() {
                                  loading = true;
                                  valueError = null;
                                  passwordError = null;
                                  error = null;
                                  serverReloaded = false;
                                });
                                final value = normalize?.call(raw) ?? raw.trim();
                                final currentPassword = passwordController.text;
                                passwordController.clear();
                                final result =
                                    await submit(value, currentPassword);
                                if (!sheetContext.mounted) return;
                                if (result.success) {
                                  Navigator.of(sheetContext).pop();
                                  if (mounted) _toast(result.message ?? 'Profile updated.');
                                  return;
                                }
                                setSheetState(() {
                                  loading = false;
                                  valueError = const {
                                    'USERNAME_TAKEN',
                                    'EMAIL_TAKEN',
                                    'PROFILE_DUPLICATE',
                                    'VALIDATION_FAILED',
                                  }.contains(result.code)
                                      ? result.message
                                      : null;
                                  passwordError =
                                      result.code == 'CURRENT_PASSWORD_INCORRECT'
                                          ? result.message
                                          : null;
                                  error = valueError == null && passwordError == null
                                      ? result.message
                                      : null;
                                  serverReloaded = result.conflict;
                                });
                              },
                        child: loading
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Text('Save'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
      ),
    );
    valueController.dispose();
    passwordController.dispose();
  }

  Future<String?> _profileImageValidationError(Uint8List bytes) async {
    if (bytes.isEmpty || bytes.length > ProfileImageInputPolicy.maximumBytes) {
      return 'Choose an image no larger than 4 MB.';
    }
    ui.ImmutableBuffer? buffer;
    ui.ImageDescriptor? descriptor;
    try {
      final decodedBuffer = await ui.ImmutableBuffer.fromUint8List(bytes);
      buffer = decodedBuffer;
      descriptor = await ui.ImageDescriptor.encoded(decodedBuffer);
      return ProfileImageInputPolicy.validateDimensions(
        descriptor.width,
        descriptor.height,
      );
    } catch (_) {
      return 'The selected file could not be read as an image.';
    } finally {
      descriptor?.dispose();
      buffer?.dispose();
    }
  }

  Future<void> _pickProfileImage() async {
    if (!_profiles.isOnline) {
      _toast('Profile image changes require an internet connection.');
      return;
    }
    try {
      final picked = await _imagePicker.pickImage(
        source: ImageSource.gallery,
        maxWidth: 2048,
        maxHeight: 2048,
        imageQuality: 88,
      );
      if (picked == null) return;
      final bytes = await picked.readAsBytes();
      final imageError = await _profileImageValidationError(bytes);
      if (!mounted) return;
      if (imageError != null) {
        _toast(imageError);
        return;
      }
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (dialogContext) => AnimatedBuilder(
          animation: _profiles,
          builder: (dialogContext, _) => AlertDialog(
          title: const Text('Use this profile image?'),
          content: ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: Image.memory(
              bytes,
              width: 240,
              height: 240,
              fit: BoxFit.cover,
              errorBuilder: (_, __, ___) => const SizedBox(
                height: 120,
                child: Center(child: Text('This image cannot be previewed.')),
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: _profiles.isOnline
                  ? () => Navigator.of(dialogContext).pop(true)
                  : null,
              child: const Text('Upload'),
            ),
          ],
        ),
        ),
      );
      if (confirmed != true) return;
      final result = await _profiles.uploadImage(bytes);
      if (mounted) _toast(result.message ?? 'Image request finished.');
    } catch (_) {
      _toast('The image could not be selected.');
    }
  }

  Future<void> _confirmRemoveProfileImage() async {
    if (!_profiles.isOnline) {
      _toast('Profile image changes require an internet connection.');
      return;
    }
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AnimatedBuilder(
        animation: _profiles,
        builder: (dialogContext, _) => AlertDialog(
        title: const Text('Remove profile image?'),
        content: const Text('Your initials will be shown instead.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppTheme.danger),
            onPressed: _profiles.isOnline
                ? () => Navigator.of(dialogContext).pop(true)
                : null,
            child: const Text('Remove'),
          ),
        ],
      ),
      ),
    );
    if (confirmed != true) return;
    final result = await _profiles.removeImage();
    if (mounted) _toast(result.message ?? 'Image request finished.');
  }

  @override
  Widget build(BuildContext context) {
    final profile = _profiles.profile;
    final canMutate = profile != null &&
        _profiles.isOnline &&
        !_profiles.loading &&
        !_profiles.mutating;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Profile'),
        automaticallyImplyLeading: false,
        actions: [
          IconButton(
            tooltip: 'Refresh profile',
            onPressed: _profiles.loading || !_profiles.isOnline
                ? null
                : () => _profiles.refresh(),
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: Column(
        children: [
          if (!_profiles.isOnline)
            Container(
              width: double.infinity,
              color: AppTheme.warning.withOpacity(.12),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: const Row(
                children: [
                  Icon(Icons.cloud_off_rounded, size: 16, color: AppTheme.warning),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Offline — showing saved profile data. Changes are disabled.',
                      style: TextStyle(fontSize: 12, color: AppTheme.warning),
                    ),
                  ),
                ],
              ),
            ),
          if (_profiles.loading)
            const LinearProgressIndicator(minHeight: 2),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async {
                await _profiles.refresh();
              },
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                children: [
                  if (profile == null)
                    _buildProfileEmptyState()
                  else ...[
                    _buildProfileHeader(profile),
                    const SizedBox(height: 20),
                    _buildSection('Account information', [
                      _editableInfoTile(
                        Icons.badge_outlined,
                        'Full name',
                        profile.fullName,
                        canMutate ? () => _editFullName(profile) : null,
                      ),
                      _editableInfoTile(
                        Icons.email_outlined,
                        'Email',
                        profile.email ?? 'Not provided',
                        canMutate ? () => _editEmail(profile) : null,
                      ),
                      _editableInfoTile(
                        Icons.person_outline,
                        'Username',
                        profile.username,
                        canMutate ? () => _editUsername(profile) : null,
                      ),
                    ]),
                    const SizedBox(height: 12),
                    _buildSection('Account details', [
                      _infoTile(
                        Icons.security_outlined,
                        'Role',
                        UserRoles.displayName(profile.role),
                      ),
                      _infoTile(
                        Icons.verified_user_outlined,
                        'Account status',
                        profile.isActive ? 'Active' : 'Inactive',
                      ),
                      if (profile.createdAt != null)
                        _infoTile(
                          Icons.calendar_today_outlined,
                          'Account created',
                          profile.createdAt!,
                        ),
                      if (profile.lastLogin != null)
                        _infoTile(
                          Icons.login_rounded,
                          'Last login',
                          profile.lastLogin!,
                        ),
                    ]),
                    const SizedBox(height: 12),
                    _buildSection('Security', [
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(Icons.key_rounded, size: 20),
                        title: const Text('Change password'),
                        subtitle: Text(
                          _profiles.isOnline
                              ? 'Requires your current password'
                              : 'Available when online',
                          style: const TextStyle(fontSize: 12),
                        ),
                        trailing: _profiles.mutating
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Icon(Icons.chevron_right_rounded),
                        onTap: canMutate ? _showChangePassword : null,
                      ),
                    ]),
                  ],
                  if (_profiles.errorMessage != null) ...[
                    const SizedBox(height: 12),
                    _buildProfileError(_profiles.errorMessage!),
                  ],
                  const SizedBox(height: 12),
                  // Owner-safe recovery inventory (not the old aggregate sum).
                  _buildSyncSection(),
                  const SizedBox(height: 12),
                  ..._buildAppLockSection(),
                  const SizedBox(height: 12),
                  _buildSection('App', [
                    const AppUpdatesTile(),
                    _infoTile(
                      Icons.info_outline,
                      'Version',
                      '${AppConfig.appVersion} (${AppConfig.appBuild})',
                    ),
                    _infoTile(Icons.cloud_outlined, 'Server', AppConfig.apiBaseUrl),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      leading: const Icon(
                        Icons.bug_report_outlined,
                        size: 18,
                        color: AppTheme.textSecondary,
                      ),
                      title: const Text('Diagnostics'),
                      subtitle: const Text(
                        'Device info & crash report for the administrator',
                        style: TextStyle(fontSize: 12),
                      ),
                      trailing: const Icon(Icons.chevron_right, size: 18),
                      onTap: _showDiagnostics,
                    ),
                  ]),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      onPressed: _logout,
                      icon: const Icon(Icons.logout_rounded, size: 18),
                      label: const Text('Logout'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AppTheme.danger,
                        side: BorderSide(color: AppTheme.danger.withOpacity(0.4)),
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildProfileEmptyState() {
    final offline = !_profiles.isOnline;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            Icon(
              offline ? Icons.cloud_off_rounded : Icons.person_search_rounded,
              size: 42,
              color: AppTheme.textSecondary,
            ),
            const SizedBox(height: 12),
            Text(
              offline ? 'No saved profile is available' : 'Loading your profile',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            Text(
              offline
                  ? 'Connect to the internet once to securely save your profile for offline viewing.'
                  : 'Profile information will appear here when the server responds.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary),
            ),
            if (!offline) ...[
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: _profiles.loading ? null : () => _profiles.refresh(),
                icon: const Icon(Icons.refresh_rounded),
                label: const Text('Retry'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildProfileError(String message) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppTheme.danger.withOpacity(.08),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.error_outline, size: 18, color: AppTheme.danger),
            const SizedBox(width: 8),
            Expanded(
              child: Semantics(
                liveRegion: true,
                child: Text(
                  message,
                  style: const TextStyle(fontSize: 12, color: AppTheme.danger),
                ),
              ),
            ),
          ],
        ),
      );

  // ── App Lock (Telegram-style local passcode) ─────────────────

  List<Widget> _buildAppLockSection() {
    final tiles = <Widget>[
      SwitchListTile(
        secondary: const Icon(Icons.lock_outline, size: 20),
        title: const Text('App Lock', style: TextStyle(fontSize: 13.5)),
        subtitle: Text(
          _lockConfigured
              ? 'Passcode protects this app'
              : 'Require a passcode to open the app',
          style: const TextStyle(fontSize: 11.5),
        ),
        value: _lockConfigured,
        onChanged: (v) async {
          if (v) {
            await _setupPasscode();
          } else {
            await _disablePasscode();
          }
        },
      ),
    ];

    if (_lockConfigured) {
      tiles.add(ListTile(
        leading: const Icon(Icons.timer_outlined, size: 20),
        title: const Text('Auto-lock', style: TextStyle(fontSize: 13.5)),
        subtitle: Text(
            '${AppLockService.autoLockOptions[_autoLockSecs] ?? 'Custom'}',
            style: const TextStyle(fontSize: 11.5)),
        trailing: const Icon(Icons.chevron_right, size: 18),
        onTap: _chooseAutoLock,
      ));
      tiles.add(SwitchListTile(
        secondary: const Icon(Icons.fingerprint, size: 20),
        title:
            const Text('Unlock with fingerprint', style: TextStyle(fontSize: 13.5)),
        subtitle: const Text('When the phone supports it',
            style: TextStyle(fontSize: 11.5)),
        value: _biometricOn,
        onChanged: (v) async {
          if (v) {
            final pin = await _pinDialog(title: 'Confirm your passcode');
            if (pin == null) return;
            if (!await _appLock.verifyPin(pin)) {
              _toast('Wrong passcode.');
              return;
            }
            await _appLock.setBiometricEnabled(true);
          } else {
            await _appLock.setBiometricEnabled(false);
          }
          await _refreshLockState();
        },
      ));
      tiles.add(ListTile(
        leading: const Icon(Icons.key_outlined, size: 20),
        title:
            const Text('Change passcode', style: TextStyle(fontSize: 13.5)),
        trailing: const Icon(Icons.chevron_right, size: 18),
        onTap: _changePasscode,
      ));
      tiles.add(ListTile(
        leading: const Icon(Icons.lock_rounded, size: 20),
        title: const Text('Lock now', style: TextStyle(fontSize: 13.5)),
        onTap: () => _appLock.lockNow(),
      ));
    }

    return [_buildSection('Privacy & Security', tiles)];
  }

  Future<void> _setupPasscode() async {
    final first = await _pinDialog(title: 'Choose a passcode (4-8 digits)');
    if (first == null) return;
    final second = await _pinDialog(title: 'Confirm the passcode');
    if (second == null) return;
    if (first != second) {
      _toast('Passcodes do not match.');
      return;
    }
    final err = await _appLock.setPin(first);
    if (err != null) {
      _toast(err);
      return;
    }
    _toast('App Lock is on.');
    await _refreshLockState();
  }

  Future<void> _disablePasscode() async {
    final pin = await _pinDialog(title: 'Enter passcode to turn off');
    if (pin == null) return;
    final err = await _appLock.disable(pin);
    if (err != null) {
      _toast(err);
      return;
    }
    _toast('App Lock is off.');
    await _refreshLockState();
  }

  Future<void> _changePasscode() async {
    final current = await _pinDialog(title: 'Current passcode');
    if (current == null) return;
    final first = await _pinDialog(title: 'New passcode (4-8 digits)');
    if (first == null) return;
    final second = await _pinDialog(title: 'Confirm the new passcode');
    if (second == null) return;
    if (first != second) {
      _toast('New passcodes do not match.');
      return;
    }
    final err = await _appLock.changePin(current, first);
    if (err != null) {
      _toast(err);
      return;
    }
    _toast('Passcode changed.');
  }

  Future<void> _chooseAutoLock() async {
    final options = AppLockService.autoLockOptions;
    final picked = await showDialog<int>(
      context: context,
      builder: (ctx) => SimpleDialog(
        title: const Text('Auto-lock', style: TextStyle(fontSize: 15)),
        children: [
          for (final entry in options.entries)
            RadioListTile<int>(
              title: Text(entry.value, style: const TextStyle(fontSize: 13)),
              value: entry.key,
              groupValue: _autoLockSecs,
              onChanged: (v) => Navigator.of(ctx).pop(v),
            ),
        ],
      ),
    );
    if (picked == null) return;
    await _appLock.setAutoLockSeconds(picked);
    await _refreshLockState();
  }

  Future<String?> _pinDialog({required String title}) async {
    final ctrl = TextEditingController();
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title, style: const TextStyle(fontSize: 15)),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          keyboardType: TextInputType.number,
          obscureText: true,
          maxLength: 8,
          decoration: const InputDecoration(
            hintText: '• • • •',
            counterText: '',
            isDense: true,
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('CANCEL')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppTheme.primary),
            onPressed: () => Navigator.of(ctx).pop(ctrl.text.trim()),
            child: const Text('OK'),
          ),
        ],
      ),
    );
    if (result == null || result.isEmpty) return null;
    return result;
  }

  void _toast(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(msg)));
  }

  Widget _buildSyncSection() {
    final status = _syncStatus ?? _sync.lastStatus;
    final syncing = status.syncing;
    final unresolved = status.privateUnresolvedTotal +
        status.sharedHymnUnresolvedTotal +
        status.communicationDraftCount;
    final attention =
        status.rejected + status.blockedDependency + status.resolvedConflict;
    final subtitle = unresolved == 0
        ? 'Everything is up to date'
        : [
            '${status.retryableDue + status.retryableWaiting + status.inFlight} waiting',
            if (attention > 0) '$attention need attention',
            if (status.pausedAuth + status.pausedScope > 0)
              '${status.pausedAuth + status.pausedScope} paused',
          ].join(' · ');
    return _buildSection('Sync Status', [
      ListTile(
        contentPadding: EdgeInsets.zero,
        onTap: () => Navigator.of(context).pushNamed('/sync-center'),
        leading: Container(
          width: 42,
          height: 42,
          decoration: BoxDecoration(
              color: AppTheme.primary.withOpacity(.08),
              borderRadius: BorderRadius.circular(12)),
          child: syncing
              ? const Padding(
                  padding: EdgeInsets.all(11),
                  child: CircularProgressIndicator(strokeWidth: 2))
              : Icon(
                  unresolved > 0
                      ? Icons.sync_problem_rounded
                      : Icons.cloud_done_rounded,
                  color: unresolved > 0
                      ? AppTheme.warning
                      : AppTheme.success,
                  size: 22,
                ),
        ),
        title: const Text('Sync Center',
            style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
        subtitle: Text(subtitle,
            style: TextStyle(
                fontSize: 12,
                color: unresolved > 0
                    ? AppTheme.warning
                    : AppTheme.success)),
        trailing: const Icon(Icons.chevron_right_rounded),
      ),
      const SizedBox(height: 6),
      SizedBox(
        width: double.infinity,
        child: OutlinedButton.icon(
          onPressed: syncing
              ? null
              : () async {
                  final result = await _sync.syncAll(force: true);
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(result.message)),
                    );
                    _loadPendingCount();
                  }
                },
          icon: const Icon(Icons.sync, size: 16),
          label: const Text('Sync Now'),
          style: OutlinedButton.styleFrom(foregroundColor: AppTheme.primary),
        ),
      ),
    ]);
  }

  Widget _buildProfileHeader(UserProfile profile) {
    final initials = _getInitials(profile.fullName);
    final image = _profiles.imageBytes;
    final canChangeImage = _profiles.isOnline && !_profiles.mutating;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            Semantics(
              image: true,
              label: 'Profile image for ${profile.fullName}',
              child: Stack(
                alignment: Alignment.center,
                children: [
                  CircleAvatar(
                    radius: 44,
                    backgroundColor: AppTheme.primary.withOpacity(0.15),
                    foregroundImage: image == null ? null : MemoryImage(image),
                    child: image == null
                        ? Text(
                            initials,
                            style: const TextStyle(
                              color: AppTheme.primary,
                              fontSize: 24,
                              fontWeight: FontWeight.w700,
                            ),
                          )
                        : null,
                  ),
                  if (_profiles.uploadingImage)
                    Container(
                      width: 88,
                      height: 88,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        color: Colors.black45,
                      ),
                      child: const Padding(
                        padding: EdgeInsets.all(30),
                        child: CircularProgressIndicator(
                          strokeWidth: 3,
                          color: Colors.white,
                        ),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              alignment: WrapAlignment.center,
              spacing: 8,
              runSpacing: 4,
              children: [
                TextButton.icon(
                  onPressed: canChangeImage ? _pickProfileImage : null,
                  icon: const Icon(Icons.photo_camera_outlined, size: 17),
                  label: Text(profile.profileImage.present ? 'Replace' : 'Upload'),
                ),
                if (profile.profileImage.present)
                  TextButton.icon(
                    onPressed:
                        canChangeImage ? _confirmRemoveProfileImage : null,
                    icon: const Icon(Icons.delete_outline, size: 17),
                    label: const Text('Remove'),
                    style: TextButton.styleFrom(foregroundColor: AppTheme.danger),
                  ),
              ],
            ),
            if (!_profiles.isOnline)
              const Text(
                'Image actions are available when online.',
                style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
              ),
            const SizedBox(height: 8),
            Text(
              profile.fullName,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 3),
            Text(
              '@${profile.username}',
              style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary),
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
              decoration: BoxDecoration(
                color: AppTheme.primary.withOpacity(0.12),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(
                UserRoles.displayName(profile.role),
                style: const TextStyle(
                  color: AppTheme.primary,
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Diagnostics (P65: field crash reports without adb) ────────

  Future<void> _showDiagnostics() async {
    final tiers = DeviceTierService.instance;
    if (tiers.info == null) {
      await tiers.boot();
    }
    final crash = CrashLogService.instance;
    final raw = await crash.readRaw();
    final hasLog = raw.trim().isNotEmpty;
    // Keep the dialog readable: show only the tail of the log.
    final tail =
        raw.length > 4096 ? '…${raw.substring(raw.length - 4096)}' : raw;
    final recentCrash = await crash.lastNativeCrash();
    final report = CrashLogService.buildReport(
      appVersion: AppConfig.appVersion,
      appBuild: AppConfig.appBuild,
      server: AppConfig.apiBaseUrl,
      device: tiers.info,
      crashLogTail: tail,
    );
    if (!mounted) return;

    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Diagnostics'),
        content: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Device tier: ${tiers.tier.name.toUpperCase()}'
                '${tiers.info != null ? ' · ${tiers.info!.primaryAbi}' : ''}',
                style: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w600),
              ),
              if (recentCrash != null) ...[
                const SizedBox(height: 8),
                Text(
                  '⚠ The app closed itself unexpectedly recently '
                  '(${recentCrash.at != null ? recentCrash.at!.toLocal().toString().substring(0, 16) : 'unknown time'}).',
                  style: TextStyle(fontSize: 12, color: AppTheme.danger),
                ),
              ],
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                constraints: const BoxConstraints(maxHeight: 260),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppTheme.textSecondary.withOpacity(0.08),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: SingleChildScrollView(
                  child: Text(
                    tail.trim().isEmpty ? 'No recorded errors.' : tail.trim(),
                    style: const TextStyle(
                        fontFamily: 'monospace', fontSize: 11, height: 1.35),
                  ),
                ),
              ),
              const SizedBox(height: 8),
              const Text(
                'Review the report before sharing it privately with an '
                'authorized administrator. It includes device details and '
                'error logs, which may contain sensitive information.',
                style: TextStyle(fontSize: 11, color: Colors.black54),
              ),
            ],
          ),
        ),
        actions: [
          if (hasLog)
            TextButton(
              onPressed: () async {
                await crash.clear();
                if (ctx.mounted) Navigator.of(ctx).pop();
              },
              child: const Text('Clear log'),
            ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Close'),
          ),
          FilledButton.icon(
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: report));
              if (ctx.mounted) Navigator.of(ctx).pop();
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(
                      content: Text('Diagnostic report copied to clipboard')),
                );
              }
            },
            icon: const Icon(Icons.copy_rounded, size: 16),
            label: const Text('Copy report'),
          ),
        ],
      ),
    );
  }

  Widget _buildSection(String title, List<Widget> children) {
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

  Widget _editableInfoTile(
    IconData icon,
    String label,
    String value,
    VoidCallback? onEdit,
  ) {
    return ListTile(
      contentPadding: EdgeInsets.zero,
      dense: true,
      leading: Icon(icon, size: 19, color: AppTheme.textSecondary),
      title: Text(label, style: const TextStyle(fontSize: 11)),
      subtitle: Text(
        value,
        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
      ),
      trailing: IconButton(
        tooltip: onEdit == null ? 'Available when online' : 'Edit $label',
        onPressed: onEdit,
        icon: const Icon(Icons.edit_outlined, size: 18),
      ),
    );
  }

  Widget _infoTile(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          Icon(icon, size: 18, color: AppTheme.textSecondary),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label,
                    style:
                        TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                const SizedBox(height: 1),
                Text(value,
                    style: const TextStyle(
                        fontSize: 13, fontWeight: FontWeight.w500)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _getInitials(String name) {
    final parts = name
        .trim()
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .toList(growable: false);
    if (parts.isEmpty) return '?';
    final first = String.fromCharCode(parts.first.runes.first);
    if (parts.length == 1) return first.toUpperCase();
    final second = String.fromCharCode(parts[1].runes.first);
    return '$first$second'.toUpperCase();
  }
}


