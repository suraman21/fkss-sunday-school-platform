// MOCK API for layout tests ONLY (made-up data). Mirrors the real endpoints' shapes.
const http=require('http'),fs=require('fs'),path=require('path');
const ROOT=require('path').resolve(__dirname,'../../frontend');
const mime={'.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.js':'application/javascript; charset=utf-8'};
let done=new Set([1,2,3]); let notes=[{id:1,title:'የሙከራ ማስታወቂያ',body:'Mock announcement body',created_at:'2026-10-17 08:00:00',is_read:false}];
const lessons=[{id:1,n:'፩.፩',t:'የሃይማኖት ምንነት',u:1},{id:2,n:'፩.፪',t:'የሳይንስ ምንነት',u:1},{id:3,n:'፩.፫',t:'የሃይማኖትና ሳይንስ ግንኙነት',u:1},{id:4,n:'፪.፩',t:'የባህል ምንነትና ምንጭ',u:2},{id:5,n:'፪.፪',t:'ሃይማኖትና ባሕል',u:2},{id:6,n:'፪.፫',t:'ባህል ከሃይማኖት ጋር',u:2}];
const unlocked=new Set([1,2,3]);
const st=l=>done.has(l.id)?'completed':(unlocked.has(l.id)?'available':'locked');
const totals=()=>{const t={total:6,completed:0,available:0,locked:0};lessons.forEach(l=>t[st(l)]++);return t;};
function path_(){const t=totals();const cur=lessons.find(l=>st(l)==='available');
 const u1done=lessons.filter(l=>l.u===1).every(l=>done.has(l.id));
 const units=[1,2].map(u=>{const ls=lessons.filter(l=>l.u===u).map(l=>({lesson_id:l.id,lesson_number:l.n,title:l.t,state:st(l)}));const c=ls.filter(l=>l.state==='completed').length;
  return {chapter_id:u,number:String(u),title:u===1?'ሃይማኖትና ሳይንስ':'ሃይማኖትና ባሕል',open:u===1,waiting_for:u===1?null:'previous_quiz',quiz:{required:true,passed:false,available:u===1&&u1done},lessons:ls,total:ls.length,completed:c,percent:Math.round(c*100/ls.length)};});
 return {success:true,student:{name:'ሙከራ ተማሪ',grade:'10',class:'A'},totals:t,percent:Math.round(t.completed*100/6),current_lesson:cur?{lesson_id:cur.id,title:cur.t,lesson_number:cur.n,subject:'ትምህርተ ሃይማኖት'}:null,subjects:[{subject_id:1,name:'ትምህርተ ሃይማኖት',units,total:6,completed:t.completed,percent:Math.round(t.completed*100/6)}]};}
