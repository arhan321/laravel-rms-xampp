(()=>{
 'use strict';
 const data=window.__RMS_HEALTH_CHART__; const canvas=document.getElementById('hi-trend-chart');
 const picker=document.getElementById('hi-axis-picker'); const caption=document.getElementById('hi-baseline-caption');
 if(!data||!canvas||!picker)return;
 const context=canvas.getContext('2d');if(!context)return;
 function render(){
  const ax=picker.value, rows=(data.series||[]).map(x=>({x:x.at,y:x.axes?x.axes[ax]:null,job:x.job})).filter(x=>Number.isFinite(x.y));
  // Job-specific baselines must not be compared with mixed or unidentified jobs.
  const jobs=[...new Set(rows.map(x=>x.job||'__unspecified__'))];
  const job=jobs.length===1?jobs[0]:null;
  const baseline=job?(data.baselines||[]).find(x=>x.axis===ax&&x.job_key===job&&x.mad>0):null;
  const W=900,H=370,L=60,R=24,T=26,B=45;
  context.clearRect(0,0,W,H);context.fillStyle='#14293e';context.fillRect(0,0,W,H);
  if(!rows.length){caption.textContent='No valid telemetry for '+ax;context.fillStyle='#bdcee0';context.font='16px sans-serif';context.fillText('Telemetry history not available',L+20,H/2);return;}
  const values=rows.map(x=>x.y);
  if(baseline){values.push(baseline.median,baseline.median+3.5*1.4826*baseline.mad,Math.max(0,baseline.median-3.5*1.4826*baseline.mad));}
  const lo=Math.min(...values),hi=Math.max(...values),pad=Math.max((hi-lo)*.1,.001),mn=Math.max(0,lo-pad),mx=hi+pad;
  const xp=i=>L+i*(W-L-R)/Math.max(rows.length-1,1);
  const yp=y=>H-B-(y-mn)*(H-T-B)/(mx-mn||1);
  context.strokeStyle='rgba(205,230,250,.12)';context.fillStyle='#a6c3dd';context.font='12px sans-serif';
  for(let i=0;i<=4;i++){const y=T+i*(H-T-B)/4;context.beginPath();context.moveTo(L,y);context.lineTo(W-R,y);context.stroke();context.fillText((mx-(mx-mn)*i/4).toFixed(2),5,y+5);}
  if(baseline){
   const span=3.5*1.4826*baseline.mad,top=yp(baseline.median+span),bottom=yp(Math.max(0,baseline.median-span));
   context.fillStyle='rgba(255,197,85,.09)';context.fillRect(L,top,W-L-R,bottom-top);
   context.strokeStyle='#ffc973';context.setLineDash([7,5]);context.beginPath();context.moveTo(L,yp(baseline.median));context.lineTo(W-R,yp(baseline.median));context.stroke();context.setLineDash([]);
  }
  context.strokeStyle='#56c9f1';context.lineWidth=2.5;context.beginPath();
  rows.forEach((p,i)=>i?context.lineTo(xp(i),yp(p.y)):context.moveTo(xp(i),yp(p.y)));context.stroke();
  context.fillStyle='#b4cce1';context.font='12px sans-serif';context.fillText(rows[0].x,L,H-16);
  if(rows.length>1){let txt=rows[rows.length-1].x;context.fillText(txt,W-R-context.measureText(txt).width,H-16);}
  caption.textContent=baseline?`Job ${job==='__unspecified__'?'UNKNOWN':job} · reference ${baseline.median.toFixed(3)} · comparison range available`:(job===null?'Mixed jobs; reference hidden':'Reference unavailable for this job/axis');
 }
 picker.addEventListener('change',render);render();
})();
