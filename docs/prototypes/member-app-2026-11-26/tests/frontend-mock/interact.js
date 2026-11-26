const { chromium } = require('playwright');
(async()=>{
 const b=await chromium.launch(); const ctx=await b.newContext({viewport:{width:375,height:740}}); const page=await ctx.newPage();
 const errs=[]; page.on('pageerror',e=>errs.push(e.message));
 const U='http://localhost:8766/';
 await page.goto(U+'student-dashboard.html',{waitUntil:'networkidle'});
 // bell
 await page.click('.header-actions .fk-iconbtn >> nth=0'); await page.waitForSelector('.fk-note-item'); await page.screenshot({path:'./p-bell.png'});
 await page.click('.fk-note-item'); await page.waitForFunction(()=>document.getElementById('fk-bell-badge').hidden,null,{timeout:5000}).catch(()=>{}); const badgeHidden=await page.$eval('#fk-bell-badge',e=>e.hidden); console.log('badge hidden after reading:',badgeHidden);
 // profile
 await page.click('.profile-avatar'); await page.waitForSelector('.fk-att-grid'); await page.screenshot({path:'./p-profile.png'});
 await page.click('text=ፈቃድ ጠይቅ'); await page.waitForSelector('.fk-modal'); await page.fill('#pr-reason','ሙከራ'); await page.click('.fk-modal button[type=submit]'); await page.waitForSelector('.fk-inline-msg.ok'); console.log('permission sent OK'); await page.screenshot({path:'./p-perm.png'}); await page.click('.fk-modal .fk-iconbtn');
 // settings -> password
 await page.click('.header-actions .fk-iconbtn >> nth=1'); await page.click('text=የይለፍ ቃል ቀይር'); await page.fill('#pw-cur','oldpass12'); await page.fill('#pw-new','newpass123'); await page.fill('#pw-conf','newpass123'); await page.click('.fk-modal button[type=submit]'); await page.waitForSelector('.fk-inline-msg.ok'); console.log('password change OK'); await page.click('.fk-modal .fk-iconbtn');
 // chat
 await page.click('.fk-fab'); await page.fill('.fk-chat-win input','what is faith'); await page.click('.fk-chat-win button[type=submit]'); await page.waitForSelector('.fk-chat-win .fk-source'); await page.screenshot({path:'./p-chat.png'}); console.log('chat OK');
 // lessons + lesson
 await page.goto(U+'lessons.html',{waitUntil:'networkidle'}); await page.screenshot({path:'./p-lessons-full.png',fullPage:true});
 await page.goto(U+'lesson.html?id=3',{waitUntil:'networkidle'}); await page.screenshot({path:'./p-lesson.png'});
 await page.click('button:has-text("ጨርሻ"), button.fk-btn >> nth=0').catch(()=>{});
 console.log('errors:',errs.join('|')||'none'); await b.close();})();