const body=r=>new Promise(res=>{let d='';r.on('data',c=>d+=c);r.on('end',()=>{try{res(JSON.parse(d||'{}'))}catch(e){res({})}})});
const send=(res,c,o)=>{res.writeHead(c,{'Content-Type':'application/json; charset=utf-8'});res.end(JSON.stringify(o));};
const TEXT='ሃይማኖት በቁሙ ማመን፣ መታመን፣ እምነት፣ ጽኑ ተስፋ ማለት ነው። ማመን በዓይነ ሥጋ ዐይተን የማንችለውን ይሆናል ይደረጋል ብሎ መቀበል ነው።\n\n## ፩.፪.፩ ሳይንስ ምንነቱና የት መጣው\n\nሳይንስ ሲር ወይም ሴንቲያ ከሚለው የላቲን ቃል የተገኘ ሲሆን ትርጉሙም ስለ ቁሳዊ ዓለም ሁነት በጥናት የተገኘ ዕውቀት ማለት ነው።\n\n> “በአባቱ ዕቅፍ ያለ አንድ ልጁ እርሱ ገለጠልን እንጂ፤ እግዚአብሔርንስ ከቶ ያየው የለም።” ዮሐ ፩፥፲፰\n\n- ፩. ምልከታ (observation)\n\n- ፪. መላምት (hypothesis)\n\n- ፫. ሙከራ (experimentation)';
http.createServer(async(req,res)=>{const u=new URL(req.url,'http://x'),p=u.pathname,m=req.method;
 if(p.startsWith('/api/')){let mm;
  if(p==='/api/auth/me')return send(res,200,{success:true,user:{role:'student',id:'FK-1001',name:'ሙከራ ተማሪ',grade:'10','class':'A',mustChangePassword:false}});
  if(p==='/api/auth/logout')return send(res,200,{success:true});
  if(p==='/api/auth/change-password'){const b=await body(req);return b.current_password==='oldpass12'?send(res,200,{success:true}):send(res,403,{success:false,message:'The current password is incorrect.'});}
  if(p==='/api/learning/path')return send(res,200,path_());
  if((mm=p.match(/^\/api\/learning\/lessons\/(\d+)$/))){const l=lessons.find(x=>x.id==mm[1]);if(!l)return send(res,404,{success:false});if(st(l)==='locked')return send(res,403,{success:false,code:'LESSON_LOCKED',message:'locked'});
   return send(res,200,{success:true,lesson:{lesson_id:l.id,lesson_number:l.n,title:l.t,description:TEXT,state:st(l),subject:{id:1,name:'ትምህርተ ሃይማኖት'},unit:{id:l.u,number:String(l.u),title:'ሃይማኖትና ሳይንስ'},materials:[],question_count:0,completion_rule:'confirm',quiz_passed:false,can_complete:st(l)==='available'}});}
  if((mm=p.match(/^\/api\/learning\/lessons\/(\d+)\/complete$/))&&m==='POST'){const l=lessons.find(x=>x.id==mm[1]);done.add(l.id);return send(res,200,{success:true,state:'completed',totals:totals(),percent:50,next_lesson:null});}
  if(p==='/api/progress'){const t=totals();return send(res,200,{success:true,lessons:{...t,percent:Math.round(t.completed*100/6)},subjects:[{subject_id:1,name:'ትምህርተ ሃይማኖት',total:6,completed:t.completed,percent:Math.round(t.completed*100/6)}],
    quizzes:{started:3,completed:3,passed:2,average_score:70,recent:[{id:12,scope:'unit',mode:'final_review',score:'80.00',passed:1,completed_at:'2026-10-17 09:00:00'}]},
    activity:[{type:'lesson_completed',title:'የሃይማኖት ምንነት',at:'2026-10-17 08:00:00'}],milestones:[{key:'first_lesson',earned:true},{key:'five_lessons',earned:false},{key:'first_quiz',earned:true},{key:'first_pass',earned:true},{key:'subject_done',earned:false}],
    next_lesson:null,week:[0,1,2,3,4,5,6].map(i=>({date:'2026-10-'+(11+i),active:i===4||i===6})),score_history:[{score:40,passed:false,at:'x'},{score:65,passed:true,at:'x'},{score:80,passed:true,at:'x'},{score:75,passed:true,at:'x'}]});}
  if(p==='/api/progress/weak-topics')return send(res,200,{success:true,topics:[{lesson_id:2,lesson_number:'፩.፪',title:'የሳይንስ ምንነት',answered:4,wrong:3,open:true}]});
  if(p==='/api/progress/attempts/12')return send(res,200,{success:true,questions:[{text:'ጥያቄ?',explanation:'ማብራሪያ',answered:true,selected_option_id:2,options:[{id:1,label:'ሀ',text:'ትክክል',is_correct:true},{id:2,label:'ለ',text:'ስህተት',is_correct:false}]}]});
  if(p==='/api/verse/today')return send(res,200,{success:true,verse:{reference:'ዮሐ ፩፥፲፰',text_am:'በአባቱ ዕቅፍ ያለ አንድ ልጁ እርሱ ገለጠልን እንጂ፤ እግዚአብሔርንስ ከቶ ያየው የለም።',source:'textbook'}});
  if(p==='/api/notifications')return send(res,200,{success:true,unread:notes.filter(n=>!n.is_read).length,notifications:notes});
  if((mm=p.match(/^\/api\/notifications\/(\d+)\/read$/))){notes.forEach(n=>{if(n.id==mm[1])n.is_read=true});return send(res,200,{success:true});}
  if(p==='/api/notifications/read-all'){notes.forEach(n=>n.is_read=true);return send(res,200,{success:true});}
  if(p==='/api/attendance')return send(res,200,{success:true,counts:{present:7,absent:1,late:1,permission:1},total:10,percent:80,records:[]});
  if(p==='/api/permissions'&&m==='POST'){const b=await body(req);return b.reason?send(res,201,{success:true}):send(res,400,{success:false,message:'Please write a reason'});}
  if(p==='/api/permissions')return send(res,200,{success:true,requests:[{id:1,request_date:'2026-10-20',reason:'የሙከራ ምክንያት',status:'pending',review_note:null}]});
  if(p==='/api/past-papers')return send(res,200,{success:true,papers:[{id:1,subject:'ትምህርተ ሃይማኖት',exam_year:'2018',title:'የማጠቃለያ ምዘና',file_name:'x.pdf'}]});
  if(p==='/api/assistant/ask')return send(res,200,{success:true,answer:'በተከፈቱ ትምህርቶችዎ ውስጥ ያገኘሁት፦',sources:[{type:'lesson',lesson_id:1,title:'የሃይማኖት ምንነት',snippet:'ሃይማኖት በቁሙ ማመን…'}]});
  if(p.startsWith('/api/tasks/'))return send(res,200,{success:true,tasks:[{task_id:1,title:'የሙከራ ተግባር',description:'desc',subject_name:'ትምህርተ ሃይማኖት',due_date:'2026-10-30',attachments:[{file_name:'a.pdf',file_path:'uploads/a.pdf'}]}]});
  return send(res,404,{success:false});}
 let f=p==='/'?'/index.html':p;const full=path.join(ROOT,f);if(!full.startsWith(ROOT)||!fs.existsSync(full)||fs.statSync(full).isDirectory()){res.writeHead(404);return res.end('nf');}
 res.writeHead(200,{'Content-Type':mime[path.extname(full)]||'application/octet-stream'});fs.createReadStream(full).pipe(res);}).listen(8766,()=>console.log('mock2 on 8766'));
