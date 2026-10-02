<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/sicherheit.php';
td_seite_schuetzen();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tesla Dashboard</title>
<link rel="icon" href="/assets/icons/favicon.svg?v=2" type="image/svg+xml" id="favicon">
<link rel="alternate icon" href="/favicon.ico?v=2" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
<link rel="manifest" href="/manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#0a0a14">
<meta name="apple-mobile-web-app-title" content="Tesla">
<meta name="mobile-web-app-capable" content="yes">
<link rel="stylesheet" href="/assets/tesla.css?v=36">
<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>
<body>

<!-- ═══════════════════════════════════════════ HEADER ═══ -->
<header id="app-header">
  <div class="header-brand">
    <img class="t-logo" src="/assets/icons/favicon.svg?v=2" alt="">
    <h1>Tesla Dashboard</h1>
  </div>
  <div class="header-meta">
    <span id="vehicle-state-badge" class="state-badge state-online">
      <span class="dot state-dot-online"></span>
      <span id="state-label">Laden…</span>
    </span>
    <span id="header-soc" style="font-variant-numeric:tabular-nums;font-weight:700;font-size:.9rem"></span>
    <span id="header-range" style="font-size:.8rem;color:var(--muted)"></span>
    <span id="header-time" class="header-time"></span>
    <a href="/logout.php" class="btn-logout">Abmelden</a>
  </div>
</header>

<!-- ═══════════════════════════════════════════ TABS ═══ -->
<nav id="tab-nav" role="tablist">
  <button class="tab-btn active" data-tab="overview" role="tab"><span class="tab-icon">⚡</span>Übersicht</button>
  <button class="tab-btn" data-tab="map"      role="tab"><span class="tab-icon">📍</span>Karte</button>
  <button class="tab-btn" data-tab="drives"   role="tab"><span class="tab-icon">🚗</span>Fahrten</button>
  <button class="tab-btn" data-tab="charging" role="tab"><span class="tab-icon">🔋</span>Laden</button>
  <button class="tab-btn" data-tab="battery"  role="tab"><span class="tab-icon">📊</span>Batterie</button>
  <button class="tab-btn" data-tab="statistik" role="tab"><span class="tab-icon">📈</span>Statistik</button>
  <button class="tab-btn" data-tab="pendeln" role="tab"><span class="tab-icon">🔁</span>Pendeln</button>
  <?php if (DAHEIM_API !== ''): ?><button class="tab-btn" data-tab="wallbox"  role="tab"><span class="tab-icon">🏠</span>Wallbox</button><?php endif; ?>
</nav>

<!-- ═══════════════════════════════════════════ CONTENT ═══ -->
<main id="main-content">

  <!-- ─────────────── TAB: ÜBERSICHT ─────────────── -->
  <div id="tab-overview" class="tab-panel active">
    <!-- Auffälligkeiten der letzten 48 h -->
    <div class="card af-karte" style="margin-bottom:1rem">
      <div class="card-header">
        <span class="card-title">Auffälligkeiten (letzte 48 h)</span>
        <span class="stat-hint" id="af-stand"></span>
      </div>
      <div id="af-liste"><div class="loading-msg"><div class="spinner"></div>Prüfe…</div></div>
    </div>

    <!-- Fahrzeugzustand (TeslaMate-MQTT, nur lesen; ausgeblendet ohne MQTT-Quelle) -->
    <div class="card" style="margin-bottom:1rem"<?= ZUSTAND_QUELLE === 'keine' ? ' hidden' : '' ?>>
      <div class="card-header">
        <span class="card-title">Fahrzeugzustand</span>
        <span class="stat-hint" id="zs-stand"></span>
      </div>
      <div id="zs-hinweise"></div>
      <div class="zs-grid" id="zs-grid"><div class="loading-msg"><div class="spinner"></div>Lade…</div></div>
    </div>

    <!-- Zeitleiste: fahren / laden / wach / schläft -->
    <div class="card" style="margin-bottom:1rem">
      <div class="card-header" style="flex-wrap:wrap;gap:.5rem">
        <span class="card-title">Zeitleiste</span>
        <div class="range-filter" id="zl-range-filter" style="margin:0">
          <button class="range-btn active" data-range="24h">24 h</button>
          <button class="range-btn" data-range="48h">48 h</button>
          <button class="range-btn" data-range="7d">7 Tage</button>
        </div>
      </div>
      <div class="zl-wrap">
        <div class="zl-balken" id="zl-balken"></div>
        <div class="zl-achse" id="zl-achse"></div>
        <div class="zl-info" id="zl-info">Für Details über den Balken fahren oder tippen.</div>
      </div>
      <div class="zl-legende" id="zl-legende"></div>
    </div>

    <div class="grid-4" style="margin-bottom:1rem">
      <div class="stat-card" id="ov-akku-karte">
        <div class="stat-label">Batterie <span class="lade-badge" id="ov-lade-badge" hidden></span></div>
        <div class="stat-value val-green" id="ov-soc">--<span class="stat-unit">%</span></div>
        <div class="battery-wrap">
          <div class="battery-track"><div class="battery-fill" id="ov-soc-bar" style="width:0%"></div><div class="battery-limit" id="ov-soc-limit" hidden></div></div>
          <span class="battery-pct" id="ov-soc-pct">--%</span>
        </div>
        <div class="stat-sub" id="ov-range">Reichweite: -- km</div>
        <div class="lade-info" id="ov-laden" hidden></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Temperatur</div>
        <div class="stat-value" id="ov-temp">--<span class="stat-unit">°C außen</span></div>
        <div class="stat-sub" id="ov-temp-in">Innen: -- °C</div>
        <div class="stat-sub" id="ov-heater"></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Letzte Fahrt</div>
        <div class="stat-value val-blue" id="ov-last-dist">--<span class="stat-unit">km</span></div>
        <div class="stat-sub" id="ov-last-route">-- → --</div>
        <div class="stat-sub" id="ov-last-soc"></div>
      </div>
      <div class="stat-card status-karte" id="ov-status-karte">
        <div class="stat-label">Status</div>
        <div class="stat-value" id="ov-state" style="font-size:1.4rem">--</div>
        <div class="stat-sub" id="ov-since"></div>
        <div class="stat-sub" id="ov-speed"></div>
      </div>
    </div>

    <div class="grid-2" style="margin-bottom:1rem">
      <!-- Mini-Karte -->
      <div class="card" style="padding:0;overflow:hidden">
        <div id="mini-map" style="height:280px;background:var(--card)"></div>
      </div>
      <!-- TPMS -->
      <div class="card" id="ov-tpms-karte">
        <div class="card-header">
          <span class="card-title">Reifendruck (bar)</span>
          <span style="font-size:.75rem;color:var(--muted)">Soll: <?= TPMS_NOMINAL_BAR ?> bar</span>
        </div>
        <div class="tpms-car" id="tpms-diagram">
          <!-- Auto von oben (Proportionen eines Model X: 5,04 m lang, 2,0 m breit) -->
          <svg viewBox="0 0 120 200" xmlns="http://www.w3.org/2000/svg" aria-label="Auto von oben">
            <defs>
              <linearGradient id="tx-lack" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#2b2d4d"/><stop offset=".5" stop-color="#40436e"/><stop offset="1" stop-color="#2b2d4d"/>
              </linearGradient>
              <linearGradient id="tx-glas" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#1b2140"/><stop offset=".55" stop-color="#121529"/><stop offset="1" stop-color="#1d2446"/>
              </linearGradient>
            </defs>
            <!-- Räder (unter der Karosserie) -->
            <g fill="#0c0d18" stroke="#34365c" stroke-width="1">
              <rect x="15" y="29" width="12" height="29" rx="4"/><rect x="93" y="29" width="12" height="29" rx="4"/>
              <rect x="15" y="135" width="12" height="29" rx="4"/><rect x="93" y="135" width="12" height="29" rx="4"/>
            </g>
            <!-- Außenspiegel -->
            <g fill="#3a3d66"><ellipse cx="19" cy="62" rx="5" ry="3"/><ellipse cx="101" cy="62" rx="5" ry="3"/></g>
            <!-- Karosserie -->
            <path d="M60 9 C80 9 93 15 95 29 L96 58 C97 70 96.5 80 96 90 L96 150 C97 163 96 176 92 184 C86 191 74 192 60 192
                     C46 192 34 191 28 184 C24 176 23 163 24 150 L24 90 C23.5 80 23 70 24 58 L25 29 C27 15 40 9 60 9 Z"
                  fill="url(#tx-lack)" stroke="#5c5f93" stroke-width="1.2"/>
            <!-- Scheinwerfer und Motorhaube -->
            <path d="M31 21 C35 16 41 14 46 14" stroke="#cfe0ff" stroke-width="2.2" stroke-linecap="round" fill="none" opacity=".75"/>
            <path d="M89 21 C85 16 79 14 74 14" stroke="#cfe0ff" stroke-width="2.2" stroke-linecap="round" fill="none" opacity=".75"/>
            <path d="M42 30 C52 27 68 27 78 30" stroke="#5c5f93" stroke-width=".8" fill="none"/>
            <!-- Panorama-Frontscheibe (reicht beim X bis übers Dach) -->
            <path d="M33 50 C44 44 76 44 87 50 L88 100 C76 104 44 104 32 100 Z" fill="url(#tx-glas)" stroke="#5c5f93" stroke-width=".8"/>
            <path d="M40 52 C50 49 58 48 64 48" stroke="#ffffff" stroke-width="1.4" stroke-linecap="round" opacity=".18" fill="none"/>
            <!-- Falcon-Wing-Türen mit mittigem Scharnier -->
            <path d="M32 104 L58 106 L58 147 L33 145 Z" fill="#35385f" stroke="#5c5f93" stroke-width=".8"/>
            <path d="M88 104 L62 106 L62 147 L87 145 Z" fill="#35385f" stroke="#5c5f93" stroke-width=".8"/>
            <line x1="60" y1="104" x2="60" y2="149" stroke="#7a7eb8" stroke-width="1.2"/>
            <!-- Heckscheibe, Heckklappe, Rücklicht -->
            <path d="M36 152 C48 156 72 156 84 152 L82 168 C70 171 50 171 38 168 Z" fill="url(#tx-glas)" stroke="#5c5f93" stroke-width=".8"/>
            <path d="M30 183 C45 189 75 189 90 183" stroke="#e31937" stroke-width="2.4" stroke-linecap="round" fill="none"/>
          </svg>
          <div class="tpms-wheel fl"><span class="psi-val" id="tpms-fl">--</span><span class="psi-label">VL</span></div>
          <div class="tpms-wheel fr"><span class="psi-val" id="tpms-fr">--</span><span class="psi-label">VR</span></div>
          <div class="tpms-wheel rl"><span class="psi-val" id="tpms-rl">--</span><span class="psi-label">HL</span></div>
          <div class="tpms-wheel rr"><span class="psi-val" id="tpms-rr">--</span><span class="psi-label">HR</span></div>
        </div>
        <div class="tpms-temp" id="tpms-temp"></div>
      </div>
    </div>

    <!-- 30-Tage Schnellstatistik -->
    <div class="card">
      <div class="card-header"><span class="card-title">Letzten 30 Tage</span></div>
      <div class="grid-4" id="ov-monthly-stats">
        <div class="stat-card"><div class="stat-label">Fahrten</div><div class="stat-value val-blue" id="ov-m-drives">--</div></div>
        <div class="stat-card"><div class="stat-label">Kilometer</div><div class="stat-value val-accent" id="ov-m-km">--</div></div>
        <div class="stat-card"><div class="stat-label">Geladen (kWh)</div><div class="stat-value val-green" id="ov-m-kwh">--</div><div class="stat-sub" id="ov-m-kosten"></div></div>
        <div class="stat-card"><div class="stat-label">Max. Geschw.</div><div class="stat-value" id="ov-m-speed">--<span class="stat-unit">km/h</span></div></div>
      </div>
    </div>
  </div>

  <!-- ─────────────── TAB: KARTE ─────────────── -->
  <div id="tab-map" class="tab-panel">
    <div class="karte-leiste">
      <div class="range-filter" id="karte-modus" style="margin:0">
        <button class="range-btn" data-modus="position">Aktuelle Position</button>
        <button class="range-btn active" data-modus="heute">Heute</button>
        <button class="range-btn" data-modus="7d">7 Tage</button>
        <button class="range-btn" data-modus="30d">30 Tage</button>
        <button class="range-btn" data-modus="90d">90 Tage</button>
        <button class="range-btn" data-modus="1y">1 Jahr</button>
        <button class="range-btn" data-modus="all">Alle</button>
      </div>
      <select id="karte-fahrt" class="karte-auswahl"><option value="">Fahrt wählen …</option></select>
      <label class="karte-schalter"><input type="checkbox" id="karte-tempo" checked> Tempo-Farben</label>
    </div>
    <div class="karte-legende" id="karte-legende" hidden></div>
    <div id="map-container">
      <div id="vehicle-map"></div>
      <div class="map-overlay" id="map-info-box">
        <div id="map-address"><b>Laden…</b></div>
        <p id="map-coords" style="font-size:.7rem;color:var(--muted);margin-top:.3rem"></p>
        <p id="map-updated" style="font-size:.7rem;color:var(--muted)"></p>
      </div>
    </div>
  </div>

  <!-- ─────────────── TAB: FAHRTEN ─────────────── -->
  <div id="tab-drives" class="tab-panel">
    <div class="card" style="margin-bottom:1rem">
      <div class="card-header">
        <span class="card-title">Fahrten</span>
        <div class="range-filter" id="drives-range-filter">
          <button class="range-btn" data-range="24h">24h</button>
          <button class="range-btn" data-range="7d">7 Tage</button>
          <button class="range-btn active" data-range="30d">30 Tage</button>
          <button class="range-btn" data-range="1y">1 Jahr</button>
          <button class="range-btn" data-range="all">Alle</button>
        </div>
      </div>
      <div class="grid-4" style="margin-bottom:1rem">
        <div class="stat-card"><div class="stat-label">Fahrten</div><div class="stat-value val-blue" id="dr-total">--</div></div>
        <div class="stat-card"><div class="stat-label">Gesamt km</div><div class="stat-value val-accent" id="dr-km">--</div><div class="stat-sub" id="dr-kosten"></div></div>
        <div class="stat-card"><div class="stat-label">Ø Temperatur</div><div class="stat-value" id="dr-temp">--<span class="stat-unit">°C</span></div></div>
        <div class="stat-card"><div class="stat-label">Max. Tempo</div><div class="stat-value" id="dr-speed">--<span class="stat-unit">km/h</span></div></div>
      </div>
      <div class="chart-wrap" style="height:220px">
        <canvas id="chart-monthly-km"></canvas>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Fahrten nach Monat</span>
        <span class="stat-hint">alle Fahrten · Monatszeile anklicken zum Auf-/Zuklappen</span></div>
      <div style="overflow-x:auto">
        <table class="data-table monats-tabelle">
          <thead>
            <tr>
              <th>Datum</th>
              <th>Von</th>
              <th>Nach</th>
              <th>Temp °C</th>
              <th>km</th>
              <th>Dauer</th>
              <th>Max km/h</th>
              <th>SOC Start</th>
              <th>SOC Ende</th>
              <th title="Energie der Fahrt × Strompreis (Mischpreis nach Ladeort)">Kosten</th>
            </tr>
          </thead>
          <tbody id="drives-table-body">
            <tr><td colspan="9" class="loading-msg"><div class="spinner"></div>Daten werden geladen…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ─────────────── TAB: LADEN ─────────────── -->
  <div id="tab-charging" class="tab-panel">
    <div class="card" style="margin-bottom:1rem">
      <div class="card-header">
        <span class="card-title">Ladesessions</span>
        <div class="range-filter" id="charge-range-filter">
          <button class="range-btn" data-range="24h">24h</button>
          <button class="range-btn" data-range="7d">7 Tage</button>
          <button class="range-btn active" data-range="30d">30 Tage</button>
          <button class="range-btn" data-range="1y">1 Jahr</button>
          <button class="range-btn" data-range="all">Alle</button>
        </div>
      </div>
      <div class="grid-4" style="margin-bottom:1rem">
        <div class="stat-card"><div class="stat-label">Sessions</div><div class="stat-value val-green" id="ch-sessions">--</div></div>
        <div class="stat-card"><div class="stat-label">Energie AC (kWh)</div><div class="stat-value val-yellow" id="ch-kwh-ac">--</div><div class="stat-sub" id="ch-kosten"></div></div>
        <div class="stat-card"><div class="stat-label">Energie DC (kWh)</div><div class="stat-value val-blue" id="ch-kwh-dc">--</div></div>
        <div class="stat-card"><div class="stat-label">Ø Effizienz</div><div class="stat-value val-green" id="ch-eff">--<span class="stat-unit">%</span></div></div>
      </div>
      <div class="grid-2">
        <div>
          <div class="chart-titel">Geladen je Monat <span class="stat-hint">(alle Ladungen, kWh am Auto-Eingang, Linie = Kosten)</span></div>
          <div class="chart-wrap" style="height:200px">
            <canvas id="chart-charge-kwh"></canvas>
          </div>
        </div>
        <div>
          <div class="chart-titel">Laden nach Wochentag <span class="stat-hint">(gewählter Zeitraum, kWh geladen)</span></div>
          <div class="chart-wrap" style="height:200px">
            <canvas id="chart-charge-weekday"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Ladungen nach Monat</span>
        <span class="stat-hint">alle Ladungen · Monatszeile anklicken zum Auf-/Zuklappen</span></div>
      <div style="overflow-x:auto">
        <table class="data-table monats-tabelle">
          <thead>
            <tr>
              <th>Datum</th>
              <th>Ort</th>
              <th>Temp °C</th>
              <th>kWh geladen</th>
              <th>kWh verbraucht</th>
              <th title="im Akku ÷ vom Auto aufgenommen; Monatszeile: energiegewichtet, ohne Ladungen unter 1 kWh">Effizienz</th>
              <th>Dauer</th>
              <th>SOC Start</th>
              <th>SOC Ende</th>
              <th title="kWh am Auto-Eingang × Strompreis (daheim Fixtarif, anderswo angenommen)">Kosten</th>
            </tr>
          </thead>
          <tbody id="charge-table-body">
            <tr><td colspan="9" class="loading-msg"><div class="spinner"></div>Daten werden geladen…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ─────────────── TAB: BATTERIE ─────────────── -->
  <div id="tab-battery" class="tab-panel">
    <div class="grid-3" style="margin-bottom:1rem">
      <div class="stat-card">
        <div class="stat-label">Aktueller SOC</div>
        <div class="stat-value val-green" id="bat-soc">--<span class="stat-unit">%</span></div>
        <div class="battery-wrap">
          <div class="battery-track"><div class="battery-fill" id="bat-soc-bar" style="width:0%"></div></div>
        </div>
        <div class="stat-sub" id="bat-usable">Nutzbar: -- %</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Reichweite (bewertet)</div>
        <div class="stat-value val-blue" id="bat-range">--<span class="stat-unit">km</span></div>
        <div class="stat-sub" id="bat-ideal">Ideal: -- km</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Batterie-Heizung</div>
        <div class="stat-value" id="bat-heater" style="font-size:1.4rem">--</div>
        <div class="stat-sub" id="bat-last-update"></div>
      </div>
    </div>

    <div class="card" style="margin-bottom:1rem">
      <div class="card-header"><span class="card-title">Akkuverlauf</span>
        <div class="range-filter" id="av-range" style="margin:0">
          <button class="range-btn" data-h="12">12 h</button><button class="range-btn active" data-h="24">24 h</button>
          <button class="range-btn" data-h="48">48 h</button><button class="range-btn" data-h="168">7 Tage</button>
        </div></div>
      <div class="stat-sub" id="av-info" style="margin-bottom:.5rem"></div>
      <div style="position:relative;height:260px"><canvas id="chart-akkuverlauf"></canvas></div>
      <div class="stat-sub" style="margin-top:.4rem"><span style="color:#5b7fc9">■</span> Fahrt · <span style="color:var(--green)">■</span> Ladung · Linie: Akkustand, gestrichelt: Reichweite</div>
    </div>

    <div class="card" style="margin-bottom:1rem">
      <div class="card-header"><span class="card-title">Akku-Gesundheit (nutzbare Kapazität)</span></div>
      <div class="kap-kacheln">
        <div><div class="stat-label">Nutzbar jetzt</div><div class="kap-wert val-green" id="kap-jetzt">--</div><div class="stat-sub" id="kap-jetzt-sub"></div></div>
        <div><div class="stat-label">Gesundheit</div><div class="kap-wert" id="kap-soh">--</div><div class="stat-sub" id="kap-soh-sub"></div></div>
        <div><div class="stat-label">Verloren seit neu</div><div class="kap-wert val-yellow" id="kap-verlust">--</div><div class="stat-sub" id="kap-verlust-sub"></div></div>
        <div><div class="stat-label">Reichweite bei 100 %</div><div class="kap-wert val-blue" id="kap-r100">--</div><div class="stat-sub">laut Auto (bewertet)</div></div>
      </div>
      <div class="kap-balken" title="nutzbare Kapazität im Verhältnis zum Neuzustand"><div id="kap-balken-ist"></div></div>
      <div class="chart-wrap" style="height:240px">
        <canvas id="chart-kapazitaet"></canvas>
      </div>
      <div class="kap-hinweis" id="kap-info"></div>
    </div>

    <div class="grid-2" style="margin-bottom:1rem">
      <div class="card">
        <div class="card-header"><span class="card-title">Ladeeffizienz je Ladung</span></div>
        <div class="chart-wrap" style="height:220px">
          <canvas id="chart-eff-trend"></canvas>
        </div>
        <div class="kap-hinweis" id="eff-trend-info"></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">SOC-Verteilung (90 Tage)</span></div>
        <div class="chart-wrap" style="height:220px">
          <canvas id="chart-soc-dist"></canvas>
        </div>
      </div>
    </div>

    <!-- Reifendruck-Verlauf am 26.09.2026 entfernt: steht im Tab Statistik („Reifendruck & Temperatur“) -->
    <div class="card">
      <div class="card-header"><span class="card-title">Temperatur vs. Reichweiteneffizienz</span></div>
      <div class="chart-wrap" style="height:200px">
        <canvas id="chart-temp-range"></canvas>
      </div>
    </div>
  </div>

  <!-- ─────────────── TAB: STATISTIK ─────────────── -->
  <div id="tab-statistik" class="tab-panel">
    <div class="card-header" style="margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
      <div class="range-filter" id="stat-range-filter" style="margin:0">
        <button class="range-btn" data-range="30d">30 Tage</button>
        <button class="range-btn" data-range="90d">90 Tage</button>
        <button class="range-btn" data-range="1y">1 Jahr</button>
        <button class="range-btn active" data-range="all">Gesamt</button>
      </div>
      <span class="stat-hint" id="stat-info"></span>
    </div>

    <div class="grid-4" style="margin-bottom:1rem">
      <div class="stat-card">
        <div class="stat-label">Ø Verbrauch</div>
        <div class="stat-value val-accent" id="st-whkm">--<span class="stat-unit">Wh/km</span></div>
        <div class="stat-sub" id="st-whkm-sub">Werksangabe: -- Wh/km</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Gefahren</div>
        <div class="stat-value val-blue" id="st-km">--<span class="stat-unit">km</span></div>
        <div class="stat-sub" id="st-km-sub">-- Fahrten · -- h</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Energie verbraucht</div>
        <div class="stat-value val-green" id="st-kwh">--<span class="stat-unit">kWh</span></div>
        <div class="stat-sub" id="st-kwh-sub">Rekuperiert: -- kWh</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Standverlust</div>
        <div class="stat-value" id="st-stand">--<span class="stat-unit">km/Tag</span></div>
        <div class="stat-sub" id="st-stand-sub">--</div>
      </div>
    </div>

    <!-- Reichweitenrechner -->
    <div class="card" style="margin-bottom:1rem">
      <div class="card-header">
        <span class="card-title">Reichweitenrechner – aus deinen eigenen Fahrten</span>
        <span class="stat-hint" id="calc-basis"></span>
      </div>
      <div class="calc-grid">
        <label class="calc-field">
          <span>Außentemperatur <b id="calc-temp-val">15 °C</b></span>
          <input type="range" id="calc-temp" min="-20" max="35" step="1" value="15">
        </label>
        <label class="calc-field">
          <span>Fahrprofil</span>
          <select id="calc-speed">
            <option value="30">Stadt (30–50 km/h)</option>
            <option value="70">Landstraße (70–90 km/h)</option>
            <option value="90">Schnellstraße (90–110 km/h)</option>
            <option value="110" selected>Autobahn (110–130 km/h)</option>
            <option value="130">Autobahn schnell (130+ km/h)</option>
          </select>
        </label>
        <label class="calc-field">
          <span>Akku von <b id="calc-von-val">--</b> bis <b id="calc-bis-val">10 %</b></span>
          <div style="display:flex;gap:.5rem">
            <input type="range" id="calc-von" min="10" max="100" step="1" value="90">
            <input type="range" id="calc-bis" min="0" max="50" step="1" value="10">
          </div>
        </label>
        <div class="calc-result">
          <div class="stat-value val-green" id="calc-km">--<span class="stat-unit">km</span></div>
          <div class="stat-sub" id="calc-detail">--</div>
        </div>
      </div>
    </div>

    <!-- Verbrauchsmatrix -->
    <div class="card" style="margin-bottom:1rem">
      <div class="card-header">
        <span class="card-title">Verbrauch nach Temperatur &amp; Geschwindigkeit (Wh/km)</span>
        <span class="stat-hint">klein: gefahrene km · blass: unter 1 km Datenbasis</span>
      </div>
      <div id="stat-matrix" style="overflow-x:auto"><div class="loading-msg"><div class="spinner"></div>Lade…</div></div>
    </div>

    <div class="grid-2" style="margin-bottom:1rem">
      <div class="card">
        <div class="card-header"><span class="card-title">Verbrauch nach Außentemperatur</span><span class="stat-hint" id="st-temp-hinweis"></span></div>
        <div class="chart-wrap" style="height:230px"><canvas id="chart-st-temp"></canvas></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Verbrauch nach Geschwindigkeit</span></div>
        <div class="chart-wrap" style="height:230px"><canvas id="chart-st-speed"></canvas></div>
      </div>
    </div>

    <div class="grid-2" style="margin-bottom:1rem">
      <div class="card">
        <div class="card-header"><span class="card-title">Monate: Kilometer &amp; Verbrauch</span></div>
        <div class="chart-wrap" style="height:230px"><canvas id="chart-st-monat"></canvas></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Bergauf / bergab je Fahrt</span></div>
        <div class="chart-wrap" style="height:230px"><canvas id="chart-st-hoehe"></canvas></div>
      </div>
    </div>

    <div class="grid-2" style="margin-bottom:1rem">
      <div class="card">
        <div class="card-header"><span class="card-title">Ladeverluste nach Ladeleistung</span></div>
        <div class="chart-wrap" style="height:230px"><canvas id="chart-st-verlust"></canvas></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Akku: geschätzte Kapazität &amp; Reichweite bei 100 %</span></div>
        <div class="chart-wrap" style="height:230px"><canvas id="chart-st-akku"></canvas></div>
      </div>
    </div>

    <div class="grid-2" style="margin-bottom:1rem">
      <div class="card">
        <div class="card-header"><span class="card-title">Standverlust je Parkphase (ohne Laden)</span></div>
        <div class="chart-wrap" style="height:230px"><canvas id="chart-st-stand"></canvas></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Reifendruck &amp; Temperatur (Tagesmittel)</span></div>
        <div class="chart-wrap" style="height:230px"><canvas id="chart-st-reifen"></canvas></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Rekorde</span></div>
      <div class="grid-3" id="stat-rekorde"><div class="loading-msg"><div class="spinner"></div>Lade…</div></div>
    </div>
  </div>

  <!-- ─────────────── TAB: PENDELN ─────────────── -->
  <div id="tab-pendeln" class="tab-panel">
    <div class="card-header" style="margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
      <div class="range-filter" id="pendel-range-filter" style="margin:0">
        <button class="range-btn" data-range="90d">90 Tage</button>
        <button class="range-btn" data-range="1y">1 Jahr</button>
        <button class="range-btn active" data-range="all">Gesamt</button>
      </div>
      <span class="stat-hint" id="pendel-orte"></span>
    </div>
    <div id="pendel-leer" class="card" hidden></div>
    <div id="pendel-inhalt">
      <div class="grid-2" id="pendel-richtungen" style="margin-bottom:1rem"><div class="loading-msg"><div class="spinner"></div>Lade…</div></div>
      <div class="grid-2" style="margin-bottom:1rem">
        <div class="card"><div class="card-header"><span class="card-title">Verbrauch je Fahrt</span></div>
          <div class="chart-wrap" style="height:230px"><canvas id="chart-pd-verlauf"></canvas></div></div>
        <div class="card"><div class="card-header"><span class="card-title">Dauer nach Abfahrtszeit</span></div>
          <div class="chart-wrap" style="height:230px"><canvas id="chart-pd-abfahrt"></canvas></div></div>
      </div>
      <div class="grid-2" style="margin-bottom:1rem">
        <div class="card"><div class="card-header"><span class="card-title">Verbrauch nach Außentemperatur</span></div>
          <div class="chart-wrap" style="height:230px"><canvas id="chart-pd-temp"></canvas></div></div>
        <div class="card"><div class="card-header"><span class="card-title">Monate: Verbrauch &amp; Dauer</span></div>
          <div class="chart-wrap" style="height:230px"><canvas id="chart-pd-monat"></canvas></div></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Alle Pendelfahrten</span><span class="stat-hint" id="pendel-anzahl"></span></div>
        <div style="overflow-x:auto"><table class="data-table">
          <thead><tr><th>Datum</th><th>Richtung</th><th>Dauer</th><th>km</th><th>Wh/km</th><th>kWh</th><th>Kosten</th><th>Ø / max km/h</th><th>°C</th><th>Akku</th><th>Klima</th></tr></thead>
          <tbody id="pendel-tabelle"></tbody>
        </table></div>
      </div>
    </div>
  </div>

  <!-- ─────────────── TAB: WALLBOX ─────────────── -->
  <div id="tab-wallbox" class="tab-panel">
    <div class="grid-2" style="margin-bottom:1rem">
      <div class="card">
        <div class="card-header">
          <span class="card-title">DaheimLader Status</span>
          <button class="btn-logout" id="wb-aktualisieren">&#8635; Aktualisieren</button>
        </div>
        <div id="wb-status">
          <div class="loading-msg"><div class="spinner"></div>Verbindung zur Wallbox…</div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Ladesteuerung &amp; Kalibrierung</span></div>
        <div id="wb-control">
          <div class="loading-msg"><div class="spinner"></div>Lade…</div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title">Letzte Ladesessions (DaheimLader)</span></div>
      <div id="wb-history">
        <div class="loading-msg"><div class="spinner"></div>Lade…</div>
      </div>
    </div>
  </div>

