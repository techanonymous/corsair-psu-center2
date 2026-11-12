<?php
/*
 * Corsair PSU Center v2 - power/temperature history + alerts.
 *
 * Both are fed by the energy collector (energyd.php), which already samples the
 * PSU every 10 s from sysfs, so neither adds any extra polling or USB traffic.
 *
 * HISTORY is kept as a set of fixed-size tiers, each a rolling list of averaged
 * buckets. Finer tiers cover short ranges, coarser ones long ranges, so every
 * range the UI offers is drawn from a few hundred to ~1,400 points:
 *
 *   tier   bucket   kept     serves
 *   t10    10 s     1 h      1h
 *   t60    1 min    24 h     6h, 12h, 24h
 *   t600   10 min   7 d      7d
 *   t3600  1 h      30 d     30d
 *   t86400 1 day    366 d    365d
 *
 * A row is [ts, pout_avg, pout_max, pin_avg, vrm_avg, vrm_max, case_avg, fan_avg,
 * cpu_avg, gpu_avg, drives_avg, sysfan_rpm_avg, sysfan_count] (any value may be null).
 *
 * POWER BREAKDOWN (cpu / gpu / drives) shows what is driving the PSU's output:
 *   cpu    - Intel/AMD RAPL package energy counters (/sys/class/powercap). Measured.
 *   gpu    - nvidia-smi power.draw (NVIDIA) and amdgpu hwmon power (AMD). Measured.
 *   drives - ESTIMATED: no sensor exists, so each disk is counted as spun down /
 *            idle / busy (Unraid's spin state + I/O activity) times typical watts.
 * These are DC figures, comparable to the PSU's power OUT. The UI stacks them with
 * "other" = out - (cpu + gpu + drives) (board, RAM, fans, CPU VRM losses...) and
 * "PSU losses" = in - out, so the stack adds up to the wall draw. A source that
 * is missing on this system is simply null and left off. The live copy goes to RAM every few samples; the flash
 * copy (all tiers except t10) is written only every few hours and on a clean stop,
 * to spare the USB stick.
 *
 * ALERTS are raised through Unraid's own notification system (the bell, plus
 * whatever email / push agents the user has set up). A condition must hold for
 * ALERT_TRIP consecutive samples before it fires and be clear for ALERT_TRIP
 * samples before a "back to normal" notice is sent, so a single odd reading never
 * notifies. One notification per event - no repeats while it stays active.
 */

require_once __DIR__ . '/lib.php';

define('CPC_HIST_RAM',   CPC_RUN . '/corsairpsucenter2-history.json');
define('CPC_HIST_FLASH', CFG_DIR . '/history.json');
define('CPC_ALERTS',     CPC_RUN . '/corsairpsucenter2-alerts.json');
if (!defined('CPC_NOTIFY')) define('CPC_NOTIFY', '/usr/local/emhttp/webGui/scripts/notify');

const HIST_TIERS = [               // step (s) => number of buckets kept
    10    => 360,
    60    => 1440,
    600   => 1008,
    3600  => 720,
    86400 => 366,
];
const HIST_RANGES = [              // UI range => [tier step, span seconds]
    '1h'   => [10,    3600],
    '6h'   => [60,    21600],
    '12h'  => [60,    43200],
    '24h'  => [60,    86400],
    '7d'   => [600,   604800],
    '30d'  => [3600,  2592000],
    '365d' => [86400, 31536000],
];
const HIST_FIELDS = ['pout', 'pin', 'vrm', 'case', 'fan', 'cpu', 'gpu', 'drv', 'sfan', 'nfan'];

if (!defined('CPC_POWERCAP'))  define('CPC_POWERCAP',  '/sys/class/powercap');
if (!defined('CPC_BLOCK'))     define('CPC_BLOCK',     '/sys/block');
if (!defined('CPC_DISKS_INI')) define('CPC_DISKS_INI', '/var/local/emhttp/disks.ini');
if (!defined('CPC_NVSMI'))     define('CPC_NVSMI',     '/usr/bin/nvidia-smi');

