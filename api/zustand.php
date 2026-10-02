<?php
// Fahrzeugzustand (nur lesen): Verriegelung, Türen, Fenster, Klappen, Wächtermodus, Klima, Laden, Warnungen.
// Quelle: TeslaMate-MQTT, direkt oder über ioBroker (lib/fahrzeugwerte.php, Einstellung ZUSTAND_QUELLE).
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
require_once dirname(__DIR__) . '/lib/fahrzeugwerte.php';
td_api_schuetzen();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$r = td_fahrzeugwerte();
if ($r === null) {
    echo json_encode(['ok' => false, 'error' => ZUSTAND_QUELLE === 'keine' ? 'Keine Quelle für Fahrzeugwerte eingerichtet' : 'Fahrzeugwerte (MQTT) nicht erreichbar']);
    exit;
}
$w = $r['werte'];
$zeit = $r['zeit'];
$b = fn(string $k) => ($w[$k] ?? null) === true;

$istX = strtoupper((string)($w['model'] ?? '')) === 'X';   // Model X: hintere Türen heißen Falcon Wing
$tueren = [
    'Fahrer vorne'     => $b('driver_front_door_open'),
    'Beifahrer vorne'  => $b('passenger_front_door_open'),
    ($istX ? 'Falcon Wing links' : 'hinten links')   => $b('driver_rear_door_open'),
    ($istX ? 'Falcon Wing rechts' : 'hinten rechts') => $b('passenger_rear_door_open'),
];
$fenster = [
    'Fahrer vorne'    => $b('driver_front_window_open'),
    'Beifahrer vorne' => $b('passenger_front_window_open'),
    'Fahrer hinten'   => $b('driver_rear_window_open'),
    'Beifahrer hinten'=> $b('passenger_rear_window_open'),
];
$fahrt = in_array($w['state'] ?? '', ['driving'], true) || in_array($w['shift_state'] ?? 'P', ['D', 'R', 'N'], true);

// Hinweise: warn = sollte man sich ansehen, info = nur zur Kenntnis
$hinweise = [];
$h = function (string $stufe, string $text) use (&$hinweise) { $hinweise[] = ['stufe' => $stufe, 'text' => $text]; };
if (($w['locked'] ?? null) === false && !$fahrt) $h('warn', 'Nicht verriegelt');
$offen = array_keys(array_filter($tueren));
if ($offen && !$fahrt) $h('warn', 'Tür offen: ' . implode(', ', $offen));
$offen = array_keys(array_filter($fenster));
if ($offen) $h('warn', 'Fenster offen: ' . implode(', ', $offen));
if ($b('trunk_open')) $h('warn', 'Heckklappe offen');
if ($b('frunk_open')) $h('warn', 'Frunk offen');
if ($b('charge_port_door_open') && !$b('plugged_in')) $h('warn', 'Ladeklappe offen, kein Kabel angesteckt');
foreach (['fl' => 'vorne links', 'fr' => 'vorne rechts', 'rl' => 'hinten links', 'rr' => 'hinten rechts'] as $r => $name) {
    if ($b("tpms_soft_warning_$r")) $h('warn', "Reifendruck niedrig $name (" . ($w["tpms_pressure_$r"] ?? '?') . ' bar)');
}
if (($w['healthy'] ?? true) === false) $h('warn', 'TeslaMate meldet ein Problem (healthy = false)');
if ($b('sentry_mode')) $h('info', 'Wächtermodus aktiv');
if ($b('is_user_present')) $h('info', 'Jemand ist im Auto');
if (!in_array($w['climate_keeper_mode'] ?? 'off', ['off', null], true)) $h('info', 'Klima-Modus: ' . $w['climate_keeper_mode']);
if ($b('is_preconditioning')) $h('info', 'Vorklimatisierung läuft');
if ($b('update_available')) $h('info', 'Software-Update verfügbar' . (!empty($w['update_version']) ? ': ' . $w['update_version'] : ''));
if ($b('service_mode')) $h('info', 'Service-Modus aktiv');

echo json_encode([
    'ok'       => true,
    'status'   => $w['state'] ?? null,
    'seit'     => $w['since'] ?? null,
    'aktualisiert' => $zeit ? date('c', max($zeit)) : null,
    'verriegelt'   => $w['locked'] ?? null,
    'tueren'       => $tueren,
    'fenster'      => $fenster,
    'heckklappe'   => $b('trunk_open'),
    'frunk'        => $b('frunk_open'),
    'ladeklappe'   => $b('charge_port_door_open'),
    'angesteckt'   => $b('plugged_in'),
    'ladezustand'  => $w['charging_state'] ?? null,   // Charging, Complete, Stopped, Starting, NoPower, Disconnected
    'ladelimit'    => $w['charge_limit_soc'] ?? null,
    'akku'         => $w['battery_level'] ?? null,
    'laden'        => [
        'kw'         => $w['charger_power'] ?? null,
        'ampere'     => $w['charger_actual_current'] ?? null,
        'volt'       => $w['charger_voltage'] ?? null,
        'phasen'     => $w['charger_phases'] ?? null,
        'kwh'        => $w['charge_energy_added'] ?? null,
        'stunden_bis_voll' => $w['time_to_full_charge'] ?? null,
        'reichweite_km'    => $w['rated_battery_range_km'] ?? null,
    ],
    'waechter'     => $b('sentry_mode'),
    'klima'        => $b('is_climate_on'),
    'innen'        => $w['inside_temp'] ?? null,
    'aussen'       => $w['outside_temp'] ?? null,
    'gang'         => $w['shift_state'] ?? null,
    'software'     => $w['version'] ?? null,
    'hinweise'     => $hinweise,
], JSON_UNESCAPED_UNICODE);