</main><!-- /main-content -->

<script>
/* ═══════════════════════════════════════════════════════
   Tesla Dashboard – Frontend JavaScript
   ═══════════════════════════════════════════════════════ */

(function() {
'use strict';

// ── Helpers ──────────────────────────────────────────

function el(id) { return document.getElementById(id); }

function fmt(v, dec = 1) {
  if (v === null || v === undefined || v === '') return '--';
  return parseFloat(v).toFixed(dec);
}

// Strompreise je kWh brutto (config.php). Daheim: Fixtarif + Netz NÖ + USt. Anderswo: Annahme.
// Fahrten: Mischpreis nach dem Anteil daheim geladener Energie der letzten 12 Monate (kommt mit /api/stats.php).
const STROMPREIS_CT = <?= json_encode(STROMPREIS_CT) ?>;
const HAT_DAHEIMLADER = <?= json_encode(DAHEIM_API !== '') ?>, HAT_ZUSTAND = <?= json_encode(ZUSTAND_QUELLE !== 'keine') ?>;
const PREIS_FREMD_CT = <?= json_encode(STROMPREIS_FREMD_CT) ?>;
let PREIS_FAHRT_CT = STROMPREIS_CT, ANTEIL_DAHEIM = 100;
const ctText = ct => ct.toLocaleString('de-AT', {minimumFractionDigits: ct % 1 ? 2 : 0, maximumFractionDigits: 2}) + ' ct/kWh';
const PREIS_TEXT = ctText(STROMPREIS_CT);
function eur(kwh, ct = STROMPREIS_CT) {
  if (kwh === null || kwh === undefined || isNaN(kwh)) return '--';
  return (kwh * ct / 100).toLocaleString('de-AT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
}
function eurTitel(kwh, was, ct = STROMPREIS_CT) { return `${fmt(kwh, 2)} kWh${was ? ' ' + was : ''} × ${ctText(ct)}`; }
// Verbrauch einer Fahrt: Mischpreis
const eurFahrt = kwh => eur(kwh, PREIS_FAHRT_CT);
function fahrtPreisTitel(kwh) {
  return eurTitel(kwh, 'verbraucht', PREIS_FAHRT_CT) + (ANTEIL_DAHEIM < 100
    ? ` (Mischpreis: ${ANTEIL_DAHEIM} % daheim zu ${PREIS_TEXT}, Rest anderswo zu ${ctText(PREIS_FREMD_CT)})` : ' (alles daheim geladen)');
}
function preiseAusStats(d) {
  if (d && d.strompreis_fahrt_ct) { PREIS_FAHRT_CT = d.strompreis_fahrt_ct; ANTEIL_DAHEIM = d.anteil_daheim_pct ?? 100; }
}

function fmtDuration(min) {
  if (!min) return '--';
  const h = Math.floor(min / 60), m = Math.round(min % 60);
  return h > 0 ? `${h}h ${m}min` : `${m} min`;
}

function parseDate(str) {
  if (!str) return null;
  // Normalize: space→T, fix short TZ offset (+02 → +02:00)
  const s = str.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00');
  const d = new Date(s);
  return isNaN(d.getTime()) ? null : d;
}

function fmtDate(str) {
  const d = parseDate(str);
  if (!d) return '--';
  return d.toLocaleString('de-AT', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}

function fmtDateShort(str) {
  const d = parseDate(str);
  if (!d) return '--';
  return d.toLocaleString('de-AT', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
}

function socClass(soc) {
  if (soc === null) return '';
  if (soc >= 50) return 'val-green';
  if (soc >= 25) return 'val-yellow';
  return 'val-accent';
}

function socBarClass(soc) {
  if (soc >= 50) return '';
  if (soc >= 25) return 'mid';
  return 'low';
}

// Datenabfrage mit verständlichen Fehlern: abgelaufene Anmeldung (Dashboard oder Cloudflare Access),
// keine Verbindung, Serverfehler. Fehlertext steht in err.message.
function apiGet(url) {
  return fetch(url, {credentials: 'same-origin', redirect: 'manual'}).then(r => {
    if (r.type === 'opaqueredirect' || r.status === 0) {   // Cloudflare Access leitet zur Anmeldung um
      anmeldungAbgelaufen(); throw new Error('Anmeldung abgelaufen – bitte Seite neu laden');
    }
    if (r.status === 401) { anmeldungAbgelaufen(); throw new Error('Anmeldung abgelaufen – bitte neu anmelden'); }
    if (!r.ok) throw new Error('Serverfehler (HTTP ' + r.status + ')');
    return r.json().catch(() => { throw new Error('Unerwartete Antwort vom Server'); });
  }, () => { throw new Error('Keine Verbindung zum Dashboard'); });
}

// Einmalig einen gut sichtbaren Hinweis oben einblenden
function anmeldungAbgelaufen() {
  if (document.getElementById('anmeldung-hinweis')) return;
  const d = document.createElement('div');
  d.id = 'anmeldung-hinweis';
  d.innerHTML = 'Anmeldung abgelaufen. <a href="/login.php">Neu anmelden</a> oder <a href="" onclick="location.reload();return false">Seite neu laden</a>';
  document.body.prepend(d);
}

// ── Clock ─────────────────────────────────────────────
(function clock() {
  function tick() {
    const now = new Date();
    const s = now.toLocaleTimeString('de-AT', {hour:'2-digit', minute:'2-digit'});
    const t = el('header-time');
    if (t) t.textContent = s;
  }
  tick();
  setInterval(tick, 1000);
})();

// ── Chart defaults ─────────────────────────────────────
Chart.defaults.color = '#8888aa';
Chart.defaults.borderColor = '#2a2a4e';
Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
Chart.defaults.font.size = 11;

const chartInstances = {};

function makeChart(id, config) {
  if (chartInstances[id]) {
    chartInstances[id].destroy();
    // Neuaufbau (z. B. automatische Aktualisierung) ohne Animation
    config.options = Object.assign({}, config.options, {animation: false});
  }
  const canvas = el(id);
  if (!canvas) return null;
  chartInstances[id] = new Chart(canvas, config);
  return chartInstances[id];
}

// ═══════════════════════════════════════════════════════
// POSITION + OVERVIEW
// ═══════════════════════════════════════════════════════

let miniMap = null, vehicleMap = null;
let miniMarker = null, vehicleMarker = null;

function initMaps() {
  const tileUrl = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
  const tileOpts = { attribution: '© OpenStreetMap', maxZoom: 19 };

  if (!miniMap) {
    miniMap = L.map('mini-map', {zoomControl: false, attributionControl: false, dragging: false, scrollWheelZoom: false}).setView([47.8, 13.0], 10);
    L.tileLayer(tileUrl, tileOpts).addTo(miniMap);
  }
  if (!vehicleMap) {
    vehicleMap = L.map('vehicle-map').setView([47.8, 13.0], 12);
    L.tileLayer(tileUrl, tileOpts).addTo(vehicleMap);
  }
}

const teslaIcon = L.divIcon({
  className: '',
  html: '<div style="width:24px;height:24px;background:#e31937;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,.6)"></div>',
  iconSize: [24, 24],
  iconAnchor: [12, 12],
});

// Hintergrundkarte (wie beim DaheimLader): abgedunkelte OSM-Karte hinter der Seite, Auto als pulsierender Punkt.
// Nicht bedienbar (pointer-events: none); zieht nur mit, wenn das Auto sich bewegt.
let bgKarte = null, bgMarker = null;
function hintergrundKarte(lat, lng) {
  if (typeof L === 'undefined') return;
  if (!bgKarte) {
    const div = document.createElement('div');
    div.id = 'map-bg';
    div.setAttribute('aria-hidden', 'true');
    document.body.prepend(div);
    bgKarte = L.map(div, {zoomControl: false, attributionControl: true, dragging: false, scrollWheelZoom: false,
      doubleClickZoom: false, boxZoom: false, touchZoom: false, keyboard: false, center: [lat, lng], zoom: 14});
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '© OpenStreetMap'}).addTo(bgKarte);
    bgMarker = L.marker([lat, lng], {interactive: false, keyboard: false,
      icon: L.divIcon({className: '', html: '<div class="bg-auto"></div>', iconSize: [14, 14], iconAnchor: [7, 7]})}).addTo(bgKarte);
    return;
  }
  const alt = bgMarker.getLatLng();
  if (Math.abs(alt.lat - lat) < 1e-5 && Math.abs(alt.lng - lng) < 1e-5) return;
  bgMarker.setLatLng([lat, lng]);
  bgKarte.panTo([lat, lng], {animate: true, duration: 1.5});
}

function updatePosition(data) {
  if (!data.ok) return;

  const pos = data.position;
  const state = data.state;
  const addr = data.address;
  const drv = data.last_drive;

  // Header badges
  if (state) {
    const stateMap = {
      driving:  ['Fährt',   'driving'],
      charging: ['Lädt',    'charging'],
      online:   ['Online',  'online'],
      asleep:   ['Schläft', 'asleep'],
      offline:  ['Offline', 'offline'],
      suspended:['Bereit',  'online'],
    };
    const [label, cls] = stateMap[state.state] || [state.state, 'online'];
    const badge = el('vehicle-state-badge');
    badge.className = `state-badge state-${cls}`;
    badge.querySelector('.dot').className = `dot state-dot-${cls}`;
    el('state-label').textContent = label;
    el('ov-state').textContent = label;
    // Status-Kachel dezent in der Farbe der Zeitleiste
    const stArt = {asleep: 'schlaeft', suspended: 'schlaeft', online: 'wach', updating: 'wach', driving: 'fahren',
                   charging: 'laden', offline: 'offline'}[state.state] || 'wach';
    el('ov-status-karte').className = 'stat-card status-karte st-' + stArt;
    el('ov-since').textContent = state.since ? 'Seit ' + fmtDateShort(state.since) : '';
  }

  if (pos) {
    // Beim Laden ist der MQTT-Wert aktueller als die letzte Position in der DB
    const soc = zsAkku !== null ? zsAkku : pos.battery_level;
    const range = zsReichweite !== null ? zsReichweite : pos.rated_battery_range_km;

    el('header-soc').textContent = soc !== null ? soc + ' %' : '';
    el('header-soc').className = socClass(soc);
    el('header-range').textContent = range !== null ? fmt(range, 0) + ' km' : '';

    // Overview stat cards
    el('ov-soc').innerHTML = (soc !== null ? soc : '--') + '<span class="stat-unit">%</span>';
    el('ov-soc').className = 'stat-value ' + socClass(soc);
    const bar = el('ov-soc-bar');
    bar.style.width = (soc || 0) + '%';
    bar.className = 'battery-fill ' + socBarClass(soc || 0);
    el('ov-soc-pct').textContent = (soc !== null ? soc : '--') + ' %';
    el('ov-range').textContent = 'Reichweite: ' + (range !== null ? fmt(range, 0) + ' km' : '--');

    el('ov-temp').innerHTML = fmt(pos.outside_temp, 1) + '<span class="stat-unit">°C außen</span>';
    el('ov-temp-in').textContent = 'Innen: ' + fmt(pos.inside_temp, 1) + ' °C';
    el('ov-heater').textContent = pos.battery_heater ? '🔥 Batterieheizung aktiv' : '';
    el('ov-heater').style.color = pos.battery_heater ? 'var(--warn)' : '';

    el('ov-speed').textContent = (pos.speed !== null && pos.speed > 0) ? pos.speed + ' km/h' : '';

    // Battery tab
    if (el('bat-soc')) {
      el('bat-soc').innerHTML = (soc !== null ? soc : '--') + '<span class="stat-unit">%</span>';
      el('bat-soc-bar').style.width = (soc || 0) + '%';
      el('bat-soc-bar').className = 'battery-fill ' + socBarClass(soc || 0);
      el('bat-range').innerHTML = fmt(range, 0) + '<span class="stat-unit">km</span>';
      el('bat-last-update').textContent = pos.date_local ? 'Stand: ' + fmtDateShort(pos.date_local) : '';
    }

    // TPMS
    const nominal = <?= TPMS_NOMINAL_BAR ?>;
    // Zu wenig Druck streng bewerten, zu viel großzügig: warm gefahrene Reifen haben normal 0,2–0,3 bar mehr,
    // der Sollwert (Schild B-Säule) gilt für kalte Reifen
    function tpmsClass(v) {
      if (v === null || v === undefined) return '';
      const d = Math.round((v - nominal) * 100) / 100;   // auf 0,01 bar runden (Kommazahl-Ungenauigkeit)
      if (d < 0) return -d <= 0.15 ? 'psi-ok' : -d <= 0.3 ? 'psi-low' : 'psi-crit';   // wie api/auffaelligkeiten.php
      return d <= 0.35 ? 'psi-ok' : d <= 0.5 ? 'psi-low' : 'psi-crit';
    }
    let tpmsSchlimmst = 'psi-ok';
    ['fl','fr','rl','rr'].forEach(pos_key => {
      const v = pos['tpms_' + pos_key];
      const e = el('tpms-' + pos_key);
      if (e) {
        e.textContent = v !== null ? fmt(v, 2) : '--';
        e.className = 'psi-val ' + tpmsClass(v);
      }
      const k = tpmsClass(v);
      if (k === 'psi-crit' || (k === 'psi-low' && tpmsSchlimmst !== 'psi-crit')) tpmsSchlimmst = k;
    });
    // Außentemperatur zum Messzeitpunkt (Druck ändert sich um ca. 0,1 bar je 10 °C)
    el('tpms-temp').innerHTML = pos.outside_temp !== null && pos.outside_temp !== undefined
      ? `Gemessen bei <b>${fmt(pos.outside_temp, 1)} °C</b> außen${pos.date_local ? ' · ' + fmtDateShort(pos.date_local) : ''} · Faustregel: ±0,1 bar je 10 °C`
      : '';
    // Kärtchen in der Farbe des schlechtesten Rads (orange/rot)
    const tk = el('ov-tpms-karte');
    tk.classList.toggle('karte-hinweis', tpmsSchlimmst === 'psi-low');
    tk.classList.toggle('karte-warn', tpmsSchlimmst === 'psi-crit');
    akkuKachelFarbe(soc);

    // Maps
    const lat = pos.latitude, lng = pos.longitude;
    if (lat && lng) hintergrundKarte(lat, lng);
    if (lat && lng) {
      [miniMap, vehicleMap].forEach((map, i) => {
        if (!map) return;
        const marker = i === 0 ? miniMarker : vehicleMarker;
        if (!marker) {
          const m = L.marker([lat, lng], {icon: teslaIcon}).addTo(map);
          if (i === 0) miniMarker = m; else vehicleMarker = m;
        } else {
          marker.setLatLng([lat, lng]);
        }
        if (i === 0) miniMap.setView([lat, lng], 13);
        else if (karteModus === 'position') vehicleMap.setView([lat, lng], 14);   // Strecken nicht wegschieben
      });
    }
  }

  if (drv) {
    el('ov-last-dist').innerHTML = fmt(drv.distance, 0) + '<span class="stat-unit">km</span>';
    el('ov-last-route').textContent = (drv.start_city || '?') + ' → ' + (drv.end_city || '?');
    el('ov-last-soc').textContent = (drv.soc_start || '--') + '% → ' + (drv.soc_end || '--') + '%';
  }

  if (addr && karteModus === 'position') {   // Infokasten nicht überschreiben, solange Strecken angezeigt werden
    const addrStr = [addr.road, addr.house_number, addr.city].filter(Boolean).join(' ');
    el('map-address').innerHTML = '<b>' + (addrStr || addr.display_name || 'Unbekannt') + '</b>';
    if (pos) {
      el('map-coords').textContent = fmt(pos.latitude, 5) + ', ' + fmt(pos.longitude, 5);
      el('map-updated').textContent = pos.date_local ? 'Stand: ' + fmtDateShort(pos.date_local) : '';
    }
  }
}

function loadPosition() {
  apiGet('/api/position.php').then(updatePosition).catch(() => {
    el('ov-state').textContent = 'Fehler';
  });
}

// ═══════════════════════════════════════════════════════
// STATS (DRIVES + CHARGING)
// ═══════════════════════════════════════════════════════

let currentDrivesRange = '30d';
let currentChargeRange = '30d';

function loadStats(range, target) {
  return apiGet('/api/stats.php?range=' + range);
}

function renderDrives(data) {
  preiseAusStats(data);
  if (!data || !data.ok) {
    el('drives-table-body').innerHTML = '<tr><td colspan="10"><div class="error-msg">Fehler beim Laden der Daten</div></td></tr>';
    return;
  }
  const s = data.drives.summary;
  if (s) {
    el('dr-total').textContent = s.total_drives;
    el('dr-km').textContent = fmt(s.total_km, 0);
    el('dr-kosten').textContent = s.total_kwh ? `${fmt(s.total_kwh, 1)} kWh · ≈ ${eurFahrt(s.total_kwh)} Strom` : '';
    el('dr-kosten').title = s.total_kwh ? fahrtPreisTitel(s.total_kwh) : '';
    el('dr-temp').innerHTML = fmt(s.avg_temp, 1) + '<span class="stat-unit">°C</span>';
    el('dr-speed').innerHTML = fmt(s.max_speed, 0) + '<span class="stat-unit">km/h</span>';
  }

  // Monthly chart
  const monthly = data.drives.monthly || [];
  makeChart('chart-monthly-km', {
    type: 'bar',
    data: {
      labels: monthly.map(m => m.month).reverse(),
      datasets: [{
        label: 'km',
        data: monthly.map(m => m.km).reverse(),
        backgroundColor: 'rgba(227,25,55,.7)',
        borderColor: '#e31937',
        borderWidth: 1,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, grid: { color: '#2a2a4e' }, ticks: { color: '#8888aa' } },
        x: { grid: { display: false }, ticks: { color: '#8888aa' } }
      }
    }
  });

  // Tabelle: alle Fahrten, nach Monat gruppiert (unabhängig vom Zeitraum oben)
  fahrtenTabelle(data.drives.alle || data.drives.recent || []);
}

// ── Tabellen nach Monat (Laden, Fahrten) ────────────────────────────────
// Je Monat eine Kopfzeile mit Summen (auch zugeklappt sichtbar); laufender Monat und Vormonat offen, ältere zu.
// Vom Benutzer auf-/zugeklappte Monate merken wir uns je Tabelle, damit die 30-s-Aktualisierung sie nicht zurücksetzt.
const monatOffen = {};
const MONATE_DE = ['Jänner','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
const euroText = x => x.toLocaleString('de-AT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
function monatsTabelle(tbodyId, liste, leer, zeile, kopfZellen) {
  const tbody = el(tbodyId), offenMerk = monatOffen[tbodyId] || (monatOffen[tbodyId] = {});
  if (!liste.length) { tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:var(--muted);padding:2rem">${leer}</td></tr>`; return; }
  const monate = new Map();
  for (const x of liste) {
    const d = parseDate(x.start_local); if (!d) continue;
    const k = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    if (!monate.has(k)) monate.set(k, []);
    monate.get(k).push(x);
  }
  const jetzt = new Date(), vm = new Date(jetzt.getFullYear(), jetzt.getMonth() - 1, 1);
  const grenze = `${vm.getFullYear()}-${String(vm.getMonth() + 1).padStart(2, '0')}`;
  let html = '';
  for (const [k, l] of [...monate.entries()].sort((a, b) => b[0].localeCompare(a[0]))) {
    const offen = k in offenMerk ? offenMerk[k] : k >= grenze;
    const [jj, mm] = k.split('-');
    html += `<tr class="monat-kopf${offen ? ' offen' : ''}" data-monat="${k}" data-tab="${tbodyId}" tabindex="0" title="klicken zum ${offen ? 'Zuklappen' : 'Aufklappen'}">${kopfZellen(l, `<span class="monat-pfeil">▸</span> <b>${MONATE_DE[+mm - 1]} ${jj}</b>`)}</tr>`;
    html += l.map(x => zeile(x).replace('<tr>', `<tr class="monat-zeile" data-monat="${k}"${offen ? '' : ' hidden'}>`)).join('');
  }
  tbody.innerHTML = html;
}
document.addEventListener('click', e => {
  const kopf = e.target.closest && e.target.closest('tr.monat-kopf');
  if (!kopf) return;
  const k = kopf.dataset.monat, id = kopf.dataset.tab, offen = !kopf.classList.contains('offen');
  (monatOffen[id] || (monatOffen[id] = {}))[k] = offen;
  kopf.classList.toggle('offen', offen);
  kopf.title = 'klicken zum ' + (offen ? 'Zuklappen' : 'Aufklappen');
  el(id).querySelectorAll(`tr.monat-zeile[data-monat="${k}"]`).forEach(z => z.hidden = !offen);
});
document.addEventListener('keydown', e => {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('tr.monat-kopf')) { e.preventDefault(); e.target.click(); }
});
const summe = (l, f) => l.reduce((a, x) => a + (+x[f] || 0), 0);
const anzahl = (n, ein, mehr) => `<span class="stat-hint">· ${n} ${n === 1 ? ein : mehr}</span>`;

// Laden – bei Ladungen anderswo zwei Summen (daheim Fixtarif / anderswo angenommen)
function ladeKosten(c) { return c.kwh_used === null ? 0 : c.kwh_used * (c.daheim ? STROMPREIS_CT : PREIS_FREMD_CT) / 100; }
function ladeTabelle(liste) {
  monatsTabelle('charge-table-body', liste, 'Noch keine Ladungen', c => `
    <tr>
      <td>${fmtDateShort(c.start_local)}</td>
      <td>${c.location || '--'}${c.daheim ? '' : ' <span class="stat-hint">anderswo</span>'}</td>
      <td>${c.outside_temp !== null ? fmt(c.outside_temp, 1) : '--'}</td>
      <td><b class="val-green">${c.kwh_added !== null ? fmt(c.kwh_added, 2) : '--'}</b></td>
      <td>${c.kwh_used !== null ? fmt(c.kwh_used, 2) : '--'}</td>
      <td class="${c.efficiency_pct !== null && c.efficiency_pct >= 90 ? 'val-green' : 'val-yellow'}">${c.kwh_added !== null && c.kwh_added < 1 ? '<span class="stat-hint">kurz</span>' : c.efficiency_pct !== null ? c.efficiency_pct + ' %' : '--'}</td>
      <td>${fmtDuration(c.duration_min)}</td>
      <td class="${socClass(c.soc_start)}">${c.soc_start !== null ? c.soc_start + ' %' : '--'}</td>
      <td class="${socClass(c.soc_end)}">${c.soc_end !== null ? c.soc_end + ' %' : '--'}</td>
      <td title="${c.kwh_used === null ? '' : c.daheim ? eurTitel(c.kwh_used, 'am Auto-Eingang') : eurTitel(c.kwh_used, 'am Auto-Eingang', PREIS_FREMD_CT) + ' – nicht daheim geladen, Preis angenommen'}">${c.kwh_used === null ? '--' : c.daheim ? eur(c.kwh_used) : '≈ ' + eur(c.kwh_used, PREIS_FREMD_CT)}</td>
    </tr>`, (l, titel) => {
    const eff = l.filter(c => c.kwh_added >= 1 && c.kwh_used > 0);
    const effPct = eff.length ? summe(eff, 'kwh_added') / summe(eff, 'kwh_used') * 100 : null;
    const da = l.filter(c => c.daheim), weg = l.filter(c => !c.daheim);
    const teil = (fn) => weg.length ? `<span class="monat-teil">daheim ${fn(da)}</span><span class="monat-teil">anderswo ${fn(weg)}</span>` : '';
    const kwhA = x => fmt(summe(x, 'kwh_added'), 1), kwhU = x => fmt(summe(x, 'kwh_used'), 1);
    const eurD = euroText(da.reduce((a, c) => a + ladeKosten(c), 0)), eurW = euroText(weg.reduce((a, c) => a + ladeKosten(c), 0));
    return `<td colspan="3">${titel} ${anzahl(l.length, 'Ladung', 'Ladungen')}${weg.length ? `<span class="monat-teil">${da.length} daheim, ${weg.length} anderswo</span>` : ''}</td>
      <td><b>${fmt(summe(l, 'kwh_added'), 1)}</b>${teil(kwhA)}</td>
      <td>${fmt(summe(l, 'kwh_used'), 1)}${teil(kwhU)}</td>
      <td>${effPct !== null ? fmt(effPct, 1) + ' %' : '--'}</td><td>${fmtDuration(summe(l, 'duration_min'))}</td>
      <td colspan="2"></td>
      <td>${weg.length
        ? `<b>≈ ${euroText(l.reduce((a, c) => a + ladeKosten(c), 0))}</b><span class="monat-teil">daheim ${eurD}</span><span class="monat-teil" title="angenommen ${ctText(PREIS_FREMD_CT)}">anderswo ≈ ${eurW}</span>`
        : `<b>${eurD}</b>`}</td>`;
  });
}

// Fahrten
function fahrtenTabelle(liste) {
  monatsTabelle('drives-table-body', liste, 'Noch keine Fahrten', d => `
    <tr>
      <td>${fmtDateShort(d.start_local)}</td>
      <td>${d.start_city || '--'}</td>
      <td>${d.end_city || '--'}</td>
      <td>${d.outside_temp !== null ? fmt(d.outside_temp, 1) : '--'}</td>
      <td><b>${fmt(d.distance, 1)}</b></td>
      <td>${fmtDuration(d.duration_min)}</td>
      <td>${d.speed_max !== null ? fmt(d.speed_max, 0) : '--'}</td>
      <td class="${socClass(d.soc_start)}">${d.soc_start !== null ? d.soc_start + ' %' : '--'}</td>
      <td class="${socClass(d.soc_end)}">${d.soc_end !== null ? d.soc_end + ' %' : '--'}</td>
      <td title="${d.kwh !== null ? fahrtPreisTitel(d.kwh) : ''}">${d.kwh !== null ? eurFahrt(d.kwh) : '--'}</td>
    </tr>`, (l, titel) => {
    const temps = l.filter(d => d.outside_temp !== null);
    const vmax = Math.max(...l.map(d => +d.speed_max || 0));
    const kwh = summe(l, 'kwh'), km = summe(l, 'distance');
    return `<td colspan="3">${titel} ${anzahl(l.length, 'Fahrt', 'Fahrten')}</td>
      <td title="Ø Außentemperatur">${temps.length ? 'Ø ' + fmt(summe(temps, 'outside_temp') / temps.length, 1) : '--'}</td>
      <td><b>${fmt(km, 1)}</b></td><td>${fmtDuration(summe(l, 'duration_min'))}</td>
      <td>${vmax ? fmt(vmax, 0) : '--'}</td>
      <td colspan="2" class="stat-hint">${kwh && km ? Math.round(kwh / km * 1000) + ' Wh/km · ' + fmt(kwh, 1) + ' kWh' : ''}</td>
      <td title="${kwh ? fahrtPreisTitel(kwh) : ''}"><b>${kwh ? eurFahrt(kwh) : '--'}</b></td>`;
  });
}

function renderCharging(data) {
  preiseAusStats(data);
  if (!data || !data.ok) {
    el('charge-table-body').innerHTML = '<tr><td colspan="10"><div class="error-msg">Fehler beim Laden</div></td></tr>';
    return;
  }
  const s = data.charging.summary;
  if (s) {
    el('ch-sessions').textContent = s.sessions;
    el('ch-kwh-ac').textContent = fmt(s.total_kwh_ac, 1);
    el('ch-kosten').textContent = [s.kwh_daheim ? `≈ ${eur(s.kwh_daheim)} daheim (${PREIS_TEXT})` : '',
      s.kwh_fremd ? `≈ ${eur(s.kwh_fremd, PREIS_FREMD_CT)} anderswo (angenommen ${ctText(PREIS_FREMD_CT)})` : ''].filter(Boolean).join(' · ');
    el('ch-kwh-dc').textContent = fmt(s.total_kwh_dc, 1);
    el('ch-eff').innerHTML = fmt(s.avg_eff_pct, 1) + '<span class="stat-unit">%</span>';
  }

  // Geladen je Monat (letzte 12 Monate mit Ladungen): daheim/anderswo gestapelt, Kosten als Linie
  (() => {
    const m = new Map();
    for (const c of data.charging.alle || []) {
      const d = parseDate(c.start_local); if (!d) continue;
      const k = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      const x = m.get(k) || {daheim: 0, fremd: 0, eur: 0};
      if (c.daheim) x.daheim += +c.kwh_used || 0; else x.fremd += +c.kwh_used || 0;
      x.eur += ladeKosten(c); m.set(k, x);
    }
    const keys = [...m.keys()].sort().slice(-12);
    const fremd = keys.some(k => m.get(k).fremd > 0);
    makeChart('chart-charge-kwh', {
      data: {
        labels: keys.map(k => { const [y, mo] = k.split('-'); return MONATE_DE[+mo - 1].slice(0, 3) + ' ' + y.slice(2); }),
        datasets: [
          {type: 'bar', label: 'daheim (kWh)', data: keys.map(k => +m.get(k).daheim.toFixed(1)), backgroundColor: 'rgba(0,200,81,.7)', borderRadius: 4, stack: 'kwh', yAxisID: 'y'},
          ...(fremd ? [{type: 'bar', label: 'anderswo (kWh)', data: keys.map(k => +m.get(k).fremd.toFixed(1)), backgroundColor: 'rgba(255,136,0,.75)', borderRadius: 4, stack: 'kwh', yAxisID: 'y'}] : []),
          {type: 'line', label: 'Kosten (€)', data: keys.map(k => +m.get(k).eur.toFixed(2)), borderColor: '#ffb300', backgroundColor: '#ffb300', tension: .3, pointRadius: 3, yAxisID: 'eur'},
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: {mode: 'index', intersect: false},
        plugins: {legend: {display: true, labels: {color: '#8888aa', font: {size: 10}}},
          tooltip: {callbacks: {label: c => c.dataset.yAxisID === 'eur' ? `Kosten: ${euroText(c.parsed.y)}` : `${c.dataset.label.replace(' (kWh)', '')}: ${fmt(c.parsed.y, 1)} kWh`}}},
        scales: {
          y: {stacked: true, beginAtZero: true, grid: {color: '#2a2a4e'}, ticks: {color: '#8888aa', callback: v => v + ' kWh'}},
          eur: {position: 'right', beginAtZero: true, grid: {drawOnChartArea: false}, ticks: {color: '#ffb300', callback: v => v + ' €'}},
          x: {stacked: true, grid: {display: false}, ticks: {color: '#8888aa'}},
        },
      },
    });
  })();

  // Weekday chart
  const weekdays = data.charging.by_weekday || [];
  const dayNames = ['Mo','Di','Mi','Do','Fr','Sa','So'];
  const byDay = new Array(7).fill(0);
  weekdays.forEach(w => { if (w.dow >= 1 && w.dow <= 7) byDay[w.dow-1] = w.kwh; });
  makeChart('chart-charge-weekday', {
    type: 'bar',
    data: {
      labels: dayNames,
      datasets: [{
        label: 'kWh',
        data: byDay,
        backgroundColor: 'rgba(91,127,201,.7)',
        borderColor: '#5b7fc9',
        borderWidth: 1,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, grid: { color: '#2a2a4e' }, ticks: { color: '#8888aa' } },
        x: { grid: { display: false }, ticks: { color: '#8888aa' } }
      }
    }
  });

  // Tabelle: alle Ladungen, nach Monat gruppiert (unabhängig vom Zeitraum oben)
  ladeTabelle(data.charging.alle || data.charging.recent || []);
}

function loadDrives(still) {
  if (still !== true) el('drives-table-body').innerHTML = '<tr><td colspan="10"><div class="loading-msg"><div class="spinner"></div>Laden…</div></td></tr>';
  loadStats(currentDrivesRange).then(data => {
    renderDrives(data);
    // Also update 30d summary on overview tab
    if (currentDrivesRange === '30d' && data.ok) {
      const s = data.drives.summary;
      if (s) {
        el('ov-m-drives').textContent = s.total_drives;
        el('ov-m-km').textContent = fmt(s.total_km, 0);
        el('ov-m-speed').innerHTML = fmt(s.max_speed, 0) + '<span class="stat-unit">km/h</span>';
      }
      const cs = data.charging.summary;
      if (cs) {
        el('ov-m-kwh').textContent = fmt(cs.total_kwh_dc, 1);
        el('ov-m-kosten').textContent = (cs.kwh_daheim || cs.kwh_fremd) ? `≈ ${eur(cs.kwh_daheim + cs.kwh_fremd * PREIS_FREMD_CT / STROMPREIS_CT)} Strom` + (cs.kwh_fremd ? ' (inkl. anderswo)' : ' daheim') : '';
      }
    }
  });
}

function loadCharging(still) {
  if (still !== true) el('charge-table-body').innerHTML = '<tr><td colspan="10"><div class="loading-msg"><div class="spinner"></div>Laden…</div></td></tr>';
  loadStats(currentChargeRange).then(renderCharging);
}

// ═══════════════════════════════════════════════════════
// BATTERY
// ═══════════════════════════════════════════════════════

// Akkuverlauf der letzten Stunden: SOC + Reichweite, Fahrten/Ladungen hinterlegt
let avStunden = 24;
const avBereiche = {
  id: 'avBereiche',
  beforeDatasetsDraw(c, _a, opt) {
    const x = c.scales.x, a = c.chartArea, ctx = c.ctx;
    for (const [liste, farbe] of [[opt.fahrten || [], 'rgba(91,127,201,.22)'], [opt.ladungen || [], 'rgba(0,200,81,.22)']]) {
      for (const b of liste) {
        const x0 = Math.max(x.getPixelForValue(new Date(b.von).getTime()), a.left), x1 = Math.min(x.getPixelForValue(new Date(b.bis).getTime()), a.right);
        if (x1 > x0) { ctx.save(); ctx.fillStyle = farbe; ctx.fillRect(x0, a.top, Math.max(x1 - x0, 2), a.bottom - a.top); ctx.restore(); }
      }
    }
  }
};
function loadAkkuverlauf() {
  apiGet('/api/akkuverlauf.php?h=' + avStunden).then(d => {
    if (!d || !d.ok) return;
    const p = d.punkte;
    if (!p.length) { el('av-info').textContent = 'Keine Messwerte in diesem Zeitraum.'; return; }
    const erst = p[0], letzt = p[p.length - 1];
    const dSoc = letzt.soc - erst.soc, dKm = letzt.km - erst.km;
    el('av-info').textContent = `${fmt(erst.soc, 0)} % → ${fmt(letzt.soc, 0)} % (${dSoc >= 0 ? '+' : ''}${fmt(dSoc, 0)} %, ${dKm >= 0 ? '+' : ''}${fmt(dKm, 0)} km) · ${d.fahrten.length} Fahrt${d.fahrten.length === 1 ? '' : 'en'}, ${d.ladungen.length} Ladung${d.ladungen.length === 1 ? '' : 'en'}`;
    const t = p.map(x => new Date(x.t).getTime());
    makeChart('chart-akkuverlauf', {
      type: 'line', plugins: [avBereiche],
      data: { datasets: [
        { label: 'Akku %', data: p.map((x, i) => ({x: t[i], y: x.soc})), borderColor: '#00c851', backgroundColor: 'rgba(0,200,81,.08)', fill: true, yAxisID: 'soc', pointRadius: 0, borderWidth: 2, tension: .25 },
        { label: 'Reichweite km', data: p.map((x, i) => ({x: t[i], y: x.km})), borderColor: '#5b7fc9', borderDash: [5, 4], yAxisID: 'km', pointRadius: 0, borderWidth: 1.5, tension: .25 },
      ] },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        scales: {
          x: { type: 'linear', min: Date.now() - avStunden * 3600e3, max: Date.now(),
               ticks: { maxTicksLimit: 8, callback: v => { const z = new Date(v); return avStunden > 48 ? z.toLocaleDateString('de-AT', {weekday: 'short', day: '2-digit'}) : z.toLocaleTimeString('de-AT', {hour: '2-digit', minute: '2-digit'}); } } },
          soc: { position: 'left', min: 0, max: 100, title: { display: true, text: '%' } },
          km: { position: 'right', grid: { display: false }, title: { display: true, text: 'km' } } },
        plugins: { legend: { display: false }, avBereiche: { fahrten: d.fahrten, ladungen: d.ladungen },
          tooltip: { callbacks: { title: it => new Date(it[0].parsed.x).toLocaleString('de-AT', {weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'}),
                                  label: c => c.dataset.label + ': ' + fmt(c.parsed.y, c.datasetIndex ? 0 : 1) } } } }
    });
  }).catch(() => {});
}
document.querySelectorAll('#av-range .range-btn').forEach(btn => btn.addEventListener('click', () => {
  document.querySelectorAll('#av-range .range-btn').forEach(b => b.classList.toggle('active', b === btn));
  avStunden = +btn.dataset.h; loadAkkuverlauf();
}));

function loadBattery() {
  loadAkkuverlauf();
  apiGet('/api/battery.php').then(data => {
    if (!data.ok) return;

    // Current
    const c = data.current;
    if (c) {
      if (el('bat-usable')) el('bat-usable').textContent = 'Nutzbar: ' + (c.usable_battery_level !== null ? c.usable_battery_level + ' %' : '--');
      if (el('bat-ideal'))  el('bat-ideal').textContent  = 'Ideal: ' + fmt(c.ideal_range_km, 0) + ' km';
      if (el('bat-heater')) el('bat-heater').textContent = c.battery_heater ? '🔥 Aktiv' : '✓ Inaktiv';
      if (el('bat-heater')) el('bat-heater').style.color = c.battery_heater ? 'var(--warn)' : 'var(--green)';
    }

    // Akku-Gesundheit: nutzbare Kapazität, zwei Wege (Reichweite je Tag, Ladungen), Neuzustand als Linie
    const k = data.kapazitaet;
    if (k) {
      const neu = k.neu_kwh, jetzt = k.jetzt_kwh ?? k.ladungen_kwh;
      if (jetzt) {
        el('kap-jetzt').innerHTML = fmt(jetzt, 1) + '<span class="stat-unit">kWh</span>';
        el('kap-jetzt-sub').textContent = k.jetzt_kwh ? 'aus der Reichweite, letzte 60 Tage' : 'aus den Ladungen';
        const soh = jetzt / neu * 100;
        el('kap-soh').innerHTML = fmt(soh, 0) + '<span class="stat-unit">%</span>';
        el('kap-soh').className = 'kap-wert ' + (soh >= 85 ? 'val-green' : soh >= 75 ? 'val-yellow' : 'val-accent');
        el('kap-soh-sub').textContent = `vom Neuzustand (${fmt(neu, 1)} kWh)`;
        el('kap-verlust').innerHTML = fmt(neu - jetzt, 1) + '<span class="stat-unit">kWh</span>';
        el('kap-verlust-sub').textContent = fmt(100 - soh, 0) + ' % weniger als neu';
        el('kap-balken-ist').style.width = Math.min(100, soh) + '%';
      }
      if (k.reichweite100_km) el('kap-r100').innerHTML = k.reichweite100_km + '<span class="stat-unit">km</span>';

      const tage = [...new Set([...k.tage.map(t => t.tag), ...k.ladungen.map(l => l.tag)])].sort();
      const proTag = tage.map(t => { const x = k.tage.find(y => y.tag === t); return x ? x.kwh : null; });
      const proLadung = tage.map(t => { const l = k.ladungen.filter(y => y.tag === t); return l.length ? +(l.reduce((a, y) => a + y.kwh, 0) / l.length).toFixed(1) : null; });
      if (tage.length) makeChart('chart-kapazitaet', {
        type: 'line',
        data: {
          labels: tage.map(t => { const [y, m, d] = t.split('-'); return `${d}.${m}.${y.slice(2)}`; }),
          datasets: [
            {label: 'aus Reichweite (je Tag)', data: proTag, borderColor: '#00c851', backgroundColor: 'rgba(0,200,81,.12)',
             fill: 'origin', spanGaps: true, tension: 0.25, pointRadius: 3},
            {label: 'aus Ladungen (je Ladung)', data: proLadung, showLine: false, pointRadius: 6, pointStyle: 'rectRot',
             borderColor: '#ffb300', backgroundColor: '#ffb300'},
            {label: `Neuzustand ${fmt(neu, 1)} kWh`, data: tage.map(() => neu), borderColor: 'rgba(160,160,200,.6)',
             borderDash: [6, 4], pointRadius: 0, borderWidth: 1.5, fill: false},
            {label: 'Außentemperatur (°C)', data: tage.map(t => { const x = k.tage.find(y => y.tag === t); return x ? x.temp : null; }),
             yAxisID: 'temp', borderColor: 'rgba(91,155,213,.7)', backgroundColor: 'rgba(91,155,213,.7)', borderWidth: 1.2,
             pointRadius: 2, spanGaps: true, tension: 0.3, fill: false},
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: {mode: 'index', intersect: false},
          plugins: {legend: {display: true, labels: {color: '#8888aa', font: {size: 10}}},
            tooltip: {callbacks: {label: c => c.parsed.y == null ? null : c.dataset.yAxisID === 'temp'
              ? `Außentemperatur: ${c.parsed.y.toFixed(1)} °C` : `${c.dataset.label}: ${c.parsed.y.toFixed(1)} kWh (${(c.parsed.y / neu * 100).toFixed(0)} %)`}}},
          scales: {
            y: {suggestedMin: 70, suggestedMax: 100, grid: {color: '#2a2a4e'}, ticks: {color: '#8888aa', callback: v => v + ' kWh'}},
            temp: {position: 'right', suggestedMin: -10, suggestedMax: 35, grid: {drawOnChartArea: false}, ticks: {color: 'rgba(91,155,213,.9)', callback: v => v + ' °C'}},
            x: {grid: {display: false}, ticks: {color: '#8888aa', maxTicksLimit: 10}},
          },
        },
      });
      el('kap-info').innerHTML =
        `<span style="color:#00c851">●</span> <b>aus Reichweite:</b> Reichweite hochgerechnet auf 100 %` + (k.reichweite100_km ? ` (${k.reichweite100_km} km)` : '') +
        ` × Verbrauchsfaktor des Autos (${k.auto_eff_kwh_km ? Math.round(k.auto_eff_kwh_km * 1000) + ' Wh/km' : '--'}); so rechnet auch TeslaMate.<br>` +
        `<span style="color:#ffb300">◆</span> <b>aus Ladungen:</b> „Energie hinzugefügt“ ÷ geladene Prozent × 100, Ladungen ab 5 %` +
        (k.ladungen_kwh ? ` (zusammen ${fmt(k.ladungen_summe_kwh, 1)} kWh für ${k.ladungen_summe_soc} % = ${fmt(k.ladungen_kwh, 1)} kWh)` : '') +
        `; Einzelwerte streuen, weil der Akkustand nur in ganzen Prozent kommt.<br>` +
        `<span style="color:rgba(91,155,213,.9)">—</span> <b>Temperatur:</b> Bei Kälte meldet das Auto weniger Reichweite, weil der kalte Akku nicht alles hergibt – ` +
        `die Kapazität „aus Reichweite“ sinkt dann im Winter vorübergehend. Das ist keine Alterung; echte Alterung zeigt sich im Vergleich gleich warmer Tage über die Jahre.<br>` +
        `Beide Wege beruhen auf Angaben des Autos. Genauer wäre nur eine echte Messung (von fast leer auf 100 % laden).`;
    }

    // SOC distribution
    if (data.soc_distribution && data.soc_distribution.length > 0) {
      const dist = data.soc_distribution;
      makeChart('chart-soc-dist', {
        type: 'bar',
        data: {
          labels: dist.map(d => d.bucket + '%'),
          datasets: [{
            label: 'Häufigkeit',
            data: dist.map(d => d.count),
            backgroundColor: dist.map(d => {
              if (d.bucket >= 50) return 'rgba(0,200,81,.7)';
              if (d.bucket >= 25) return 'rgba(255,179,0,.7)';
              return 'rgba(227,25,55,.7)';
            }),
            borderRadius: 3,
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, grid: { color: '#2a2a4e' }, ticks: { color: '#8888aa' } },
            x: { grid: { display: false }, ticks: { color: '#8888aa', maxTicksLimit: 12 } }
          }
        }
      });
    }

    // Ladeeffizienz je Ladung (im Akku ÷ Auto-Eingang, laut Auto; Kurz-Ladungen < 1 kWh filtert die API)
    if (data.efficiency_trend && data.efficiency_trend.length > 0) {
      const eff = [...data.efficiency_trend].reverse().filter(e => e.efficiency_pct !== null && e.efficiency_pct <= 100);
      const sa = eff.reduce((a, e) => a + e.kwh_added, 0), su = eff.reduce((a, e) => a + e.kwh_used, 0);
      const schnitt = su > 0 ? sa / su * 100 : null;
      makeChart('chart-eff-trend', {
        data: {
          labels: eff.map(e => fmtDateShort(e.start_local)),
          datasets: [
            {type: 'bar', label: 'Ladeeffizienz', data: eff.map(e => e.efficiency_pct), borderRadius: 4,
             backgroundColor: eff.map(e => e.efficiency_pct >= 90 ? 'rgba(0,200,81,.75)' : e.efficiency_pct >= 85 ? 'rgba(255,179,0,.75)' : 'rgba(227,25,55,.75)')},
            ...(schnitt ? [{type: 'line', label: `Ø ${schnitt.toFixed(1)} %`, data: eff.map(() => +schnitt.toFixed(1)),
             borderColor: 'rgba(160,160,200,.8)', borderDash: [5, 4], pointRadius: 0, borderWidth: 1.5}] : []),
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: {legend: {display: true, labels: {color: '#8888aa', font: {size: 10}, filter: i => i.datasetIndex > 0}},
            tooltip: {callbacks: {label: c => { if (c.datasetIndex) return c.dataset.label; const e = eff[c.dataIndex];
              return [`${e.efficiency_pct.toFixed(1)} %`, `${e.kwh_added.toFixed(2)} kWh im Akku von ${e.kwh_used.toFixed(2)} kWh`, `Akku ${e.soc_start} → ${e.soc_end} %`]; }}}},
          scales: {
            y: {min: 75, max: 100, grid: {color: '#2a2a4e'}, ticks: {color: '#8888aa', callback: v => v + ' %'}},
            x: {grid: {display: false}, ticks: {color: '#8888aa', maxTicksLimit: 8}},
          },
        },
      });
      el('eff-trend-info').textContent = 'Im Akku ÷ vom Auto aufgenommen, beides laut Auto. Nur das Auto (nicht Wallbox und Leitung); Ladungen unter 1 kWh sind ausgenommen. Grün ≥ 90 %, gelb ≥ 85 %.';
    }

    // Temp/Range correlation
    if (data.temp_range_correlation && data.temp_range_correlation.length > 0) {
      const tr = data.temp_range_correlation;
      makeChart('chart-temp-range', {
        type: 'bar',
        data: {
          labels: tr.map(t => t.temp_bucket + ' °C'),
          datasets: [{
            label: 'Ø effektive Reichweite (km)',
            data: tr.map(t => t.avg_eff_range),
            backgroundColor: tr.map(t => {
              if (t.temp_bucket >= 15) return 'rgba(0,200,81,.7)';
              if (t.temp_bucket >= 5)  return 'rgba(255,179,0,.7)';
              return 'rgba(61,90,153,.7)';
            }),
            borderRadius: 4,
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, title: { display: true, text: 'Temperatur (°C) vs. Reichweite', color: '#8888aa' } },
          scales: {
            y: { beginAtZero: false, grid: { color: '#2a2a4e' }, ticks: { color: '#8888aa' } },
            x: { grid: { display: false }, ticks: { color: '#8888aa' } }
          }
        }
      });
    }
  });
}

// ═══════════════════════════════════════════════════════
// WALLBOX
// ═══════════════════════════════════════════════════════

function wbStateHtml(label) {
  // DaheimLader meldet deutsch („Laden“) – ältere Stände englisch
  const map = {
    'Standby':  ['state-online',   'state-dot-online'],   'Bereit':   ['state-online',   'state-dot-online'],
    'Charging': ['state-charging', 'state-dot-charging'], 'Laden':    ['state-charging', 'state-dot-charging'],
    'Finished': ['state-online',   'state-dot-online'],   'Fertig':   ['state-online',   'state-dot-online'],
    'Error':    ['state-offline',  'state-dot-offline'],  'Fehler':   ['state-offline',  'state-dot-offline'],
    'Angesteckt': ['state-online', 'state-dot-online'],
  };
  const [cls, dot] = map[label] || map['Standby'];
  return `<span class="state-badge ${cls}"><span class="dot ${dot}"></span>${esc(label)}</span>`;
}

// Lädt nur, wenn wirklich Strom fließt: Die Wallbox bleibt nach Ladeende (Auto voll, nur angesteckt)
// auf Status 4 „Laden“ stehen.
function wbLaedt(wb) {
  if (!wb) return false;
  const strom = ['l1', 'l2', 'l3'].some(p => (+wb['current_' + p + '_a'] || 0) > 1);
  return (+wb.power_w || 0) > 200 || strom;
}

// Angezeigter Status: „Laden“ ohne Stromfluss heißt „Angesteckt“
function wbStatusText(wb) {
  const l = wb.charging_state_label || 'Standby';
  return ['Laden', 'Charging'].includes(l) && !wbLaedt(wb) ? 'Angesteckt' : l;
}

function fmtDauerS(s) {
  if (!s || s <= 0) return '--';
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
  return h > 0 ? `${h} h ${String(m).padStart(2, '0')} min` : `${m} min`;
}

// Energiefluss Hausanschluss → Kabel → Wallbox → Bordlader → Akku
function wbFluss(wb, cmp, kabel, laedt) {
  if (!laedt || !cmp) return '';
  const knoten = [
    ['Hausanschluss', cmp.power_at_grid_kw, '🏠'],
    ['Wallbox', cmp.wallbox_power_kw ?? wb.power_kw, '🔌'],
    ['Bordlader', cmp.charger_power_kw, '🚗'],
    ['Akku (DC)', cmp.dc_kw_estimate, '🔋'],
  ].filter(k => k[1] !== null && k[1] !== undefined);
  const verlust = kabel && kabel.loss_kw_total ? `<div class="wb-verlust">Kabelverlust Hausanschluss → Wallbox: <b>${fmt(kabel.loss_kw_total * 1000, 0)} W</b> (${fmt(kabel.loss_pct, 1)} %)</div>` : '';
  return `<div class="wb-fluss">${knoten.map((k, i) => `
      ${i ? '<div class="wb-pfeil"><span></span></div>' : ''}
      <div class="wb-knoten"><div class="wb-knoten-icon">${k[2]}</div><div class="wb-knoten-kw">${fmt(k[1], 2)} kW</div><div class="wb-knoten-label">${k[0]}</div></div>`).join('')}
    </div>${verlust}`;
}

// ── Verlustbilanz Hausanschluss → Akku (Hover-Fenster) ─────────────────
// Leitungsverlust wächst mit dem Quadrat des Stroms, also mit P². Faktor a (kW Verlust je kW²) als
// Ausgleichsgerade durch die Kalibrierungspunkte: Verlust = a · P². Über eine Ladung: a · P_Ø · E.
let wbKalib = null, wbBilanzen = {};
function leitungsFaktor(kalib) {
  const r = (kalib && kalib.results || []).filter(x => x.cable_loss_kw > 0 && x.wb_avg_kw > 0);
  if (!r.length) return null;
  return r.reduce((s, x) => s + x.cable_loss_kw * x.wb_avg_kw ** 2, 0) / r.reduce((s, x) => s + x.wb_avg_kw ** 4, 0);
}

// b: {titel, wb (kWh an der Wallbox), wbGemessen, akku (kWh laut Auto), stunden}
function verlustBilanz(b) {
  const a = leitungsFaktor(wbKalib);
  if (!(b.wb > 0) || !(b.stunden > 0)) return null;
  const pSchnitt = b.wb / b.stunden;
  const leitung = a !== null ? a * pSchnitt * b.wb : null;
  const netz = b.wb + (leitung || 0);
  const akku = b.akku > 0 ? Math.min(b.akku, b.wb) : null;
  return {...b, pSchnitt, leitung, netz, akku, imAuto: akku !== null ? b.wb - akku : null};
}

function verlustFenster(b) {
  const pct = x => fmt(x / b.netz * 100, 1) + ' %';
  const kwh = x => fmt(x, 2) + ' kWh';
  const e = x => `<td class="vf-eur">${eur(x)}</td>`;
  const teile = [
    ['akku', 'Im Akku', b.akku],
    ['auto', 'Verluste im Auto', b.imAuto],
    ['leitung', 'Leitungsverlust', b.leitung],
  ].filter(t => t[2] !== null && t[2] > 0);
  const gesamt = (b.leitung || 0) + (b.imAuto || 0);
  // Leitungsverlust je Ladestrom aus der Kalibrierung
  const r = (wbKalib && wbKalib.results || []).filter(x => x.cable_loss_kw > 0);
  const maxW = Math.max(...r.map(x => x.cable_loss_kw), 0.001);
  const nah = r.length ? r.reduce((m, x) => Math.abs(x.wb_avg_kw - b.pSchnitt) < Math.abs(m.wb_avg_kw - b.pSchnitt) ? x : m) : null;
  return `
    <div class="vf-titel">${esc(b.titel)}</div>
    <div class="vf-balken">${teile.map(t => `<div class="vf-${t[0]}" style="flex:${t[2]}" title="${t[1]}"></div>`).join('')}</div>
    <table class="vf-tabelle">
      <tr><td>🏠 Aus dem Hausanschluss</td><td>${kwh(b.netz)}</td><td>100 %</td>${e(b.netz)}</tr>
      ${b.leitung !== null ? `<tr class="vf-minus"><td><i class="vf-leitung"></i>Leitung bis zur Wallbox</td><td>− ${kwh(b.leitung)}</td><td>${pct(b.leitung)}</td>${e(b.leitung)}</tr>` : ''}
      <tr><td>🔌 An der Wallbox ${b.wbGemessen ? '<small>(Zähler)</small>' : '<small>(laut Auto)</small>'}</td><td>${kwh(b.wb)}</td><td>${pct(b.wb)}</td>${e(b.wb)}</tr>
      ${b.imAuto !== null ? `<tr class="vf-minus"><td><i class="vf-auto"></i>Ladegerät, Kühlung, Grundlast</td><td>− ${kwh(b.imAuto)}</td><td>${pct(b.imAuto)}</td>${e(b.imAuto)}</tr>` : ''}
      ${b.akku !== null ? `<tr><td><i class="vf-akku"></i>🔋 Im Akku <small>(Schätzung des Autos)</small></td><td>${kwh(b.akku)}</td><td>${pct(b.akku)}</td>${e(b.akku)}</tr>` : ''}
      <tr class="vf-summe"><td>Gesamtverlust</td><td>${kwh(gesamt)}</td><td>${pct(gesamt)}</td>${e(gesamt)}</tr>
    </table>
    ${r.length ? `<div class="vf-unter">Leitungsverlust je Ladestrom <small>(Kalibrierung)</small></div>
    <div class="vf-strom">${r.map(x => `
      <div class="vf-strom-zeile ${x === nah ? 'aktiv' : ''}"><span>${x.amps} A</span>
        <div class="vf-strom-balken"><div style="width:${x.cable_loss_kw / maxW * 100}%"></div></div>
        <span>${fmt(x.cable_loss_kw * 1000, 0)} W · ${fmt(x.cable_loss_kw / x.wb_avg_kw * 100, 1)} %</span></div>`).join('')}
    </div>` : ''}
    <div class="vf-hinweis">Kosten: kWh × ${PREIS_TEXT} (Fixtarif inkl. Netz und USt). Ø ${fmt(b.pSchnitt, 1)} kW. Leitungsverlust aus dem Spannungsabfall Hausanschluss → Wallbox hochgerechnet.
      ${b.wbGemessen ? 'Die Akku-Angabe schätzt das Auto; sie liegt eher zu hoch, die Verluste im Auto also eher zu niedrig.' : 'Für ältere Ladungen ist kein Wallbox-Zählerstand gespeichert; Grundlage ist die Angabe des Autos (eher zu hoch).'}</div>`;
}

// Ein Fenster für alle Elemente mit data-verlust (Maus: drüberfahren, Touch: antippen)
(function () {
  let pop = null;
  const zeige = ziel => {
    const b = wbBilanzen[ziel.dataset.verlust]; if (!b) return;
    if (!pop) { pop = document.createElement('div'); pop.id = 'verlust-fenster'; document.body.appendChild(pop); }
    pop.innerHTML = verlustFenster(b); pop.hidden = false;
    const r = ziel.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight;
    let x = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), innerWidth - w - 8);
    let y = r.bottom + 8; if (y + h > innerHeight - 8 && r.top - h - 8 > 8) y = r.top - h - 8;
    pop.style.left = (x + scrollX) + 'px'; pop.style.top = (y + scrollY) + 'px';
  };
  const weg = () => { if (pop) pop.hidden = true; };
  document.addEventListener('mouseover', e => { const z = e.target.closest && e.target.closest('[data-verlust]'); if (z) zeige(z); });
  document.addEventListener('mouseout', e => { const z = e.target.closest && e.target.closest('[data-verlust]'); if (z && !z.contains(e.relatedTarget)) weg(); });
  document.addEventListener('click', e => { const z = e.target.closest && e.target.closest('[data-verlust]'); if (z) zeige(z); else if (!(e.target.closest && e.target.closest('#verlust-fenster'))) weg(); });
  addEventListener('scroll', weg, {passive: true});
})();

// Ziel und Restzeit (dezent unter der kW-Anzeige) – nur beim Laden
function wbZiel(wb, cmp, ctrl, z, laedt) {
  if (!laedt) return '';
  const soc = z && z.akku !== null && z.akku !== undefined ? z.akku : (cmp ? cmp.battery_level_pct : null);
  const auto = ctrl && ctrl.mode === 'auto';
  const ziel = auto && ctrl.auto_target_soc ? ctrl.auto_target_soc : (z ? z.ladelimit : null);
  const h = z && z.laden ? z.laden.stunden_bis_voll : null;
  const rest = fmtStunden(h);
  const fertig = fertigUm(h);
  if (soc === null && ziel === null && !rest) return '';
  const teile = [];
  if (soc !== null) teile.push(`Akku <b>${soc} %</b>` + (ziel ? ` → Ziel <b>${ziel} %</b>` : ''));
  if (rest) teile.push(`noch <b>${rest}</b>`);
  if (fertig) teile.push(`fertig ca. <b>${fertig}</b>`);
  if (auto && ctrl.auto_target_time) teile.push(`Automatik bis ${esc(ctrl.auto_target_time)}`);
  return `<div class="wb-ziel">
      ${soc !== null ? `<div class="wb-ziel-balken"><div class="ist" style="width:${Math.min(100, soc)}%"></div>${ziel ? `<div class="ziel" style="left:${Math.min(100, ziel)}%" title="Ziel ${ziel} %"></div>` : ''}</div>` : ''}
      <div class="wb-ziel-text">${teile.join(' · ')}</div>
    </div>`;
}

// Hausanschluss und Lastbegrenzung (DaheimLader, seit 29.09.2026): ab 2027 wird ein 15-min-Mittel über 10 kW teurer
function wbHausanschluss(lb) {
  if (!lb || lb.haus_kw === null || lb.haus_kw === undefined) return '';
  const k = lb.konfig || {}, v = lb.viertel || {}, teuer = lb.teuer_kw || 10, gr = k.aktiv ? +k.grenze_kw : null, skala = 12;
  const kw = +lb.haus_kw, ohne = lb.haus_ohne_wb_kw, prog = v.prognose_kw;
  const stufe = x => x > teuer ? 'rot' : (gr && x > gr) ? 'gelb' : 'gruen';
  const status = !k.aktiv ? ['aus', 'Begrenzung aus'] : lb.erlaubt_a === 0 ? ['rot', 'Pause – zu viel am Hausanschluss']
    : lb.erlaubt_a >= 16 ? ['gruen', 'nicht eingeschränkt'] : ['gelb', `Tesla höchstens ${lb.erlaubt_a} A`];
  const teile = [];
  // ohne = Netzbezug ohne Wallbox (Hausverbrauch − Carport-PV); negativ = PV deckt das Haus, der Überschuss geht ins Auto
  if (ohne !== null && ohne !== undefined) teile.push(ohne < -0.05
    ? `davon Tesla ${fmt(Math.max(kw - ohne, 0), 1)} kW · PV deckt das Haus, ${fmt(-ohne, 1)} kW Überschuss ins Auto`
    : `davon Tesla ${fmt(Math.max(kw - ohne, 0), 1)} · Haus (nach PV) ${fmt(ohne, 1)} kW`);
  if (lb.wp_laeuft) teile.push('Wärmepumpe läuft');
  return `<div class="ha-box">
      <div class="ha-kopf"><span class="w-label">Hausanschluss gesamt (ganzes Haus)</span><span class="ha-status ${status[0]}">${status[1]}</span></div>
      <div class="ha-zeile"><span class="ha-kw ${stufe(kw)}">${fmt(kw, 1)}</span><span class="wb-einheit">kW</span><span class="wb-limit">${teile.join(' · ')}</span></div>
      <div class="ha-balken"><div class="ha-fuell ${stufe(kw)}" style="width:${Math.min(100, Math.max(0, kw / skala * 100))}%"></div>
        ${gr ? `<div class="ha-marke" style="left:${gr / skala * 100}%" title="Grenze ${fmt(gr, 1)} kW"></div>` : ''}
        <div class="ha-marke teuer" style="left:${teuer / skala * 100}%" title="${teuer} kW – ab hier teurer"></div></div>
      ${v.von ? `<div class="wb-sub">Viertelstunde ${v.von}–${v.bis}: bisher Ø <b>${fmt(v.bisher_kw ?? kw, 1)}</b> kW · Prognose Ø <b class="ha-${stufe(prog ?? 0)}">${fmt(prog ?? 0, 1)}</b> kW${gr ? ` · Grenze ${fmt(gr, 1)} kW` : ''}</div>` : ''}
      ${k.aktiv && lb.grund ? `<div class="wb-sub">Regelung: ${esc(lb.grund)}</div>` : ''}
    </div>`;
}

function renderWbStatus(wb, cmp, kabel, ctrl, z, lb) {
  if (!wb || !wb.ok) {
    return '<div class="error-msg">' + esc(wb && wb.error ? wb.error : 'Keine Daten') + '</div>';
  }
  const laedt = wbLaedt(wb);
  const limitA = +wb.current_limit_a || 0, maxA = +wb.evse_max_a || limitA || 16;
  const phasen = ['l1', 'l2', 'l3'].filter(p => (+wb['current_' + p + '_a'] || 0) > 0.5).length || 3;
  const maxKw = maxA * 230 * 3 / 1000;
  const anteil = Math.min(100, Math.max(0, (+wb.power_kw || 0) / maxKw * 100));
  const avgKw = wb.session_duration_s > 60 ? wb.session_energy_kwh / (wb.session_duration_s / 3600) : null;
  const netzV = kabel ? {l1: kabel.shelly_v_l1, l2: kabel.shelly_v_l2, l3: kabel.shelly_v_l3} : {};
  return `
    <div class="wb-kopf">
      ${wbStateHtml(wbStatusText(wb))}
      ${laedt ? '<span class="lade-badge">⚡ lädt</span>' : ''}
    </div>
    <div class="wb-leistung">
      <span class="wb-kw ${laedt ? 'aktiv' : ''}">${(+wb.power_kw || 0).toFixed(2)}</span><span class="wb-einheit">kW</span>
      <span class="wb-limit">${limitA > 0 ? `Limit ${limitA} A · max. ${maxA} A (${fmt(maxKw, 1)} kW)` : ''}</span>
    </div>
    <div class="wb-balken ${laedt ? 'aktiv' : ''}"><div style="width:${anteil}%"></div></div>
    ${wbHausanschluss(lb)}
    ${wbZiel(wb, cmp, ctrl, z, laedt)}
    ${wbFluss(wb, cmp, kabel, laedt)}
    <div class="wb-phasen">
      ${['l1', 'l2', 'l3'].map(p => {
        const a = +wb['current_' + p + '_a'] || 0, v = +wb['voltage_' + p + '_v'] || 0, nv = netzV[p];
        const drop = nv ? nv - v : null;
        return `<div class="wb-phase">
          <div class="w-label">${p.toUpperCase()}</div>
          <div class="wb-phase-a ${a > 0.5 ? 'val-green' : ''}">${a.toFixed(1)}<small> A</small></div>
          <div class="wb-phase-balken ${laedt && a > 0.5 ? 'aktiv' : ''}"><div style="width:${limitA ? Math.min(100, a / limitA * 100) : 0}%"></div></div>
          <div class="wb-phase-v">${v.toFixed(0)} V${drop !== null && laedt ? ` <span title="Spannungsabfall Hausanschluss → Wallbox">−${fmt(drop, 1)} V</span>` : ''}</div>
        </div>`;
      }).join('')}
    </div>
    <div class="wb-kennzahlen">
      <div class="wallbox-item ${wbBilanzen.session ? 'hat-verlust' : ''}" ${wbBilanzen.session ? 'data-verlust="session"' : ''}><div class="w-label">Session geladen</div>
        <div class="w-value val-green">${(+wb.session_energy_kwh || 0).toFixed(2)}<small> kWh</small></div>
        <div class="wb-sub">${fmtDauerS(wb.session_duration_s)}${avgKw ? ' · Ø ' + fmt(avgKw, 1) + ' kW' : ''}</div>
        ${wbBilanzen.session ? `<div class="wb-sub">≈ <b style="color:var(--text)">${eur(wbBilanzen.session.netz)}</b> ab Hausanschluss</div><div class="wb-sub vf-i">ⓘ Verlustbilanz – drüberfahren oder antippen</div>` : ''}</div>
      <div class="wallbox-item"><div class="w-label">Im Akku angekommen</div>
        <div class="w-value">${cmp && cmp.tesla_energy_added_kwh !== null && cmp.tesla_energy_added_kwh !== undefined ? fmt(cmp.tesla_energy_added_kwh, 2) + '<small> kWh</small>' : (wbBilanzen.session && wbBilanzen.session.akku ? fmt(wbBilanzen.session.akku, 2) + '<small> kWh</small>' : '--')}</div>
        <div class="wb-sub">laut Auto${!cmp && wbBilanzen.session && wbBilanzen.session.akku ? ' · letzte Ladung' : ''}${cmp && cmp.battery_level_pct ? ' · Akku ' + cmp.battery_level_pct + ' %' : ''}</div></div>
      <div class="wallbox-item"><div class="w-label">Gesamt geladen</div>
        <div class="w-value val-blue">${((+wb.total_energy_kwh || 0) / 1000).toFixed(2)}<small> MWh</small></div>
        <div class="wb-sub">${(+wb.total_energy_kwh || 0).toFixed(0)} kWh total</div></div>
    </div>`;
}

function renderWbControl(ctrl, calib) {
  if (!ctrl) return '<div class="error-msg">Keine Steuerdaten</div>';
  const modeLabel = ctrl.mode === 'auto' ? '⚡ Auto' : '✋ Manuell';
  const modeColor = ctrl.mode === 'auto' ? 'val-green' : '';
  let html = `
    <div style="margin-bottom:1rem">
      <div class="stat-label">Modus</div>
      <div class="stat-value ${modeColor}" style="font-size:1.6rem">${modeLabel}</div>
    </div>
    <div class="grid-2" style="margin-bottom:1rem">
      <div class="wallbox-item">
        <div class="w-label">Ziel-SOC</div>
        <div class="w-value val-blue">${ctrl.auto_target_soc}<span style="font-size:.75rem;font-weight:400;color:var(--muted)"> %</span></div>
      </div>
      <div class="wallbox-item">
        <div class="w-label">Fertig bis</div>
        <div class="w-value">${ctrl.auto_target_time || '--'}</div>
      </div>
    </div>`;
  if (ctrl.mode === 'auto' && ctrl.auto_status) {
    html += `<div style="font-size:.8rem;color:var(--muted);margin-bottom:.8rem">Status: ${ctrl.auto_status}</div>`;
  }
  if (ctrl.auto_needed_h) {
    html += `<div style="font-size:.8rem;color:var(--muted);margin-bottom:.8rem">Benötigte Zeit: ${ctrl.auto_needed_h.toFixed(1)} h</div>`;
  }
  if (ctrl.auto_error) {
    html += `<div class="error-msg" style="margin-bottom:.8rem">${ctrl.auto_error}</div>`;
  }
  if (calib && calib.recommendation) {
    const rec = calib.recommendation;
    html += `
      <div style="border-top:1px solid var(--border);padding-top:.8rem;margin-top:.4rem">
        <div class="stat-label" style="margin-bottom:.5rem">Kalibrierung – Empfehlung</div>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;font-size:.85rem;align-items:center">
          <span><b style="color:var(--text)">${rec.amps} A</b><span style="color:var(--muted)"> → </span><b style="color:var(--green)">${rec.wb_avg_kw.toFixed(1)} kW</b></span>
          ${rec.efficiency_pct ? `<span style="color:var(--muted)" title="TeslaMate-Angabe „Energie hinzugefügt“ (Schätzung des Autos) im Verhältnis zum Wallbox-Zähler. Kein Wirkungsgrad: Über 100 % heißt nur, dass das Auto mehr Energie meldet, als die Wallbox gemessen hat.">Auto-Schätzung ${rec.efficiency_pct >= 100 ? '+' : ''}${fmt(rec.efficiency_pct - 100, 1)} % ggü. Wallbox</span>` : ''}
          <span style="color:var(--muted)">Kabelverlust ${rec.cable_loss_kw.toFixed(2)} kW</span>
        </div>
      </div>`;
  }
  return html;
}

function renderWbHistory(history) {
  if (!history || history.length === 0) {
    return '<div style="text-align:center;color:var(--muted);padding:2rem">Keine Ladesessions vorhanden</div>';
  }
  // Ladeeffizienz wie in TeslaMate: im Akku angekommen / am Auto-Eingang aufgenommen (beides Angaben des Autos).
  // Unter 1 kWh (Vorklimatisieren, Standby) oder über 100 % ist der Wert nicht aussagekräftig.
  const effizienz = h => {
    const rein = +h.charge_energy_used, akku = +h.charge_energy_added;
    if (!(rein > 0) || !(akku >= 1)) return null;
    const e = akku / rein * 100;
    return e <= 100 ? e : null;
  };
  const rows = history.map(h => { const eff = effizienz(h); return `
    <tr>
      <td>${fmtDateShort(h.start_date)}</td>
      <td>
        <span class="${socClass(h.start_battery_level)}">${h.start_battery_level !== null ? h.start_battery_level + '%' : '--'}</span>
        → <span class="${socClass(h.end_battery_level)}">${h.end_battery_level !== null ? h.end_battery_level + '%' : '--'}</span>
      </td>
      <td><b class="val-green">${h.charge_energy_added !== null ? (+h.charge_energy_added).toFixed(2) : '--'} kWh</b></td>
      ${(() => { const b = wbBilanzen['h' + h.id]; const k = b && b.wbGemessen ? b.netz : (h.charge_energy_added >= 1 ? +h.charge_energy_used : null);
         return `<td title="${k ? eurTitel(k, b && b.wbGemessen ? 'ab Hausanschluss (Wallbox-Zähler + Leitung)' : 'am Auto-Eingang (laut Auto)') : ''}">${k ? eur(k) : '--'}</td>`; })()}
      <td class="${eff !== null && eff >= 88 ? 'val-green' : 'val-yellow'}${wbBilanzen['h' + h.id] ? ' hat-verlust' : ''}" ${wbBilanzen['h' + h.id] ? `data-verlust="h${h.id}"` : ''}>${eff !== null ? eff.toFixed(1) + ' %' : '--'}</td>
      <td>${fmtDuration(h.duration_min)}</td>
    </tr>`; }).join('');
  return `
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead><tr><th>Datum</th><th>SOC</th><th>Geladen</th><th>Kosten</th><th>Effizienz</th><th>Dauer</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

// Session der Wallbox (Zähler) und TeslaMate-Ladungen zu Verlustbilanzen verbinden
function wbBilanzenBerechnen(data) {
  wbKalib = data.calibration || null; wbBilanzen = {};
  const wb = data.wallbox || {}, hist = (data.teslamate && data.teslamate.history) || [];
  const cmp = data.comparison, h0 = hist[0];
  const wbKwh = +wb.session_energy_kwh || 0, wbH = (+wb.session_duration_s || 0) / 3600;
  // Gehört die letzte TeslaMate-Ladung zur Wallbox-Session? (Dauer ±15 min, Energie ±25 %)
  const passt = h0 && wbKwh > 0 && Math.abs(h0.duration_min / 60 - wbH) < 0.25 && Math.abs(h0.charge_energy_added - wbKwh) < wbKwh * 0.25;
  const akkuSession = wbLaedt(wb) && cmp ? cmp.tesla_energy_added_kwh : (passt ? +h0.charge_energy_added : null);
  if (wbKwh >= 0.5) {
    const b = verlustBilanz({titel: wbLaedt(wb) ? 'Laufende Ladung' : 'Letzte Ladung (Wallbox-Session)', wb: wbKwh, wbGemessen: true, akku: akkuSession, stunden: wbH});
    if (b) wbBilanzen.session = b;
  }
  hist.forEach((h, i) => {
    if (!(h.charge_energy_added >= 1)) return;
    if (i === 0 && passt && wbBilanzen.session) { wbBilanzen['h' + h.id] = {...wbBilanzen.session, titel: 'Ladung ' + fmtDateShort(h.start_date)}; return; }
    const b = verlustBilanz({titel: 'Ladung ' + fmtDateShort(h.start_date), wb: +h.charge_energy_used, wbGemessen: false, akku: +h.charge_energy_added, stunden: h.duration_min / 60});
    if (b) wbBilanzen['h' + h.id] = b;
  });
}

function loadWallbox(still) {
  if (!HAT_DAHEIMLADER) return;
  if (still !== true) ['wb-status','wb-control','wb-history'].forEach(id => {
    const e = el(id);
    if (e) e.innerHTML = '<div class="loading-msg"><div class="spinner"></div>Verbinde…</div>';
  });
  apiGet('/api/proxy.php?endpoint=combined').then(data => {
    if (!data || data.ok === false) {
      const msg = '<div class="error-msg">' + (data && data.error ? data.error : 'Verbindung fehlgeschlagen') + '</div>';
      ['wb-status','wb-control','wb-history'].forEach(id => { const e = el(id); if (e) e.innerHTML = msg; });
      return;
    }
    wbBilanzenBerechnen(data);
    if (el('wb-status')) {
      el('wb-status').innerHTML = renderWbStatus(data.wallbox, data.comparison, data.cable_loss, data.charge_control, zsLetzter, data.lastbegrenzung);
      const karte = el('wb-status').closest('.card');
      if (karte) karte.classList.toggle('wb-laedt', wbLaedt(data.wallbox));
    }
    if (el('wb-control')) el('wb-control').innerHTML = renderWbControl(data.charge_control, data.calibration);
    if (el('wb-history')) el('wb-history').innerHTML = renderWbHistory(data.teslamate && data.teslamate.history);
  }).catch(e => {
    // apiGet liefert verständliche Texte (Anmeldung/Verbindung/Server); alles andere ist ein Anzeigefehler
    const netz = e && /Anmeldung|Verbindung|Server|Antwort/.test(e.message);
    console.error('Wallbox:', e);
    const msg = `<div class="error-msg">${netz ? esc(e.message) : 'Anzeigefehler: ' + esc(e && e.message || String(e))}</div>`;
    ['wb-status','wb-control','wb-history'].forEach(id => { const e = el(id); if (e) e.innerHTML = msg; });
  });
}

// ═══════════════════════════════════════════════════════
// STATISTIK
// ═══════════════════════════════════════════════════════

let currentStatRange = 'all';
let statRechner = null;

const SPEED_LABELS = {0:'< 30', 30:'30–50', 50:'50–70', 70:'70–90', 90:'90–110', 110:'110–130', 130:'130+'};
// Grobe Faktoren, falls für ein Profil noch keine eigenen Messwerte vorliegen
// (bezogen auf den eigenen Gesamtdurchschnitt; Luftwiderstand wächst mit dem Quadrat der Geschwindigkeit)
const SPEED_FALLBACK = {0:1.05, 30:1.0, 50:0.95, 70:1.0, 90:1.12, 110:1.3, 130:1.5};

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function tempLabel(t) { return `${t} … ${t + 5} °C`; }
function whColor(v, min, max) {
  if (v === null || v === undefined) return 'transparent';
  const r = max > min ? Math.min(1, Math.max(0, (v - min) / (max - min))) : 0.5;
  return `hsl(${Math.round(130 - r * 130)}, 70%, 32%)`;
}

function renderMatrix(rows) {
  const box = el('stat-matrix');
  const cells = rows.filter(r => r.wh_km !== null);
  if (!cells.length) { box.innerHTML = '<div class="loading-msg">Noch keine Fahrdaten im Zeitraum.</div>'; return; }
  const temps  = [...new Set(cells.map(r => r.temp_bin))].sort((a, b) => a - b);
  const speeds = Object.keys(SPEED_LABELS).map(Number).filter(s => cells.some(r => r.speed_bin === s));
  const valid  = cells.filter(r => r.km >= 1 && r.wh_km > 0).map(r => r.wh_km);
  const min = Math.min(...valid), max = Math.max(...valid);
  let h = '<table class="matrix-table"><thead><tr><th>°C \\ km/h</th>';
  speeds.forEach(s => h += `<th>${SPEED_LABELS[s]}</th>`);
  h += '</tr></thead><tbody>';
  temps.slice().reverse().forEach(t => {
    h += `<tr><th>${tempLabel(t)}</th>`;
    speeds.forEach(s => {
      const c = cells.find(r => r.temp_bin === t && r.speed_bin === s);
      if (!c) { h += '<td class="mx-empty">–</td>'; return; }
      const thin = c.km < 1 || c.wh_km <= 0;
      h += `<td class="${thin ? 'mx-thin' : ''}" style="background:${thin ? 'transparent' : whColor(c.wh_km, min, max)}">`
         + `<b>${c.wh_km}</b><small>${fmt(c.km, 1)} km</small></td>`;
    });
    h += '</tr>';
  });
  box.innerHTML = h + '</tbody></table>';
}

// Reichweitenrechner: eigener Messwert für Temperatur × Profil, sonst Schätzung aus Kurven bzw. Werksverbrauch
function calcWhKm(temp, speedBin) {
  const r = statRechner; if (!r) return null;
  const m = r.matrix.filter(c => c.wh_km > 80);
  const tb = Math.floor(temp / 5) * 5;
  const exact = m.find(c => c.temp_bin === tb && c.speed_bin === speedBin && c.km >= 10);
  if (exact) return {wh: exact.wh_km, quelle: `gemessen aus ${fmt(exact.km, 1)} km bei diesen Bedingungen`};

  // Ø je Geschwindigkeitsklasse (über alle Temperaturen)
  const speedAvg = {};
  Object.keys(SPEED_LABELS).map(Number).forEach(sb => {
    const rows = m.filter(c => c.speed_bin === sb);
    const km = rows.reduce((a, c) => a + c.km, 0);
    if (km >= 3) speedAvg[sb] = rows.reduce((a, c) => a + c.wh_km * c.km, 0) / km;
  });
  // Temperatureinfluss bereinigt um das Fahrprofil: jede Zelle gegen den Schnitt ihrer Geschwindigkeitsklasse
  const tRows = m.filter(c => c.temp_bin === tb && speedAvg[c.speed_bin]);
  const tKm   = tRows.reduce((a, c) => a + c.km, 0);
  const tp    = tKm >= 15 ? tRows.reduce((a, c) => a + c.km * c.wh_km, 0) / tRows.reduce((a, c) => a + c.km * speedAvg[c.speed_bin], 0) : null;
  const base  = r.basis.wh_km_gesamt || r.basis.werk_wh_km;
  const sp    = speedAvg[speedBin] || null;
  const fSpeed = sp ? sp / base : SPEED_FALLBACK[speedBin];
  // unter 15 °C ca. +1,5 %/K (Heizung, Akku), wenn noch keine eigenen Winterdaten vorliegen
  const fTemp  = tp ?? (1 + Math.max(0, 15 - temp) * 0.015);
  const teile = [sp ? 'Profil gemessen' : 'Profil geschätzt', tp ? 'Temperatur gemessen' : 'Temperatur geschätzt'];
  return {wh: Math.round(base * fSpeed * fTemp), quelle: 'kombiniert: ' + teile.join(', ')};
}

function updateCalc() {
  const r = statRechner; if (!r) return;
  const temp = +el('calc-temp').value, speed = +el('calc-speed').value;
  let von = +el('calc-von').value, bis = +el('calc-bis').value;
  if (bis >= von) { bis = Math.max(0, von - 1); el('calc-bis').value = bis; }
  el('calc-temp-val').textContent = temp + ' °C';
  el('calc-von-val').textContent = von + ' %';
  el('calc-bis-val').textContent = bis + ' %';
  const kap = r.basis.kapazitaet_kwh || (r.basis.reichweite_100_km && r.basis.werk_wh_km
              ? r.basis.reichweite_100_km * r.basis.werk_wh_km / 1000 : null);
  const w = calcWhKm(temp, speed);
  if (!kap || !w) { el('calc-km').innerHTML = '--<span class="stat-unit">km</span>'; el('calc-detail').textContent = 'Noch zu wenig Daten.'; return; }
  const km = kap * (von - bis) / 100 / (w.wh / 1000);
  el('calc-km').innerHTML = `≈ ${Math.round(km)}<span class="stat-unit">km</span>`;
  el('calc-detail').textContent = `${w.wh} Wh/km · ${w.quelle}`;
  el('calc-basis').textContent = `Basis: ${fmt(kap, 1)} kWh nutzbar` + (r.basis.kapazitaet_kwh ? ' (aus deinen Ladungen)' : ' (geschätzt)');
}

function renderRekorde(rk) {
  const box = el('stat-rekorde');
  const card = (titel, r, wert, sub) => r ? `<div class="stat-card rekord-card">
      <div class="stat-label">${titel}</div><div class="stat-value" style="font-size:1.4rem">${wert}</div>
      <div class="stat-sub">${esc(fmtDateShort(r.start_date))}${r.von ? ' · ' + esc(r.von) + (r.nach && r.nach !== r.von ? ' → ' + esc(r.nach) : '') : ''}</div>
      ${sub ? `<div class="stat-sub">${sub}</div>` : ''}</div>` : '';
  const h = [
    card('Längste Fahrt', rk.laengste, `${fmt(rk.laengste?.km, 1)} km`, rk.laengste ? `${rk.laengste.wh_km ?? '--'} Wh/km · ${fmtDuration(rk.laengste.dauer_min)}` : ''),
    card('Sparsamste Fahrt (≥ 5 km)', rk.sparsamste, `${rk.sparsamste?.wh_km} Wh/km`, rk.sparsamste ? `${fmt(rk.sparsamste.km, 1)} km · ${fmt(rk.sparsamste.temp, 0)} °C` : ''),
    card('Durstigste Fahrt (≥ 5 km)', rk.durstigste, `${rk.durstigste?.wh_km} Wh/km`, rk.durstigste ? `${fmt(rk.durstigste.km, 1)} km · Ø ${rk.durstigste.v} km/h` : ''),
    card('Schnellster Schnitt (≥ 5 km)', rk.schnellste, `${rk.schnellste?.v} km/h`, rk.schnellste ? `${fmt(rk.schnellste.km, 1)} km · ${rk.schnellste.wh_km} Wh/km` : ''),
    card('Kälteste Fahrt', rk.kaelteste, `${fmt(rk.kaelteste?.temp, 1)} °C`, rk.kaelteste ? `${fmt(rk.kaelteste.km, 1)} km · ${rk.kaelteste.wh_km ?? '--'} Wh/km` : ''),
    card('Höchster Punkt', rk.hoechste, `${rk.hoechste?.hoehe} m`, ''),
  ].join('');
  box.innerHTML = h || '<div class="loading-msg">Noch keine Fahrten im Zeitraum.</div>';
}

function loadStatistik(still) {
  if (still !== true) el('stat-info').textContent = 'Lade…';
  apiGet('/api/statistik.php?range=' + encodeURIComponent(currentStatRange)).then(d => {
    if (!d.ok) { el('stat-info').textContent = d.error || 'Fehler beim Laden'; return; }
    el('stat-info').textContent = d.neu > 0 ? `${d.neu} neue Fahrt(en) ausgewertet` : '';

    // Kennzahlen
    const s = d.summe || {};
    const werk = d.rechner.basis.werk_wh_km;
    el('st-whkm').innerHTML = (s.wh_km ?? '--') + '<span class="stat-unit">Wh/km</span>';
    el('st-whkm-sub').textContent = werk ? `Werksangabe: ${werk} Wh/km` + (s.wh_km ? ` (${s.wh_km > werk ? '+' : ''}${Math.round((s.wh_km / werk - 1) * 100)} %)` : '') : '';
    el('st-km').innerHTML = fmt(s.km, 0) + '<span class="stat-unit">km</span>';
    el('st-km-sub').textContent = `${s.fahrten ?? 0} Fahrten · ${fmt(s.stunden, 1)} h · ${s.hoehenmeter ?? 0} Hm bergauf`;
    el('st-kwh').innerHTML = fmt(s.kwh, 1) + '<span class="stat-unit">kWh</span>';
    el('st-kwh-sub').textContent = (s.kwh ? `≈ ${eurFahrt(s.kwh)} Strom · ` : '') + (s.kwh_rekup ? `Rekuperiert: ${fmt(s.kwh_rekup, 1)} kWh (${Math.round(s.kwh_rekup / (s.kwh + s.kwh_rekup) * 100)} % der Antriebsenergie)` : 'Rekuperiert: --');
    const st = d.stand;
    el('st-stand').innerHTML = (st && st.km_pro_tag !== null ? fmt(st.km_pro_tag, 1) : '--') + '<span class="stat-unit">km/Tag</span>';
    el('st-stand-sub').textContent = st && st.anzahl ? `≈ ${fmt(st.kwh_pro_tag, 2)} kWh/Tag · ${st.anzahl} Parkphasen, ${st.stunden} h` : 'Noch keine Parkphasen ohne Laden';

    // Rechner
    statRechner = d.rechner;
    if (d.rechner.basis.soc_aktuell && !el('calc-von').dataset.touched) el('calc-von').value = Math.max(10, d.rechner.basis.soc_aktuell);
    updateCalc();

    renderMatrix(d.matrix);

    // Temperatur-Kurve. „mit/ohne Klima“ nur, wenn es für BEIDE genug Daten gibt (beim Tesla läuft die
    // Klimaanlage im Automatikbetrieb fast immer; Gesamt liegt rechnerisch immer zwischen den beiden Linien)
    const klimaVergleich = d.temp.some(t => t.wh_km_klima !== null) && d.temp.some(t => t.wh_km_ohne !== null);
    // Nur Bereiche mit genug Strecke bilden die Kurve; dünne Werte (z. B. zwei Kurzstrecken) als graue Punkte
    const TEMP_MIN_KM = 15;
    const tempReihen = [
      {label: 'Verbrauch', data: d.temp.map(t => t.km >= TEMP_MIN_KM ? t.wh_km : null), borderColor: '#e31937',
       backgroundColor: 'rgba(227,25,55,.15)', fill: true, tension: .3, spanGaps: true},
      {label: `wenig Daten (< ${TEMP_MIN_KM} km)`, data: d.temp.map(t => t.km < TEMP_MIN_KM ? t.wh_km : null), showLine: false,
       pointRadius: 5, pointStyle: 'circle', borderColor: '#8888aa', backgroundColor: 'transparent', borderWidth: 2},
    ];
    if (klimaVergleich) tempReihen.push(
      {label: 'mit Klima/Heizung', data: d.temp.map(t => t.wh_km_klima), borderColor: '#ff8800', borderDash: [4,4], tension: .3, spanGaps: true},
      {label: 'ohne Klima', data: d.temp.map(t => t.wh_km_ohne), borderColor: '#5b7fc9', borderDash: [2,3], tension: .3, spanGaps: true});
    el('st-temp-hinweis').textContent = klimaVergleich ? '' : 'Klima war bei allen Fahrten an – kein Vergleich mit/ohne möglich';
    makeChart('chart-st-temp', {
      type: 'line',
      data: {
        labels: d.temp.map(t => tempLabel(t.temp_bin)),
        datasets: tempReihen
      },
      options: {maintainAspectRatio: false, plugins: {tooltip: {callbacks: {afterLabel: c => c.datasetIndex <= 1 ? `${d.temp[c.dataIndex].km} km Datenbasis` : ''}}},
                scales: {y: {title: {display: true, text: 'Wh/km'}}}}
    });

    // Geschwindigkeits-Kurve
    makeChart('chart-st-speed', {
      type: 'bar',
      data: {
        labels: d.speed.map(x => SPEED_LABELS[x.speed_bin] + ' km/h'),
        datasets: [{label: 'Wh/km', data: d.speed.map(x => x.wh_km > 0 ? x.wh_km : null),
                    backgroundColor: d.speed.map(x => x.km >= 1 ? 'rgba(61,90,153,.8)' : 'rgba(61,90,153,.3)'), borderRadius: 4}]
      },
      options: {maintainAspectRatio: false, plugins: {legend: {display: false},
                tooltip: {callbacks: {afterLabel: c => `${d.speed[c.dataIndex].km} km Datenbasis`}}},
                scales: {y: {title: {display: true, text: 'Wh/km'}}}}
    });

    // Monate
    // Alle Monate zwischen erstem und letztem zeigen (Monate ohne Daten als Lücke, nicht übersprungen)
    const mListe = [];
    if (d.monate.length) {
      let [jj, mm] = d.monate[0].monat.split('-').map(Number);
      const ende = d.monate[d.monate.length - 1].monat;
      for (let i = 0; i < 240; i++) {
        const k = `${jj}-${String(mm).padStart(2, '0')}`; mListe.push(k);
        if (k >= ende) break;
        if (++mm > 12) { mm = 1; jj++; }
      }
    }
    const mWert = (k, f) => { const x = d.monate.find(m => m.monat === k); return x ? x[f] : null; };
    const mText = k => { const [jj, mm] = k.split('-'); return MONATE_DE[+mm - 1].slice(0, 3) + ' ' + jj.slice(2); };
    makeChart('chart-st-monat', {
      data: {
        labels: mListe.map(mText),
        datasets: [
          {type: 'bar',  label: 'km', data: mListe.map(k => mWert(k, 'km')), backgroundColor: 'rgba(61,90,153,.7)', yAxisID: 'y', stack: 'km', borderRadius: 4},
          // Wh/km-Achse ab 0: sonst zoomt sie auf wenige Wh/km und kleine Unterschiede wirken riesig
          {type: 'line', label: 'Wh/km', data: mListe.map(k => mWert(k, 'wh_km')), borderColor: '#ffb300', backgroundColor: '#ffb300', yAxisID: 'y1', tension: 0, pointRadius: 4, spanGaps: false},
          // Geladen als gestapelter Balken neben den km: daheim grün, anderswo (Ladesäule, Supercharger) rot
          {type: 'bar', label: 'geladen daheim kWh', data: mListe.map(k => mWert(k, 'daheim_kwh') ?? mWert(k, 'geladen_kwh')), backgroundColor: 'rgba(0,200,81,.7)', yAxisID: 'y', stack: 'laden', borderRadius: 4},
          {type: 'bar', label: 'geladen anderswo kWh', data: mListe.map(k => mWert(k, 'anderswo_kwh')), backgroundColor: 'rgba(227,25,55,.75)', yAxisID: 'y', stack: 'laden', borderRadius: 4},
        ]
      },
      options: {maintainAspectRatio: false, plugins: {tooltip: {mode: 'index', intersect: false}},
                scales: {x: {stacked: true}, y: {stacked: true, beginAtZero: true, title: {display: true, text: 'km / kWh'}},
                y1: {position: 'right', beginAtZero: true, suggestedMax: 300, grid: {drawOnChartArea: false}, title: {display: true, text: 'Wh/km'}}}}
    });

    // Höhenmeter
    makeChart('chart-st-hoehe', {
      type: 'scatter',
      data: {datasets: [{label: 'Fahrt', data: d.fahrten.filter(f => f.km >= 2).map(f => ({x: f.hm_pro_km, y: f.wh_km, f})),
                         backgroundColor: 'rgba(227,25,55,.7)', pointRadius: 5}]},
      options: {maintainAspectRatio: false, plugins: {legend: {display: false},
                tooltip: {callbacks: {label: c => { const f = c.raw.f; return `${fmtDateShort(f.start_date)}: ${f.km} km, ${f.wh_km} Wh/km, ↑${f.ascent} ↓${f.descent} m`; }}}},
                scales: {x: {title: {display: true, text: 'Höhenmeter netto pro km (+ = bergauf)'}}, y: {title: {display: true, text: 'Wh/km'}}}}
    });

    // Ladeverluste
    const lv = d.ladungen.filter(l => l.effizienz_pct && l.p_avg);
    makeChart('chart-st-verlust', {
      type: 'scatter',
      data: {datasets: [
        {label: 'AC', data: lv.filter(l => !l.dc).map(l => ({x: l.p_avg, y: l.effizienz_pct, l})), backgroundColor: 'rgba(0,200,81,.8)', pointRadius: 6},
        {label: 'DC', data: lv.filter(l => l.dc).map(l => ({x: l.p_avg, y: l.effizienz_pct, l})), backgroundColor: 'rgba(255,136,0,.8)', pointRadius: 6},
      ]},
      options: {maintainAspectRatio: false,
                plugins: {tooltip: {callbacks: {label: c => { const l = c.raw.l; return `${fmtDateShort(l.start_date)}: ${l.kwh} kWh, Ø ${l.p_avg} kW, ${l.ampere ?? '?'} A, ${l.phasen ?? '?'}-phasig, ${l.effizienz_pct} %` + (l.temp !== null ? `, ${l.temp} °C` : ''); }}}},
                scales: {x: {title: {display: true, text: 'Ø Ladeleistung (kW)'}}, y: {title: {display: true, text: 'im Akku angekommen (%)'}, suggestedMax: 100}}}
    });

    // Akku
    const ak = d.ladungen.filter(l => l.km_stand && (l.kapazitaet_kwh || l.reichweite_100_km));
    makeChart('chart-st-akku', {
      type: 'scatter',
      data: {datasets: [
        {label: 'Kapazität (kWh)', data: ak.filter(l => l.kapazitaet_kwh).map(l => ({x: l.km_stand, y: l.kapazitaet_kwh})), backgroundColor: '#00c851', yAxisID: 'y', pointRadius: 5},
        {label: 'Reichweite bei 100 % (km)', data: ak.filter(l => l.reichweite_100_km).map(l => ({x: l.km_stand, y: l.reichweite_100_km})), backgroundColor: '#5b7fc9', yAxisID: 'y1', pointRadius: 5},
      ]},
      options: {maintainAspectRatio: false,
                scales: {x: {title: {display: true, text: 'Kilometerstand'}, ticks: {callback: v => (v / 1000).toFixed(1) + 'k'}},
                         y: {title: {display: true, text: 'kWh'}}, y1: {position: 'right', grid: {drawOnChartArea: false}, title: {display: true, text: 'km'}}}}
    });

    // Standverlust
    const sl = d.stand_liste.slice().reverse();
    makeChart('chart-st-stand', {
      type: 'bar',
      data: {labels: sl.map(x => fmtDateShort(x.von)),
             datasets: [{label: 'km Reichweite pro Tag', data: sl.map(x => x.km_pro_tag), backgroundColor: 'rgba(255,179,0,.75)', borderRadius: 4}]},
      options: {maintainAspectRatio: false, plugins: {legend: {display: false},
                tooltip: {callbacks: {afterLabel: c => `${sl[c.dataIndex].stunden} h geparkt, ${sl[c.dataIndex].verlust_km} km verloren`}}},
                scales: {y: {title: {display: true, text: 'km/Tag'}}}}
    });

    // Reifen
    const soll = <?= json_encode(TPMS_NOMINAL_BAR) ?>;
    const rf = d.reifen;
    makeChart('chart-st-reifen', {
      data: {
        labels: rf.map(x => x.datum),
        datasets: [
          {type: 'line', label: 'VL', data: rf.map(x => x.fl), borderColor: '#e31937', tension: .3, yAxisID: 'y'},
          {type: 'line', label: 'VR', data: rf.map(x => x.fr), borderColor: '#ff8800', tension: .3, yAxisID: 'y'},
          {type: 'line', label: 'HL', data: rf.map(x => x.rl), borderColor: '#5b7fc9', tension: .3, yAxisID: 'y'},
          {type: 'line', label: 'HR', data: rf.map(x => x.rr), borderColor: '#00c851', tension: .3, yAxisID: 'y'},
          {type: 'line', label: 'Soll', data: rf.map(() => soll), borderColor: 'rgba(255,255,255,.35)', borderDash: [6,4], pointRadius: 0, yAxisID: 'y'},
          {type: 'bar',  label: '°C', data: rf.map(x => x.temp), backgroundColor: 'rgba(136,136,170,.25)', yAxisID: 'y1'},
        ]
      },
      options: {maintainAspectRatio: false, scales: {y: {title: {display: true, text: 'bar'}},
                y1: {position: 'right', grid: {drawOnChartArea: false}, title: {display: true, text: '°C'}}}}
    });

    renderRekorde(d.rekorde || {});
  }).catch(() => { el('stat-info').textContent = 'Statistik konnte nicht geladen werden'; });
}

['calc-temp', 'calc-speed', 'calc-von', 'calc-bis'].forEach(id => {
  el(id).addEventListener('input', function() { this.dataset.touched = '1'; updateCalc(); });
});

document.querySelectorAll('#stat-range-filter .range-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#stat-range-filter .range-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentStatRange = this.dataset.range;
    loadStatistik();
  });
});

// ═══════════════════════════════════════════════════════
// PENDELN (Fahrten zwischen festen Orten, je Richtung)
// ═══════════════════════════════════════════════════════

let currentPendelRange = 'all';
const PD_FARBEN = ['#5b7fc9', '#ff8800', '#00c851', '#e31937'];

function pdFarbe(richtungen, r) {
  const i = richtungen.indexOf(r);
  return PD_FARBEN[(i < 0 ? 0 : i) % PD_FARBEN.length];
}

function pdKarte(r, farbe) {
  return `<div class="card pd-karte" style="border-top:3px solid ${farbe}">
    <div class="card-header"><span class="card-title" style="color:${farbe}">${esc(r.richtung)}</span>
      <span class="stat-hint">${r.n} Fahrt${r.n === 1 ? '' : 'en'}${r.letzte ? ' · zuletzt ' + esc(fmtDateShort(r.letzte)) : ''}</span></div>
    <div class="grid-4 pd-werte">
      <div><div class="stat-label">Ø Verbrauch</div><div class="stat-value">${r.wh_km ?? '--'}<span class="stat-unit">Wh/km</span></div>
        <div class="stat-sub">${r.wh_km_min ?? '--'} – ${r.wh_km_max ?? '--'} Wh/km</div></div>
      <div><div class="stat-label">Ø Dauer</div><div class="stat-value">${fmtDuration(r.min)}</div>
        <div class="stat-sub">${fmtDuration(r.min_min)} – ${fmtDuration(r.min_max)}</div></div>
      <div><div class="stat-label">Ø Akku</div><div class="stat-value">${r.soc_verbrauch !== null ? '−' + fmt(r.soc_verbrauch, 1) : '--'}<span class="stat-unit">%</span></div>
        <div class="stat-sub">${fmt(r.km, 1)} km · Ø ${r.v ?? '--'} km/h · ${r.temp !== null ? fmt(r.temp, 1) + ' °C' : '--'}</div></div>
      <div><div class="stat-label">Ø Kosten je Fahrt</div><div class="stat-value">${r.kwh !== null && r.kwh !== undefined ? eurFahrt(r.kwh) : '--'}</div>
        <div class="stat-sub">${r.kwh ? fmt(r.kwh, 2) + ' kWh × ' + ctText(PREIS_FAHRT_CT) : ''}</div></div>
    </div>
  </div>`;
}

function loadPendeln(still) {
  if (still !== true) el('pendel-orte').textContent = 'Lade…';
  apiGet('/api/pendel.php?range=' + encodeURIComponent(currentPendelRange)).then(d => {
    if (!d.ok) { el('pendel-orte').textContent = d.error || 'Fehler beim Laden'; return; }
    el('pendel-orte').textContent = 'Orte: ' + d.orte.map(o => `${o.name} (${fmt(o.radius_km, 0)} km Umkreis)`).join(' · ');
    const leer = !d.fahrten.length;
    el('pendel-leer').hidden = !leer; el('pendel-inhalt').hidden = leer;
    if (leer) {
      el('pendel-leer').innerHTML = `<div class="loading-msg" style="line-height:1.6">Noch keine Pendelfahrten im Zeitraum.<br>
        Gezählt wird jede Fahrt, die im Umkreis des einen Ortes beginnt und im Umkreis des anderen endet – ohne Zwischenstopp.</div>`;
      return;
    }
    const namen = d.richtungen.map(r => r.richtung);
    el('pendel-richtungen').innerHTML = d.richtungen.map(r => pdKarte(r, pdFarbe(namen, r.richtung))).join('');

    // Verbrauch je Fahrt über die Zeit
    makeChart('chart-pd-verlauf', {
      type: 'scatter',
      data: {datasets: namen.map(n => ({
        label: n, showLine: true, tension: .25, borderColor: pdFarbe(namen, n), backgroundColor: pdFarbe(namen, n), pointRadius: 4,
        data: d.fahrten.filter(f => f.richtung === n && f.wh_km !== null).map(f => ({x: parseDate(f.start).getTime(), y: f.wh_km, f})).sort((a, b) => a.x - b.x)
      }))},
      options: {maintainAspectRatio: false,
        plugins: {tooltip: {callbacks: {label: c => { const f = c.raw.f; return `${fmtDateShort(f.start)}: ${f.wh_km} Wh/km, ${fmtDuration(f.min)}, ${f.temp ?? '--'} °C`; }}}},
        scales: {x: {ticks: {callback: v => new Date(v).toLocaleDateString('de-AT', {day: '2-digit', month: '2-digit'})}},
                 y: {title: {display: true, text: 'Wh/km'}}}}
    });

    // Dauer nach Abfahrtszeit (Stau)
    makeChart('chart-pd-abfahrt', {
      type: 'scatter',
      data: {datasets: namen.map(n => ({
        label: n, backgroundColor: pdFarbe(namen, n), pointRadius: 5,
        data: d.fahrten.filter(f => f.richtung === n).map(f => ({x: f.abfahrt_h, y: f.min, f}))
      }))},
      options: {maintainAspectRatio: false,
        plugins: {tooltip: {callbacks: {label: c => { const f = c.raw.f; return `${fmtDateShort(f.start)}: ${fmtDuration(f.min)}, ${f.km} km`; }}}},
        scales: {x: {title: {display: true, text: 'Abfahrt (Uhrzeit)'}, min: 0, max: 24, ticks: {stepSize: 2, callback: v => v + ':00'}},
                 y: {title: {display: true, text: 'Minuten'}}}}
    });

    // Verbrauch nach Temperatur
    const bins = [...new Set(d.temp.map(t => t.temp_bin))].sort((a, b) => a - b);
    makeChart('chart-pd-temp', {
      type: 'bar',
      data: {labels: bins.map(b => `${b} … ${b + 5} °C`),
             datasets: namen.map(n => ({label: n, backgroundColor: pdFarbe(namen, n), borderRadius: 4,
               data: bins.map(b => { const t = d.temp.find(x => x.richtung === n && x.temp_bin === b); return t ? t.wh_km : null; })}))},
      options: {maintainAspectRatio: false, scales: {y: {title: {display: true, text: 'Wh/km'}}}}
    });

    // Monate
    const monate = [...new Set(d.monate.map(m => m.monat))].sort();
    makeChart('chart-pd-monat', {
      data: {labels: monate, datasets: namen.flatMap(n => [
        {type: 'bar', label: n + ' · Wh/km', backgroundColor: pdFarbe(namen, n) + 'cc', borderRadius: 4, yAxisID: 'y',
         data: monate.map(m => { const x = d.monate.find(v => v.richtung === n && v.monat === m); return x ? x.wh_km : null; })},
        {type: 'line', label: n + ' · Minuten', borderColor: pdFarbe(namen, n), borderDash: [4, 4], yAxisID: 'y1', tension: .3,
         data: monate.map(m => { const x = d.monate.find(v => v.richtung === n && v.monat === m); return x ? x.min : null; })}
      ])},
      options: {maintainAspectRatio: false, scales: {y: {title: {display: true, text: 'Wh/km'}},
        y1: {position: 'right', grid: {drawOnChartArea: false}, title: {display: true, text: 'Minuten'}}}}
    });

    // Tabelle
    el('pendel-anzahl').textContent = `${d.fahrten.length} Fahrt${d.fahrten.length === 1 ? '' : 'en'}`;
    el('pendel-tabelle').innerHTML = d.fahrten.map(f => `<tr>
      <td>${esc(fmtDate(f.start))}</td>
      <td><span class="pd-punkt" style="background:${pdFarbe(namen, f.richtung)}"></span>${esc(f.richtung)}</td>
      <td>${fmtDuration(f.min)}</td><td>${fmt(f.km, 1)}</td>
      <td><b>${f.wh_km ?? '--'}</b></td><td>${f.kwh !== null ? fmt(f.kwh, 1) : '--'}</td><td>${f.kwh !== null ? eurFahrt(f.kwh) : '--'}</td>
      <td>${f.v ?? '--'} / ${f.v_max ?? '--'}</td><td>${f.temp !== null ? fmt(f.temp, 1) : '--'}</td>
      <td>${f.soc_start ?? '--'} → ${f.soc_ende ?? '--'} %</td><td>${f.klima_pct !== null ? f.klima_pct + ' %' : '--'}</td>
    </tr>`).join('');
  }).catch(() => { el('pendel-orte').textContent = 'Pendel-Auswertung konnte nicht geladen werden'; });
}

document.querySelectorAll('#pendel-range-filter .range-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#pendel-range-filter .range-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentPendelRange = this.dataset.range;
    loadPendeln();
  });
});

// ═══════════════════════════════════════════════════════
// KARTE: Fahrten und Zeiträume einzeichnen
// ═══════════════════════════════════════════════════════

let karteModus = 'position', streckenLayer = null, fahrtenListeGeladen = false;
const V_STUFEN = [[30, '#00c851', '< 30'], [50, '#7bd84a', '30–50'], [80, '#ffd000', '50–80'], [100, '#ff8800', '80–100'],
                  [130, '#ff4d4d', '100–130'], [999, '#c21bff', '130+']];
function vFarbe(v) { for (const [g, f] of V_STUFEN) if ((v ?? 0) < g) return f; return V_STUFEN[V_STUFEN.length - 1][1]; }

// Linie in Abschnitte gleicher Tempo-Farbe zerlegen und auf die Ebene zeichnen; gibt die Linien zurück
function tempoLinie(punkte, ebene, breite) {
  const linien = [];
  let stueck = [punkte[0]], farbe = vFarbe(punkte[0][2]);
  for (let i = 1; i < punkte.length; i++) {
    const f = vFarbe(punkte[i][2]);
    stueck.push(punkte[i]);
    if (f !== farbe || i === punkte.length - 1) {
      linien.push(L.polyline(stueck.map(x => [x[0], x[1]]), {color: farbe, weight: breite, opacity: .92, interactive: false}).addTo(ebene));
      stueck = [punkte[i]]; farbe = f;
    }
  }
  return linien;
}

function tempoLegende() {
  el('karte-legende').innerHTML = 'Geschwindigkeit: ' + V_STUFEN.map(([g, c, t]) => `<span><i style="background:${c}"></i>${t}</span>`).join('') + ' km/h';
  el('karte-legende').hidden = false;
}

function streckenEbene() {
  if (!streckenLayer && vehicleMap) streckenLayer = L.layerGroup().addTo(vehicleMap);
  return streckenLayer;
}

function fahrtText(f) {
  return `${esc(fmtDate(f.start))}${f.von ? ' · ' + esc(f.von) + (f.nach && f.nach !== f.von ? ' → ' + esc(f.nach) : '') : ''}`;
}
function fahrtWerte(f) {
  return [`${fmt(f.km, 1)} km`, fmtDuration(f.min), f.v ? `Ø ${f.v} km/h` : null, f.v_max ? `max ${f.v_max} km/h` : null,
          f.wh_km ? `${f.wh_km} Wh/km` : null, f.temp !== null && f.temp !== undefined ? `${fmt(f.temp, 1)} °C` : null,
          f.soc_start !== null && f.soc_start !== undefined ? `Akku ${f.soc_start} → ${f.soc_ende} %` : null].filter(Boolean).join(' · ');
}

// Kostenzeile fürs Karten-Infofenster und die Popups: kWh × Mischpreis
function kostenZeile(kwh) {
  if (!kwh) return '';
  return `<div class="karte-kosten" title="${fahrtPreisTitel(kwh)}">≈ <b>${eurFahrt(kwh)}</b> Strom <span>(${fmt(kwh, 1)} kWh × ${ctText(PREIS_FAHRT_CT)})</span></div>`;
}

function karteInfo(html) {
  el('map-address').innerHTML = html; el('map-coords').textContent = ''; el('map-updated').textContent = '';
}

function ladeFahrtenListe() {
  apiGet('/api/strecken.php?liste=1').then(d => {
    if (!d.ok) return;
    fahrtenListeGeladen = true;
    const sel = el('karte-fahrt'), gewaehlt = sel.value;
    sel.innerHTML = '<option value="">Fahrt wählen …</option>' + d.fahrten.map(f =>
      `<option value="${f.id}">${esc(fmtDateShort(f.start))} · ${fmt(f.km, 1)} km${f.von ? ' · ' + esc(f.von) + (f.nach && f.nach !== f.von ? ' → ' + esc(f.nach) : '') : ''}</option>`).join('');
    sel.value = gewaehlt;
  });
}

function zeigePosition() {
  karteModus = 'position';
  if (streckenLayer) streckenLayer.clearLayers();
  el('karte-legende').hidden = true;
  el('karte-fahrt').value = '';
  loadPosition();
  if (vehicleMarker) vehicleMap.setView(vehicleMarker.getLatLng(), 14);
}

function zeigeFahrt(id) {
  karteModus = 'fahrt';
  karteInfo('<b>Lade Fahrt…</b>');
  apiGet('/api/strecken.php?fahrt=' + encodeURIComponent(id)).then(d => {
    if (!d.ok) { karteInfo(`<b>${esc(d.error || 'Fahrt nicht verfügbar')}</b>`); return; }
    const ebene = streckenEbene(); ebene.clearLayers();
    const p = d.punkte; if (!p.length) { karteInfo('<b>Keine Positionsdaten zu dieser Fahrt</b>'); return; }
    // dunkler Rand unter der farbigen Linie, damit sie sich von den OSM-Straßenfarben abhebt
    L.polyline(p.map(x => [x[0], x[1]]), {color: '#0b1a3a', weight: 9, opacity: .55}).addTo(ebene);
    tempoLinie(p, ebene, 5);   // nach Geschwindigkeit eingefärbt
    L.circleMarker([p[0][0], p[0][1]], {radius: 7, color: '#fff', weight: 2, fillColor: '#00c851', fillOpacity: 1}).bindTooltip('Start').addTo(ebene);
    L.circleMarker([p[p.length - 1][0], p[p.length - 1][1]], {radius: 7, color: '#fff', weight: 2, fillColor: '#e31937', fillOpacity: 1}).bindTooltip('Ziel').addTo(ebene);
    vehicleMap.fitBounds(L.latLngBounds(p.map(x => [x[0], x[1]])), {padding: [40, 40]});
    const f = d.fahrt;
    karteInfo(`<b>${fahrtText(f)}</b><p>${fahrtWerte(f)}</p>${kostenZeile(f.wh_km && f.km ? f.wh_km * f.km / 1000 : null)}`);
    tempoLegende();
    el('karte-fahrt').value = String(id);
  }).catch(e => karteInfo(`<b>Fahrt konnte nicht geladen werden</b><p>${esc(e.message)}</p>`));
}

function zeigeZeitraum(range, beimOeffnen) {
  karteModus = range;
  karteInfo('<b>Lade Fahrten…</b>');
  el('karte-fahrt').value = '';
  apiGet('/api/strecken.php?range=' + encodeURIComponent(range)).then(d => {
    if (!d.ok) { karteInfo(`<b>${esc(d.error || 'Nicht verfügbar')}</b>`); return; }
    const ebene = streckenEbene(); ebene.clearLayers();
    const grenzen = [];
    d.fahrten.forEach(f => {
      const linie = d.linien[f.id]; if (!linie || linie.length < 2) return;
      linie.forEach(x => grenzen.push([x[0], x[1]]));
      const ll = linie.map(x => [x[0], x[1]]);
      let pl;
      if (el('karte-tempo').checked) {
        // Tempo-Farben: dunkler Rand, farbige Abschnitte, darüber eine unsichtbare breite Linie für Maus/Finger
        const rand = L.polyline(ll, {color: '#0b1a3a', weight: 8, opacity: .5, interactive: false}).addTo(ebene);
        tempoLinie(linie, ebene, 4);
        pl = L.polyline(ll, {color: '#000', weight: 16, opacity: 0}).addTo(ebene);
        pl.on('mouseover', () => rand.setStyle({weight: 12, opacity: .95, color: '#ffffff'}));
        pl.on('mouseout', () => rand.setStyle({weight: 8, opacity: .5, color: '#0b1a3a'}));
      } else {
        // Dunkelblau: hebt sich von den roten/orangen OSM-Straßen ab
        pl = L.polyline(ll, {color: '#1c3f9e', weight: 4, opacity: .75}).addTo(ebene);
        pl.on('mouseover', () => { pl.setStyle({weight: 7, opacity: 1, color: '#0a2375'}); pl.bringToFront(); });
        pl.on('mouseout', () => pl.setStyle({weight: 4, opacity: .75, color: '#1c3f9e'}));
      }
      pl.bindPopup(`<b>${fahrtText(f)}</b><br>${fahrtWerte(f)}${kostenZeile(f.wh_km && f.km ? f.wh_km * f.km / 1000 : null)}<a href="#" class="karte-detail" data-id="${f.id}">Fahrt im Detail anzeigen</a>`);
    });
    if (grenzen.length) vehicleMap.fitBounds(L.latLngBounds(grenzen), {padding: [40, 40]});
    const km = d.fahrten.reduce((a, f) => a + (+f.km || 0), 0);
    const namen = {'heute': 'heute', '7d': 'letzte 7 Tage', '30d': 'letzte 30 Tage', '90d': 'letzte 90 Tage', '1y': 'letztes Jahr', 'all': 'alle Fahrten'};
    if (!d.fahrten.length && range === 'heute' && beimOeffnen) {   // heute noch nicht gefahren → aktuelle Position
      document.querySelectorAll('#karte-modus .range-btn').forEach(b => b.classList.toggle('active', b.dataset.modus === 'position'));
      zeigePosition();
      return;
    }
    const kwhSumme = d.fahrten.reduce((a, f) => a + (f.wh_km && f.km ? f.wh_km * f.km / 1000 : 0), 0);
    karteInfo(d.fahrten.length ? `<b>${d.fahrten.length} Fahrt${d.fahrten.length === 1 ? '' : 'en'} · ${fmt(km, 0)} km</b><p>${namen[range]} · Linie anklicken für Details</p>${kostenZeile(kwhSumme || null)}`
                               : `<b>Keine Fahrten</b><p>${namen[range]}</p>`);
    if (d.fahrten.length && el('karte-tempo').checked) tempoLegende(); else el('karte-legende').hidden = true;
  }).catch(e => karteInfo(`<b>Fahrten konnten nicht geladen werden</b><p>${esc(e.message)}</p>`));
}

document.querySelectorAll('#karte-modus .range-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#karte-modus .range-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    if (this.dataset.modus === 'position') zeigePosition(); else zeigeZeitraum(this.dataset.modus);
  });
});
el('karte-fahrt').addEventListener('change', function() {
  if (!this.value) return;
  document.querySelectorAll('#karte-modus .range-btn').forEach(b => b.classList.remove('active'));
  zeigeFahrt(this.value);
});
el('karte-fahrt').addEventListener('focus', () => { if (!fahrtenListeGeladen) ladeFahrtenListe(); });
el('karte-tempo').addEventListener('change', () => {
  if (karteModus !== 'position' && karteModus !== 'fahrt') zeigeZeitraum(karteModus);   // Einzelfahrt ist immer nach Tempo gefärbt
});
document.addEventListener('click', e => {
  const a = e.target.closest && e.target.closest('.karte-detail');
  if (a) { e.preventDefault(); vehicleMap.closePopup(); zeigeFahrt(a.dataset.id); }
});

// ═══════════════════════════════════════════════════════
// AUFFÄLLIGKEITEN (letzte 48 h)
// ═══════════════════════════════════════════════════════

const AF_NAMEN = {standverlust: 'Standverlust', reifen: 'Reifendruck', verbrauch: 'Verbrauch', laden: 'Laden',
                  schlaf: 'Schlaf', wallbox: 'Wallbox-Anschluss', akku: 'Akku'};
const AF_OHNE = {verbrauch: 'keine Fahrten', wallbox: 'nur beim Laden'};
let afZuletzt = 0;

function loadAuffaelligkeiten(erzwingen) {
  if (erzwingen !== true && Date.now() - afZuletzt < 5 * 60 * 1000) return;   // höchstens alle 5 min
  afZuletzt = Date.now();
  apiGet('/api/auffaelligkeiten.php').then(d => {
    if (!d.ok) { el('af-liste').innerHTML = `<div class="loading-msg">${esc(d.error || 'Prüfung fehlgeschlagen')}</div>`; return; }
    const geprueft = Object.entries(d.geprueft || {}).map(([k, v]) =>
      `<span class="af-chip af-${v}">${esc(AF_NAMEN[k] || k)}${v === 'ok' ? ' ✓' : v === 'keine_daten' ? ` <small>(${AF_OHNE[k] || 'keine Daten'})</small>` : v === 'info' ? ' ℹ' : ' !'}</span>`).join('');
    el('af-liste').innerHTML = (d.hinweise.length
      ? d.hinweise.map(h => `<div class="af-item af-${h.stufe}"><b>${h.stufe === 'info' ? 'ℹ' : '⚠'} ${esc(h.titel)}</b><span>${esc(h.text)}</span></div>`).join('')
      : '<div class="af-alles-ok">✓ Keine Auffälligkeiten in den letzten 48 Stunden</div>')
      + `<div class="af-geprueft">Geprüft: ${geprueft}</div>`;
    el('af-stand').textContent = 'Stand ' + new Date().toLocaleTimeString('de-AT', {hour: '2-digit', minute: '2-digit'});
  }).catch(() => { el('af-liste').innerHTML = '<div class="loading-msg">Prüfung nicht verfügbar</div>'; });
}

// ═══════════════════════════════════════════════════════
// ZEITLEISTE
// ═══════════════════════════════════════════════════════

const ZL_ARTEN = {fahren: 'Gefahren', laden: 'Geladen', wach: 'Wach', schlaeft: 'Geschlafen', offline: 'Offline', unbekannt: 'Keine Daten'};
let currentZlRange = '24h', zlDaten = null, zlZuletzt = 0;

function zlUhr(t, mitTag) {
  const d = new Date(t * 1000);
  return (mitTag ? d.toLocaleDateString('de-AT', {weekday: 'short', day: '2-digit', month: '2-digit'}) + ' ' : '')
       + d.toLocaleTimeString('de-AT', {hour: '2-digit', minute: '2-digit'});
}

function zlDauer(sek) {
  const min = Math.round(sek / 60);
  if (min < 60) return min + ' min';
  const h = Math.floor(min / 60), m = min % 60;
  return h >= 24 ? `${Math.floor(h / 24)} d ${h % 24} h` : `${h} h ${String(m).padStart(2, '0')} min`;
}

function zlZeige(i) {
  const s = zlDaten.abschnitte[i]; if (!s) return;
  const mehrtaegig = zlDaten.range !== '24h';
  el('zl-info').innerHTML = `<span class="zl-punkt zl-${s.art}"></span><b>${ZL_ARTEN[s.art] || s.art}</b> · ${zlUhr(s.von, mehrtaegig)} – ${zlUhr(s.bis, mehrtaegig && new Date(s.von * 1000).getDate() !== new Date(s.bis * 1000).getDate())}`
    + ` · ${zlDauer(s.bis - s.von)}${s.detail ? ' · ' + esc(s.detail) : ''}`;
  document.querySelectorAll('.zl-seg.aktiv').forEach(x => x.classList.remove('aktiv'));
  const seg = document.querySelector(`.zl-seg[data-i="${i}"]`); if (seg) seg.classList.add('aktiv');
}

function renderZeitleiste(d) {
  zlDaten = d;
  const span = d.bis - d.von;
  el('zl-balken').innerHTML = d.abschnitte.map((s, i) =>
    `<div class="zl-seg zl-${s.art}" data-i="${i}" style="left:${(s.von - d.von) / span * 100}%;width:${(s.bis - s.von) / span * 100}%"></div>`).join('');
  // Achse: 24 h alle 3 h, 48 h alle 6 h, 7 Tage je Mitternacht
  const schritt = d.range === '24h' ? 3 : d.range === '48h' ? 6 : 24;
  const start = new Date(d.von * 1000); start.setMinutes(0, 0, 0);
  if (schritt === 24) start.setHours(24, 0, 0, 0); else start.setHours(Math.ceil(start.getHours() / schritt) * schritt);
  let ticks = '';
  for (let t = start.getTime() / 1000; t < d.bis; t += schritt * 3600) {
    const x = (t - d.von) / span * 100; if (x < 2 || x > 91) continue;   // Platz für „jetzt“ lassen
    const dt = new Date(t * 1000);
    const label = schritt === 24 ? dt.toLocaleDateString('de-AT', {weekday: 'short', day: '2-digit'})
                : (dt.getHours() === 0 ? dt.toLocaleDateString('de-AT', {weekday: 'short'}) : dt.getHours() + ':00');
    ticks += `<span style="left:${x}%">${label}</span>`;
  }
  el('zl-achse').innerHTML = ticks + '<span class="zl-jetzt" style="left:100%">jetzt</span>';
  const reihenfolge = ['fahren', 'laden', 'wach', 'schlaeft', 'offline', 'unbekannt'];
  el('zl-legende').innerHTML = reihenfolge.filter(a => d.stunden[a]).map(a =>
    `<span><span class="zl-punkt zl-${a}"></span>${ZL_ARTEN[a]} <b>${zlDauer(d.stunden[a] * 3600)}</b></span>`).join('');
}

function loadZeitleiste(erzwingen) {
  if (erzwingen !== true && Date.now() - zlZuletzt < 60 * 1000) return;   // höchstens jede Minute
  zlZuletzt = Date.now();
  apiGet('/api/zeitleiste.php?range=' + encodeURIComponent(currentZlRange)).then(d => {
    if (!d.ok) { el('zl-info').textContent = d.error || 'Zeitleiste nicht verfügbar'; return; }
    renderZeitleiste(d);
  }).catch(() => { el('zl-info').textContent = 'Zeitleiste nicht verfügbar'; });
}

el('zl-balken').addEventListener('mousemove', e => { const i = e.target.dataset && e.target.dataset.i; if (i !== undefined) zlZeige(+i); });
el('zl-balken').addEventListener('click', e => { const i = e.target.dataset && e.target.dataset.i; if (i !== undefined) zlZeige(+i); });
document.querySelectorAll('#zl-range-filter .range-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#zl-range-filter .range-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentZlRange = this.dataset.range;
    el('zl-info').textContent = 'Für Details über den Balken fahren oder tippen.';
    loadZeitleiste(true);
  });
});

// ═══════════════════════════════════════════════════════
// FAHRZEUGZUSTAND (nur lesen)
// ═══════════════════════════════════════════════════════

const ZS_STATUS = {asleep: 'schläft', online: 'online', driving: 'fährt', charging: 'lädt', offline: 'offline',
                   suspended: 'ruht', updating: 'Software-Update', start: 'startet'};

function zsItem(label, wert, stufe) {
  return `<div class="zs-item zs-${stufe}"><div class="zs-label">${esc(label)}</div><div class="zs-wert">${wert}</div></div>`;
}

let zsLetzter = null;    // letzte Antwort von api/zustand.php (auch für die Wallbox-Karte)

// Batterie-Kachel: rot unter 40 %, orange unter 50 % (Wunsch 26.09.2026).
// Während der Ladung pulsiert die Kachel in dieser Farbe (ab 50 % grün), siehe tesla.css.
const AKKU_ROT = 40, AKKU_ORANGE = 50;
function akkuKachelFarbe(soc) {
  const karte = el('ov-akku-karte'); if (!karte || soc === null || soc === undefined) return;
  const z = zsLetzter || {};
  const laedt = !!z.angesteckt && (z.ladezustand === 'Charging' || z.ladezustand === 'Starting');
  const wert = z.akku !== null && z.akku !== undefined ? z.akku : soc;   // Live-Wert (MQTT) ist aktueller als die DB
  karte.classList.toggle('karte-warn', wert < AKKU_ROT);
  karte.classList.toggle('karte-hinweis', wert >= AKKU_ROT && wert < AKKU_ORANGE);
}
let zsAkku = null;       // Akkustand aus MQTT, solange angesteckt (siehe loadPosition)
let zsReichweite = null; // ebenso die Reichweite

const LADE_STATUS = {Charging: 'lädt', Starting: 'startet', Complete: 'abgeschlossen', Stopped: 'gestoppt',
                     NoPower: 'kein Strom', Disconnected: 'nicht angesteckt'};

// Voraussichtliches Ende als Uhrzeit (aus Stunden bis voll)
function fertigUm(h) {
  return h > 0 ? new Date(Date.now() + h * 3600000).toLocaleTimeString('de-AT', {hour: '2-digit', minute: '2-digit'}) : null;
}

function fmtStunden(h) {
  if (h === null || h === undefined || h <= 0) return null;
  const min = Math.round(h * 60);
  return min >= 60 ? `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')} min` : `${min} min`;
}

// Batterie-Kachel: pulsierend grün beim Laden, ruhig grün wenn fertig
function renderLadeKachel(z) {
  const karte = el('ov-akku-karte'); if (!karte) return;
  const lz = z.ladezustand, l = z.laden || {};
  const laedt  = z.angesteckt && (lz === 'Charging' || lz === 'Starting');
  const fertig = z.angesteckt && lz === 'Complete';
  karte.classList.toggle('laedt', laedt);
  karte.classList.toggle('lade-fertig', fertig);
  karte.classList.toggle('angesteckt', !!z.angesteckt && !laedt && !fertig);

  const badge = el('ov-lade-badge');
  badge.hidden = !z.angesteckt;
  badge.textContent = laedt ? '⚡ lädt' : fertig ? '✓ voll' : '🔌 ' + (LADE_STATUS[lz] || 'angesteckt');

  const info = el('ov-laden');
  if (laedt) {
    const rest = fmtStunden(l.stunden_bis_voll), um = fertigUm(l.stunden_bis_voll);
    info.innerHTML = `<b>${fmt(l.kw, 0)} kW</b>` + (l.ampere ? ` · ${l.ampere} A` + (l.phasen ? ` × ${l.phasen}` : '') : '')
      + `<br>${rest ? 'voll in ' + rest + (um ? ` · fertig ca. <b class="lade-um">${um}</b>` : '') + '<br>' : ''}+${fmt(l.kwh, 1)} kWh · Ziel ${z.ladelimit ?? '--'} %`;
  } else if (fertig) {
    info.innerHTML = `Ladung abgeschlossen · +${fmt(l.kwh, 1)} kWh · Limit ${z.ladelimit ?? '--'} %`;
  } else if (z.angesteckt) {
    info.innerHTML = `Angesteckt · ${esc(LADE_STATUS[lz] || lz || '--')} · Limit ${z.ladelimit ?? '--'} %`;
  }
  info.hidden = !z.angesteckt;

  // Favicon und Tab-Titel zeigen das Laden auch im Hintergrund-Tab
  const fav = document.getElementById('favicon');
  const favSoll = laedt ? '/assets/icons/favicon-laden.svg?v=2' : '/assets/icons/favicon.svg?v=2';
  if (fav && !fav.href.endsWith(favSoll)) fav.href = favSoll;
  document.title = laedt && z.akku !== null && z.akku !== undefined ? `⚡ ${z.akku} % · Tesla Dashboard` : 'Tesla Dashboard';

  const lim = el('ov-soc-limit');
  lim.hidden = !(z.angesteckt && z.ladelimit);
  if (z.ladelimit) lim.style.left = z.ladelimit + '%';

  if (z.akku !== null && z.akku !== undefined) { zsLetzter = z; akkuKachelFarbe(zsAkku !== null ? zsAkku : z.akku); }

  // Akkustand beim Laden live aus MQTT
  zsAkku = z.akku !== null && z.akku !== undefined ? z.akku : null;   // Live-Wert (MQTT) für Zahl und Farbe
  if (zsAkku !== null) {
    el('ov-soc').innerHTML = zsAkku + '<span class="stat-unit">%</span>';
    el('ov-soc').className = 'stat-value ' + socClass(zsAkku);
    el('ov-soc-bar').style.width = zsAkku + '%';
    el('ov-soc-pct').textContent = zsAkku + ' %';
    el('header-soc').textContent = zsAkku + ' %';
  }
  zsReichweite = zsAkku !== null && l.reichweite_km ? l.reichweite_km : null;
  if (zsReichweite !== null) {
    el('ov-range').textContent = 'Reichweite: ' + fmt(zsReichweite, 0) + ' km';
    el('header-range').textContent = fmt(zsReichweite, 0) + ' km';
  }
}

function loadZustand() {
  if (!HAT_ZUSTAND) return;
  apiGet('/api/zustand.php').then(z => {
    if (!z.ok) { el('zs-grid').innerHTML = `<div class="loading-msg">${esc(z.error || 'Zustand nicht verfügbar')}</div>`; return; }
    zsLetzter = z;
    renderLadeKachel(z);
    const offen = o => Object.keys(o).filter(k => o[k]);
    const tOffen = offen(z.tueren), fOffen = offen(z.fenster);
    const ja = (b, t, f) => b ? t : f;
    el('zs-grid').innerHTML = [
      zsItem('Verriegelt', ja(z.verriegelt, '🔒 ja', '🔓 nein'), ja(z.verriegelt, 'ok', 'warn')),
      zsItem('Türen', tOffen.length ? '🚪 offen: ' + esc(tOffen.join(', ')) : 'alle zu', tOffen.length ? 'warn' : 'ok'),
      zsItem('Fenster', fOffen.length ? '🪟 offen: ' + esc(fOffen.join(', ')) : 'alle zu', fOffen.length ? 'warn' : 'ok'),
      zsItem('Heckklappe / Frunk', `${ja(z.heckklappe, 'offen', 'zu')} / ${ja(z.frunk, 'offen', 'zu')}`, (z.heckklappe || z.frunk) ? 'warn' : 'ok'),
      zsItem('Laden', `${z.angesteckt ? '🔌 angesteckt' : 'nicht angesteckt'}${z.ladezustand && z.ladezustand !== 'Disconnected' ? ' · ' + esc(z.ladezustand) : ''}${z.ladelimit ? ' · Limit ' + z.ladelimit + ' %' : ''}`,
             (z.ladeklappe && !z.angesteckt) ? 'warn' : (z.angesteckt && (z.ladezustand === 'Charging' || z.ladezustand === 'Starting')) ? 'laedt' : 'neutral'),
      zsItem('Wächtermodus', ja(z.waechter, '👁 an', 'aus'), 'neutral'),
      zsItem('Klima', `${ja(z.klima, '❄ an', 'aus')}${z.innen !== null ? ' · innen ' + fmt(z.innen, 1) + ' °C' : ''}`, 'neutral'),
      zsItem('Software', esc(z.software || '--'), 'neutral'),
    ].join('');
    el('zs-hinweise').innerHTML = (z.hinweise || []).map(h =>
      `<div class="zs-hinweis zs-hinweis-${h.stufe}">${h.stufe === 'warn' ? '⚠' : 'ℹ'} ${esc(h.text)}</div>`).join('');
    const st = ZS_STATUS[z.status] || z.status || '--';
    el('zs-stand').textContent = `Status: ${st}` + (z.seit ? ` seit ${fmtDateShort(z.seit)}` : '') + (z.aktualisiert ? ` · Stand ${fmtDateShort(z.aktualisiert)}` : '');
  }).catch(() => { el('zs-grid').innerHTML = '<div class="loading-msg">Zustand nicht verfügbar</div>'; });
}

// ═══════════════════════════════════════════════════════
// TAB NAVIGATION
// ═══════════════════════════════════════════════════════

let mapsInitDone = false;
let batteryLoaded = false;
let wallboxLoaded = false;

const tabState = {
  drives:  { loaded: false, load: loadDrives },
  charging:{ loaded: false, load: loadCharging },
  battery: { loaded: false, load: loadBattery },
  statistik: { loaded: false, load: loadStatistik },
  pendeln:   { loaded: false, load: loadPendeln },
  wallbox: { loaded: false, load: loadWallbox },
};

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    const tab = this.dataset.tab;
    el('tab-' + tab).classList.add('active');

    // Init map on first show
    if ((tab === 'map' || tab === 'overview') && !mapsInitDone) {
      mapsInitDone = true;
      initMaps();
      loadPosition();
    }
    if (tab === 'map') {
      setTimeout(() => { if (vehicleMap) vehicleMap.invalidateSize(); }, 100);
      if (!fahrtenListeGeladen) { ladeFahrtenListe(); zeigeZeitraum('heute', true); }   // beim ersten Öffnen: heutige Fahrten
    }

    // Lazy-load tabs
    if (tabState[tab] && !tabState[tab].loaded) {
      tabState[tab].loaded = true;
      tabState[tab].load();
    }
  });
});

// ── Range filter wiring ────────────────────────────────

document.querySelectorAll('#drives-range-filter .range-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#drives-range-filter .range-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentDrivesRange = this.dataset.range;
    loadDrives();
  });
});

document.querySelectorAll('#charge-range-filter .range-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('#charge-range-filter .range-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentChargeRange = this.dataset.range;
    loadCharging();
  });
});

