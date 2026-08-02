<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/web_auth.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/ui.php';

pvdash_require_stats();
$pdo = db();
$devices = pvdash_devices($pdo);
$defaultDevice = pvdash_default_device();
if (!in_array($defaultDevice, $devices, true)) {
    $devices[] = $defaultDevice;
    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);
}
$batteryCapacity = pvdash_battery_capacity_kwh();
$monthNames = APP_LANG === 'de'
    ? ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
    : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(APP_LANG, ENT_QUOTES, 'UTF-8') ?>" <?= pvdash_html_attributes() ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= th('battery_title') ?> – <?= htmlspecialchars(pvdash_site_title(), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body <?= pvdash_body_attributes() ?>>
<div class="wrap wide-wrap">
  <section class="card">
    <header class="stats-header">
      <div>
        <?php pvdash_render_brand_heading(t('battery_title')); ?>
        <p class="muted no-margin"><?= th('battery_intro') ?></p>
      </div>
      <?php pvdash_render_navigation('stats'); ?>
    </header>

    <?php pvdash_render_statistics_subnav('battery'); ?>

    <?php if ($batteryCapacity <= 0): ?>
      <div class="alert alert-info"><?= th('battery_capacity_missing') ?></div>
    <?php endif; ?>

    <div class="controls analysis-controls">
      <div class="group"><label for="device"><?= th('stats_device') ?></label><select id="device"><?php foreach ($devices as $device): ?><option value="<?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?>" <?= $device === $defaultDevice ? 'selected' : '' ?>><?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
      <div class="group"><label for="mode"><?= th('stats_mode') ?></label><select id="mode"><option value="year"><?= th('stats_year') ?></option><option value="month"><?= th('stats_month') ?></option><option value="week"><?= th('stats_week') ?></option><option value="range"><?= th('stats_range') ?></option></select></div>
      <div class="group"><label for="year"><?= th('stats_year') ?></label><select id="year"></select></div>
      <div class="group" id="month-group"><label for="month"><?= th('stats_month') ?></label><select id="month"><?php foreach ($monthNames as $index => $name): ?><option value="<?= $index + 1 ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
      <div class="group" id="week-group"><label for="week"><?= th('stats_week') ?></label><select id="week"></select></div>
      <div class="group analysis-range" id="range-group" hidden><label for="from"><?= th('stats_from') ?></label><input type="date" id="from"><label for="to"><?= th('stats_to') ?></label><input type="date" id="to"></div>
      <div class="group"><button id="apply" class="button button-primary" type="button"><?= th('stats_apply') ?></button></div>
    </div>

    <div class="analysis-kpis" id="battery-kpis">
      <article class="analysis-kpi kpi-charge"><span><?= th('battery_charge_total') ?></span><strong id="kpi-charge">–</strong><small>kWh</small></article>
      <article class="analysis-kpi kpi-discharge"><span><?= th('battery_discharge_total') ?></span><strong id="kpi-discharge">–</strong><small>kWh</small></article>
      <article class="analysis-kpi kpi-throughput"><span><?= th('battery_throughput_total') ?></span><strong id="kpi-throughput">–</strong><small>kWh</small></article>
      <article class="analysis-kpi kpi-cycles"><span><?= th('battery_cycles') ?></span><strong id="kpi-cycles">–</strong><small><?= $batteryCapacity > 0 ? htmlspecialchars(number_format($batteryCapacity, 2, ',', '.'), ENT_QUOTES, 'UTF-8') . ' kWh' : '–' ?></small></article>
      <article class="analysis-kpi kpi-ratio"><span><?= th('battery_return_ratio') ?></span><strong id="kpi-ratio">–</strong><small>%</small></article>
      <article class="analysis-kpi kpi-share"><span><?= th('battery_consumption_share') ?></span><strong id="kpi-share">–</strong><small>%</small></article>
    </div>

    <div id="empty-state" class="analysis-empty" hidden><?= th('analysis_no_data') ?></div>

    <div class="analysis-chart-grid">
      <article class="analysis-chart-card analysis-chart-wide"><div class="card-head"><h2><?= th('battery_chart_energy') ?></h2></div><div class="analysis-chart-wrap"><canvas id="batteryEnergyChart"></canvas></div></article>
      <article class="analysis-chart-card"><div class="card-head"><h2><?= th('battery_chart_ratios') ?></h2></div><div class="analysis-chart-wrap"><canvas id="batteryRatioChart"></canvas></div></article>
      <article class="analysis-note"><strong><?= th('battery_daily_average') ?>:</strong> <span id="battery-daily-average">–</span> kWh<br><span><?= th('battery_note_ratio') ?></span></article>
    </div>

    <div class="table-wrap table-wrap-sticky analysis-table-wrap">
      <table class="fancy analysis-table">
        <thead><tr><th><?= th('t19') ?></th><th><?= th('t22') ?></th><th><?= th('t23') ?></th><th><?= th('battery_table_throughput') ?></th><th><?= th('battery_table_ratio') ?></th><th><?= th('battery_table_cycles') ?></th><th><?= th('battery_table_share') ?></th></tr></thead>
        <tbody id="battery-body"></tbody>
        <tfoot id="battery-foot"></tfoot>
      </table>
    </div>
  </section>
