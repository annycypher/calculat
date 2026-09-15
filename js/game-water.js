// js/game-water.js
// Игра «Water Glow» (сортировка жидкостей) для страницы /games/water/.
// Исходник — gameswater.html из папки заказчика; логика не менялась, добавлено только
// зеркало уровня в #score (его использует общий модуль рейтинга/мотивации).
// Всё считается в браузере: данные никуда не отправляются, звук — WebAudio без файлов.
(function(){
'use strict';
const $=id=>document.getElementById(id);
const cv=$('cv'), ctx=cv.getContext('2d'), stage=$('stage');
const CAP=4, LIFT=150, BACK=170, STEP=155;
const PAL=[
  {c:'#ff8fb2',g:'255,143,178'},
  {c:'#ffd479',g:'255,212,121'},
  {c:'#6fd3f2',g:'111,211,242'},
  {c:'#a78bfa',g:'167,139,250'},
  {c:'#7ee2a8',g:'126,226,168'},
  {c:'#6e8bff',g:'110,139,255'},
  {c:'#ff9d76',g:'255,157,118'}
];
const lvlEl=$('lvl'), movesEl=$('moves'), bestEl=$('best'), scoreEl=$('score');
const overEl=$('over'), overTitle=$('overTitle'), overText=$('overText');
const btnUndo=$('btnUndo'), btnExtra=$('btnExtra'), deadEl=$('dead');

function sGet(k){ try{return localStorage.getItem(k);}catch(e){return null;} }
function sSet(k,v){ try{localStorage.setItem(k,v);}catch(e){} }

let level=Math.max(1, +sGet('wsort-level')||1);
let best=Math.max(1, +sGet('wsort-best')||1);
let soundOn=sGet('wsort-sound')!=='0';
let totalMoves=+sGet('wsort-moves')||0;
let sessionSolved=0;

let tubes=[], geom=[], W=0, H=0, tw=56, th=170;
let selected=-1, pour=null, history=[], moves=0, usedExtra=false;
let glow={}, shake={}, liftCur={}, sparkParts=[], confetti=[];
let won=false, lastT=0;
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));

let AC=null;
function ac(){
  if(!AC){ try{AC=new (window.AudioContext||window.webkitAudioContext)();}catch(e){} }
  if(AC&&AC.state==='suspended') AC.resume();
  return AC;
}
function tone(f,dur,type,vol,slide,when){
  if(!soundOn) return; const a=ac(); if(!a) return;
  const t=a.currentTime+(when||0);
  const o=a.createOscillator(), g=a.createGain();
  o.type=type||'sine'; o.frequency.setValueAtTime(f,t);
  if(slide) o.frequency.linearRampToValueAtTime(slide,t+dur);
  g.gain.setValueAtTime(vol||.06,t);
  g.gain.exponentialRampToValueAtTime(.0001,t+dur);
  o.connect(g).connect(a.destination); o.start(t); o.stop(t+dur+.02);
}
const sSel  =()=>tone(640,.05,'sine',.04);
const sBad  =()=>tone(150,.12,'triangle',.06,110);
const sPour =()=>tone(320,.18,'sine',.035,620);
const sGlug =n=>tone(560-n*45,.09,'sine',.06,Math.max(120,320-n*25));
const sDone =()=>{tone(660,.14,'sine',.06);tone(990,.2,'sine',.05,null,.07);};
const sWinS =()=>[523,659,784,1046].forEach((f,i)=>tone(f,.16,'sine',.06,null,i*.085));
const sUndo =()=>tone(420,.08,'sine',.04,300);
const sExtra=()=>tone(500,.1,'sine',.05,700);