// ═══════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════

// Always init maps (overview is the default active tab)
initMaps();
mapsInitDone = true;
loadPosition();

// 30-Tage-Werte der Übersicht
function loadUebersicht30() {
  loadStats('30d').then(data => {
  preiseAusStats(data);
  if (!data.ok) return;
  const s = data.drives.summary;
  if (s) {
    el('ov-m-drives').textContent = s.total_drives;
    el('ov-m-km').textContent = fmt(s.total_km, 0);
    el('ov-m-speed').innerHTML = fmt(s.max_speed, 0) + '<span class="stat-unit">km/h</span>';
  }
  const cs = data.charging.summary;
  if (cs) {
    el('ov-m-kwh').textContent = fmt(cs.total_kwh_dc, 1);
    el('ov-m-kosten').textContent = (cs.kwh_daheim || cs.kwh_fremd) ? `≈ ${eur(cs.kwh_daheim + cs.kwh_fremd * PREIS_FREMD_CT / STROMPREIS_CT)} Strom` + (cs.kwh_fremd ? ' (inkl. anderswo)' : ' daheim') : '';
  }
});
}
loadUebersicht30();

// Automatische Aktualisierung: alle 30 s den offenen Tab still neu laden (pausiert im Hintergrund)
const AKTUALISIEREN = {
  'tab-overview':  () => { loadPosition(); loadZustand(); loadUebersicht30(); loadAuffaelligkeiten(); loadZeitleiste(); },
  'tab-map':       () => loadPosition(),
  'tab-drives':    () => loadDrives(true),
  'tab-charging':  () => loadCharging(true),
  'tab-battery':   () => loadBattery(),
  'tab-statistik': () => loadStatistik(true),
  'tab-pendeln':   () => loadPendeln(true),
  // Wallbox: eigener 10-s-Takt (siehe unten)
};
function aktivenTabAktualisieren() {
  if (document.hidden) return;
  const tab = document.querySelector('.tab-panel.active');
  const f = tab && AKTUALISIEREN[tab.id];
  if (f) f();
  if (tab && tab.id !== 'tab-overview') { loadZustand(); if (tab.id !== 'tab-map') loadPosition(); }   // Kopfzeile/Status aktuell halten
}
loadZustand();
loadAuffaelligkeiten(true);
loadZeitleiste(true);
setInterval(aktivenTabAktualisieren, 30000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) aktivenTabAktualisieren(); });
if (el('wb-aktualisieren')) el('wb-aktualisieren').addEventListener('click', () => loadWallbox());
// Wallbox-Tab: alle 10 s still aktualisieren, solange er offen ist
setInterval(() => { const t = el('tab-wallbox'); if (t && t.classList.contains('active') && !document.hidden) loadWallbox(true); }, 10000);

})();
</script>
</body>
</html>
