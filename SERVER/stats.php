<?php
require_once __DIR__.'/inc/db.php';
require __DIR__ . '/inc/config.php';

function loadTranslations(string $lang): array {
    $file = __DIR__ . "/lang/{$lang}.php";
    return is_file($file) ? (include $file) : [];
}
$T  = loadTranslations(APP_LANG);
$EN = loadTranslations('en'); // Fallback

function t(string $key, array $vars = []): string {
    global $T, $EN;
    $text = $T[$key] ?? $EN[$key] ?? $key;
    foreach ($vars as $k => $v) { $text = str_replace('{' . $k . '}', (string)$v, $text); }
    return $text;
}
function th(string $key, array $vars = []): string {
    return htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');
}

/* Vergütung (ct/kWh) aus config.php – Komma/Punkt tolerant */
$V_CT_RAW  = isset($verguetung) ? (string)$verguetung : '0';
$V_CT_JS   = str_replace(',', '.', $V_CT_RAW);
?>
<!doctype html>
<html>
<head>
<meta charset='utf-8'/>
<meta name='viewport' content='width=device-width, initial-scale=1'/>
<title>PenguinPVDash – Stats</title>
<link rel='stylesheet' href='assets/style.css'/>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  .controls{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
  .controls .group{background:var(--card);border:1px solid var(--border);padding:8px 10px;border-radius:10px}
  .controls label{font-size:12px;color:#cfe1ff;margin-right:6px}
  .controls select,.controls input{background:#0f1630;color:#eaf2ff;border:1px solid var(--border);border-radius:8px;padding:6px 8px}
  .stats-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}

  .table-wrap .fancy thead th{ white-space:nowrap; }
  .table-wrap .fancy tbody tr:nth-child(odd){ background: rgba(255,255,255,.04); }
  .table-wrap .fancy tbody tr:nth-child(even){ background: rgba(0,0,0,.025); }
  .fancy td.peak{ background: rgba(107,226,159,.18); box-shadow: inset 0 0 0 1px rgba(107,226,159,.35); }
  .fancy td.low { background: rgba(255,99,132,.16);  box-shadow: inset 0 0 0 1px rgba(255,99,132,.30); }
  .fancy tfoot tr.sumrow{
    background: linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.04));
    font-weight: 600; border-top: 3px solid rgba(255,255,255,.35);
  }
  .fancy tfoot tr.sumrow td{ padding-top: 12px; padding-bottom: 10px; vertical-align: top; }
  .fancy tfoot tr.sumrow td:first-child{ letter-spacing:.2px; text-transform:uppercase; opacity:.9; }
  .subsum{ font-weight:500; opacity:.9; margin-top:6px; font-size:.92em; display:none; }
  .reveal-link{ display:inline-block; margin-top:6px; font-size:.9em; opacity:.85; text-decoration:underline; cursor:pointer; }

  /* Chart-Container */
  .chart-card{ margin-top:14px; }
  .chart-wrap{ position:relative; width:100%; height:360px; }
  @media (min-width: 1100px){
    .chart-wrap{ height:420px; }
  }
</style>
</head>
<body>
<div class='wrap'>
  <div class='card'>
    <div class='stats-header'>
      <h1>Stats</h1>
      <a href='./' class='badge'>Zurück</a>
    </div>

    <div class='controls'>
      <div class='group'><label>Gerät</label><input id='device' value='home'/></div>
      <div class='group'>
        <label>Modus</label>
        <select id='mode'>
          <option value='year'>Jahr</option>
          <option value='month'>Monat</option>
          <option value='week'>Woche</option>
          <option value='range'>Bereich</option>
        </select>
      </div>
      <div class='group'><label>Jahr</label><select id='year'></select></div>
      <div class='group' id='month-group'>
        <label>Monat</label>
        <select id='month'>
          <option value='1'>Jan</option><option value='2'>Feb</option><option value='3'>Mär</option><option value='4'>Apr</option>
          <option value='5'>Mai</option><option value='6'>Jun</option><option value='7'>Jul</option><option value='8'>Aug</option>
          <option value='9'>Sep</option><option value='10'>Okt</option><option value='11'>Nov</option><option value='12'>Dez</option>
        </select>
      </div>
      <div class='group' id='week-group'><label>ISO-Woche</label><select id='week'></select></div>
      <div class='group' id='range-group' style='display:none'>
        <label>Von</label><input type='date' id='from'/>
        <label>Bis</label><input type='date' id='to'/>
      </div>
      <div class='group'><button id='apply' class='badge'>Anwenden</button></div>
    </div>

    <div class='table-wrap'>
      <table class='fancy'>
        <thead>
          <tr>
            <th><?= th('t19') ?></th>
            <th><?= th('t20') ?></th>
            <th><?= th('t21') ?></th>
            <th><?= th('t22') ?></th>
            <th><?= th('t23') ?></th>
            <th><?= th('t24') ?></th>
            <th><?= htmlspecialchars((t('t31_gross')==='t31_gross'?'Bruttoverbrauch':t('t31_gross')),ENT_QUOTES,'UTF-8') ?></th>
            <th><?= th('t25') ?></th>
          </tr>
        </thead>
        <tbody id='hist-tbody'></tbody>
        <tfoot id='hist-tfoot'></tfoot>
      </table>
    </div>

    <!-- Charts -->
    <div class="chart-card">
      <div class="card-head"><h2>Diagramm</h2></div>
      <div class="chart-wrap">
        <canvas id="statsChart"></canvas>
      </div>
    </div>

  </div>