/* Typical DC draw per drive state, in watts (estimates - there is no sensor). */
const DRIVE_WATTS = [
    'hdd'  => ['standby' => 0.8, 'idle' => 5.0, 'busy' => 7.5],
    'ssd'  => ['standby' => 0.3, 'idle' => 0.5, 'busy' => 2.5],
    'nvme' => ['standby' => 0.5, 'idle' => 2.0, 'busy' => 5.0],
];

/* ------------------------------------------------------------------ sample */

/* CPU package power from RAPL energy counters (needs two samples; null until then). */
function bd_cpu(&$bst, $now) {
    $uj = 0.0; $range = 0.0; $found = false;
    foreach (glob(CPC_POWERCAP . '/intel-rapl:*') as $d) {
        if (substr_count(basename($d), ':') !== 1) continue;            // packages only, not sub-zones
        $e = @file_get_contents("$d/energy_uj");
        if ($e === false) continue;
        $found = true;
        $uj += (float)trim($e);
        $range += (float)@trim(@file_get_contents("$d/max_energy_range_uj"));
    }
    if (!$found) { unset($bst['cpu']); return null; }
    $w = null;
    if (isset($bst['cpu'])) {
        list($pt, $pe) = $bst['cpu'];
        $dt = $now - $pt; $de = $uj - $pe;
        if ($de < 0 && $range > 0) $de += $range;                         // counter wrapped
        if ($dt > 0 && $de >= 0) $w = round($de / 1e6 / $dt, 1);
    }
    $bst['cpu'] = [$now, $uj];
    return $w;
}

/* GPU board power: NVIDIA via nvidia-smi, AMD via the amdgpu hwmon. Summed. */
function bd_gpu(&$bst, $now) {
    // nvidia-smi is a process spawn and can nudge the card out of its deepest idle,
    // so it is polled at most every 30 s and the last value reused in between.
    if (isset($bst['gpu']) && $now - $bst['gpu'][0] < 30) return $bst['gpu'][1];
    $w = 0.0; $found = false;
    if (is_executable(CPC_NVSMI)) {
        $out = [];
        @exec(escapeshellarg(CPC_NVSMI) . ' --query-gpu=power.draw --format=csv,noheader,nounits 2>/dev/null', $out, $rc);
        if ($rc === 0) foreach ($out as $l) if (is_numeric(trim($l))) { $w += (float)trim($l); $found = true; }
    }
    foreach (glob(CPC_HWMON) as $h) {
        if (@trim(@file_get_contents("$h/name")) !== 'amdgpu') continue;
        foreach (['power1_average', 'power1_input'] as $f) {
            $v = @file_get_contents("$h/$f");
            if ($v !== false && is_numeric(trim($v))) { $w += (float)trim($v) / 1e6; $found = true; break; }
        }
    }
    $r = $found ? round($w, 1) : null;
    $bst['gpu'] = [$now, $r];
    return $r;
}

/* Drives: estimated from type + state. Spin state from Unraid's disks.ini, busy from I/O. */
function bd_drives(&$bst) {
    $spundown = [];
    if (file_exists(CPC_DISKS_INI)) {
        $ini = @parse_ini_file(CPC_DISKS_INI, true);
        if (is_array($ini)) foreach ($ini as $d)
            if (!empty($d['device'])) $spundown[$d['device']] = (($d['spundown'] ?? '0') === '1');
    }
    $w = 0.0; $n = 0; $prev = $bst['io'] ?? []; $io = [];
    foreach (glob(CPC_BLOCK . '/*') as $b) {
        $dev = basename($b);
        if (!preg_match('/^(sd[a-z]+|nvme\d+n\d+|hd[a-z]+)$/', $dev)) continue;   // physical disks only
        if (!file_exists("$b/device")) continue;
        $type = (strpos($dev, 'nvme') === 0) ? 'nvme'
              : ((@trim(@file_get_contents("$b/queue/rotational")) === '1') ? 'hdd' : 'ssd');
        $st = preg_split('/\s+/', trim((string)@file_get_contents("$b/stat")));
        $ticks = isset($st[9]) ? (float)$st[9] : 0;                        // io_ticks (ms busy)
        $io[$dev] = $ticks;
        if (!empty($spundown[$dev]))                       $state = 'standby';
        elseif (isset($prev[$dev]) && $ticks > $prev[$dev]) $state = 'busy';
        else                                                $state = 'idle';
        $w += DRIVE_WATTS[$type][$state]; $n++;
    }
    $bst['io'] = $io;
    return $n ? round($w, 1) : null;
}

