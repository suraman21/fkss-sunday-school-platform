import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/attendance_preparation_envelope.dart';

void main() {
  final fixture = jsonDecode(File(
    '../../tests/fixtures/attendance_preparation_envelope_v1.json',
  ).readAsStringSync()) as Map;
  Map<String, dynamic> fresh() =>
      Map<String, dynamic>.from(fixture['envelope'] as Map);
  const scope = AttendancePreparationScope(
    ownerUserId: 7,
    authorizationVersion: 3,
    role: 'teacher',
    familyId:
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    serverIdentity: 'https://school.example/api/v1',
  );
  String encode(List<int> bytes) => base64Url.encode(bytes).replaceAll('=', '');
  Map<String, dynamic> body(Map<String, dynamic> e) =>
      jsonDecode(utf8.decode(base64Url.decode(
        base64Url.normalize(e['payload'] as String),
      ))) as Map<String, dynamic>;
  Map<String, dynamic> root(Map<String, dynamic> e) =>
      jsonDecode(utf8.decode(base64Url.decode(base64Url.normalize(
        (e['grant'] as String).split('.').first,
      )))) as Map<String, dynamic>;
  void setRoot(Map<String, dynamic> e, Map<String, dynamic> r) {
    final mac = (e['grant'] as String).split('.').last;
    e['grant'] = '${encode(utf8.encode(jsonEncode(r)))}.$mac';
  }

  // Intentionally does NOT sign. The decoder checks transport consistency,
  // never authenticity, and may not be used as an offline permission check.
  void replacePayload(Map<String, dynamic> e, Map<String, dynamic> p,
      {bool updateRoot = true, String? exactText}) {
    final bytes = utf8.encode(exactText ?? jsonEncode(p));
    e['payload'] = encode(bytes);
    if (updateRoot) {
      final r = root(e);
      for (final key in r.keys.toList()) {
        if (p.containsKey(key)) r[key] = p[key];
      }
      r['payload_sha256'] = sha256.convert(bytes).toString();
      setRoot(e, r);
    }
  }

  AttendancePreparationEnvelope decode(Map<String, dynamic> e,
          {AttendancePreparationScope expected = scope,
          int max = 1024 * 1024}) =>
      AttendancePreparationEnvelope.decode(e,
          expected: expected, maxPayloadBytes: max);

  test('actual PHP issuer fixture retains exact UTF8 and opaque grant', () {
    final e = fresh();
    final result = decode(e);
    expect(result.payloadBase64Url, e['payload']);
    expect(result.opaqueGrant, e['grant']);
    expect(result.payloadUtf8, contains('ፈለገ ቅዱሳን'));
    expect(result.payloadSha256,
        sha256.convert(utf8.encode(result.payloadUtf8)).toString());
    expect(result.deadline - result.issuedAt, 30 * 86400);
    expect(body(e)['classes'], isEmpty);
    e.clear();
    expect(result.preparationId.length, 64);
    expect(result.payloadUtf8, contains('ፈለገ ቅዱሳን'));
  });

  test('exact whitespace is retained; re-encoding is not digest verification',
      () {
    final e = fresh();
    final p = body(e);
    final text = ' \n${const JsonEncoder.withIndent('  ').convert(p)}\n';
    replacePayload(e, p, exactText: text);
    expect(decode(e).payloadUtf8, text);
    expect(decode(e).payloadSha256,
        isNot(sha256.convert(utf8.encode(jsonEncode(p))).toString()));
  });

  test('corrupted exact payload fails without an updated digest', () {
    final e = fresh();
    replacePayload(e, body(e)..['label'] = 'changed', updateRoot: false);
    expect(() => decode(e), throwsFormatException);
  });

  test('resource budget refuses entire response, never truncates', () {
    final e = fresh();
    final size = utf8.encode(decode(e).payloadUtf8).length;
    expect(() => decode(e, max: size - 1), throwsFormatException);
    expect(decode(e, max: size).payloadBase64Url, e['payload']);
    expect(() => decode(e, max: 0), throwsArgumentError);
  });

  for (final field in [
    'preparation_version',
    'payload',
    'grant',
    'authorizes_settlement'
  ]) {
    test('missing envelope $field fails', () {
      expect(() => decode(fresh()..remove(field)), throwsFormatException);
    });
  }
  for (final value in [null, '', '=', 'A', 'AB', 'e30=', 'a b', 'a\nb', 12]) {
    test('malformed payload $value fails', () {
      expect(() => decode(fresh()..['payload'] = value), throwsFormatException);
    });
  }
  for (final value in [
    null,
    '',
    'abc',
    'a.b.c',
    '${'a' * 17000}.123',
    '${encode(utf8.encode('{}'))}.${'A' * 64}'
  ]) {
    test('malformed opaque grant ${value.toString().length} fails', () {
      expect(() => decode(fresh()..['grant'] = value), throwsFormatException);
    });
  }
  for (final field in {
    'owner_user_id': 8,
    'authorization_version': 4,
    'role': 'school_admin',
    'family_id': 'b' * 64,
    'server_identity': 'https://other.example/api/v1',
  }.entries) {
    test('foreign ${field.key} fails even with internally consistent claims',
        () {
      final e = fresh();
      replacePayload(e, body(e)..[field.key] = field.value);
      expect(() => decode(e), throwsFormatException);
    });
  }
  for (final field in root(fresh()).keys) {
    test('missing root $field fails', () {
      final e = fresh();
      setRoot(e, root(e)..remove(field));
      expect(() => decode(e), throwsFormatException);
    });
  }
  for (final field in [
    'preparation_id',
    'issued_at',
    'deadline',
    'family_id'
  ]) {
    test('root/payload $field mismatch fails', () {
      final e = fresh();
      setRoot(e, root(e)..[field] = 'different');
      expect(() => decode(e), throwsFormatException);
    });
  }
  for (final pair in [
    [0, 1],
    [1, 1],
    [10, 9],
    [1, 2592002],
    [1.0, 2],
    ['1', 2],
    [true, 2],
    [1, null],
    [9007199254740991, 9007199254740992],
  ]) {
    test('invalid lease bounds $pair fail', () {
      final e = fresh();
      replacePayload(
          e,
          body(e)
            ..['issued_at'] = pair[0]
            ..['deadline'] = pair[1]);
      expect(() => decode(e), throwsFormatException);
    });
  }
  test('wrong envelope version, unknown field or settlement flag fails', () {
    for (final e in [
      fresh()..['preparation_version'] = 2,
      fresh()..['preparation_version'] = 1.0,
      fresh()..['authorizes_settlement'] = true,
      fresh()..['unknown'] = true
    ]) {
      expect(() => decode(e), throwsFormatException);
    }
  });
  test('incomplete, wrong version and non-root claims fail', () {
    for (final entry in {'complete': false, 'preparation_version': 2}.entries) {
      final e = fresh();
      replacePayload(e, body(e)..[entry.key] = entry.value);
      expect(() => decode(e), throwsFormatException);
    }
    final e = fresh();
    setRoot(e, root(e)..['kind'] = 'day');
    expect(() => decode(e), throwsFormatException);
  });
  test('UTF8, JSON and object shapes are checked', () {
    for (final bytes in [
      [0xff],
      utf8.encode('null'),
      utf8.encode('[]'),
      utf8.encode('{')
    ]) {
      final e = fresh()..['payload'] = encode(bytes);
      expect(() => decode(e), throwsFormatException);
    }
  });
  test('transport consistency does not verify MAC or authorize capture', () {
    final e = fresh();
    e['grant'] = '${(e['grant'] as String).split('.').first}.${'0' * 64}';
    expect(decode(e).opaqueGrant, e['grant']);
    // A valid-shaped root and even complete=true do not validate the dataset.
    // There is deliberately no ready/canCapture/settled method on this type.
  });
  test('invalid captured scopes are rejected', () {
    for (final server in [
      'http://school.example/api/v1',
      'https://school.example/api/v1?x=1',
      'https://user@school.example/api/v1',
      'https://school.example/api/v1#fragment',
      'https://school.example/other'
    ]) {
      expect(
          () => decode(fresh(),
              expected: AttendancePreparationScope(
                ownerUserId: 7,
                authorizationVersion: 3,
                role: 'teacher',
                familyId: scope.familyId,
                serverIdentity: server,
              )),
          throwsFormatException);
    }
  });
}
