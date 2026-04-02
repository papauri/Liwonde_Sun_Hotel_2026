const puppeteer = require('puppeteer');
const path = require('path');
(async()=>{
  const b=await puppeteer.launch({headless:true,args:['--no-sandbox']});
  const p=await b.newPage();
  await p.setViewport({width:1600,height:900});
  await p.goto('file:///'+path.resolve(__dirname,'capture-static.html').replace(/\\/g,'/'),{waitUntil:'networkidle0',timeout:30000});
  await p.evaluateHandle('document.fonts.ready');
  await p.screenshot({path:path.resolve(__dirname,'ad-phenomenal.png'),fullPage:false});
  await b.close();
  console.log('Done');
})();