</div>

<script>
/* Vergütung in €/kWh (aus PHP, Komma/Punkt tolerant) */
const FEEDIN_CT = parseFloat('<?= $V_CT_JS ?>');
const FEEDIN_EUR_PER_KWH = isFinite(FEEDIN_CT) ? (FEEDIN_CT/100) : 0;

const tb=document.getElementById('hist-tbody');
const tf=document.getElementById('hist-tfoot');
const yearSel=document.getElementById('year');
const weekSel=document.getElementById('week');
const monthSel=document.getElementById('month');
const modeSel=document.getElementById('mode');
const rangeGroup=document.getElementById('range-group');
const monthGroup=document.getElementById('month-group');
const weekGroup=document.getElementById('week-group');

function fillYears(){ const y=(new Date()).getFullYear(); yearSel.innerHTML=''; for(let i=0;i<15;i++){const o=document.createElement('option');o.value=String(y-i);o.textContent=String(y-i);yearSel.appendChild(o);} }
function fillWeeks(){ weekSel.innerHTML=''; for(let i=1;i<=53;i++){const o=document.createElement('option');o.value=String(i);o.textContent=String(i);weekSel.appendChild(o);} }
function toggleInputs(){ const m=modeSel.value; rangeGroup.style.display=(m==='range')?'':'none'; monthGroup.style.display=(m==='month')?'':'none'; weekGroup.style.display=(m==='week')?'':'none'; }
modeSel.addEventListener('change',toggleInputs);

function isoWeekStart(y,w){ const s=new Date(Date.UTC(y,0,1+(w-1)*7)); const d=s.getUTCDay()||7; const start=new Date(s); if(d<=4)start.setUTCDate(s.getUTCDate()-d+1);else start.setUTCDate(s.getUTCDate()+8-d); return start; }
function fmtDate(d){ const y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),dd=String(d.getDate()).padStart(2,'0'); return y+'-'+m+'-'+dd; }
function todayStr(){ const d=new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; }

const COLS=['pv_kwh','feed_in_kwh','batt_in_kwh','batt_out_kwh','consumption_kwh','gross_kwh','grid_import_kwh'];
const isNum = (v)=> Number.isFinite(v);
const n2 = (v)=> (Math.round((v||0)*100)/100);
const nz = (v)=> (isNum(v) ? v : 0);

/* ==== Brutto-Helfer ==== */
/* Bruttoverbrauch (Hauslast) = (PV - Einspeisung - Batt IN) + Batt OUT + Netzbezug */
function computeGross(it){
  const pv   = parseFloat(it.pv_kwh);
  const exp  = parseFloat(it.feed_in_kwh);
  const bin  = parseFloat(it.batt_in_kwh);
  const bout = parseFloat(it.batt_out_kwh);
  const imp  = parseFloat(it.grid_import_kwh);
  const v = nz(pv) - nz(exp) - nz(bin) + nz(bout) + nz(imp);
  return v;
}

