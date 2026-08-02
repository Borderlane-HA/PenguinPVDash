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
$monthNames = APP_LANG === 'de'
    ? ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
    : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(APP_LANG, ENT_QUOTES, 'UTF-8') ?>" <?= pvdash_html_attributes() ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= th('autarky_title') ?> – <?= htmlspecialchars(pvdash_site_title(), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body <?= pvdash_body_attributes() ?>>
<div class="wrap wide-wrap">
  <section class="card">
    <header class="stats-header">
      <div>
        <?php pvdash_render_brand_heading(t('autarky_title')); ?>
        <p class="muted no-margin"><?= th('autarky_intro') ?></p>
      </div>
      <?php pvdash_render_navigation('stats'); ?>
    </header>

    <?php pvdash_render_statistics_subnav('autarky'); ?>

    <div class="controls analysis-controls">
      <div class="group"><label for="device"><?= th('stats_device') ?></label><select id="device"><?php foreach ($devices as $device): ?><option value="<?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?>" <?= $device === $defaultDevice ? 'selected' : '' ?>><?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
      <div class="group"><label for="mode"><?= th('stats_mode') ?></label><select id="mode"><option value="year"><?= th('stats_year') ?></option><option value="month"><?= th('stats_month') ?></option><option value="week"><?= th('stats_week') ?></option><option value="range"><?= th('stats_range') ?></option></select></div>
      <div class="group"><label for="year"><?= th('stats_year') ?></label><select id="year"></select></div>
      <div class="group" id="month-group"><label for="month"><?= th('stats_month') ?></label><select id="month"><?php foreach ($monthNames as $index => $name): ?><option value="<?= $index + 1 ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
      <div class="group" id="week-group"><label for="week"><?= th('stats_week') ?></label><select id="week"></select></div>
      <div class="group analysis-range" id="range-group" hidden><label for="from"><?= th('stats_from') ?></label><input type="date" id="from"><label for="to"><?= th('stats_to') ?></label><input type="date" id="to"></div>
      <div class="group"><button id="apply" class="button button-primary" type="button"><?= th('stats_apply') ?></button></div>
    </div>

    <div class="analysis-kpis" id="autarky-kpis">
      <article class="analysis-kpi kpi-autarky"><span><?= th('autarky_rate') ?></span><strong id="kpi-autarky">–</strong><small>%</small></article>
      <article class="analysis-kpi kpi-self-consumption"><span><?= th('self_consumption_rate') ?></span><strong id="kpi-self-consumption">–</strong><small>%</small></article>
      <article class="analysis-kpi kpi-self-supplied"><span><?= th('self_supplied_energy') ?></span><strong id="kpi-self-supplied">–</strong><small>kWh</small></article>
      <article class="analysis-kpi kpi-onsite"><span><?= th('onsite_pv_energy') ?></span><strong id="kpi-onsite">–</strong><small>kWh</small></article>
      <article class="analysis-kpi kpi-grid"><span><?= th('grid_energy') ?></span><strong id="kpi-grid">–</strong><small>kWh</small></article>
      <article class="analysis-kpi kpi-export"><span><?= th('exported_energy') ?></span><strong id="kpi-export">–</strong><small>kWh</small></article>
    </div>

    <div id="empty-state" class="analysis-empty" hidden><?= th('analysis_no_data') ?></div>

    <div class="analysis-chart-grid autarky-chart-grid">
      <article class="analysis-chart-card analysis-chart-wide"><div class="card-head"><h2><?= th('autarky_chart_trend') ?></h2></div><div class="analysis-chart-wrap"><canvas id="autarkyTrendChart"></canvas></div></article>
      <article class="analysis-chart-card"><div class="card-head"><h2><?= th('autarky_chart_consumption_mix') ?></h2></div><div class="analysis-chart-wrap analysis-chart-doughnut"><canvas id="consumptionMixChart"></canvas></div></article>
      <article class="analysis-chart-card"><div class="card-head"><h2><?= th('autarky_chart_pv_mix') ?></h2></div><div class="analysis-chart-wrap analysis-chart-doughnut"><canvas id="pvMixChart"></canvas></div></article>
    </div>

    <div class="table-wrap table-wrap-sticky analysis-table-wrap">
      <table class="fancy analysis-table">
        <thead><tr><th><?= th('t19') ?></th><th><?= th('self_supplied_energy') ?></th><th><?= th('autarky_rate') ?></th><th><?= th('onsite_pv_energy') ?></th><th><?= th('self_consumption_rate') ?></th><th><?= th('autarky_table_grid_share') ?></th><th><?= th('autarky_table_export_share') ?></th></tr></thead>
        <tbody id="autarky-body"></tbody>
        <tfoot id="autarky-foot"></tfoot>
      </table>
    </div>
  </section>