function solveOK(start){
  const seen=new Set(); let nodes=0;
  const key=st=>st.map(u=>u.join('.')).sort().join('|');
  const isWinSt=st=>st.every(u=>u.length===0||(u.length===CAP&&u.every(c=>c===u[0])));
  function dfs(st,d){
    if(isWinSt(st)) return true;
    if(d>110||++nodes>25000) return false;
    const k=key(st); if(seen.has(k)) return false; seen.add(k);
    for(let i=0;i<st.length;i++){
      const a=st[i]; if(!a.length) continue;
      const top=a[a.length-1]; let run=1;
      for(let j=a.length-2;j>=0&&a[j]===top;j--) run++;
      for(let j2=0;j2<st.length;j2++){
        if(i===j2) continue;
        const b=st[j2];
        if(b.length>=CAP) continue;
        if(b.length&&b[b.length-1]!==top) continue;
        if(!b.length&&run===a.length) continue;
        const mv=Math.min(run,CAP-b.length);
        const ns=st.map((u,x)=>{
          if(x===j2){const nb=u.slice();for(let q=0;q<mv;q++)nb.push(top);return nb;}
          if(x===i) return u.slice(0,u.length-mv);
          return u;
        });
        if(dfs(ns,d+1)) return true;
      }
    }
    return false;
  }
  return dfs(start.map(u=>u.slice()),0);
}
function genTubes(cc){
  let last=null;
  for(let a=0;a<60;a++){
    const pool=[];
    for(let c=0;c<cc;c++) for(let k=0;k<CAP;k++) pool.push(c);
    for(let i=pool.length-1;i>0;i--){const j=(Math.random()*(i+1))|0;const tmp=pool[i];pool[i]=pool[j];pool[j]=tmp;}
    const t=[];
    for(let c=0;c<cc;c++) t.push(pool.slice(c*CAP,(c+1)*CAP));
    t.push([]); t.push([]);
    last=t;
    if(solveOK(t)) return t;
  }
  return last;
}

function pourInfo(from,to){
  const a=tubes[from], b=tubes[to];
  if(!a.length) return null;
  if(b.length>=CAP) return null;
  const top=a[a.length-1];
  if(b.length&&b[b.length-1]!==top) return null;
  let run=1;
  for(let k=a.length-2;k>=0&&a[k]===top;k--) run++;
  const mv=Math.min(run,CAP-b.length);
  return mv>0?{count:mv,color:top}:null;
}
function isComplete(i){const a=tubes[i];return a.length===CAP&&a.every(c=>c===a[0]);}
function isWin(){for(let i=0;i<tubes.length;i++){const a=tubes[i];if(a.length&&!(a.length===CAP&&a.every(c=>c===a[0])))return false;}return true;}
function existsMove(){
  for(let i=0;i<tubes.length;i++)for(let j=0;j<tubes.length;j++)
    if(i!==j&&pourInfo(i,j)) return true;
  return false;
}

function startPour(from,to,info){
  pour={
    from,to,color:info.color,count:info.count,
    srcBefore:tubes[from].slice(), dstBefore:tubes[to].slice(),
    t:0, flow:0, lastFull:0,
    tilt:(geom[to].cx>=geom[from].cx?1:-1)*(46*Math.PI/180),
    _x:0,_y:0,_r:0
  };
  sPour();
}
function finishPour(){
  const p=pour;
  tubes[p.from]=p.srcBefore.slice(0,p.srcBefore.length-p.count);
  tubes[p.to]=p.dstBefore.slice();
  for(let k=0;k<p.count;k++) tubes[p.to].push(p.color);
  history.push({from:p.from,to:p.to,count:p.count,color:p.color,movesBefore:moves});
  moves++; totalMoves++; sSet('wsort-moves',totalMoves);
  const wasC=p.dstBefore.length===CAP&&p.dstBefore.every(c=>c===p.color);
  const nowC=isComplete(p.to);
  if(nowC&&!wasC){ glow[p.to]=performance.now(); sparkleBurst(p.to); sDone(); }
  if(!nowC) delete glow[p.to];
  pour=null;
  refresh();
  if(isWin()){ winSequence(); return; }
  if(!existsMove()) deadEl.classList.add('on');
}
function updatePour(dt){
  pour.t+=dt*1000;
  const TR=pour.count*STEP;
  if(pour.t>=LIFT&&pour.t<LIFT+TR){
    pour.flow=pour.count*Math.min(1,(pour.t-LIFT)/TR);
    const units=Math.floor(pour.flow+1e-6);
    if(units>pour.lastFull){
      pour.lastFull=units;
      splash(geom[pour.to].cx, surfaceY(pour.to,pour.dstBefore.length+units), pour.color);
      sGlug(units);
    }
  }
  if(pour.t>=LIFT+TR+BACK) finishPour();
}
function undo(){
  if(!history.length||pour||won) return;
  const h=history.pop();
  for(let k=0;k<h.count;k++) tubes[h.from].push(h.color);
  tubes[h.to]=tubes[h.to].slice(0,tubes[h.to].length-h.count);
  moves=h.movesBefore;
  if(!isComplete(h.to)) delete glow[h.to];
  deadEl.classList.remove('on');
  sUndo(); refresh();
}
function addTube(){
  if(usedExtra||pour||won) return;
  usedExtra=true; selected=-1;
  tubes.push([]); layout(); sExtra(); refresh();
}