/* ==== Tabelle + Summen + Tagesmittel ==== */
function buildRowsAndSummary(items){
  tb.innerHTML=''; if(tf) tf.innerHTML='';

  const today=todayStr();

  // Extremwerte ohne heute
  const series={}; COLS.forEach(k=>series[k]=[]);
  items.forEach(it=>{
    if(it.day===today) return;
    COLS.forEach(k=>{
      const v = (k==='gross_kwh') ? computeGross(it) : parseFloat(it[k]);
      if(isNum(v)) series[k].push(v);
    });
  });
  const extremes={};
  COLS.forEach(k=>{
    const arr=series[k]; extremes[k]=(arr.length? {min:Math.min(...arr),max:Math.max(...arr)} : {min:null,max:null});
  });

  // Zeilen
  items.forEach(it=>{
    const tr=document.createElement('tr');
    const d=document.createElement('td'); d.textContent=it.day; tr.appendChild(d);
    COLS.forEach(k=>{
      const td=document.createElement('td');
      const raw = (k==='gross_kwh') ? computeGross(it) : parseFloat(it[k]);
      if(!isNum(raw)) td.textContent='–';
      else{
        td.textContent=n2(raw).toLocaleString('de-DE');
        if(it.day!==today){
          const ex=extremes[k];
          if(ex.max!==null && raw===ex.max) td.classList.add('peak');
          if(ex.min!==null && raw===ex.min) td.classList.add('low');
        }
      }
      tr.appendChild(td);
    });
    tb.appendChild(tr);
  });

  // Summen + Tagesmittel (inkl. heute in Summe; Mittel über Anzahl vorhandener Tage)
  const sums={}; COLS.forEach(k=>{ let s=0; items.forEach(it=>{
    const v = (k==='gross_kwh') ? computeGross(it) : parseFloat(it[k]);
    if(isNum(v)) s+=v;
  }); sums[k]=s; });

  const dayCount = items.length || 1;
  const avgs = {}; COLS.forEach(k=> avgs[k] = (sums[k]/dayCount));

  // Summenzeile
  if(tf){
    const tr=document.createElement('tr'); tr.className='sumrow';
    const td0=document.createElement('td'); td0.textContent='<?= htmlspecialchars((t('t29_total')==='t29_total'?'Gesamt':t('t29_total')),ENT_QUOTES,'UTF-8') ?>'; tr.appendChild(td0);
    COLS.forEach(k=>{
      const td=document.createElement('td');
      if(k==='feed_in_kwh'){
        const kwh = n2(sums[k]);
        const eur = (kwh * FEEDIN_EUR_PER_KWH);
        td.innerHTML =
          `${kwh.toLocaleString('de-DE')}
           <div class="subsum" id="sum_eur_stats">≈ ${eur.toLocaleString('de-DE',{minimumFractionDigits:2, maximumFractionDigits:2})} €</div>
           <br><span class="reveal-link" data-role="reveal-stats"><?= htmlspecialchars((t('t30')==='t30'?'€ anzeigen (geschützt)':t('t30')),ENT_QUOTES,'UTF-8') ?></span>`;
      }else{
        td.textContent = n2(sums[k]).toLocaleString('de-DE');
      }
      tr.appendChild(td);
    });
    tf.appendChild(tr);

    // Tagesmittel-Zeile
    const trAvg=document.createElement('tr'); trAvg.className='sumrow';
    const tdA0=document.createElement('td'); tdA0.textContent='<?= htmlspecialchars((t('t33_daily_mean')==='t33_daily_mean'?'Tagesmittel':t('t33_daily_mean')),ENT_QUOTES,'UTF-8') ?>'; trAvg.appendChild(tdA0);
    COLS.forEach(k=>{
      const td=document.createElement('td');
      td.textContent = n2(avgs[k]).toLocaleString('de-DE');
      trAvg.appendChild(td);
    });
    tf.appendChild(trAvg);
  }
}