</div>
<script>
const NUMBER_LOCALE = <?= json_encode(APP_LANG === 'de' ? 'de-DE' : 'en-US') ?>;
const rootStyles = getComputedStyle(document.body);
const UI_TEXT = rootStyles.getPropertyValue('--fg').trim() || '#eaf2ff';
const UI_GRID = rootStyles.getPropertyValue('--chart-grid').trim() || 'rgba(255,255,255,.08)';
const ACCENT = rootStyles.getPropertyValue('--accent').trim() || '#4e8cff';
const isNum = value => Number.isFinite(value);
const num = value => { const parsed=Number.parseFloat(value); return isNum(parsed)?Math.max(0,parsed):0; };
const clampPercent = value => Math.max(0,Math.min(100,value));
const fmt = (value,digits=2) => isNum(value)?value.toLocaleString(NUMBER_LOCALE,{minimumFractionDigits:0,maximumFractionDigits:digits}):'–';
const pct = value => isNum(value)?`${fmt(value,1)} %`:'–';

const yearSel=document.getElementById('year'); const monthSel=document.getElementById('month'); const weekSel=document.getElementById('week'); const modeSel=document.getElementById('mode'); const monthGroup=document.getElementById('month-group'); const weekGroup=document.getElementById('week-group'); const rangeGroup=document.getElementById('range-group');
let trendChart=null,consumptionChart=null,pvChart=null;
function fillYears(){const y=new Date().getFullYear();for(let i=0;i<15;i++){const o=document.createElement('option');o.value=String(y-i);o.textContent=String(y-i);yearSel.appendChild(o)}}
function fillWeeks(){for(let i=1;i<=53;i++){const o=document.createElement('option');o.value=String(i);o.textContent=String(i);weekSel.appendChild(o)}}
function toggleInputs(){const mode=modeSel.value;monthGroup.hidden=mode!=='month';weekGroup.hidden=mode!=='week';rangeGroup.hidden=mode!=='range'}
function isoWeekStart(y,w){const s=new Date(Date.UTC(y,0,1+(w-1)*7));const d=s.getUTCDay()||7;const start=new Date(s);start.setUTCDate(s.getUTCDate()+(d<=4?1-d:8-d));return start}
function fmtDate(d){return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`}
function selectedRange(){const y=parseInt(yearSel.value,10),m=parseInt(monthSel.value,10),w=parseInt(weekSel.value,10);let start,end;if(modeSel.value==='year'){start=fmtDate(new Date(y,0,1));end=fmtDate(new Date(y,11,31))}else if(modeSel.value==='month'){start=fmtDate(new Date(y,m-1,1));end=fmtDate(new Date(y,m,0))}else if(modeSel.value==='week'){const s=isoWeekStart(y,w),e=new Date(s);e.setUTCDate(s.getUTCDate()+6);start=fmtDate(new Date(s.getUTCFullYear(),s.getUTCMonth(),s.getUTCDate()));end=fmtDate(new Date(e.getUTCFullYear(),e.getUTCMonth(),e.getUTCDate()))}else{start=document.getElementById('from').value;end=document.getElementById('to').value}return{start,end}}
function derived(item){const consumption=num(item.consumption_kwh),grid=num(item.grid_import_kwh),pv=num(item.pv_kwh),feed=num(item.feed_in_kwh);const selfSupplied=Math.max(0,consumption-grid);const onsitePv=Math.max(0,pv-feed);return{day:item.day,consumption,grid,pv,feed,selfSupplied,onsitePv,autarky:consumption>0?clampPercent(selfSupplied/consumption*100):null,selfConsumption:pv>0?clampPercent(onsitePv/pv*100):null,gridShare:consumption>0?clampPercent(grid/consumption*100):null,exportShare:pv>0?clampPercent(feed/pv*100):null}}
function setKpi(id,value,digits=2){document.getElementById(id).textContent=fmt(value,digits)}
function chartBase(){return{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:UI_TEXT}},tooltip:{callbacks:{label:ctx=>`${ctx.dataset.label||ctx.label}: ${fmt(ctx.parsed.y??ctx.parsed,1)}${ctx.dataset.unit||''}`}}},scales:{x:{ticks:{color:UI_TEXT,maxRotation:0,autoSkip:true},grid:{color:UI_GRID}},y:{beginAtZero:true,max:100,ticks:{color:UI_TEXT},grid:{color:UI_GRID},title:{display:true,text:'%',color:UI_TEXT}}}}}
function doughnutOptions(){return{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{position:'bottom',labels:{color:UI_TEXT,padding:16}},tooltip:{callbacks:{label:ctx=>`${ctx.label}: ${fmt(ctx.parsed,2)} kWh`}}}}}
function renderCharts(rows,summary){const labels=rows.map(r=>r.day);const datasets=[{label:'<?= th('autarky_rate') ?>',data:rows.map(r=>r.autarky),borderColor:ACCENT,backgroundColor:'rgba(78,140,255,.15)',fill:true,tension:.3,pointRadius:2,spanGaps:true,unit:' %'},{label:'<?= th('self_consumption_rate') ?>',data:rows.map(r=>r.selfConsumption),borderColor:'#34d399',backgroundColor:'rgba(52,211,153,.10)',tension:.3,pointRadius:2,spanGaps:true,unit:' %'}];const options=chartBase();if(trendChart){trendChart.data.labels=labels;trendChart.data.datasets=datasets;trendChart.update()}else{trendChart=new Chart(document.getElementById('autarkyTrendChart'),{type:'line',data:{labels,datasets},options})}
 const consumptionData={labels:['<?= th('autarky_self_supplied') ?>','<?= th('autarky_from_grid') ?>'],datasets:[{data:[summary.selfSupplied,summary.grid],backgroundColor:['#34d399','#f59e0b'],borderWidth:0,hoverOffset:8}]};if(consumptionChart){consumptionChart.data=consumptionData;consumptionChart.update()}else{consumptionChart=new Chart(document.getElementById('consumptionMixChart'),{type:'doughnut',data:consumptionData,options:doughnutOptions()})}
 const pvData={labels:['<?= th('autarky_onsite') ?>','<?= th('autarky_exported') ?>'],datasets:[{data:[summary.onsitePv,summary.feed],backgroundColor:['#5a8cff','#22d3ee'],borderWidth:0,hoverOffset:8}]};if(pvChart){pvChart.data=pvData;pvChart.update()}else{pvChart=new Chart(document.getElementById('pvMixChart'),{type:'doughnut',data:pvData,options:doughnutOptions()})}}
function render(items){const rows=items.map(derived);const empty=rows.length===0;document.getElementById('empty-state').hidden=!empty;const body=document.getElementById('autarky-body'),foot=document.getElementById('autarky-foot');body.innerHTML='';foot.innerHTML='';let consumption=0,grid=0,pv=0,feed=0;for(const row of rows){consumption+=row.consumption;grid+=row.grid;pv+=row.pv;feed+=row.feed;const tr=document.createElement('tr');[row.day,fmt(row.selfSupplied),pct(row.autarky),fmt(row.onsitePv),pct(row.selfConsumption),pct(row.gridShare),pct(row.exportShare)].forEach(v=>{const td=document.createElement('td');td.textContent=v;tr.appendChild(td)});body.appendChild(tr)}const selfSupplied=Math.max(0,consumption-grid),onsitePv=Math.max(0,pv-feed),autarky=consumption>0?clampPercent(selfSupplied/consumption*100):null,selfConsumption=pv>0?clampPercent(onsitePv/pv*100):null,gridShare=consumption>0?clampPercent(grid/consumption*100):null,exportShare=pv>0?clampPercent(feed/pv*100):null;setKpi('kpi-autarky',autarky,1);setKpi('kpi-self-consumption',selfConsumption,1);setKpi('kpi-self-supplied',selfSupplied);setKpi('kpi-onsite',onsitePv);setKpi('kpi-grid',grid);setKpi('kpi-export',feed);if(rows.length){const tr=document.createElement('tr');tr.className='sumrow';['<?= th('analysis_total') ?>',fmt(selfSupplied),pct(autarky),fmt(onsitePv),pct(selfConsumption),pct(gridShare),pct(exportShare)].forEach(v=>{const td=document.createElement('td');td.textContent=v;tr.appendChild(td)});foot.appendChild(tr)}renderCharts(rows,{selfSupplied,grid,onsitePv,feed})}
async function load(){const{start,end}=selectedRange();if(!start||!end)return;const button=document.getElementById('apply');button.disabled=true;try{const device=document.getElementById('device').value;const response=await fetch(`api/range.php?device=${encodeURIComponent(device)}&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`);if(!response.ok)throw new Error(`HTTP ${response.status}`);const payload=await response.json();render(Array.isArray(payload.items)?payload.items:[])}catch(error){document.getElementById('empty-state').hidden=false;document.getElementById('empty-state').textContent=error.message}finally{button.disabled=false}}
modeSel.addEventListener('change',toggleInputs);document.getElementById('apply').addEventListener('click',load);fillYears();fillWeeks();toggleInputs();load();
</script>
</body>
</html>
