<?php
require_once __DIR__ . '/inc/db.php';
?><!doctype html>
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

  <div id="diagram" class="card">
    <svg viewBox="0 0 800 420" class="flow">
      <g class="links">
        <g class="link" id="link-pv-home"><line x1="400" y1="70" x2="650" y2="210"></line><circle class="dot" r="4"></circle></g>
        <g class="link" id="link-pv-grid"><line x1="400" y1="70" x2="150" y2="210"></line><circle class="dot" r="4"></circle></g>
        <g class="link" id="link-pv-batt"><line x1="400" y1="70" x2="400" y2="330"></line><circle class="dot" r="4"></circle></g>
        <g class="link" id="link-grid-home"><line x1="150" y1="210" x2="650" y2="210"></line><circle class="dot" r="4"></circle></g>
        <g class="link" id="link-grid-batt"><line x1="150" y1="210" x2="400" y2="330"></line><circle class="dot" r="4"></circle></g>
        <g class="link" id="link-batt-home"><line x1="400" y1="330" x2="650" y2="210"></line><circle class="dot" r="4"></circle></g>
      </g>

      <g id="node-pv" class="node" transform="translate(400,70)">
        <circle r="48"></circle>
        <text y="-22" class="title">PV</text>
        <text y="-2" class="value" id="pv-val">–</text>
      </g>
      <g id="node-home" class="node" transform="translate(650,210)">
        <circle r="48"></circle>
        <text y="-22" class="title">Zuhause</text>
        <text y="-2" class="value" id="home-val">–</text>
      </g>
      <g id="node-grid" class="node" transform="translate(150,210)">
        <circle r="48"></circle>
        <text y="-22" class="title">Netz</text>
        <text y="-2" class="value" id="grid-val">–</text>
      </g>
      <g id="node-batt" class="node" transform="translate(400,340)">
        <circle r="48"></circle>
        <text y="-22" class="title">Batterie</text>
        <text y="-2" class="value" id="batt-val">–</text>
      </g>
    </svg>

    <div class="today-grid">
      <div class="mini-card">
        <div class="mini-head"><span>Batterie</span><span id="soc-text" class="badge">–%</span></div>
        <div class="battery">
          <svg viewBox="0 0 140 60" class="battery-svg">
            <rect x="2" y="10" width="120" height="40" rx="6" ry="6" class="b-body"/>
            <rect x="122" y="22" width="14" height="16" rx="3" ry="3" class="b-cap"/>
            <clipPath id="b-clip"><rect x="2" y="10" width="120" height="40" rx="6" ry="6"/></clipPath>
            <rect id="b-fill" x="2" y="10" width="0" height="40" class="b-fill" clip-path="url(#b-clip)"/>
            <g class="ticks" id="b-ticks"></g>
          </svg>
        </div>
        <div class="mini-rows">
          <div><span class="muted">IN heute</span><span id="bi-day">– kWh</span></div>
          <div><span class="muted">OUT heute</span><span id="bo-day">– kWh</span></div>
        </div>
      </div>

      <div class="mini-card">
        <div class="mini-head"><span>PV gesamt (Tag)</span></div>
        <div class="big-val"><span id="pv-day">–</span><small>kWh</small></div>
      </div>

      <div class="mini-card">
        <div class="mini-head"><span>Einspeisung gesamt (Tag)</span></div>
        <div class="big-val"><span id="fi-day">–</span><small>kWh</small></div>
      </div>

      <div class="mini-card span-2">
        <div class="mini-head"><span>Verbrauch & Netzbezug (Tag)</span></div>
        <div class="mini-rows">
          <div><span class="muted">Verbrauch gesamt</span><span id="cons-day">– kWh</span></div>
          <div><span class="muted">Netzbezug gesamt</span><span id="imp-day">– kWh</span></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Letzte 30 Tage</h2>
      <div class="legend-dots">
        <span><i class="dot dot-pv"></i>PV</span>
        <span><i class="dot dot-fi"></i>Einspeisung</span>
        <span><i class="dot dot-bi"></i>Batt IN</span>
        <span><i class="dot dot-bo"></i>Batt OUT</span>
        <span><i class="dot dot-cons"></i>Verbrauch</span>
        <span><i class="dot dot-imp"></i>Netzbezug</span>
      </div>
    </div>
    <div class="table-wrap">
      <table class="fancy">
        <thead>
          <tr><th>Tag</th><th>PV</th><th>Einspeisung</th><th>Batt IN</th><th>Batt OUT</th><th>Verbrauch</th><th>Netzbezug</th></tr>
        </thead>
        <tbody id="hist-tbody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
const fmt = v => (v==null || isNaN(v)) ? "–" : (Math.round(v*100)/100).toString().replace('.', ',');

function setBatterySOC(pct){
  pct = Math.max(0, Math.min(100, pct||0));
  const fill = document.getElementById('b-fill');
  const w = 120 * (pct/100);
  fill.setAttribute('width', w);
  const socText = document.getElementById('soc-text');
  socText.textContent = Math.round(pct) + "%";
  let c = '#5ad17d'; if (pct < 30) c = '#ff7b7b'; else if (pct < 60) c = '#ffd166';
  fill.style.fill = c;
}