/*
 * System fans: every hwmon device's fan*_input - the motherboard Super-I/O chip
 * and any fan controller with a kernel driver (Corsair Commander Pro/Core XT
 * "corsaircpro", NZXT "nzxtsmart2", Aquacomputer, etc.). The PSU's own fan is
 * always left out (it has its own readout). GPU fans are left out by default;
 * the user can exclude or include any source by its hwmon name (setting
 * fan_exclude, comma separated). Only fans reporting > 0 RPM count, so empty
 * headers and stopped fans don't drag the average down.
 * Returns ['avg' => rpm|null, 'count' => n, 'sources' => [...]].
 */
const FAN_EXCLUDE_DEFAULT = 'amdgpu,nouveau,radeon,i915,xe';

function fan_exclude_list($cfg) {
    $v = isset($cfg['fan_exclude']) ? (string)$cfg['fan_exclude'] : FAN_EXCLUDE_DEFAULT;
    return array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
}

function sys_fans($exclude = null) {
    if ($exclude === null) $exclude = fan_exclude_list([]);
    $sum = 0; $n = 0; $src = [];
    foreach (glob(CPC_HWMON) as $h) {
        $name = @trim(@file_get_contents("$h/name"));
        if ($name === 'corsairpsu' || $name === '') continue;
        $fans = [];
        foreach (glob("$h/fan*_input") as $f) {
            $v = @trim(@file_get_contents($f));
            if (!is_numeric($v)) continue;
            $lbl = @trim(@file_get_contents(preg_replace('/_input$/', '_label', $f)));
            $fans[] = ['label' => $lbl !== '' && $lbl !== false ? $lbl : basename($f, '_input'), 'rpm' => (int)$v];
        }
        if (!$fans) continue;
        $inc = !in_array($name, $exclude, true);
        if (!isset($src[$name])) $src[$name] = ['name' => $name, 'included' => $inc, 'fans' => []];
        $src[$name]['fans'] = array_merge($src[$name]['fans'], $fans);
        if ($inc) foreach ($fans as $x) if ($x['rpm'] > 0 && $x['rpm'] < 30000) { $sum += $x['rpm']; $n++; }
    }
    return ['avg' => $n ? (int)round($sum / $n) : null, 'count' => $n, 'sources' => array_values($src)];
}

/* One reading of everything history + alerts need. Sysfs only (plus nvidia-smi if present). */
function monitor_sample($H, &$bst = null, $now = null, $fanExclude = null) {
    if ($bst === null) $bst = [];
    $now = $now ?? microtime(true);
    $psu = detect_psu($H);
    list($pIn, ) = input_power($H, $psu);
    $pOut = rd("$H/power1_input", 1000000);
    return [
        'pout' => $pOut,
        'pin'  => $pIn,
        'vrm'  => rd("$H/temp1_input", 1000),
        'case' => rd("$H/temp2_input", 1000),
        'fan'  => rd("$H/fan1_input"),
        'v12'  => rd("$H/in1_input", 1000),
        'v5'   => rd("$H/in2_input", 1000),
        'v33'  => rd("$H/in3_input", 1000),
        'load' => ($psu['capacity'] && $pOut !== null) ? $pOut / $psu['capacity'] * 100 : null,
        'cpu'  => bd_cpu($bst, $now),
        'gpu'  => bd_gpu($bst, $now),
        'drv'  => bd_drives($bst),
    ] + (function () use ($fanExclude) {
        $f = sys_fans($fanExclude);
        return ['sfan' => $f['avg'], 'nfan' => $f['avg'] === null ? null : $f['count']];
    })();
}

