import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/grades_preparation_dataset.dart';

void main() {
  final fixture = jsonDecode(File('../../tests/fixtures/grades_preparation_dataset_v1.json').readAsStringSync()) as Map;
  final payload = fixture['payload'] as Map<String, dynamic>;

  test('decodes valid grades payload', () {
    final ds = GradesPreparationDataset.decode(jsonEncode(payload));
    expect(ds.preparationId, '824c5645ed41c2f8b4c387f7439c9105a69caf76783a210c23c00fb6f531d288');
    expect(ds.classes, hasLength(1));
    expect(ds.classes.first.subjects.first.id, 10);
    expect(ds.classes.first.assessments.first.id, 1001);
    expect(ds.members, hasLength(2));
  });

  test('rejects missing keys', () {
    final bad = Map<String, dynamic>.from(payload);
    bad.remove('members');
    expect(() => GradesPreparationDataset.decode(jsonEncode(bad)), throwsA(isA<FormatException>()));
  });

  test('rejects duplicate subject id', () {
    final bad = Map<String, dynamic>.from(payload);
    final cls = Map<String, dynamic>.from((bad['classes'] as List).first as Map);
    final subjects = List<Map<String, dynamic>>.from(cls['subjects'] as List);
    subjects.add(Map<String, dynamic>.from(subjects.first));
    cls['subjects'] = subjects;
    bad['classes'] = [cls];
    expect(() => GradesPreparationDataset.decode(jsonEncode(bad)), throwsA(isA<FormatException>()));
  });

  test('rejects assessment with unknown subject', () {
    final bad = Map<String, dynamic>.from(payload);
    final cls = Map<String, dynamic>.from((bad['classes'] as List).first as Map);
    final assessments = List<Map<String, dynamic>>.from(cls['assessments'] as List);
    assessments.first = {...assessments.first, 'subject_id': 9999};
    cls['assessments'] = assessments;
    bad['classes'] = [cls];
    expect(() => GradesPreparationDataset.decode(jsonEncode(bad)), throwsA(isA<FormatException>()));
  });

  test('rejects lease beyond 30 days', () {
    final bad = Map<String, dynamic>.from(payload);
    bad['deadline'] = (bad['issued_at'] as int) + 31 * 86400;
    expect(() => GradesPreparationDataset.decode(jsonEncode(bad)), throwsA(isA<FormatException>()));
  });
}
