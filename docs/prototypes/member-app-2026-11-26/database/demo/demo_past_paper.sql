-- OPTIONAL demo row so the Past Papers section shows the sample exam you provided.
-- Run on felege_kidusan_test only, after migration 003:
--   mysql -u USER -p felege_kidusan_test < database\demo\demo_past_paper.sql
INSERT INTO past_papers (grade, subject, exam_year, title, file_name, file_path)
VALUES ('10', 'የማጠቃለያ ምዘና', '2018', 'ፈተና ፲ኛ ክፍል (ማሳያ)', '2018-grade10-final-exam-demo.pdf', 'past-papers/2018-grade10-final-exam-demo.pdf');
