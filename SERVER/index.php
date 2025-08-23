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
    $text = $T[$key] ?? $EN[$key] ?? $key; // Fallback auf EN oder Key
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

// Für sichere HTML-Ausgabe:
function th(string $key, array $vars = []): string {
    return htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');
}

?>

<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>PenguinPVDash</title>
  <link rel="stylesheet" href="assets/style.css"/>
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
          <path id="l_pv_house"   d="M160,50 C360,50 540,50 740,50"   stroke="#6be29f" stroke-width="4" fill="none"></path>
          <path id="l_pv_grid"    d="M160,70 C360,110 540,190 740,230" stroke="#6be29f" stroke-width="4" fill="none"></path>
          <path id="l_pv_batt"    d="M130,70 C220,120 220,200 130,210" stroke="#6be29f" stroke-width="4" fill="none"></path>
          <path id="l_batt_house" d="M90,210  C320,180 520,90  740,50"  stroke="#ffd480" stroke-width="4" fill="none"></path>

          <!-- Token-Gruppen für Animation -->
          <g id="tok_pv_house"></g>
          <g id="tok_pv_grid"></g>
          <g id="tok_pv_batt"></g>
          <g id="tok_batt_house"></g>
        </svg>

        <!-- Labels über den Linien -->
        <!-- <div class="flow-label" id="lab_pv_house"   style="left:430px; top:22px">PV→Haus 0,0 kW</div> -->
        <!-- <div class="flow-label" id="lab_pv_grid"    style="left:430px; top:140px">PV→Netz 0,0 kW</div> -->
        <!-- <div class="flow-label" id="lab_pv_batt"    style="left:140px; top:150px">PV→Batterie 0,0 kW</div> -->
        <!-- <div class="flow-label" id="lab_batt_house" style="left:430px; top:80px">Batterie→Haus 0,0 kW</div> -->

        <!-- Knoten -->
        <div class="node" id="n_pv">
          <h3><?= th('t1') ?></h3>
          <div class="sub"><span id="pv_now">0,0 kW</span></div>
        </div>

        <div class="node" id="n_house">
          <h3><?= th('t2') ?></h3>
          <div class="sub"><span id="cons_now">0,0 kW</span></div>
        </div>

        <div class="node" id="n_grid">
          <h3><?= th('t6') ?></h3>
          <div class="sub">
            <?= th('t7') ?>: <span id="grid_import_now">0,0</span> kW ·
            <?= th('t8') ?>: <span id="export_now">0,0</span> kW
          </div>
        </div>

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
      <div class="box">
        <div class="label"><?= th('t10') ?> (kWh):</div>
        <div class="val" id="k_pv">–</div>
      </div>
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
          <div class="row"><span><?= th('t16') ?> (kWh):</span><span id="k_imp">–</span></div>
          <div class="row"><span><?= th('t17') ?> (kWh):</span><span id="k_feed">–</span></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= th('t18') ?> (kWh)</h2></div>
    <div class="table-wrap">
      <table class="fancy">
        <thead>
          <tr>
            <th><?= th('t19') ?></th><th><?= th('t20') ?></th><th><?= th('t21') ?></th><th><?= th('t22') ?></th><th><?= th('t23') ?></th><th><?= th('t24') ?></th><th><?= th('t25') ?></th>
          </tr>
        </thead>
        <tbody id="hist-tbody"></tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= th('t26') ?>…</h2></div>
    <p><?= th('t27') ?>: <a href="stats.php" target="_blank"><?= th('t28') ?></a></p>
  </div>

</div>

<script>
/* ===== Helpers ===== */
function f2(v){ return (Math.round((v||0)*100)/100).toLocaleString('de-DE'); }
function kw(v){ return f2(Math.max(0, v||0)); }
function setText(id,v){ const el=document.getElementById(id); if(el) el.textContent=v; }
function setFlowVisible(id,on){ const e=document.getElementById(id); if(e) e.style.display = on? '' : 'none'; }
function scaleWidth(v){ const x=Math.max(0, v||0); return Math.min(16, 2 + x*2); }
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
function flowVisuals(v){
  const x=Math.max(0, v||0);
  const count = Math.min(10, Math.max(0, Math.round(x*2))); // bis 10 Kreise
  const dur = Math.max(1.2, Math.min(12, 8/(x>0?x:0.1)));  // je mehr Leistung, desto schneller
  return {count, dur};
}
function setLabel(id, txt){ const e=document.getElementById(id); if(e) e.textContent=txt; }