</div>
<script>
const NUMBER_LOCALE = <?= json_encode(APP_LANG === 'de' ? 'de-DE' : 'en-US') ?>;
const BATTERY_CAPACITY = <?= json_encode($batteryCapacity, JSON_PRESERVE_ZERO_FRACTION) ?>;
const MIN_DAILY_CHARGE_FOR_RATIO = 0.05;
const rootStyles = getComputedStyle(document.body);
const UI_TEXT = rootStyles.getPropertyValue('--fg').trim() || '#eaf2ff';
const UI_GRID = rootStyles.getPropertyValue('--chart-grid').trim() || 'rgba(255,255,255,.08)';
const ACCENT = rootStyles.getPropertyValue('--accent').trim() || '#4e8cff';
const isNum = value => Number.isFinite(value);
const num = value => { const parsed = Number.parseFloat(value); return isNum(parsed) ? Math.max(0, parsed) : 0; };
const clampPercent = value => Math.max(0, Math.min(100, value));
const fmt = (value, digits = 2) => isNum(value) ? value.toLocaleString(NUMBER_LOCALE, {minimumFractionDigits:0, maximumFractionDigits:digits}) : '–';
const pct = value => isNum(value) ? `${fmt(value, 1)} %` : '–';

const yearSel = document.getElementById('year');
const monthSel = document.getElementById('month');
const weekSel = document.getElementById('week');
const modeSel = document.getElementById('mode');
const monthGroup = document.getElementById('month-group');
const weekGroup = document.getElementById('week-group');
const rangeGroup = document.getElementById('range-group');
let energyChart = null;
let ratioChart = null;

