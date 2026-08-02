<?php

declare(strict_types=1);

function pvdash_render_standard_flow(): void
{
    ?>
    <div class="card">
      <div class="flowwrap">
        <div class="flow">
          <svg viewBox="0 0 900 260" preserveAspectRatio="none" aria-hidden="true">
            <path id="l_pv_house" d="M160,50 C360,50 540,50 740,50" stroke="#6be29f" stroke-width="3" fill="none"></path>
            <path id="l_pv_grid" d="M160,70 C360,110 540,190 740,230" stroke="#6be29f" stroke-width="3" fill="none"></path>
            <path id="l_pv_batt" d="M130,70 C220,120 220,200 130,210" stroke="#6be29f" stroke-width="3" fill="none"></path>
            <path id="l_batt_house" d="M90,210 C320,180 520,90 740,50" stroke="#ffd480" stroke-width="3" fill="none"></path>
            <path id="l_grid_house" d="M740,230 C700,200 700,80 740,50" stroke="#89b4ff" stroke-width="3" fill="none"></path>
            <g id="tok_pv_house"></g><g id="tok_pv_grid"></g><g id="tok_pv_batt"></g><g id="tok_batt_house"></g><g id="tok_grid_house"></g>
          </svg>

          <div class="node" id="n_pv">
            <div class="ico ico-pv" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4.5" stroke="#25c77a" stroke-width="2"/><g stroke="#25c77a" stroke-width="2" stroke-linecap="round"><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/><line x1="4.2" y1="4.2" x2="6.3" y2="6.3"/><line x1="17.7" y1="17.7" x2="19.8" y2="19.8"/><line x1="17.7" y1="6.3" x2="19.8" y2="4.2"/><line x1="4.2" y1="19.8" x2="6.3" y2="17.7"/></g></svg>
            </div>
            <div><h3><?= th('t1') ?></h3><div class="sub"><span id="pv_now">0,0 kW</span></div></div>
          </div>

          <div class="node" id="n_house">
            <div class="ico ico-house" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none"><path d="M3 11.5L12 4l9 7.5" stroke="#5a8cff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.5 10.5V20h13V10.5" stroke="#5a8cff" stroke-width="2" stroke-linejoin="round"/><rect x="10" y="13" width="4" height="4.5" stroke="#5a8cff" stroke-width="2"/></svg>
            </div>
            <div><h3><?= th('t2') ?></h3><div class="sub"><span id="cons_now">0,0 kW</span></div></div>
          </div>

          <div class="node" id="n_grid">
            <div class="ico ico-grid" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none"><path d="M12 2v20" stroke="#f4a84a" stroke-width="2" stroke-linecap="round"/><path d="M4 7h16M6 10h12M8 13h8M10 16h4" stroke="#f4a84a" stroke-width="2" stroke-linecap="round"/><path d="M12 2l6 5-6 3-6-3 6-5z" stroke="#f4a84a" stroke-width="2" stroke-linejoin="round"/></svg>
            </div>
            <div><h3><?= th('t6') ?></h3><div class="sub"><?= th('t7') ?>: <span id="grid_import_now">0,0</span> kW · <?= th('t8') ?>: <span id="export_now">0,0</span> kW</div></div>
          </div>

          <div class="node" id="n_batt">
            <div class="bat" id="bat_icon"><div class="fill" style="width:0%"></div></div>
            <div><h3><?= th('t3') ?> <span id="soc_txt">0%</span></h3><div class="sub"><?= th('t4') ?>: <span id="b_in_now">0,0</span> kW · <?= th('t5') ?>: <span id="b_out_now">0,0</span> kW</div></div>
          </div>
        </div>
      </div>
    </div>
    <?php
}