function initLevel(){
  tubes=genTubes(Math.min(2+level,7));
  history=[]; moves=0; usedExtra=false; won=false; selected=-1; pour=null;
  glow={}; shake={}; liftCur={}; sparkParts=[]; confetti=[];
  overEl.classList.remove('on'); deadEl.classList.remove('on');
  refresh(); layout();
}
function winSequence(){
  won=true; sessionSolved++;
  if(level+1>best){ best=level+1; }
  sSet('wsort-level',level+1); sSet('wsort-best',best);
  for(let i=0;i<130;i++){
    confetti.push({
      x:Math.random()*W, y:-20-Math.random()*160,
      vx:(Math.random()-.5)*2, vy:1.6+Math.random()*2.6,
      rot:Math.random()*6.3, vr:(Math.random()-.5)*.25,
      s:5+Math.random()*5, col:PAL[(Math.random()*PAL.length)|0].c
    });
  }
  sWinS(); refresh();
  setTimeout(()=>{
    overTitle.textContent='Уровень '+level+' пройден!';
    overText.textContent='Ходов: '+moves+(moves<=Math.min(2+level,7)*3?' · блестяще!':'');
    overEl.classList.add('on');
  },900);
}
function refresh(){
  lvlEl.textContent=level; if(scoreEl) scoreEl.textContent=level;
  movesEl.textContent=moves;
  bestEl.textContent=best;
  btnUndo.classList.toggle('off',!history.length||!!pour||won);
  btnExtra.classList.toggle('off',usedExtra||won);
  syncStats();
}
function syncStats(){
  const a=$('statLvl'), b=$('statSolved'), c=$('statMoves');
  if(a) a.textContent=level;
  if(b) b.textContent=sessionSolved;
  if(c) c.textContent=totalMoves.toLocaleString('ru-RU');
}

