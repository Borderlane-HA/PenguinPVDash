<?php
require_once __DIR__ . '/inc/db.php';
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

/* Vergütung (ct/kWh) – Komma/Punkt tolerant -> für JS als Dezimalpunkt */
$V_CT_RAW  = isset($verguetung) ? (string)$verguetung : '0';
$V_CT_JS   = str_replace(',', '.', $V_CT_RAW); // z. B. "10.45"
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>PenguinPVDash</title>
  <link rel="stylesheet" href="assets/style.css"/>
  <style>
    /* Grundlayout der Knoten/Icons */
    .flow .node{ display:flex; align-items:center; gap:.75rem; }
    .flow .node .ico{
      width:56px; height:34px; flex:0 0 56px; border-radius:10px;
      display:grid; place-items:center; position:relative;
      background: linear-gradient(180deg, #ffffff60 0%, #ffffff10 60%, transparent 100%);
      transition: transform .25s ease, filter .25s ease, box-shadow .25s ease;
    }
    .flow .node .ico svg{ width:26px; height:26px; }

    /* Glow-Puls an Knoten bei Aktivität (JS setzt .on) */
    .flow .node.on .ico{ animation: glow 1.6s ease-in-out infinite; }
    @keyframes glow{
      0%,100%{ box-shadow: inset 0 0 0 1px rgba(255,255,255,.12), 0 0 0 0 rgba(255,255,255,.0); }
      50%{    box-shadow: inset 0 0 8px 2px rgba(255,255,255,.18), 0 0 14px 4px rgba(255,255,255,.10); }
    }

    /* Rahmen/Schimmer je Typ */
    .ico-pv{ box-shadow: inset 0 0 0 1px #6be29f66; }
    .ico-pv svg{ filter: drop-shadow(0 0 4px #6be29f66); }

    .ico-house{ box-shadow: inset 0 0 0 1px #89b4ff66; }
    .ico-house svg{ filter: drop-shadow(0 0 4px #89b4ff66); }

    .ico-grid{ box-shadow: inset 0 0 0 1px #f4b26666; }
    .ico-grid svg{ filter: drop-shadow(0 0 4px #f4b26666); }

    /* Fancy-Effekte */
    #n_pv.on .ico svg    { filter: drop-shadow(0 0 6px #6be29f); }
    #n_house.on .ico svg { filter: drop-shadow(0 0 6px #5a8cff); }
    #n_grid.on .ico svg  { filter: drop-shadow(0 0 6px #f4a84a); }
    .ico-pv   { background: rgba(107,226,159,0.10); }
    .ico-house{ background: rgba(90,140,255,0.10); }
    .ico-grid { background: rgba(244,168,74,0.10); }
    .flow .node .ico:hover { transform: scale(1.06); cursor: pointer; }

    /* Token-Schimmer */
    .token{ filter: drop-shadow(0 0 2px rgba(255,255,255,.6)); }
    .token.grid { fill: #5a8cff; }
    #l_grid_house { stroke-dasharray: 6; opacity:.95; }

    /* Batterie-Icon mit Füllstand */
    #n_batt{ display:flex; align-items:center; gap:.75rem; }
    #n_batt .bat{
      position: relative; width: 80px; height: 26px; overflow: hidden;
      border: 2px solid rgba(255,255,255,.55); border-radius: 5px; background: #0b1228;
      box-shadow: 0 0 8px rgba(0,0,0,.08) inset;
    }
    #n_batt .bat::after{
      content:""; position:absolute; right:-8px; top:50%; transform: translateY(-50%);
      width: 8px; height: 14px; border: 2px solid rgba(255,255,255,.55);
      border-left: 0; border-radius: 0 4px 4px 0; background: #0b1228;
    }
    #n_batt .bat .fill{
      position:absolute; left:0; top:0; bottom:0; width:0%;
      background: linear-gradient(90deg, #22c55e 0%, #86efac 60%, #bbf7d0 100%);
      transition: width .35s ease;
    }
    #n_batt .bat.low  .fill{ background: linear-gradient(90deg, #ef4444 0%, #fca5a5 100%); }
    #n_batt .bat.mid  .fill{ background: linear-gradient(90deg, #f59e0b 0%, #fde68a 100%); }
    #n_batt .bat:before{
      content:""; position:absolute; left:0; right:0; top:0; height:6px;
      background: linear-gradient(180deg, rgba(255,255,255,.25), rgba(255,255,255,0));
      pointer-events:none;
    }

    /* Tabelle: Zebra + Peaks/Lows + Summe + Reveal + Tagesmittel */
    .table-wrap .fancy thead th { white-space: nowrap; }
    .table-wrap .fancy tbody tr:nth-child(odd){ background: rgba(255,255,255,.04); }
    .table-wrap .fancy tbody tr:nth-child(even){ background: rgba(0,0,0,.025); }
    .fancy td.peak{ background: rgba(107,226,159,.18); box-shadow: inset 0 0 0 1px rgba(107,226,159,.35); }
    .fancy td.low { background: rgba(255,99,132,.16);  box-shadow: inset 0 0 0 1px rgba(255,99,132,.30); }
    .fancy tfoot tr.sumrow{
      background: linear-gradient(180deg, rgba(255,255,255,.14), rgba(255,255,255,.04));
      font-weight: 600; border-top: 3px solid rgba(255,255,255,.35);
    }
    .fancy tfoot tr.avgrow{
      background: linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.03));
      font-weight: 500; border-top: 1px dashed rgba(255,255,255,.25);
    }
    .fancy tfoot tr.sumrow td,
    .fancy tfoot tr.avgrow td{ padding-top: 12px; padding-bottom: 10px; vertical-align: top; }
    .fancy tfoot tr.sumrow td:first-child,
    .fancy tfoot tr.avgrow td:first-child{ letter-spacing:.2px; text-transform:uppercase; opacity:.9; }
    .subsum{ font-weight:500; opacity:.9; margin-top:6px; font-size:.92em; display:none; }
    .reveal-link{ display:inline-block; margin-top:6px; font-size:.9em; opacity:.85; text-decoration:underline; cursor:pointer; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>PenguinPVDash</h1>

  <!-- ====== Flow (mit animierten Kreisen) ====== -->
  <div class="card">
    <div class="flowwrap">
      <div class="flow">
        <svg viewBox="0 0 900 260" preserveAspectRatio="none">
          <!-- Linien -->
          <path id="l_pv_house"   d="M160,50 C360,50 540,50 740,50"   stroke="#6be29f" stroke-width="3" fill="none"></path>
          <path id="l_pv_grid"    d="M160,70 C360,110 540,190 740,230" stroke="#6be29f" stroke-width="3" fill="none"></path>
          <path id="l_pv_batt"    d="M130,70 C220,120 220,200 130,210" stroke="#6be29f" stroke-width="3" fill="none"></path>
          <path id="l_batt_house" d="M90,210  C320,180 520,90  740,50"  stroke="#ffd480" stroke-width="3" fill="none"></path>
          <path id="l_grid_house" d="M740,230 C700,200 700,80 740,50" stroke="#89b4ff" stroke-width="3" fill="none"></path>

          <!-- Token-Gruppen -->
          <g id="tok_pv_house"></g>
          <g id="tok_pv_grid"></g>
          <g id="tok_pv_batt"></g>
          <g id="tok_batt_house"></g>
          <g id="tok_grid_house"></g>
        </svg>

        <!-- Knoten: PV -->
        <div class="node" id="n_pv">
          <div class="ico ico-pv" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <circle cx="12" cy="12" r="4.5" stroke="#25c77a" stroke-width="2"/>
              <g stroke="#25c77a" stroke-width="2" stroke-linecap="round">
                <line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/>
                <line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/>
                <line x1="4.2" y1="4.2" x2="6.3" y2="6.3"/><line x1="17.7" y1="17.7" x2="19.8" y2="19.8"/>
                <line x1="17.7" y1="6.3" x2="19.8" y2="4.2"/><line x1="4.2" y1="19.8" x2="6.3" y2="17.7"/>
              </g>
            </svg>
          </div>
          <div>
            <h3><?= th('t1') ?></h3>
            <div class="sub"><span id="pv_now">0,0 kW</span></div>
          </div>
        </div>

        <!-- Knoten: Haus -->
        <div class="node" id="n_house">
          <div class="ico ico-house" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M3 11.5L12 4l9 7.5" stroke="#5a8cff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M5.5 10.5V20h13V10.5" stroke="#5a8cff" stroke-width="2" stroke-linejoin="round"/>
              <rect x="10" y="13" width="4" height="4.5" stroke="#5a8cff" stroke-width="2"/>
            </svg>
          </div>
          <div>
            <h3><?= th('t2') ?></h3>
            <div class="sub"><span id="cons_now">0,0 kW</span></div>
          </div>
        </div>

        <!-- Knoten: Netz -->
        <div class="node" id="n_grid">
          <div class="ico ico-grid" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M12 2v20" stroke="#f4a84a" stroke-width="2" stroke-linecap="round"/>
              <path d="M4 7h16M6 10h12M8 13h8M10 16h4" stroke="#f4a84a" stroke-width="2" stroke-linecap="round"/>
              <path d="M12 2l6 5-6 3-6-3 6-5z" stroke="#f4a84a" stroke-width="2" stroke-linejoin="round"/>
            </svg>
          </div>
          <div>
            <h3><?= th('t6') ?></h3>
            <div class="sub">
              <?= th('t7') ?>: <span id="grid_import_now">0,0</span> kW ·
              <?= th('t8') ?>: <span id="export_now">0,0</span> kW
            </div>
          </div>
        </div>

        <!-- Knoten: Batterie -->
        <div class="node" id="n_batt">
          <div class="bat" id="bat_icon"><div class="fill" style="width:0%"></div></div>
          <div>
            <h3><?= th('t3') ?> <span id="soc_txt">0%</span></h3>
            <div class="sub"><?= th('t4') ?>: <span id="b_in_now">0,0</span> kW · <?= th('t5') ?>: <span id="b_out_now">0,0</span> kW</div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ====== Heute (Tageswerte kWh) ====== -->
  <div class="card">
    <div class="card-head"><h2><?= th('t9') ?></h2></div>
    <div class="kpi3">
      <div class="box"><div class="label"><?= th('t10') ?> (kWh):</div><div class="val" id="k_pv">–</div></div>
      <div class="box">
        <div class="label"><?= th('t11') ?>:</div>
        <div class="lines">
          <div class="row"><span><?= th('t12') ?> (kWh):</span><span id="k_bin">–</span></div>
          <div class="row"><span><?= th('t13') ?> (kWh):</span><span id="k_bout">–</span></div>
        </div>
      </div>
      <div class="box">
        <div class="label"><?= th('t14') ?>:</div>
        <div class="lines">
          <div class="row"><span><?= th('t15') ?> (kWh):</span><span id="k_cons">–</span></div>
          <!-- NEU: Bruttoverbrauch heute -->
          <div class="row">
            <span><?= htmlspecialchars((t('t31_gross')==='t31_gross'?'Bruttoverbrauch':t('t31_gross')),ENT_QUOTES,'UTF-8') ?> (kWh):</span>
            <span id="k_gross">–</span>
          </div>
          <div class="row"><span><?= th('t16') ?> (kWh):</span><span id="k_imp">–</span></div>
          <div class="row"><span><?= th('t17') ?> (kWh):</span><span id="k_feed">–</span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ====== Letzte 30 Tage ====== -->
  <div class="card">
    <div class="card-head"><h2><?= th('t18') ?> (kWh)</h2></div>
    <div class="table-wrap">
      <table class="fancy">
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
        <tbody id="hist-tbody"></tbody>
        <tfoot id="hist-tfoot"></tfoot>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= th('t26') ?>…</h2></div>
    <p><?= th('t27') ?>: <a href="stats.php" target="_blank"><?= th('t28') ?></a></p>
  </div>
</div>

<script>
/* Vergütung (€/kWh) – aus PHP */
const FEEDIN_CT = parseFloat('<?= $V_CT_JS ?>');            // z. B. "10.45"
const FEEDIN_EUR_PER_KWH = isFinite(FEEDIN_CT) ? (FEEDIN_CT/100) : 0;

/* ===== Helpers ===== */
function f2(v){ return (Math.round((v||0)*100)/100).toLocaleString('de-DE'); }
function kw(v){ return f2(Math.max(0, v||0)); }
function setText(id,v){ const el=document.getElementById(id); if(el) el.textContent=v; }
function setFlowVisible(id,on){ const e=document.getElementById(id); if(e) e.style.display = on? '' : 'none'; }
function setLabel(id, txt){ const e=document.getElementById(id); if(e) e.textContent=txt; }
function setNodeOn(id,on){ const n=document.getElementById(id); if(n){ n.classList.toggle('on', !!on); } }
function todayStr(){ const d=new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; }

function rebuildTokens(groupId, pathId, count, durSec, cls){
  const g=document.getElementById(groupId); if(!g) return; g.innerHTML=''; if(count<=0) return;
  for(let i=0;i<count;i++){
    const c=document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    c.setAttribute('r','3'); c.setAttribute('class','token '+cls);
    const am=document.createElementNS('http://www.w3.org/2000/svg','animateMotion');
    am.setAttribute('dur', (durSec.toFixed(2))+'s');
    am.setAttribute('repeatCount','indefinite');
    am.setAttribute('rotate','auto');
    am.setAttribute('begin', (i*(durSec/count)).toFixed(2)+'s');
    const mp=document.createElementNS('http://www.w3.org/2000/svg','mpath');
    mp.setAttributeNS('http://www.w3.org/1999/xlink','xlink:href','#'+pathId);
    am.appendChild(mp); c.appendChild(am); g.appendChild(c);
  }
}
/* Fluss-Token-Parametrisierung */
function flowVisuals(v){
  const x = Math.max(0, v||0);
  const count = Math.max(2, Math.min(8, Math.round(Math.sqrt(x)*3)));
  const dur = Math.max(2.2, Math.min(14, 10 / Math.log2(2 + x)));
  return {count, dur};
}

/* ===== Live-Refresh ===== */
async function refreshLive(){
  const r = await fetch('api/last.php?device=home&_t='+Date.now());
  const j = await r.json();
  const s = j.latest || {};
  const t = j.today || {};

  const unit = (s.unit||'kW').toLowerCase();
  const toKW = unit==='w' ? (x)=>x/1000 : (x)=>x;

  const pv   = toKW(parseFloat(s.pv_power ?? 0));
  const cons = toKW(parseFloat(s.consumption ?? 0));
  const gi   = toKW(parseFloat(s.grid_import ?? 0));  // kann bei Export negativ sein
  const fi   = toKW(parseFloat(s.feed_in));           // kann fehlen/NaN sein
  const exportKW = Number.isFinite(fi) ? Math.max(fi, 0) : Math.max(-gi, 0);
  const importKW = Math.max(gi, 0);

  const bi  = toKW(parseFloat(s.battery_charge ?? 0));
  const bo  = toKW(parseFloat(s.battery_discharge ?? 0));
  const soc = parseFloat(s.battery_soc ?? 0);

  setText('pv_now', kw(pv)+' kW');
  setText('cons_now', kw(cons)+' kW');
  setText('grid_import_now', kw(importKW));
  setText('export_now', kw(exportKW));
  setText('b_in_now', kw(bi));
  setText('b_out_now', kw(bo));
  setText('soc_txt', isFinite(soc) ? Math.round(soc)+'%' : '0%');

  // Batterie-Füllstand im Icon
  const bat = document.getElementById('bat_icon');
  const fill = bat ? bat.querySelector('.fill') : null;
  if(fill){
    const p = Math.max(0, Math.min(100, isFinite(soc)?soc:0));
    fill.style.width = p+'%';
    bat.classList.remove('low','mid');
    if(p<30) bat.classList.add('low'); else if(p<60) bat.class.add('mid'); // <- fixed below
  }

  // **Fix**: classList add mid korrekt
  if(bat){
    bat.classList.remove('low','mid');
    const p = Math.max(0, Math.min(100, isFinite(soc)?soc:0));
    if(p<30) bat.classList.add('low');
    else if(p<60) bat.classList.add('mid');
  }

  // Node-Glow (fancy) wenn Leistung anliegt
  setNodeOn('n_pv',    pv > 0.01);
  setNodeOn('n_house', cons > 0.01);
  setNodeOn('n_grid',  (importKW > 0.01) || (exportKW > 0.01));
  setNodeOn('n_batt',  (bi > 0.01) || (bo > 0.01));

  // Fluss-Sichtbarkeit
  const pvOn          = pv > 0.01;
  const pv_house_on   = pvOn && cons     > 0.01;
  const pv_grid_on    = pvOn && exportKW > 0.01;
  const pv_batt_on    = pvOn && bi       > 0.01;
  const batt_house_on = bo   > 0.01;
  const grid_house_on = importKW > 0.01;

  setFlowVisible('l_pv_house',   pv_house_on);
  setFlowVisible('l_pv_grid',    pv_grid_on);
  setFlowVisible('l_pv_batt',    pv_batt_on);
  setFlowVisible('l_batt_house', batt_house_on);
  setFlowVisible('l_grid_house', grid_house_on);

  // (Optionale) Labels
  setLabel('lab_pv_house',   'PV→Haus '     + kw(cons)     + ' kW');
  setLabel('lab_pv_grid',    'PV→Netz '     + kw(exportKW) + ' kW');
  setLabel('lab_pv_batt',    'PV→Batterie ' + kw(bi)       + ' kW');
  setLabel('lab_batt_house', 'Batterie→Haus '+ kw(bo)      + ' kW');
  setLabel('lab_grid_house', 'Netz→Haus ' + kw(importKW) + ' kW');

  // Token-Animation
  const v1 = flowVisuals(cons);
  rebuildTokens('tok_pv_house','l_pv_house', pv_house_on ? v1.count : 0, v1.dur, 'pv');

  const v2 = flowVisuals(exportKW);
  rebuildTokens('tok_pv_grid','l_pv_grid',   pv_grid_on ? v2.count : 0, v2.dur, 'pv');

  const v3 = flowVisuals(bi);
  rebuildTokens('tok_pv_batt','l_pv_batt',   pv_batt_on ? v3.count : 0, v3.dur, 'pv');

  const v4 = flowVisuals(bo);
  rebuildTokens('tok_batt_house','l_batt_house', batt_house_on ? v4.count : 0, v4.dur, 'batt');

  const v5 = flowVisuals(importKW);
  rebuildTokens('tok_grid_house', 'l_grid_house', grid_house_on ? v5.count : 0, v5.dur, 'grid');

  // ===== Heute (kWh) =====
  function tv(x){ return x==null ? '–' : f2(parseFloat(x)); }
  const t_pv   = parseFloat(t.pv_kwh)           || 0;
  const t_feed = parseFloat(t.feed_in_kwh)      || 0;
  const t_bin  = parseFloat(t.batt_in_kwh)      || 0;
  const t_bout = parseFloat(t.batt_out_kwh)     || 0;
  const t_imp  = parseFloat(t.grid_import_kwh)  || 0;
  const t_cons = parseFloat(t.consumption_kwh);       // kann fehlen/„falsch“ laut HA

  // NEU: Bruttoverbrauch heute (Hauslast)
  const gross_today = (t_pv - t_feed - t_bin) + t_bout + t_imp;

  setText('k_pv',   tv(t_pv));
  setText('k_feed', tv(t_feed));
  setText('k_bin',  tv(t_bin));
  setText('k_bout', tv(t_bout));
  setText('k_cons', tv(t_cons));
  setText('k_imp',  tv(t_imp));
  setText('k_gross', tv(gross_today));
}

/* ===== History 30 Tage (Peaks/Lows ohne heute) + Summen + Tagesmittel ===== */
async function refreshHistory(){
  const r = await fetch('api/daily.php?device=home&days=30&_t='+Date.now());
  const j = await r.json();
  const items = Array.isArray(j.items) ? j.items : [];

  const tb = document.getElementById('hist-tbody');
  const tf = document.getElementById('hist-tfoot');
  tb.innerHTML = ''; if(tf) tf.innerHTML = '';

  // Spalten inkl. berechnetem Bruttoverbrauch
  // Bruttoverbrauch (Hauslast) = (PV - Einspeisung - Batt IN) + Batt OUT + Netzbezug
  const cols = ['pv_kwh','feed_in_kwh','batt_in_kwh','batt_out_kwh','consumption_kwh','gross_kwh','grid_import_kwh'];

  const isNum = (v)=> Number.isFinite(v);
  const n2 = (v)=> (Math.round(v*100)/100);
  const nz = (v)=> (isNum(v) ? v : 0);
  const today = todayStr();

  function computeGross(it){
    const pv   = parseFloat(it.pv_kwh);
    const exp  = parseFloat(it.feed_in_kwh);
    const bout = parseFloat(it.batt_out_kwh);
    const bin  = parseFloat(it.batt_in_kwh);
    const imp  = parseFloat(it.grid_import_kwh);
    return nz(pv) - nz(exp) - nz(bin) + nz(bout) + nz(imp);
  }

  // Extremwerte ohne heute
  const series = {}; cols.forEach(k => series[k] = []);
  items.forEach(it=>{
    if(it.day === today) return;
    cols.forEach(k=>{
      let v = null;
      if(k === 'gross_kwh') v = computeGross(it);
      else v = parseFloat(it[k]);
      if(isNum(v)) series[k].push(v);
    });
  });
  const extremes = {};
  cols.forEach(k=>{
    const arr = series[k]; extremes[k] = (arr.length? {min:Math.min(...arr), max:Math.max(...arr)} : {min:null,max:null});
  });

  // Zeilen
  items.forEach(it=>{
    const tr=document.createElement('tr');
    const d=document.createElement('td'); d.textContent=it.day; tr.appendChild(d);
    cols.forEach(k=>{
      const c=document.createElement('td');
      let raw = null;
      if(k === 'gross_kwh') raw = computeGross(it);
      else raw = parseFloat(it[k]);

      if(!isNum(raw)){
        c.textContent='–';
      } else {
        c.textContent = n2(raw).toLocaleString('de-DE');
        if(it.day !== today){
          const ex = extremes[k];
          if(ex.max!==null && raw===ex.max) c.classList.add('peak');
          if(ex.min!==null && raw===ex.min) c.classList.add('low');
        }
      }
      tr.appendChild(c);
    });
    tb.appendChild(tr);
  });

  // Summen (inkl. heute) und Tagesmittel (über alle angezeigten Tage)
  const sums = {}, avgs = {};
  const daysCount = Math.max(1, items.length);
  cols.forEach(k=>{
    let s=0;
    items.forEach(it=>{
      let v = null;
      if(k === 'gross_kwh') v = computeGross(it);
      else v = parseFloat(it[k]);
      if(isNum(v)) s += v;
    });
    sums[k]=s;
    avgs[k]= s / daysCount;
  });

  if(tf){
    // Summenzeile
    const tr=document.createElement('tr'); tr.className='sumrow';
    const td0=document.createElement('td'); td0.textContent='<?= htmlspecialchars((t('t29_total')==='t29_total'?'Gesamt':t('t29_total')),ENT_QUOTES,'UTF-8') ?>'; tr.appendChild(td0);

    cols.forEach(k=>{
      const td=document.createElement('td');
      if(k==='feed_in_kwh'){
        const kwh = n2(sums[k]);
        const eur = (kwh * FEEDIN_EUR_PER_KWH);
        td.innerHTML =
          `${kwh.toLocaleString('de-DE')}
           <div class="subsum" id="sum_eur_index">≈ ${eur.toLocaleString('de-DE',{minimumFractionDigits:2, maximumFractionDigits:2})} €</div>
           <br><span class="reveal-link" data-role="reveal-index"><?= htmlspecialchars((t('t30')==='t30'?'€ anzeigen (geschützt)':t('t30')),ENT_QUOTES,'UTF-8') ?></span>`;
      } else {
        td.textContent = n2(sums[k]).toLocaleString('de-DE');
      }
      tr.appendChild(td);
    });
    tf.appendChild(tr);

    // Tagesmittel-Zeile
    const trAvg=document.createElement('tr'); trAvg.className='avgrow';
    const tdA0=document.createElement('td'); tdA0.textContent='<?= htmlspecialchars((t('t32_avg')==='t32_avg'?'Tagesmittel':t('t32_avg')),ENT_QUOTES,'UTF-8') ?>'; trAvg.appendChild(tdA0);
    cols.forEach(k=>{
      const td=document.createElement('td');
      td.textContent = n2(avgs[k]).toLocaleString('de-DE');
      trAvg.appendChild(td);
    });
    tf.appendChild(trAvg);
  }
}

/* ===== Reveal-Handling: Codeeingabe -> Server prüft -> € anzeigen ===== */
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
  if(trg && trg.matches('.reveal-link[data-role="reveal-index"]')){
    const ok = await verifyVerguetungCode();
    if (ok) {
      const el = document.getElementById('sum_eur_index');
      if(el){ el.style.display='block'; }
      trg.remove();
    } else {
      alert('Code ungültig.');
    }
  }
});

/* ===== Loop ===== */
function loop(){ refreshLive(); refreshHistory(); }
loop();
setInterval(refreshLive, 12000);
setInterval(refreshHistory, 60000);
</script>
</body>
</html>