/* ----------------------------------------------------------------- history */

function hist_bucket($ts, $step) {
    // daily buckets start at local midnight; shorter ones are plain multiples
    return $step >= 86400 ? strtotime(date('Y-m-d', $ts)) : intdiv($ts, $step) * $step;
}

function hist_empty() {
    $h = ['saved' => 0, 'tiers' => []];
    foreach (HIST_TIERS as $step => $keep) $h['tiers'][$step] = ['rows' => [], 'cur' => null];
    return $h;
}

function hist_acc_new($bucket) {
    $a = ['ts' => $bucket, 'pmax' => null, 'vmax' => null];
    foreach (HIST_FIELDS as $f) { $a["s_$f"] = 0.0; $a["n_$f"] = 0; }
    return $a;
}

function hist_acc_row($a) {
    $avg = function ($f) use ($a) { return $a["n_$f"] ? round($a["s_$f"] / $a["n_$f"], 1) : null; };
    return [$a['ts'], $avg('pout'), $a['pmax'] === null ? null : round($a['pmax'], 1), $avg('pin'),
            $avg('vrm'), $a['vmax'] === null ? null : round($a['vmax'], 1), $avg('case'),
            $a['n_fan'] ? (int)round($a['s_fan'] / $a['n_fan']) : null,
            $avg('cpu'), $avg('gpu'), $avg('drv'),
            $a['n_sfan'] ? (int)round($a['s_sfan'] / $a['n_sfan']) : null,
            $a['n_nfan'] ? (int)round($a['s_nfan'] / $a['n_nfan']) : null];
}

/* Fold one sample into every tier. */
function hist_add(&$h, $ts, $s) {
    foreach (HIST_TIERS as $step => $keep) {
        $t = &$h['tiers'][$step];
        $b = hist_bucket($ts, $step);
        if ($t['cur'] !== null && $t['cur']['ts'] !== $b) {
            $t['rows'][] = hist_acc_row($t['cur']);
            $t['cur'] = null;
        }
        if ($t['cur'] === null) $t['cur'] = hist_acc_new($b);
        foreach (HIST_FIELDS as $f) {
            if (isset($s[$f]) && $s[$f] !== null) { $t['cur']["s_$f"] += $s[$f]; $t['cur']["n_$f"]++; }
        }
        if ($s['pout'] !== null) $t['cur']['pmax'] = max($t['cur']['pmax'] ?? $s['pout'], $s['pout']);
        if ($s['vrm']  !== null) $t['cur']['vmax'] = max($t['cur']['vmax'] ?? $s['vrm'],  $s['vrm']);
        // drop buckets that have aged out of this tier's window
        $oldest = $ts - $keep * $step;
        $drop = 0;
        while ($drop < count($t['rows']) && $t['rows'][$drop][0] < $oldest) $drop++;
        if ($drop) $t['rows'] = array_slice($t['rows'], $drop);
        unset($t);
    }
}

