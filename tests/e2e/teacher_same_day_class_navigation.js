// Execute the actual teacher dashboard's class selection and lock functions
// with a synthetic DOM/fetch. No server or production data is contacted.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../../admin/dashboards/teacher.php'), 'utf8');
const start = source.indexOf('        function attSetLocked(');
const end = source.indexOf('        function renderAttendanceTable(', start);
assert(start !== -1 && end > start, 'teacher attendance functions missing');
const nodes = {};
for (const id of ['attClassSelect','attDateInput','attLessonWrap','attLessonSelect','attLessonNo','attendanceCard','attQuickActions','selectAttMsg','attSaveBtn','attLessonInfo','attTitle','attSubtitle']) {
  nodes[id] = {value:'', style:{}, disabled:false, innerHTML:'', textContent:''};
}
nodes.attDateInput.value = '2026-10-25';
const rowControls = [{disabled:false},{disabled:false}];
const requests = [];
const context = {
  document: {getElementById: id => nodes[id], querySelectorAll: () => rowControls},
  attSubjectsCache:{}, attReqSeq:0, attendanceData:[], attSaved:{}, window:{},
  attShowLoadError: message => { throw new Error(message); },
  attSelectedLesson:() => null, escapeHtml:s=>s,
  AttendanceSheet:{snapshot:() => ({})}, renderAttendanceTable:() => {},
  fetch: async url => {
    requests.push(url);
    if (url.includes('action=subjects')) return {json:async()=>({status:'success',available:false})};
    const id = new URL('https://synthetic.invalid'+url).searchParams.get('class_id');
    return {json:async()=>({status:'success', class_name:`Class ${id}`, students:[{member_id:Number(id)}],
      lessons:id==='1' ? [{subject_name:'Synthetic',lesson_no:1,present:1,absent:0,late:0,excused:0}] : []})};
  },
};
vm.createContext(context);
vm.runInContext(source.slice(start,end), context);
const tick = () => new Promise(resolve=>setImmediate(resolve));
(async()=>{
  nodes.attClassSelect.value='1'; context.onAttClassChange(); await tick(); await tick();
  assert.strictEqual(nodes.attSaveBtn.disabled,true, 'derived first-class sheet is locked');
  assert.strictEqual(nodes.attClassSelect.disabled,false, 'class navigation remains enabled');
  assert.strictEqual(nodes.attDateInput.disabled,false, 'date navigation remains enabled');
  nodes.attClassSelect.value='2'; context.onAttClassChange(); await tick(); await tick();
  assert.strictEqual(nodes.attSaveBtn.disabled,false, 'different class becomes writable');
  assert.strictEqual(nodes.attTitle.textContent,'Class 2');
  assert(requests.some(url=>url.includes('class_id=1&date=2026-10-25')));
  assert(requests.some(url=>url.includes('class_id=2&date=2026-10-25')));
  console.log('PASS: teacher web class selection remains usable across same-day sheet locks');
})().catch(err=>{ console.error(err);process.exitCode=1; });
