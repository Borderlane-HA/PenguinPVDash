<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/web_auth.php';
require_once __DIR__ . '/inc/i18n.php';
pvdash_require_stats();
$canViewCompensation = pvdash_can_view_compensation();
$feedInCt = $canViewCompensation ? (float) pvdash_config('feed_in_ct', 0.0) : 0.0;
$defaultDevice = pvdash_default_device();
$monthNames = APP_LANG === 'de'
    ? ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
    : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(APP_LANG, ENT_QUOTES, 'UTF-8') ?>">
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
      <div class='brand-title'><img class='brand-icon' src='assets/penguin-pv-icon.png' alt='' width='42' height='42'><h1><?= th('stats_title') ?></h1><span class="status-pill <?= pvdash_is_admin() ? 'status-manual' : 'status-auto' ?>"><?= pvdash_is_admin() ? th('role_admin') : th('role_guest') ?></span></div>
      <div class='top-actions'><a href='./' class='button'><?= th('stats_back') ?></a><?php if (pvdash_is_admin()): ?><a href='admin/' class='button button-primary'><?= th('nav_admin') ?></a><a href='admin/settings.php' class='button'><?= th('nav_settings') ?></a><a href='logout.php' class='button'><?= th('nav_logout') ?></a><?php elseif (pvdash_session_role() === 'guest'): ?><a href='logout.php' class='button'><?= th('nav_logout') ?></a><?php else: ?><a href='login.php?admin=1&amp;next=stats.php' class='button'><?= th('nav_admin_login') ?></a><?php endif; ?></div>
    </div>

    <div class='controls'>
      <div class='group'><label><?= th('stats_device') ?></label><input id='device' value='<?= htmlspecialchars($defaultDevice, ENT_QUOTES, 'UTF-8') ?>'/></div>
      <div class='group'>
        <label><?= th('stats_mode') ?></label>
        <select id='mode'>
          <option value='year'><?= th('stats_year') ?></option>
          <option value='month'><?= th('stats_month') ?></option>
          <option value='week'><?= th('stats_week') ?></option>
          <option value='range'><?= th('stats_range') ?></option>
        </select>
      </div>
      <div class='group'><label><?= th('stats_year') ?></label><select id='year'></select></div>
      <div class='group' id='month-group'>
        <label><?= th('stats_month') ?></label>
        <select id='month'>
          <?php foreach ($monthNames as $monthIndex => $monthName): ?>
            <option value='<?= $monthIndex + 1 ?>'><?= htmlspecialchars($monthName, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class='group' id='week-group'><label><?= th('stats_week') ?></label><select id='week'></select></div>
      <div class='group' id='range-group' style='display:none'>
        <label><?= th('stats_from') ?></label><input type='date' id='from'/>
        <label><?= th('stats_to') ?></label><input type='date' id='to'/>
      </div>
      <div class='group'><button id='apply' class='button button-primary'><?= th('stats_apply') ?></button></div>
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
            <th><?= th('t25') ?></th>
          </tr>
        </thead>
        <tbody id='hist-tbody'></tbody>
        <tfoot id='hist-tfoot'></tfoot>
      </table>
    </div>

    <!-- Charts -->
    <div class="chart-card">
      <div class="card-head"><h2><?= th('stats_chart') ?></h2></div>
      <div class="chart-wrap">
        <canvas id="statsChart"></canvas>
      </div>
    </div>

  </div>
</div>

<script>
/* Vergütung in €/kWh (aus PHP, Komma/Punkt tolerant) */
const FEEDIN_CT = <?= json_encode($feedInCt, JSON_PRESERVE_ZERO_FRACTION) ?>;
const FEEDIN_EUR_PER_KWH = isFinite(FEEDIN_CT) ? (FEEDIN_CT/100) : 0;
const CAN_VIEW_COMPENSATION = <?= $canViewCompensation ? 'true' : 'false' ?>;
const NUMBER_LOCALE = <?= json_encode(APP_LANG === 'de' ? 'de-DE' : 'en-US') ?>;

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

const COLS=['pv_kwh','feed_in_kwh','batt_in_kwh','batt_out_kwh','consumption_kwh','grid_import_kwh'];
const isNum = (v)=> Number.isFinite(v);
const n2 = (v)=> (Math.round((v||0)*100)/100);


/* ==== Tabelle + Summen + Tagesmittel ==== */
function buildRowsAndSummary(items){
  tb.innerHTML=''; if(tf) tf.innerHTML='';

  const today=todayStr();

  // Extremwerte ohne heute
  const series={}; COLS.forEach(k=>series[k]=[]);
  items.forEach(it=>{
    if(it.day===today) return;
    COLS.forEach(k=>{
      const v = parseFloat(it[k]);
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
      const raw = parseFloat(it[k]);
      if(!isNum(raw)) td.textContent='–';
      else{
        td.textContent=n2(raw).toLocaleString(NUMBER_LOCALE);
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
    const v = parseFloat(it[k]);
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
      if(k==='feed_in_kwh' && CAN_VIEW_COMPENSATION){
        const kwh = n2(sums[k]);
        const eur = (kwh * FEEDIN_EUR_PER_KWH);
        td.innerHTML = `${kwh.toLocaleString(NUMBER_LOCALE)}<div class="subsum visible-subsum"><?= th('t30') ?>: ≈ ${eur.toLocaleString(NUMBER_LOCALE,{minimumFractionDigits:2, maximumFractionDigits:2})} €</div>`;
      }else{
        td.textContent = n2(sums[k]).toLocaleString(NUMBER_LOCALE);
      }
      tr.appendChild(td);
    });
    tf.appendChild(tr);

    // Tagesmittel-Zeile
    const trAvg=document.createElement('tr'); trAvg.className='sumrow';
    const tdA0=document.createElement('td'); tdA0.textContent='<?= htmlspecialchars((t('t33_daily_mean')==='t33_daily_mean'?'Tagesmittel':t('t33_daily_mean')),ENT_QUOTES,'UTF-8') ?>'; trAvg.appendChild(tdA0);
    COLS.forEach(k=>{
      const td=document.createElement('td');
      td.textContent = n2(avgs[k]).toLocaleString(NUMBER_LOCALE);
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
      const v = parseFloat(it[key]);
      return isNum(v) ? +n2(v) : null;
    });
  }

  const ds = [
    { key:'pv_kwh',          label:'<?= th('t20') ?>', color:'#6be29f' },
    { key:'feed_in_kwh',     label:'<?= th('t21') ?>', color:'#22d3ee' },
    { key:'batt_in_kwh',     label:'<?= th('t22') ?>', color:'#f59e0b' },
    { key:'batt_out_kwh',    label:'<?= th('t23') ?>', color:'#fb7185' },
    { key:'consumption_kwh', label:'<?= th('t24') ?>', color:'#5a8cff' },
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
            return `${ctx.dataset.label}: ${isFinite(v)? v.toLocaleString(NUMBER_LOCALE,{minimumFractionDigits:0, maximumFractionDigits:2}) : '–'} kWh`;
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


fillYears(); fillWeeks(); toggleInputs(); document.getElementById('apply').click();
</script>
</body>
</html>