/* ===== Live-Refresh ===== */
async function refreshLive(){
  const r = await fetch('api/last.php?device=home&_t='+Date.now());
  const j = await r.json();
  const s = j.latest || {};
  const t = j.today || {};

  // Einheit in kW normalisieren
  const unit = (s.unit||'kW').toLowerCase();
  const toKW = unit==='w' ? (x)=>x/1000 : (x)=>x;

  const pv   = toKW(parseFloat(s.pv_power ?? 0));
  const cons = toKW(parseFloat(s.consumption ?? 0));
  const gi   = toKW(parseFloat(s.grid_import ?? 0));  // kann bei Export negativ sein
  const fi   = toKW(parseFloat(s.feed_in));           // kann fehlen/NaN sein

  // Einspeisung robust ableiten:
  // 1) Wenn feed_in geliefert wird → clamp >= 0
  // 2) sonst: aus negativem Netzbezug ableiten
  const exportKW = Number.isFinite(fi) ? Math.max(fi, 0) : Math.max(-gi, 0);
  const importKW = Math.max(gi, 0);

  const bi  = toKW(parseFloat(s.battery_charge ?? 0));
  const bo  = toKW(parseFloat(s.battery_discharge ?? 0));
  const soc = parseFloat(s.battery_soc ?? 0);

  // Knotenwerte
  setText('pv_now', kw(pv)+' kW');
  setText('cons_now', kw(cons)+' kW');
  setText('grid_import_now', kw(importKW));
  setText('export_now', kw(exportKW));
  setText('b_in_now', kw(bi));
  setText('b_out_now', kw(bo));
  setText('soc_txt', isFinite(soc) ? Math.round(soc)+'%' : '0%');

  // Batterie-Icon Füllstand/Farbe
  const bat = document.getElementById('bat_icon');
  const fill = bat.querySelector('.fill');
  const p = Math.max(0, Math.min(100, isFinite(soc)?soc:0));
  fill.style.width = p+'%';
  bat.classList.remove('low','mid');
  if(p<30) bat.classList.add('low'); else if(p<60) bat.classList.add('mid');

  // Sichtbarkeit/Logik der Flüsse:
  // - PV->Haus/Netz/Batt nur wenn PV>0
  // - Batt->Haus nur wenn Batt OUT>0
  // - nie Batt->Netz
  const pvOn          = pv > 0.0001;
  const pv_house_on   = pvOn && cons     > 0.0001;
  const pv_grid_on    = pvOn && exportKW > 0.0001;
  const pv_batt_on    = pvOn && bi       > 0.0001;
  const batt_house_on = bo   > 0.0001;

  setFlowVisible('l_pv_house',   pv_house_on);
  setFlowVisible('l_pv_grid',    pv_grid_on);
  setFlowVisible('l_pv_batt',    pv_batt_on);
  setFlowVisible('l_batt_house', batt_house_on);

  // Linienstärke nach Leistung
  if(pv_house_on)   document.getElementById('l_pv_house').setAttribute('stroke-width', scaleWidth(cons));
  if(pv_grid_on)    document.getElementById('l_pv_grid').setAttribute('stroke-width',  scaleWidth(exportKW));
  if(pv_batt_on)    document.getElementById('l_pv_batt').setAttribute('stroke-width',  scaleWidth(bi));
  if(batt_house_on) document.getElementById('l_batt_house').setAttribute('stroke-width',scaleWidth(bo));

  // Linien-Labels aktualisieren
  setLabel('lab_pv_house',   'PV→Haus '     + kw(cons)     + ' kW');
  setLabel('lab_pv_grid',    'PV→Netz '     + kw(exportKW) + ' kW'); // <- Einspeisung
  setLabel('lab_pv_batt',    'PV→Batterie ' + kw(bi)       + ' kW');
  setLabel('lab_batt_house', 'Batterie→Haus '+ kw(bo)      + ' kW');

  // Tokens (Kreise) entlang der Pfade
  const v1 = flowVisuals(cons);
  rebuildTokens('tok_pv_house','l_pv_house', pv_house_on ? v1.count : 0, v1.dur, 'pv');

  const v2 = flowVisuals(exportKW);
  rebuildTokens('tok_pv_grid','l_pv_grid', pv_grid_on ? v2.count : 0, v2.dur, 'pv');

  const v3 = flowVisuals(bi);
  rebuildTokens('tok_pv_batt','l_pv_batt', pv_batt_on ? v3.count : 0, v3.dur, 'pv');

  const v4 = flowVisuals(bo);
  rebuildTokens('tok_batt_house','l_batt_house', batt_house_on ? v4.count : 0, v4.dur, 'batt');

  // Tageswerte (kWh)
  function tv(x){ return x==null ? '–' : f2(parseFloat(x)); }
  setText('k_pv',   tv(t.pv_kwh));
  setText('k_feed', tv(t.feed_in_kwh));
  setText('k_bin',  tv(t.batt_in_kwh));
  setText('k_bout', tv(t.batt_out_kwh));
  setText('k_cons', tv(t.consumption_kwh));
  setText('k_imp',  tv(t.grid_import_kwh));
}

/* ===== History ===== */
async function refreshHistory(){
  const r = await fetch('api/daily.php?device=home&days=30&_t='+Date.now());
  const j = await r.json();
  const items = j.items || [];
  const tb = document.getElementById('hist-tbody');
  tb.innerHTML = '';
  items.forEach(it=>{
    const tr=document.createElement('tr');
    const d=document.createElement('td'); d.textContent=it.day; tr.appendChild(d);
    ['pv_kwh','feed_in_kwh','batt_in_kwh','batt_out_kwh','consumption_kwh','grid_import_kwh'].forEach(k=>{
      const c=document.createElement('td');
      const v=it[k];
      c.textContent = (v==null ? '–' : (Math.round(v*100)/100).toLocaleString('de-DE'));
      tr.appendChild(c);
    });
    tb.appendChild(tr);
  });
}

/* ===== Loop ===== */
function loop(){ refreshLive(); refreshHistory(); }
loop();
setInterval(refreshLive, 12000);
setInterval(refreshHistory, 60000);
</script>
</body>
</html>
