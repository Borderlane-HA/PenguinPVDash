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
        <div class="flow flow-modern flow-modern-orbit" id="flow_modern_e3dc">
          <svg viewBox="0 0 1000 560" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <marker id="flowArrow" markerWidth="14" markerHeight="14" refX="12" refY="7" orient="auto" markerUnits="userSpaceOnUse">
                <path d="M0,1 L13,7 L0,13 z" fill="#9aa6af"></path>
              </marker>
            </defs>

            <path class="flow-base-path" d="M240,130 C330,160 405,218 440,250"></path>
            <path class="flow-base-path" d="M760,130 C670,160 595,218 560,250"></path>
            <path class="flow-base-path" d="M240,430 C330,400 405,342 440,310"></path>
            <path class="flow-base-path" d="M560,310 C595,342 670,400 760,430"></path>

            <path class="flow-active-path" id="m_pv_hub" d="M240,130 C330,160 405,218 440,250" marker-end="url(#flowArrow)" style="display:none"></path>
            <path class="flow-active-path" id="m_hub_grid" d="M560,250 C595,218 670,160 760,130" marker-end="url(#flowArrow)" style="display:none"></path>
            <path class="flow-active-path" id="m_grid_hub" d="M760,130 C670,160 595,218 560,250" marker-end="url(#flowArrow)" style="display:none"></path>
            <path class="flow-active-path" id="m_hub_batt" d="M440,310 C405,342 330,400 240,430" marker-end="url(#flowArrow)" style="display:none"></path>
            <path class="flow-active-path" id="m_batt_hub" d="M240,430 C330,400 405,342 440,310" marker-end="url(#flowArrow)" style="display:none"></path>
            <path class="flow-active-path" id="m_hub_house" d="M560,310 C595,342 670,400 760,430" marker-end="url(#flowArrow)" style="display:none"></path>

            <g id="tok_m_pv_hub"></g>
            <g id="tok_m_grid"></g>
            <g id="tok_m_batt"></g>
            <g id="tok_m_house"></g>
          </svg>

          <div class="flow-autarky-hub" id="flow_autarky_hub" style="--autarky:0">
            <div class="flow-autarky-inner">
              <div class="flow-autarky-value"><strong id="flow_autarky">–</strong><span>%</span></div>
              <small><?= th('flow_autarky_today') ?></small>
            </div>
          </div>

          <div class="node flow-orbit-node flow-orbit-pv" id="n_pv">
            <span class="flow-node-label"><?= th('t1') ?></span>
            <div class="flow-node-icon ico-pv" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="2"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/><line x1="4.2" y1="4.2" x2="6.3" y2="6.3"/><line x1="17.7" y1="17.7" x2="19.8" y2="19.8"/><line x1="17.7" y1="6.3" x2="19.8" y2="4.2"/><line x1="4.2" y1="19.8" x2="6.3" y2="17.7"/></g></svg>
            </div>
            <strong class="flow-node-value" id="pv_now">0 W</strong>
          </div>

          <div class="node flow-orbit-node flow-orbit-grid" id="n_grid">
            <span class="flow-node-label"><?= th('t6') ?></span>
            <div class="flow-node-icon ico-grid" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none"><path d="M12 2v20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M4 7h16M6 10h12M8 13h8M10 16h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M12 2l6 5-6 3-6-3 6-5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
            </div>
            <strong class="flow-node-value" id="modern_grid_now">0 W</strong>
            <small class="flow-node-mode" id="modern_grid_mode"><?= th('flow_idle') ?></small>
          </div>

          <div class="node flow-orbit-node flow-orbit-battery" id="n_batt">
            <strong class="flow-node-value" id="modern_battery_now">0 W</strong>
            <div class="flow-modern-battery-shell">
              <div class="bat" id="bat_icon"><div class="fill" style="width:0%"></div><span class="flow-battery-soc" id="soc_txt">0%</span></div>
            </div>
            <span class="flow-node-label"><?= th('t3') ?></span>
            <small class="flow-node-mode" id="modern_battery_mode"><?= th('flow_idle') ?></small>
          </div>

          <div class="node flow-orbit-node flow-orbit-house" id="n_house">
            <strong class="flow-node-value" id="cons_now">0 W</strong>
            <div class="flow-node-icon ico-house" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none"><path d="M3 11.5L12 4l9 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.5 10.5V20h13V10.5" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><rect x="10" y="13" width="4" height="4.5" stroke="currentColor" stroke-width="2"/></svg>
            </div>
            <span class="flow-node-label"><?= th('t2') ?></span>
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