/* Load the newest of the RAM and flash copies (the flash copy has no t10 tier). */
function hist_load() {
    $cands = [];
    foreach ([CPC_HIST_FLASH, CPC_HIST_RAM] as $path) {
        if (!file_exists($path)) continue;
        $j = @json_decode(@file_get_contents($path), true);
        if (!is_array($j) || !isset($j['tiers'])) continue;
        $h = hist_empty();
        $h['saved'] = (int)($j['saved'] ?? 0);
        foreach (HIST_TIERS as $step => $keep) {
            if (isset($j['tiers'][$step]['rows']) && is_array($j['tiers'][$step]['rows']))
                $h['tiers'][$step]['rows'] = $j['tiers'][$step]['rows'];
            if (isset($j['tiers'][$step]['cur']) && is_array($j['tiers'][$step]['cur']))
                $h['tiers'][$step]['cur'] = $j['tiers'][$step]['cur'];
        }
        $cands[] = $h;
    }
    if (!$cands) return hist_empty();
    usort($cands, function ($a, $b) { return $b['saved'] - $a['saved']; });   // newest first
    $best = $cands[0];
    // The flash copy never carries the 10 s tier, so after a restart it can be the
    // newest copy with that tier empty - take the tier from the RAM copy instead.
    if (!$best['tiers'][10]['rows'])
        foreach ($cands as $c) if ($c['tiers'][10]['rows']) { $best['tiers'][10] = $c['tiers'][10]; break; }
    return $best;
}

function hist_save(&$h, $flash = false) {
    $h['saved'] = time();
    $data = $h;
    $path = CPC_HIST_RAM;
    if ($flash) {
        unset($data['tiers'][10]);   // 1 h of 10 s samples is not worth flash writes
        $path = CPC_HIST_FLASH;
        if (!is_dir(CFG_DIR)) @mkdir(CFG_DIR, 0755, true);
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, json_encode($data)) !== false) @rename($tmp, $path);
}

/*
 * Rows for one UI range, oldest first, with the in-progress bucket appended so
 * the newest data shows straight away. Also returns min/avg/max summaries and
 * the energy used over the range (from the averaged input power).
 */
function hist_range($range, $now = null) {
    if (!isset(HIST_RANGES[$range])) $range = '24h';
    list($step, $span) = HIST_RANGES[$range];
    $now = $now ?? time();
    $h = hist_load();
    $t = $h['tiers'][$step];
    $rows = $t['rows'];
    if ($t['cur'] !== null) $rows[] = hist_acc_row($t['cur']);
    $from = $now - $span;
    $rows = array_values(array_filter($rows, function ($r) use ($from) { return $r[0] >= $from - 1; }));

    $sum = ['pout' => [], 'pin' => [], 'vrm' => [], 'case' => [], 'fan' => [], 'cpu' => [], 'gpu' => [], 'drv' => [], 'sfan' => []];
    $wh = 0.0; $whKnown = false;
    foreach ($rows as $r) {
        if ($r[1] !== null) $sum['pout'][] = $r[1];
        if ($r[3] !== null) { $sum['pin'][] = $r[3]; $wh += $r[3] * $step / 3600; $whKnown = true; }
        if ($r[4] !== null) $sum['vrm'][]  = $r[4];
        if ($r[6] !== null) $sum['case'][] = $r[6];
        if ($r[7] !== null) $sum['fan'][]  = $r[7];
        if (($r[8]  ?? null) !== null) $sum['cpu'][] = $r[8];
        if (($r[9]  ?? null) !== null) $sum['gpu'][] = $r[9];
        if (($r[10] ?? null) !== null) $sum['drv'][] = $r[10];
        if (($r[11] ?? null) !== null) $sum['sfan'][] = $r[11];
    }
    $peak = ['pout' => 2, 'vrm' => 5];   // use the per-bucket max column for these
    $stats = [];
    foreach ($sum as $k => $v) {
        if (!$v) { $stats[$k] = null; continue; }
        $mx = max($v);
        if (isset($peak[$k])) foreach ($rows as $r) if ($r[$peak[$k]] !== null) $mx = max($mx, $r[$peak[$k]]);
        $stats[$k] = ['min' => min($v), 'avg' => round(array_sum($v) / count($v), 1), 'max' => $mx];
    }
    return [
        'range' => $range, 'step' => $step, 'from' => $from, 'to' => $now,
        'cols'  => ['ts', 'pout', 'pout_max', 'pin', 'vrm', 'vrm_max', 'case', 'fan', 'cpu', 'gpu', 'drv', 'sfan', 'nfan'],
        'rows'  => $rows,
        'stats' => $stats,
        'kwh'   => $whKnown ? round($wh / 1000, 3) : null,
    ];
}