function layout(){
  W=stage.clientWidth;
  const n=tubes.length;
  const rows=n>5?2:1;
  const perRow=rows===1?n:Math.ceil(n/2);
  const gapX=12;
  tw=Math.max(40,Math.min(
    64,
    (W-20-gapX*(perRow-1))/perRow,
    ((innerHeight-230)/rows-(rows>1?17:0))/3
  ));
  th=Math.round(tw*3);
  const rowGap=34, padTop=26, padBot=14;
  H=padTop+rows*th+(rows-1)*rowGap+padBot;
  const dpr=Math.min(devicePixelRatio||1,2);
  cv.width=W*dpr; cv.height=H*dpr;
  cv.style.height=H+'px';
  ctx.setTransform(dpr,0,0,dpr,0,0);
  geom=[];
  for(let i=0;i<n;i++){
    const r=i<perRow?0:1;
    const cnt=r===0?perRow:n-perRow;
    const idx=r===0?i:i-perRow;
    const rowW=cnt*tw+(cnt-1)*gapX;
    const x0=(W-rowW)/2;
    const x=x0+idx*(tw+gapX);
    geom.push({x, y:padTop+r*(th+rowGap), cx:x+tw/2});
  }
}
function surfaceY(i,filled){
  const g=geom[i];
  const iy=g.y+(tw*0.135)*0.7;
  const ih=th-(tw*0.135)*0.7-(tw*0.135)*0.55;
  return iy+ih-filled*(ih/CAP);
}
function splash(x,y,ci){
  const col=PAL[ci];
  for(let i=0;i<6;i++){
    sparkParts.push({x,y,vx:(Math.random()-.5)*3,vy:-(1+Math.random()*1.8),life:1,dec:.035,sz:1.5+Math.random()*1.6,col:col.c,star:false});
  }
}
function sparkleBurst(i){
  const g=geom[i], col=tubes[i][0];
  for(let k=0;k<10;k++){
    sparkParts.push({
      x:g.cx+(Math.random()-.5)*tw, y:surfaceY(i,CAP)-Math.random()*20,
      vx:(Math.random()-.5)*.8, vy:-(.5+Math.random()*1),
      life:1, dec:.018, sz:3+Math.random()*2.5,
      r:Math.random()*6.3, vr:(Math.random()-.5)*.2, col:PAL[col].c, star:true
    });
  }
}
function tubePath(x,y,w,h){
  const r=w*.32;
  ctx.beginPath();
  ctx.moveTo(x,y);
  ctx.lineTo(x,y+h-r);
  ctx.quadraticCurveTo(x,y+h,x+r,y+h);
  ctx.lineTo(x+w-r,y+h);
  ctx.quadraticCurveTo(x+w,y+h,x+w,y+h-r);
  ctx.lineTo(x+w,y);
  ctx.closePath();
}
function getSegs(i){
  if(!pour||(pour.from!==i&&pour.to!==i)) return tubes[i].map(c=>({c,f:1}));
  if(pour.from===i){
    const segs=pour.srcBefore.map(c=>({c,f:1}));
    let rem=pour.flow;
    for(let k=segs.length-1;k>=0&&rem>1e-4;k--){
      const d=Math.min(segs[k].f,rem); segs[k].f-=d; rem-=d;
    }
    return segs;
  }
  const segs=pour.dstBefore.map(c=>({c,f:1}));
  const full=Math.min(pour.count,Math.floor(pour.flow+1e-6));
  const frac=pour.flow-full;
  for(let k=0;k<full;k++) segs.push({c:pour.color,f:1});
  if(frac>1e-4&&full<pour.count) segs.push({c:pour.color,f:frac});
  return segs;
}
function drawTubeBody(bx,by,w,h,segs,glowCol,t,stA){
  const wall=w*.135;
  const ix=bx+wall, iy=by+wall*.7, iw=w-2*wall;
  const ih=h-wall*.7-wall*.55, ibot=iy+ih, unitH=ih/CAP;
  ctx.save();
  tubePath(ix-1,iy-2,iw+2,ih+4); ctx.clip();
  let topK=-1;
  for(let k=0;k<segs.length;k++){
    const s=segs[k]; if(s.f<=.004) continue;
    const slotBot=ibot-k*unitH;
    ctx.fillStyle='rgba('+PAL[s.c].g+',.9)';
    ctx.fillRect(ix,slotBot-s.f*unitH+2,iw,s.f*unitH);
    topK=k;
  }
  if(topK>=0){
    const s=segs[topK], col=PAL[s.c];
    const surf=ibot-topK*unitH-s.f*unitH;
    const ph=t/280;
    ctx.fillStyle='rgba('+col.g+',.95)';
    ctx.beginPath();
    ctx.moveTo(ix,surf+2);
    for(let xx=0;xx<=iw;xx+=4) ctx.lineTo(ix+xx,surf+Math.sin(xx/10+ph)*2.2);
    ctx.lineTo(ix+iw,surf+6); ctx.lineTo(ix,surf+6); ctx.closePath(); ctx.fill();
    ctx.strokeStyle='rgba(255,255,255,.35)'; ctx.lineWidth=1.2;
    ctx.beginPath();
    for(let xx=0;xx<=iw;xx+=4){
      const yy=surf+Math.sin(xx/10+ph)*2.2;
      xx===0?ctx.moveTo(ix+xx,yy):ctx.lineTo(ix+xx,yy);
    }
    ctx.stroke();
  }
  const sh=ctx.createLinearGradient(ix,0,ix+iw,0);
  sh.addColorStop(0,'rgba(0,0,0,.2)'); sh.addColorStop(.14,'rgba(0,0,0,0)');
  sh.addColorStop(.86,'rgba(0,0,0,0)'); sh.addColorStop(1,'rgba(0,0,0,.14)');
  ctx.fillStyle=sh; ctx.fillRect(ix,iy-2,iw,ih+4);
  ctx.restore();
  const bg=ctx.createLinearGradient(0,by,0,by+h);
  bg.addColorStop(0,'rgba(255,255,255,.09)'); bg.addColorStop(1,'rgba(255,255,255,.03)');
  tubePath(bx+1,by+1,w-2,h-2);
  ctx.fillStyle=bg; ctx.fill();
  ctx.save();
  if(glowCol){ ctx.shadowColor=glowCol; ctx.shadowBlur=16+6*Math.sin(t/300); }
  ctx.strokeStyle='rgba(255,255,255,'+stA+')'; ctx.lineWidth=1.6;
  tubePath(bx+1,by+1,w-2,h-2); ctx.stroke();
  ctx.restore();
  const hg=ctx.createLinearGradient(0,by+h*.1,0,by+h*.85);
  hg.addColorStop(0,'rgba(255,255,255,0)'); hg.addColorStop(.5,'rgba(255,255,255,.18)'); hg.addColorStop(1,'rgba(255,255,255,0)');
  ctx.fillStyle=hg; ctx.fillRect(bx+w*.14,by+h*.1,w*.09,h*.75);
  ctx.fillStyle='rgba(255,255,255,.09)'; ctx.fillRect(bx+w*.8,by+h*.12,w*.045,h*.66);
  ctx.strokeStyle='rgba(255,255,255,.55)'; ctx.lineWidth=2.4; ctx.lineCap='round';
  ctx.beginPath(); ctx.moveTo(bx+2,by+1); ctx.lineTo(bx+w-2,by+1); ctx.stroke();
}
function drawTube(i,t){
  const g=geom[i];
  let x=g.x, y=g.y, rot=0;
  const target=(selected===i)?-12:0;
  liftCur[i]=liftCur[i]||0;
  liftCur[i]+=(target-liftCur[i])*.25;
  y+=liftCur[i];
  if(pour&&pour.from===i){
    const p=pour, tg=geom[p.to];
    const px_=clamp(tg.cx-Math.sign(p.tilt)*th*.62-tw/2,6,W-tw-6);
    const py_=Math.max(6,tg.y-th-10);
    if(p.t<LIFT){
      const k=1-Math.pow(1-p.t/LIFT,3);
      x=g.x+(px_-g.x)*k; y=g.y+(py_-g.y)*k; rot=p.tilt*(p.t/LIFT);
    }else if(p.t<LIFT+p.count*STEP){ x=px_; y=py_; rot=p.tilt; }
    else{
      const q=Math.min(1,(p.t-LIFT-p.count*STEP)/BACK);
      x=px_+(g.x-px_)*q*q; y=py_+(g.y-py_)*q*q; rot=p.tilt*(1-Math.min(1,q*1.3));
    }
    p._x=x; p._y=y; p._r=rot;
  }
  if(shake[i]&&t-shake[i]<300){
    x+=Math.sin((t-shake[i])*.09)*3.5*(1-(t-shake[i])/300);
  }
  let glowCol=null, stA=.34;
  if(glow[i]&&isComplete(i)){
    glowCol=PAL[tubes[i][tubes[i].length-1]].c; stA=.75;
    if(Math.random()<.04&&sparkParts.length<80){
      sparkParts.push({x:g.cx+(Math.random()-.5)*tw*.7,y:g.y+th*.2,vx:0,vy:-(.4+Math.random()*.5),life:1,dec:.02,sz:2.5+Math.random()*2,r:Math.random()*6.3,vr:.1,col:glowCol,star:true});
    }
  } else if(selected===i){ stA=.9; }
  ctx.save();
  ctx.translate(x+tw/2,y+th);
  ctx.rotate(rot);
  if(selected===i&&!glowCol){ ctx.shadowColor='#6fd3f2'; ctx.shadowBlur=18; }
  drawTubeBody(-tw/2,-th,tw,th,getSegs(i),glowCol,t,stA);
  ctx.restore();
}
function drawStream(){
  const p=pour, tg=geom[p.to];
  const mx=p._x+tw/2+th*Math.sin(p._r);
  const my=p._y+th-th*Math.cos(p._r);
  const ex=tg.cx, ey=surfaceY(p.to,p.dstBefore.length+pour.flow)-1;
  ctx.save();
  ctx.strokeStyle='rgba('+PAL[p.color].g+',.9)';
  ctx.lineWidth=4.5; ctx.lineCap='round';
  ctx.shadowColor=PAL[p.color].c; ctx.shadowBlur=10;
  ctx.beginPath(); ctx.moveTo(mx,my);
  ctx.quadraticCurveTo((mx+ex)/2,Math.min(my,ey)-24,ex,ey);
  ctx.stroke();
  ctx.restore();
}
function drawFx(t){
  ctx.save();
  ctx.globalCompositeOperation='lighter';
  for(let i=sparkParts.length-1;i>=0;i--){
    const p=sparkParts[i];
    p.life-=p.dec;
    if(p.life<=0){sparkParts.splice(i,1);continue;}
    p.x+=p.vx; p.y+=p.vy; if(p.vr)p.r+=p.vr;
    ctx.globalAlpha=p.life;
    if(p.star){
      ctx.save(); ctx.translate(p.x,p.y); ctx.rotate(p.r||0);
      ctx.fillStyle=p.col;
      const s=p.sz*p.life;
      ctx.beginPath();
      ctx.moveTo(0,-s);ctx.lineTo(s*.3,-s*.3);ctx.lineTo(s,0);ctx.lineTo(s*.3,s*.3);
      ctx.lineTo(0,s);ctx.lineTo(-s*.3,s*.3);ctx.lineTo(-s,0);ctx.lineTo(-s*.3,-s*.3);
      ctx.closePath(); ctx.fill(); ctx.restore();
    }else{
      p.vy+=.08;
      ctx.fillStyle=p.col;
      ctx.beginPath(); ctx.arc(p.x,p.y,Math.max(.3,p.sz*p.life),0,7); ctx.fill();
    }
  }
  ctx.restore();
  ctx.globalAlpha=1;
  for(let i=confetti.length-1;i>=0;i--){
    const c=confetti[i];
    c.x+=c.vx; c.y+=c.vy; c.vy+=.05; c.rot+=c.vr;
    if(c.y>H+20){confetti.splice(i,1);continue;}
    ctx.save(); ctx.translate(c.x,c.y); ctx.rotate(c.rot);
    ctx.fillStyle=c.col; ctx.globalAlpha=.9;
    ctx.fillRect(-c.s/2,-c.s/4,c.s,c.s/2);
    ctx.restore();
  }
  ctx.globalAlpha=1;
}
function draw(t){
  ctx.clearRect(0,0,W,H);
  const idxs=tubes.map((_,i)=>i);
  idxs.sort((a,b)=>((pour&&pour.from===b)?1:0)-((pour&&pour.from===a)?1:0));
  for(const i of idxs) drawTube(i,t);
  if(pour&&pour.t>=LIFT&&pour.t<LIFT+pour.count*STEP) drawStream();
  drawFx(t);
}
function loop(t){
  const dt=Math.min((t-lastT)/1000,.04)||0; lastT=t;
  if(pour) updatePour(dt);
  draw(t);
  requestAnimationFrame(loop);
}
cv.addEventListener('pointerdown',e=>{
  ac();
  if(won||pour) return;
  const r=cv.getBoundingClientRect();
  const x=e.clientX-r.left, y=e.clientY-r.top;
  let hit=-1;
  for(let i=0;i<geom.length;i++){
    const g=geom[i];
    if(x>=g.x-4&&x<=g.x+tw+4&&y>=g.y-16&&y<=g.y+th+6){hit=i;break;}
  }
  if(hit<0){ selected=-1; return; }
  if(selected<0){
    if(!tubes[hit].length){ shake[hit]=performance.now(); tone(150,.08,'triangle',.05); return; }
    selected=hit; sSel(); return;
  }
  if(selected===hit){ selected=-1; return; }
  const info=pourInfo(selected,hit);
  if(!info){ shake[hit]=performance.now(); sBad(); return; }
  const from=selected; selected=-1;
  deadEl.classList.remove('on');
  startPour(from,hit,info);
});
 $('btnUndo').addEventListener('click',undo);
 $('btnExtra').addEventListener('click',addTube);
 $('btnRestart').addEventListener('click',()=>{ initLevel(); });
 $('btnNext').addEventListener('click',()=>{ level++; initLevel(); });
 $('btnRetryO').addEventListener('click',()=>{ initLevel(); });
 $('soundBtn').addEventListener('click',()=>{
  soundOn=!soundOn; sSet('wsort-sound',soundOn?'1':'0');
  $('sndOn').style.display=soundOn?'':'none';
  $('sndOff').style.display=soundOn?'none':'';
});
addEventListener('resize',layout);
initLevel();
requestAnimationFrame(loop);
})();