/* ==== Diagramm ==== */
let chartMain = null;
function renderChart(items){
  const labels = items.map(it => it.day);

  function arrOf(key){
    return items.map(it=>{
      let v;
      if(key==='gross_kwh'){
        v = computeGross(it);
      } else {
        v = parseFloat(it[key]);
      }
      return isNum(v) ? +n2(v) : null;
    });
  }

  const ds = [
    { key:'pv_kwh',          label:'<?= th('t20') ?>', color:'#6be29f' },
    { key:'feed_in_kwh',     label:'<?= th('t21') ?>', color:'#22d3ee' },
    { key:'batt_in_kwh',     label:'<?= th('t22') ?>', color:'#f59e0b' },
    { key:'batt_out_kwh',    label:'<?= th('t23') ?>', color:'#fb7185' },
    { key:'consumption_kwh', label:'<?= th('t24') ?>', color:'#5a8cff' },
    { key:'gross_kwh',       label:'<?= htmlspecialchars((t('t31_gross')==='t31_gross'?'Bruttoverbrauch':t('t31_gross')),ENT_QUOTES,'UTF-8') ?>', color:'#a78bfa' },
    { key:'grid_import_kwh', label:'<?= th('t25') ?>', color:'#f4a84a' },
  ].map(d=>({
    label: d.label,
    data: arrOf(d.key),
    borderColor: d.color,
    pointRadius: 2,
    borderWidth: 2,
    tension: 0.25,
    spanGaps: true
  }));

  const ctx = document.getElementById('statsChart').getContext('2d');
  const options = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: true, labels: { color:'#cfe1ff' } },
      tooltip: {
        callbacks: {
          label: (ctx) => {
            const v = ctx.parsed.y;
            return `${ctx.dataset.label}: ${isFinite(v)? v.toLocaleString('de-DE',{minimumFractionDigits:0, maximumFractionDigits:2}) : '–'} kWh`;
          }
        }
      }
    },
    scales: {
      x: { ticks:{ color:'#cfe1ff' }, grid:{ color:'rgba(255,255,255,0.08)' } },
      y: { ticks:{ color:'#cfe1ff' }, grid:{ color:'rgba(255,255,255,0.08)' }, title:{ display:true, text:'kWh', color:'#cfe1ff' } }
    }
  };

  if(chartMain){
    chartMain.data.labels = labels;
    chartMain.data.datasets = ds;
    chartMain.update();
  } else {
    chartMain = new Chart(ctx, { type:'line', data:{ labels, datasets: ds }, options });
  }
}

/* ==== Laden & Aktionen ==== */
async function loadRange(dev,start,end){
  const r=await fetch(`api/range.php?device=${encodeURIComponent(dev)}&start=${start}&end=${end}`);
  const j=await r.json();
  const items = Array.isArray(j.items) ? j.items : [];
  buildRowsAndSummary(items);
  renderChart(items);
}

document.getElementById('apply').addEventListener('click',()=>{
  const dev=document.getElementById('device').value||'home';
  const y=parseInt(yearSel.value,10);
  const m=parseInt(monthSel.value,10);
  const w=parseInt(weekSel.value,10);
  const mode=modeSel.value;
  let start,end;

  if(mode==='year'){
    start=fmtDate(new Date(y,0,1)); end=fmtDate(new Date(y,11,31));
  }else if(mode==='month'){
    start=fmtDate(new Date(y,m-1,1)); end=fmtDate(new Date(y,m,0));
  }else if(mode==='week'){
    const s=isoWeekStart(y,w); const e=new Date(s); e.setUTCDate(s.getUTCDate()+6);
    start=fmtDate(new Date(s.getUTCFullYear(),s.getUTCMonth(),s.getUTCDate()));
    end=fmtDate(new Date(e.getUTCFullYear(),e.getUTCMonth(),e.getUTCDate()));
  }else{
    start=document.getElementById('from').value; end=document.getElementById('to').value; if(!start||!end)return;
  }
  loadRange(dev,start,end);
});

/* Reveal via Server-Check (geschützte €-Anzeige) */
async function verifyVerguetungCode() {
  const code = prompt('Code:');
  if (code == null) return false;
  try {
    const r = await fetch('api/verify_verguetung.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({code})
    });
    const j = await r.json();
    return !!j.ok;
  } catch (e) {
    alert('Serverprüfung nicht erreichbar.');
    return false;
  }
}
document.addEventListener('click', async (ev)=>{
  const trg = ev.target;
  if(trg && trg.matches('.reveal-link[data-role="reveal-stats"]')){
    const ok = await verifyVerguetungCode();
    if (ok) {
      const el = document.getElementById('sum_eur_stats');
      if(el){ el.style.display='block'; }
      trg.remove();
    } else {
      alert('Code ungültig.');
    }
  }
});

fillYears(); fillWeeks(); toggleInputs(); document.getElementById('apply').click();
</script>
</body>
</html>