/* ------------------------------------------------------------------ alerts */

/* Alert settings from settings.cfg, with defaults. */
function alert_config($cfg) {
    $b = function ($k, $d) use ($cfg) { return isset($cfg[$k]) ? ($cfg[$k] === '1' || $cfg[$k] === 1 || $cfg[$k] === true) : $d; };
    $n = function ($k, $d, $lo, $hi) use ($cfg) {
        return isset($cfg[$k]) && is_numeric($cfg[$k]) ? max($lo, min($hi, (float)$cfg[$k])) : $d;
    };
    return [
        'enabled'    => $b('alerts', true),
        'vrm'        => $n('alert_vrm', 70, 30, 120),    // °C
        'case'       => $n('alert_case', 55, 25, 100),   // °C
        'load'       => $n('alert_load', 90, 10, 120),   // % of rated output
        'rails'      => $b('alert_rails', true),         // ATX +/-5 % rail tolerance
        'fan'        => $b('alert_fan', true),
        'disconnect' => $b('alert_disconnect', true),
        'fan_mode'   => isset($cfg['mode']) ? (string)$cfg['mode'] : 'auto',
    ];
}

define('ALERT_TRIP', 3);   // consecutive samples (10 s each) to raise or clear

/* ATX spec: +12 V, +5 V and +3.3 V must stay within +/-5 %. */
const RAIL_SPEC = ['v12' => ['+12V', 12.0], 'v5' => ['+5V', 5.0], 'v33' => ['+3.3V', 3.3]];

/*
 * Which conditions are true right now. Each returns [key => [level, subject,
 * description, clear_test]] where clear_test is the (hysteresis) test that must
 * be false for the alert to count as cleared. $s is null when the PSU is gone.
 */
function alert_conditions($s, $c, $active) {
    $out = [];
    $f1 = function ($v) { return number_format($v, 1); };
    if ($s === null) {
        if ($c['disconnect'])
            $out['disconnect'] = ['alert', 'PSU not detected',
                'The Corsair PSU is no longer visible to Unraid (corsair-psu hwmon device missing). Check its USB cable.'];
        return $out;
    }
    if ($s['vrm'] !== null) {
        $lim = isset($active['vrm']) ? $c['vrm'] - 3 : $c['vrm'];   // 3 °C hysteresis
        if ($s['vrm'] >= $lim)
            $out['vrm'] = ['warning', 'PSU VRM temperature high',
                'VRM temperature is ' . $f1($s['vrm']) . '°C (alert at ' . $f1($c['vrm']) . '°C).'];
    }
    if ($s['case'] !== null) {
        $lim = isset($active['case']) ? $c['case'] - 3 : $c['case'];
        if ($s['case'] >= $lim)
            $out['case'] = ['warning', 'PSU case temperature high',
                'Case temperature is ' . $f1($s['case']) . '°C (alert at ' . $f1($c['case']) . '°C).'];
    }
    if ($s['load'] !== null) {
        $lim = isset($active['load']) ? $c['load'] - 5 : $c['load'];
        if ($s['load'] >= $lim)
            $out['load'] = ['warning', 'PSU load high',
                'Output is ' . round($s['pout']) . ' W, ' . $f1($s['load']) . '% of rated capacity (alert at ' . round($c['load']) . '%).'];
    }
    if ($c['rails']) {
        foreach (RAIL_SPEC as $k => list($label, $nom)) {
            $v = $s[$k];
            if ($v === null || $v <= 0) continue;   // 0 / missing = sensor not reported, not a fault
            $tol = isset($active["rail_$k"]) ? 0.045 : 0.05;
            if (abs($v - $nom) > $nom * $tol)
                $out["rail_$k"] = ['alert', "PSU $label rail out of spec",
                    "$label rail reads " . number_format($v, 2) . ' V; ATX allows ' .
                    number_format($nom * 0.95, 2) . '-' . number_format($nom * 1.05, 2) . ' V.'];
        }
    }
    if ($c['fan'] && $s['fan'] !== null && $s['fan'] < 1) {
        // In Default mode the PSU firmware legitimately stops the fan at low load
        // (zero-RPM). With a fixed duty or a curve the duty is >= 30 %, so 0 RPM
        // means the fan is not spinning when it should be. In Default mode only
        // flag it once the VRM is getting warm.
        $should = ($c['fan_mode'] !== 'auto') || ($s['vrm'] !== null && $s['vrm'] >= $c['vrm'] - 10);
        if ($should)
            $out['fan'] = ['alert', 'PSU fan not spinning',
                'Fan reads 0 RPM' . ($s['vrm'] !== null ? ' with VRM at ' . $f1($s['vrm']) . '°C' : '') .
                ($c['fan_mode'] !== 'auto' ? ' while a fan speed is being set.' : '.')];
    }
    return $out;
}