function pvdash_render_modern_flow(): void
{
    ?>
    <div class="card flow-card-modern">
      <div class="flowwrap flowwrap-modern">
        <div class="flow flow-modern">
          <div class="flow-hub-glow" aria-hidden="true"></div>
          <svg viewBox="0 0 1000 500" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient id="modernPvGradient" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#34d399"/><stop offset="1" stop-color="#22c55e"/></linearGradient>
              <linearGradient id="modernBattGradient" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fbbf24"/><stop offset="1" stop-color="#fb923c"/></linearGradient>
              <linearGradient id="modernGridGradient" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#60a5fa"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient>
            </defs>
            <path id="l_pv_house" d="M500,105 C500,170 500,205 500,245" stroke="url(#modernPvGradient)" stroke-width="5" fill="none" stroke-linecap="round"></path>
            <path id="l_pv_batt" d="M455,108 C360,175 285,250 235,350" stroke="url(#modernPvGradient)" stroke-width="5" fill="none" stroke-linecap="round"></path>
            <path id="l_pv_grid" d="M545,108 C640,175 715,250 765,350" stroke="url(#modernPvGradient)" stroke-width="5" fill="none" stroke-linecap="round"></path>
            <path id="l_batt_house" d="M285,385 C365,350 430,315 475,285" stroke="url(#modernBattGradient)" stroke-width="5" fill="none" stroke-linecap="round"></path>
            <path id="l_grid_house" d="M715,385 C635,350 570,315 525,285" stroke="url(#modernGridGradient)" stroke-width="5" fill="none" stroke-linecap="round"></path>
            <g id="tok_pv_house"></g><g id="tok_pv_grid"></g><g id="tok_pv_batt"></g><g id="tok_batt_house"></g><g id="tok_grid_house"></g>
          </svg>

          <div class="modern-flow-label label-pv-house" id="lab_pv_house"></div>
          <div class="modern-flow-label label-pv-batt" id="lab_pv_batt"></div>
          <div class="modern-flow-label label-pv-grid" id="lab_pv_grid"></div>
          <div class="modern-flow-label label-batt-house" id="lab_batt_house"></div>
          <div class="modern-flow-label label-grid-house" id="lab_grid_house"></div>

          <div class="node modern-node modern-pv" id="n_pv">
            <div class="ico ico-pv" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4.5" stroke="#25c77a" stroke-width="2"/><g stroke="#25c77a" stroke-width="2" stroke-linecap="round"><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/><line x1="4.2" y1="4.2" x2="6.3" y2="6.3"/><line x1="17.7" y1="17.7" x2="19.8" y2="19.8"/><line x1="17.7" y1="6.3" x2="19.8" y2="4.2"/><line x1="4.2" y1="19.8" x2="6.3" y2="17.7"/></g></svg></div>
            <div><span class="modern-node-kicker"><?= th('flow_source') ?></span><h3><?= th('t1') ?></h3><div class="sub"><span id="pv_now">0,0 kW</span></div></div>
          </div>

          <div class="node modern-node modern-house" id="n_house">
            <div class="ico ico-house" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M3 11.5L12 4l9 7.5" stroke="#5a8cff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.5 10.5V20h13V10.5" stroke="#5a8cff" stroke-width="2" stroke-linejoin="round"/><rect x="10" y="13" width="4" height="4.5" stroke="#5a8cff" stroke-width="2"/></svg></div>
            <div><span class="modern-node-kicker"><?= th('flow_live_demand') ?></span><h3><?= th('t2') ?></h3><div class="sub"><span id="cons_now">0,0 kW</span></div></div>
          </div>

          <div class="node modern-node modern-battery" id="n_batt">
            <div class="modern-battery-icon"><div class="bat" id="bat_icon"><div class="fill" style="width:0%"></div></div></div>
            <div><span class="modern-node-kicker"><?= th('flow_storage') ?></span><h3><?= th('t3') ?> <span id="soc_txt">0%</span></h3><div class="sub"><?= th('t4') ?>: <span id="b_in_now">0,0</span> kW · <?= th('t5') ?>: <span id="b_out_now">0,0</span> kW</div></div>
          </div>

          <div class="node modern-node modern-grid" id="n_grid">
            <div class="ico ico-grid" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 2v20" stroke="#f4a84a" stroke-width="2" stroke-linecap="round"/><path d="M4 7h16M6 10h12M8 13h8M10 16h4" stroke="#f4a84a" stroke-width="2" stroke-linecap="round"/><path d="M12 2l6 5-6 3-6-3 6-5z" stroke="#f4a84a" stroke-width="2" stroke-linejoin="round"/></svg></div>
            <div><span class="modern-node-kicker"><?= th('flow_grid') ?></span><h3><?= th('t6') ?></h3><div class="sub"><?= th('t7') ?>: <span id="grid_import_now">0,0</span> kW · <?= th('t8') ?>: <span id="export_now">0,0</span> kW</div></div>
          </div>
        </div>
      </div>
    </div>
    <?php
}

function pvdash_render_energy_flow(string $style): void
{
    if ($style === 'modern') {
        pvdash_render_modern_flow();
        return;
    }
    pvdash_render_standard_flow();
}
