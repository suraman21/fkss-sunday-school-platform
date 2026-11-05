import 'dart:convert';
import 'package:crypto/crypto.dart';

final class GradesPreparationScope {
  final int ownerUserId;
  final int authorizationVersion;
  final String role;
  final String familyId;
  final String serverIdentity;
  const GradesPreparationScope({required this.ownerUserId, required this.authorizationVersion, required this.role, required this.familyId, required this.serverIdentity});
}

final class GradesPreparationEnvelope {
  final String payloadUtf8;
  final String payloadBase64Url;
  final String opaqueGrant;
  final String payloadSha256;
  final String preparationId;
  final int issuedAt;
  final int deadline;
  const GradesPreparationEnvelope._({required this.payloadUtf8, required this.payloadBase64Url, required this.opaqueGrant, required this.payloadSha256, required this.preparationId, required this.issuedAt, required this.deadline});

  static GradesPreparationEnvelope decode(Object? input, {required GradesPreparationScope expected, required int maxPayloadBytes}) {
    if (maxPayloadBytes < 1) throw ArgumentError.value(maxPayloadBytes, 'maxPayloadBytes');
    _id(expected.ownerUserId); _id(expected.authorizationVersion); _hex(expected.familyId);
    final server = Uri.tryParse(expected.serverIdentity);
    if (expected.role.isEmpty || server == null || server.scheme != 'https' || server.host.isEmpty || server.userInfo.isNotEmpty || server.hasQuery || server.hasFragment || !server.path.endsWith('/api/v1')) throw const FormatException('Invalid captured preparation scope.');
    final envelope = _map(input);
    _keys(envelope, const {'preparation_version','payload','grant','authorizes_settlement'});
    if (envelope['preparation_version'] is! int || envelope['preparation_version'] != 1 || envelope['authorizes_settlement'] != false) throw const FormatException('Unsupported preparation transport.');
    final encoded = envelope['payload']; final bytes = _base64(encoded, maxPayloadBytes); final text = utf8.decode(bytes, allowMalformed: false);
    final payload = _map(jsonDecode(text)); final grant = envelope['grant'];
    if (grant is! String || grant.length > 16384) throw const FormatException('Invalid preparation grant.');
    final parts = grant.split('.'); if (parts.length != 2) throw const FormatException('Invalid preparation grant.');
    _hex(parts[1]);
    final root = _map(jsonDecode(utf8.decode(_base64(parts[0], 12288), allowMalformed: false)));
    const common = {'preparation_id','server_identity','owner_user_id','authorization_version','role','family_id','issued_at','deadline'};
    _keys(root, {...common, 'kind', 'payload_sha256'});
    if (root['kind'] != 'root' || payload['preparation_version'] is! int || payload['preparation_version'] != 1 || payload['complete'] != true) throw const FormatException('Unsupported preparation claims.');
    for (final key in common) { if (payload[key] != root[key] || payload[key].runtimeType != root[key].runtimeType) throw const FormatException('Preparation context mismatch.'); }
    _id(root['owner_user_id']); _id(root['authorization_version']); _hex(root['preparation_id']); _hex(root['family_id']); _hex(root['payload_sha256']);
    if (root['owner_user_id'] != expected.ownerUserId || root['authorization_version'] != expected.authorizationVersion || root['role'] != expected.role || root['family_id'] != expected.familyId || root['server_identity'] != expected.serverIdentity) throw const FormatException('Preparation does not match captured scope.');
    final issued = root['issued_at']; final deadline = root['deadline'];
    if (issued is! int || deadline is! int || issued < 1 || issued > 9007199254740991 || deadline <= issued || deadline > 9007199254740991 || deadline - issued > 30*86400) throw const FormatException('Invalid preparation lease bounds.');
    final digest = sha256.convert(bytes).toString();
    if (digest != root['payload_sha256']) throw const FormatException('Preparation payload digest mismatch.');
    return GradesPreparationEnvelope._(payloadUtf8: text, payloadBase64Url: encoded as String, opaqueGrant: grant, payloadSha256: digest, preparationId: root['preparation_id'] as String, issuedAt: issued, deadline: deadline);
  }

  static Map<String, dynamic> _map(Object? v) { if (v is! Map<String, dynamic>) throw const FormatException('Invalid preparation object.'); return v; }
  static void _keys(Map<String, dynamic> v, Set<String> e) { if (v.length != e.length || !e.containsAll(v.keys)) throw const FormatException('Unsupported preparation envelope fields.'); }
  static List<int> _base64(Object? v, int max) {
    if (v is! String || v.isEmpty || v.length > ((max+2)~/3)*4 || !RegExp(r'^[A-Za-z0-9_-]+$').hasMatch(v)) throw const FormatException('Invalid preparation encoding or size.');
    final b = base64Url.decode(base64Url.normalize(v)); if (b.length > max || base64Url.encode(b).replaceAll('=','') != v) throw const FormatException('Noncanonical preparation encoding or size.'); return b;
  }
  static void _id(Object? v) { if (v is! int || v < 1 || v > 9007199254740991) throw const FormatException('Invalid preparation identity.'); }
  static void _hex(Object? v) { if (v is! String || v.length != 64 || !RegExp(r'^[a-f0-9]{64}$').hasMatch(v)) throw const FormatException('Invalid preparation digest or identity.'); }
}