function moveDot(linkId, active, speedKW){
  const g = document.getElementById(linkId);
  const line = g.querySelector('line');
  const dot = g.querySelector('.dot');
  if (g._anim) cancelAnimationFrame(g._anim);
  if (!active || !line){ dot.setAttribute('cx', -10); dot.setAttribute('cy', -10); return; }
  const x1 = parseFloat(line.getAttribute('x1')), y1=parseFloat(line.getAttribute('y1'));
  const x2 = parseFloat(line.getAttribute('x2')), y2=parseFloat(line.getAttribute('y2'));
  let t = 0;
  const dt = Math.max(0.002, Math.min(0.03, (speedKW||0)/50));
  function step(){ t += dt; if (t>1) t=0; const x = x1 + (x2-x1)*t, y=y1 + (y2-y1)*t; dot.setAttribute('cx', x); dot.setAttribute('cy', y); g._anim = requestAnimationFrame(step); }
  step();
}

async function getJSON(url){ const r = await fetch(url + (url.includes('?')?'&':'?') + "_t="+Date.now()); return r.json(); }

async function refresh(){
  try{
    const last = await getJSON("api/last.php?device=home");
    const L = last.latest || {}; const T = last.today || {};
    const unit = (L.unit || "kW");
    const S = (v)=>{ let s = parseFloat(v||0); if (isNaN(s)) return 0; return unit==='W' ? s/1000 : s; };

    document.getElementById('pv-val').textContent   = fmt(L.pv_power) + " " + unit;
    document.getElementById('home-val').textContent = "Haus " + fmt(L.consumption) + " " + unit;
    document.getElementById('grid-val').textContent = "Netz " + fmt(L.grid_import) + " " + unit;
    document.getElementById('batt-val').textContent = fmt(L.battery_discharge||0)+" | "+fmt(L.battery_charge||0) + " " + unit;

    document.getElementById('pv-day').textContent   = fmt(T?.pv_kwh);
    document.getElementById('fi-day').textContent   = fmt(T?.feed_in_kwh);
    document.getElementById('bi-day').textContent   = fmt(T?.batt_in_kwh) + " kWh";
    document.getElementById('bo-day').textContent   = fmt(T?.batt_out_kwh) + " kWh";
    document.getElementById('cons-day').textContent = fmt(T?.consumption_kwh) + " kWh";
    document.getElementById('imp-day').textContent  = fmt(T?.grid_import_kwh) + " kWh";
    setBatterySOC(L.battery_soc!=null ? parseFloat(L.battery_soc) : 0);

    const pv = S(L.pv_power), cons = S(L.consumption), gi = S(L.grid_import), fi = S(L.feed_in), ch = S(L.battery_charge), dis = S(L.battery_discharge);

    const home_from_pv = Math.max(0, cons - gi);
    moveDot('link-pv-home', pv > 0 && home_from_pv > 0, Math.min(pv, home_from_pv));
    moveDot('link-pv-grid', pv > 0 && fi > 0, fi);
    moveDot('link-pv-batt', pv > 0 && ch > 0, Math.min(pv, ch));
    moveDot('link-grid-home', gi > 0, gi);
    const grid_to_batt = Math.max(0, ch - pv);
    moveDot('link-grid-batt', grid_to_batt > 0, grid_to_batt);
    moveDot('link-batt-home', dis > 0, dis);
  }catch(e){ console.error(e); }
}

async function drawHistory(){
  const j = await getJSON("api/daily.php?device=home&days=30");
  const items = j.items || [];
  const tb = document.getElementById('hist-tbody');
  tb.innerHTML = "";
  items.forEach(it=>{
    const tr = document.createElement('tr');
    const d = document.createElement('td'); d.textContent = it.day || ""; tr.appendChild(d);
    function cell(val, cls){ const c=document.createElement('td'); c.textContent=(val==null?'–':(Math.round(val*100)/100).toLocaleString('de-DE')); if(cls)c.className=cls; return c; }
    tr.appendChild(cell(it.pv_kwh,'pv'));
    tr.appendChild(cell(it.feed_in_kwh,'fi'));
    tr.appendChild(cell(it.batt_in_kwh,'bi'));
    tr.appendChild(cell(it.batt_out_kwh,'bo'));
    tr.appendChild(cell(it.consumption_kwh,'cons'));
    tr.appendChild(cell(it.grid_import_kwh,'imp'));
    tb.appendChild(tr);
  });
}

function buildBatteryTicks(){
  const g = document.getElementById('b-ticks');
  if (!g) return; g.innerHTML = "";
  for (let i=1;i<=4;i++){ const x=2+i*24; const r=document.createElementNS('http://www.w3.org/2000/svg','rect'); r.setAttribute('x',x); r.setAttribute('y',10); r.setAttribute('width',2); r.setAttribute('height',40); r.setAttribute('class','b-tick'); g.appendChild(r); }
}

buildBatteryTicks();
refresh(); drawHistory();
setInterval(refresh, 5000);
setInterval(drawHistory, 60000);
</script>
</body>
</html>
