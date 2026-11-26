const { chromium } = require('playwright');
const pages=['student-dashboard','lessons','lesson?id=3','lesson?id=5','tasks','exam-prep','progress'];
(async()=>{
 const b=await chromium.launch(); const out=[];
 for (const [label,w,h] of [['phone',375,740],['small',320,640],['desktop',1280,800]]) {
  const ctx=await b.newContext({viewport:{width:w,height:h}});
  for (const pg of pages){const page=await ctx.newPage();const errs=[];
   page.on('console',m=>{if(m.type()==='error'&&!/403|404/.test(m.text()))errs.push(m.text())});page.on('pageerror',e=>errs.push('PAGEERROR '+e.message));
   await page.goto('http://localhost:8766/'+(pg.includes('?')?pg.replace('?','.html?'):pg+'.html'),{waitUntil:'networkidle'});await page.waitForTimeout(350);
   const m=await page.evaluate(()=>({o:document.documentElement.scrollWidth>document.documentElement.clientWidth,sw:document.documentElement.scrollWidth,cw:document.documentElement.clientWidth,bottom:document.querySelectorAll('.fk-bottom-nav a').length,top:document.querySelectorAll('.dashboard-header nav a').length,fab:!!document.querySelector('.fk-fab')}));
   out.push([label,pg,m.o?'OVERFLOW '+m.sw+'>'+m.cw:'ok',errs.length?errs.join('|'):'no-errors','bottom='+m.bottom,'top='+m.top,'fab='+m.fab].join('  '));
   if(label!=='small')await page.screenshot({path:`./n-${label}-${pg.replace(/[^a-z0-9]/g,'_')}.png`});await page.close();}
  await ctx.close();}
 await b.close();console.log(out.join('\n'));})();
