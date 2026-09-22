// js/calc-dosrochnoe.js
// Калькулятор досрочного погашения — /calculators/finance/dosrochnoe/ (кластер «Досрочное погашение»).
// Три стратегии: сокращение срока, уменьшение платежа, гибрид «плати по-старому».Считает только браузер.
// Источник: Досрочное погашение/early-strategy.js (перенесён как есть, логика не менялась).
// 22.09.2026: исправлена досрочка с первого месяца. Условие month===earlyMonth-1 при
// earlyMonth=1 не срабатывало (месяца 0 в цикле нет), поэтому «внести с 1-го месяца»
// не давало эффекта. Теперь при earlyMonth=1 сумма применяется до первого платежа —
// в simulate() и в отдельном цикле стратегии «уменьшение платежа»; расчёты для
// остальных месяцев не изменились (проверено сверкой до и после правки).

(function(){
'use strict';
const $=id=>document.getElementById(id);
const fmt=n=>Math.round(n).toLocaleString('ru-RU')+' ₽';
const months=n=>{const y=Math.floor(n/12),m=n%12;return (y?y+' г ':'')+m+' м';};

/* аннуитетный платёж */
function annuity(S,rate,months){
  const i=rate/100/12;
  if(i<=0)return S/months;
  return S*i*Math.pow(1+i,months)/(Math.pow(1+i,months)-1);
}
/* симуляция графика с досрочкой каждый месяц сверх обязательного */
function simulate(S,rate,years,earlyAmt,earlyMonth,mode){
  const i=rate/100/12;
  let balance=S, month=0, totalPaid=0, totalInterest=0;
  const basePay=annuity(S,rate,Math.round(years*12));
  let pay=basePay; // обязательный
  const sched=[];
  const keepPaying=basePay; // сколько фактически вносим (старая сумма)
  // Досрочка с первого месяца вносится до первого платежа: у цикла нет месяца 0,
  // поэтому условие month===earlyMonth-1 при earlyMonth=1 не срабатывало и сумма
  // «внести с 1-го месяца» пропадала. Применяем её здесь, до цикла.
  if(earlyMonth===1 && earlyAmt>0){
    balance-=earlyAmt; totalPaid+=earlyAmt;
    sched.push({m:1,early:earlyAmt});
    if(mode!=='shorten' && balance>0) pay=annuity(balance,rate,Math.round(years*12));
  }
  while(balance>0.5 && month<600){
    month++;
    const interest=balance*i;
    let principal=pay-interest;
    if(principal<=0) return {error:'Платёж не покрывает проценты'};
    if(balance<principal) principal=balance;
    balance-=principal;
    totalPaid+=pay; totalInterest+=interest;
    sched.push({m:month,pay,extra:0,interest,body:principal,bal:Math.max(0,balance)});
    // досрочка на earlyMonth
    if(earlyMonth>1 && month===earlyMonth-1 && earlyAmt>0){
      balance-=earlyAmt; totalPaid+=earlyAmt;
      sched.push({m:month,early:earlyAmt});
      if(mode==='shorten'){ /* срок: платёж прежний, срок пересчитается сам */ }
      else if(mode==='reduce'){ pay=annuity(balance,rate,Math.round(years*12)-month); }
      else if(mode==='oldpay'){ pay=annuity(balance,rate,Math.round(years*12)-month);
        /* платим по-старому: keepPaying - pay = ежемесячная досрочка */ }
    }
    // для oldpay: разница между keepPaying и текущим pay — доп. досрочка
    if(mode==='oldpay' && month>=earlyMonth){
      const extra=Math.max(0,keepPaying-pay);
      if(extra>0 && balance>extra){
        balance-=extra; totalPaid+=extra; totalInterest-=0; // в след. мес.
        sched.push({m:month,extra});
      }
    }
  }
  return {months:month,interest:totalInterest,finalPay:pay,sched};
}
/* базовая (без досрочки) */
function baseCase(){
  const S=parseFloat($('sum').value)||0, r=parseFloat($('rate').value)||0, y=parseFloat($('years').value)||0;
  const n=Math.round(y*12), pay=annuity(S,r,n);
  return {pay,interest:pay*n-S,months:n};
}
let lastSim=null;
function calc(){
  const S=parseFloat($('sum').value)||0, r=parseFloat($('rate').value)||0, y=parseFloat($('years').value)||0;
  const E=parseFloat($('early').value)||0, EM=Math.max(1,parseInt($('earlyMonth').value)||1);
  const mode=document.querySelector('input[name=strategy]:checked').value;
  if(S<=0||r<=0||y<=0){reset();return;}
  const base=baseCase();
  // shorten
  const s1=simulate(S,r,y,E,EM,'shorten');
  // oldpay
  const s2=simulate(S,r,y,E,EM,'oldpay');
  // reduce (после досрочки платим только новый обязательный)
  const s3=(function(){
    const i=r/100/12;
    let bal=S, m=0, paid=0, int=0;
    let pay=annuity(S,r,Math.round(y*12));
    // Та же правка, что в simulate(): досрочка с первого месяца вносится до цикла.
    if(EM===1 && E>0){ bal-=E; paid+=E; if(bal>0) pay=annuity(bal,r,Math.round(y*12)); }
    while(bal>0.5 && m<600){
      m++;
      const interest=bal*i;
      let pr=pay-interest; if(pr<=0)break;
      if(bal<pr)pr=bal;
      bal-=pr; paid+=pay; int+=interest;
      if(EM>1 && m===EM-1 && E>0){ bal-=E; paid+=E;
        pay=annuity(bal,r,Math.round(y*12)-m); }
    }
    return {months:m,interest:int,finalPay:pay};
  })();
  $('tShortenTerm').textContent=months(s1.months);
  $('tShortenInt').textContent=fmt(s1.interest);
  $('tShortenPay').textContent=fmt(base.pay);
  $('tShortenFact').textContent=fmt(base.pay);
  $('tShortenSave').textContent=fmt(base.interest-s1.interest);
  $('tOldTerm').textContent=months(s2.months);
  $('tOldInt').textContent=fmt(s2.interest);
  $('tOldPay').textContent=fmt(s2.finalPay)+' ↓';
  $('tOldFact').textContent=fmt(base.pay);
  $('tOldSave').textContent=fmt(base.interest-s2.interest);
  $('tRedTerm').textContent=months(s3.months);
  $('tRedInt').textContent=fmt(s3.interest);
  $('tRedPay').textContent=fmt(s3.finalPay)+' ↓';
  $('tRedFact').textContent=fmt(s3.finalPay);
  $('tRedSave').textContent=fmt(base.interest-s3.interest);
  // бары
  const maxI=Math.max(base.interest,s1.interest,s2.interest,s3.interest);
  const set=(id,val)=>{const el=$(id);el.style.width=(val/maxI*100)+'%';$(id.replace('bar','val')).textContent=fmt(val);};
  set('bar0',base.interest);set('bar1',s1.interest);set('bar2',s2.interest);set('bar3',s3.interest);
  // вердикт
  const saveOld=base.interest-s2.interest, saveShort=base.interest-s1.interest;
  const loss=saveShort-saveOld;
  $('verdict').innerHTML='<b>Стратегия «Плати по-старому»:</b> обязательный платёж снижен до '+
    fmt(s2.finalPay)+' — страховка при падении дохода. Экономия '+fmt(saveOld)+
    ' против '+fmt(saveShort)+' при сокращении срока. Разница ('+fmt(loss)+
    ') — плата за право платить меньше в трудный месяц. '+
    'Комбинируйте: применяйте стратегию циклами, в трудный месяц платите только обязательный.';
  // график (oldpay)
  buildSchedule(s2);
  lastSim={base,s1,s2,s3};
}
function reset(){['tShortenTerm','tShortenInt','tShortenPay','tShortenFact','tShortenSave',
 'tOldTerm','tOldInt','tOldPay','tOldFact','tOldSave','tRedTerm','tRedInt','tRedPay',
 'tRedFact','tRedSave'].forEach(i=>$(i).textContent='—');
 ['bar0','bar1','bar2','bar3'].forEach(i=>$(i).style.width='0');
 ['val0','val1','val2','val3'].forEach(i=>$(i).textContent='—');
 $('verdict').textContent='Введите параметры кредита.';$('schedBody').innerHTML='';}
function buildSchedule(sim){
  const tb=$('schedBody'); tb.innerHTML='';
  if(!sim.sched)return;
  let bal=parseFloat($('sum').value)||0, m=0;
  sim.sched.forEach(r=>{
    m++;
    const tr=document.createElement('tr');
    tr.innerHTML=`<td>${m}</td><td>${r.pay!==undefined?fmt(r.pay):'досрочно'}</td>`+
      `<td>${r.early!==undefined?fmt(r.early):'—'}</td>`+
      `<td>${r.interest!==undefined?fmt(r.interest):'—'}</td>`+
      `<td>${r.body!==undefined?fmt(r.body):'—'}</td>`+
      `<td>${fmt(r.bal??bal)}</td>`;
    if(r.early!==undefined&&r.pay===undefined){ bal-=r.early; tr.children[5].textContent=fmt(bal); }
    else if(r.bal!==undefined) bal=r.bal;
    tb.appendChild(tr);
  });
}
['sum','rate','years','early','earlyMonth'].forEach(id=>$(id).addEventListener('input',calc));
document.querySelectorAll('input[name=strategy]').forEach(r=>
  r.addEventListener('change',()=>{
    document.querySelectorAll('.strat label').forEach(l=>l.classList.remove('sel'));
    r.closest('label').classList.add('sel'); calc();
  }));
 $('schedToggle').addEventListener('click',()=>{
  const s=$('schedule'); s.classList.toggle('on');
  $('schedToggle').textContent=s.classList.contains('on')
    ?'▲ Скрыть график платежей':'▼ Показать график платежей (стратегия «Плати по-старому»)';
});
 $('copyBtn').addEventListener('click',()=>{
  const t='Стратегия «Плати по-старому»: срок '+$('tOldTerm').textContent+
    ', переплата '+$('tOldInt').textContent+', обязательный платёж '+
    $('tOldPay').textContent+' (был '+$('tShortenPay').textContent+'), '+
    'экономия '+$('tOldSave').textContent+' (CalcDoc)';
  navigator.clipboard.writeText(t).then(()=>{$('copyBtn').textContent='Скопировано ✓';
    setTimeout(()=>$('copyBtn').textContent='Скопировать сравнение',1800);});
});
 $('pdfBtn').addEventListener('click',()=>{
  alert('В окне печати выберите «Сохранить как PDF».');
  window.print();
});
 $('shareBtn').addEventListener('click',()=>{
  const p=new URLSearchParams({sum:$('sum').value,rate:$('rate').value,
    years:$('years').value,early:$('early').value,em:$('earlyMonth').value,
    st:document.querySelector('input[name=strategy]:checked').value});
  const url=location.origin+location.pathname+'?'+p;
  if(navigator.share)navigator.share({title:'Сравнение стратегий досрочки',url}).catch(()=>{});
  else navigator.clipboard.writeText(url).then(()=>{$('shareBtn').textContent='Ссылка ✓';
    setTimeout(()=>$('shareBtn').textContent='Поделиться',1800);});
});
(function restore(){
  const q=new URLSearchParams(location.search);
  if(q.get('sum'))$('sum').value=q.get('sum');
  if(q.get('rate'))$('rate').value=q.get('rate');
  if(q.get('years'))$('years').value=q.get('years');
  if(q.get('early'))$('early').value=q.get('early');
  if(q.get('em'))$('earlyMonth').value=q.get('em');
  if(q.get('st')){const r=document.querySelector(`input[name=strategy][value="${q.get('st')}"]`);if(r)r.checked=true;}
  calc();
})();
})();