/*
 * Advance the alert state machine by one sample. Returns the notifications to
 * send as [[level, subject, description], ...] and updates $st in place.
 * $st = ['active' => [key => info], 'pending' => [key => count], 'clearing' => [key => count]]
 */
function alert_step(&$st, $s, $c) {
    $st += ['active' => [], 'pending' => [], 'clearing' => []];
    $send = [];
    if (!$c['enabled']) { $st = ['active' => [], 'pending' => [], 'clearing' => []]; return $send; }
    $now = alert_conditions($s, $c, $st['active']);

    foreach ($now as $k => $a) {
        unset($st['clearing'][$k]);
        if (isset($st['active'][$k])) { $st['active'][$k]['desc'] = $a[2]; continue; }
        $st['pending'][$k] = ($st['pending'][$k] ?? 0) + 1;
        if ($st['pending'][$k] >= ALERT_TRIP) {
            unset($st['pending'][$k]);
            $st['active'][$k] = ['level' => $a[0], 'subject' => $a[1], 'desc' => $a[2], 'since' => time()];
            $send[] = [$a[0], $a[1], $a[2]];
        }
    }
    foreach (array_keys($st['pending']) as $k) if (!isset($now[$k])) unset($st['pending'][$k]);
    foreach ($st['active'] as $k => $a) {
        if (isset($now[$k])) continue;
        // a missing PSU makes every other reading vanish too - keep those as they
        // were rather than announce them "cleared"
        if ($s === null && $k !== 'disconnect') continue;
        $st['clearing'][$k] = ($st['clearing'][$k] ?? 0) + 1;
        if ($st['clearing'][$k] >= ALERT_TRIP) {
            unset($st['active'][$k], $st['clearing'][$k]);
            $send[] = ['normal', preg_replace('/ (high|out of spec|not spinning|not detected)$/', '', $a['subject']) . ' back to normal',
                       'Cleared: ' . $a['subject'] . '.'];
        }
    }
    return $send;
}

/* Raise one Unraid notification. */
function alert_notify($level, $subject, $desc) {
    if (!is_executable(CPC_NOTIFY)) return false;
    $cmd = escapeshellarg(CPC_NOTIFY) . ' -e ' . escapeshellarg('Corsair PSU Center v2') .
           ' -s ' . escapeshellarg($subject) . ' -d ' . escapeshellarg($desc) .
           ' -i ' . escapeshellarg($level) . ' -l ' . escapeshellarg('/CorsairPSUCenter2');
    exec($cmd . ' >/dev/null 2>&1', $o, $rc);
    return $rc === 0;
}

/* Active alerts for the UI (written by energyd). */
function alerts_active() {
    if (!file_exists(CPC_ALERTS)) return [];
    $j = @json_decode(@file_get_contents(CPC_ALERTS), true);
    return (is_array($j) && isset($j['active']) && is_array($j['active'])) ? array_values($j['active']) : [];
}