function fillYears(){ const year = new Date().getFullYear(); for(let i=0;i<15;i++){ const option=document.createElement('option'); option.value=String(year-i); option.textContent=String(year-i); yearSel.appendChild(option); } }
function fillWeeks(){ for(let week=1;week<=53;week++){ const option=document.createElement('option'); option.value=String(week); option.textContent=String(week); weekSel.appendChild(option); } }
function toggleInputs(){ const mode=modeSel.value; monthGroup.hidden=mode!=='month'; weekGroup.hidden=mode!=='week'; rangeGroup.hidden=mode!=='range'; }
function isoWeekStart(year,week){ const simple=new Date(Date.UTC(year,0,1+(week-1)*7)); const day=simple.getUTCDay()||7; const start=new Date(simple); start.setUTCDate(simple.getUTCDate()+(day<=4?1-day:8-day)); return start; }
function fmtDate(date){ return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`; }
function selectedRange(){ const year=Number.parseInt(yearSel.value,10); const month=Number.parseInt(monthSel.value,10); const week=Number.parseInt(weekSel.value,10); let start,end; if(modeSel.value==='year'){ start=fmtDate(new Date(year,0,1)); end=fmtDate(new Date(year,11,31)); } else if(modeSel.value==='month'){ start=fmtDate(new Date(year,month-1,1)); end=fmtDate(new Date(year,month,0)); } else if(modeSel.value==='week'){ const s=isoWeekStart(year,week); const e=new Date(s); e.setUTCDate(s.getUTCDate()+6); start=fmtDate(new Date(s.getUTCFullYear(),s.getUTCMonth(),s.getUTCDate())); end=fmtDate(new Date(e.getUTCFullYear(),e.getUTCMonth(),e.getUTCDate())); } else { start=document.getElementById('from').value; end=document.getElementById('to').value; } return {start,end}; }
function derived(item){ const charge=num(item.batt_in_kwh); const discharge=num(item.batt_out_kwh); const consumption=num(item.consumption_kwh); return { day:item.day, charge, discharge, throughput:charge+discharge, ratio:charge>=MIN_DAILY_CHARGE_FOR_RATIO?discharge/charge*100:null, cycles:BATTERY_CAPACITY>0?discharge/BATTERY_CAPACITY:null, share:consumption>0?clampPercent(discharge/consumption*100):null, consumption }; }
function setKpi(id,value,digits=2){ document.getElementById(id).textContent=fmt(value,digits); }
function chartBase(){ return {responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{color:UI_TEXT}},tooltip:{callbacks:{label:ctx=>`${ctx.dataset.label}: ${fmt(ctx.parsed.y,2)}${ctx.dataset.unit||''}`}}},scales:{x:{ticks:{color:UI_TEXT,maxRotation:0,autoSkip:true},grid:{color:UI_GRID}},y:{beginAtZero:true,ticks:{color:UI_TEXT},grid:{color:UI_GRID}}}}; }
function renderCharts(rows){ const labels=rows.map(row=>row.day); const energyData=[{label:'<?= th('battery_charge_total') ?>',data:rows.map(row=>row.charge),backgroundColor:'rgba(52,211,153,.72)',borderColor:'#34d399',borderWidth:1,borderRadius:6,unit:' kWh'},{label:'<?= th('battery_discharge_total') ?>',data:rows.map(row=>row.discharge),backgroundColor:'rgba(251,113,133,.72)',borderColor:'#fb7185',borderWidth:1,borderRadius:6,unit:' kWh'}]; const energyOptions=chartBase(); energyOptions.scales.y.title={display:true,text:'kWh',color:UI_TEXT}; if(energyChart){ energyChart.data.labels=labels; energyChart.data.datasets=energyData; energyChart.update(); } else { energyChart=new Chart(document.getElementById('batteryEnergyChart'),{type:'bar',data:{labels,datasets:energyData},options:energyOptions}); }
 const ratioData=[{label:'<?= th('battery_consumption_share') ?>',data:rows.map(row=>row.share),borderColor:ACCENT,backgroundColor:'rgba(78,140,255,.14)',fill:true,tension:.28,pointRadius:2,spanGaps:true,unit:' %'},{label:'<?= th('battery_return_ratio') ?>',data:rows.map(row=>row.ratio),borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.08)',tension:.28,pointRadius:2,spanGaps:false,unit:' %'}]; const ratioOptions=chartBase(); ratioOptions.scales.y.suggestedMax=100; ratioOptions.scales.y.title={display:true,text:'%',color:UI_TEXT}; if(ratioChart){ ratioChart.data.labels=labels; ratioChart.data.datasets=ratioData; ratioChart.update(); } else { ratioChart=new Chart(document.getElementById('batteryRatioChart'),{type:'line',data:{labels,datasets:ratioData},options:ratioOptions}); } }
function render(items){ const rows=items.map(derived); const empty=rows.length===0; document.getElementById('empty-state').hidden=!empty; document.getElementById('battery-kpis').classList.toggle('is-empty',empty); const body=document.getElementById('battery-body'); const foot=document.getElementById('battery-foot'); body.innerHTML=''; foot.innerHTML='';
 let totalCharge=0,totalDischarge=0,totalConsumption=0; for(const row of rows){ totalCharge+=row.charge; totalDischarge+=row.discharge; totalConsumption+=row.consumption; const tr=document.createElement('tr'); const values=[row.day,fmt(row.charge),fmt(row.discharge),fmt(row.throughput),pct(row.ratio),row.cycles===null?'–':fmt(row.cycles,3),pct(row.share)]; values.forEach(value=>{const td=document.createElement('td');td.textContent=value;tr.appendChild(td)});body.appendChild(tr); }
 const throughput=totalCharge+totalDischarge; const ratio=totalCharge>=MIN_DAILY_CHARGE_FOR_RATIO?totalDischarge/totalCharge*100:null; const cycles=BATTERY_CAPACITY>0?totalDischarge/BATTERY_CAPACITY:null; const share=totalConsumption>0?clampPercent(totalDischarge/totalConsumption*100):null; setKpi('kpi-charge',totalCharge); setKpi('kpi-discharge',totalDischarge); setKpi('kpi-throughput',throughput); setKpi('kpi-cycles',cycles,2); setKpi('kpi-ratio',ratio,1); setKpi('kpi-share',share,1); document.getElementById('battery-daily-average').textContent=rows.length?fmt(totalDischarge/rows.length):'–';
 if(rows.length){ const tr=document.createElement('tr'); tr.className='sumrow'; [ '<?= th('analysis_total') ?>',fmt(totalCharge),fmt(totalDischarge),fmt(throughput),pct(ratio),cycles===null?'–':fmt(cycles,2),pct(share)].forEach(value=>{const td=document.createElement('td');td.textContent=value;tr.appendChild(td)}); foot.appendChild(tr); }
 renderCharts(rows);
}
async function load(){ const {start,end}=selectedRange(); if(!start||!end) return; const button=document.getElementById('apply'); button.disabled=true; try{ const device=document.getElementById('device').value; const response=await fetch(`api/range.php?device=${encodeURIComponent(device)}&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`); if(!response.ok) throw new Error(`HTTP ${response.status}`); const payload=await response.json(); render(Array.isArray(payload.items)?payload.items:[]); } catch(error){ document.getElementById('empty-state').hidden=false; document.getElementById('empty-state').textContent=error.message; } finally{ button.disabled=false; } }
modeSel.addEventListener('change',toggleInputs); document.getElementById('apply').addEventListener('click',load); fillYears(); fillWeeks(); toggleInputs(); load();
</script>
</body>
</html>
