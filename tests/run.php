<?php
/*
 * Offline tests for history, alerts and the power breakdown, against a fake
 * sysfs tree in a temp dir. No Unraid or PSU needed:   php tests/run.php
 */
$T = sys_get_temp_dir() . '/cpc2test-' . getmypid();
@mkdir("$T/cfg", 0755, true); @mkdir("$T/run"); @mkdir("$T/hw/hwmon3", 0755, true);
define('CFG_DIR', "$T/cfg");
define('CPC_RUN', "$T/run");
define('CPC_HWMON', "$T/hw/hwmon*");
define('CPC_POWERCAP', "$T/powercap");
define('CPC_BLOCK', "$T/block");
define('CPC_DISKS_INI', "$T/disks.ini");
define('CPC_NVSMI', "$T/nvidia-smi");
define('CPC_NOTIFY', "$T/notify");
require __DIR__ . '/../src/monitor.php';

$fail = 0; $pass = 0;
function ok($cond, $what) { global $fail, $pass; if ($cond) $pass++; else { $fail++; echo "FAIL: $what\n"; } }
function put($p, $v) { @mkdir(dirname($p), 0755, true); file_put_contents($p, $v); }

// ---- fake PSU (HX1000i) ----
$H = "$T/hw/hwmon3";
put("$H/name", "corsairpsu\n");
put("$H/device/uevent", "HID_ID=0003:00001B1C:00001C07\n");
function psu($pOutW, $vrm = 45, $fan = 600, $v12 = 12.05) {
    global $H;
    put("$H/power1_input", (int)($pOutW * 1e6)); put("$H/in0_input", 230000);
    put("$H/temp1_input", (int)($vrm * 1000)); put("$H/temp2_input", 35000);
    put("$H/fan1_input", $fan); put("$H/in1_input", (int)($v12 * 1000));
    put("$H/in2_input", 5020); put("$H/in3_input", 3310);
}
psu(200);
ok(hwmon_path() === $H, 'hwmon found');

// ---- breakdown: RAPL ----
put("$T/powercap/intel-rapl:0/energy_uj", 1000000);
put("$T/powercap/intel-rapl:0/max_energy_range_uj", 262143328850);
put("$T/powercap/intel-rapl:0:0/energy_uj", 5);   // sub-zone, must be ignored
$bst = [];
ok(bd_cpu($bst, 100.0) === null, 'cpu null on first sample');
put("$T/powercap/intel-rapl:0/energy_uj", 1000000 + 650e6);   // 650 J over 10 s
ok(abs(bd_cpu($bst, 110.0) - 65.0) < 0.01, 'cpu 65 W');
put("$T/powercap/intel-rapl:0/energy_uj", 100e6);            // wrapped
$w = bd_cpu($bst, 120.0);
ok($w !== null && $w > 0, 'cpu survives counter wrap');

// ---- breakdown: GPU ----
put(CPC_NVSMI, "#!/bin/sh\necho 31.50\necho 10.25\n"); chmod(CPC_NVSMI, 0755);
$g = [];
ok(bd_gpu($g, 0) === 41.8, 'nvidia sum 41.75 -> 41.8');
put("$T/hw/hwmon5/name", "amdgpu\n"); put("$T/hw/hwmon5/power1_average", 20000000);
ok(bd_gpu($g, 10) === 41.8, 'gpu cached within 30 s');
ok(bd_gpu($g, 40) === 61.8, 'nvidia + amdgpu');
unlink(CPC_NVSMI); exec("rm -rf $T/hw/hwmon5");
ok(bd_gpu($g, 100) === null, 'no gpu -> null');

// ---- breakdown: drives ----
foreach (['sda' => 1, 'sdb' => 1, 'sdc' => 0, 'nvme0n1' => 0] as $d => $rot) {
    put("$T/block/$d/queue/rotational", "$rot\n"); put("$T/block/$d/device/x", '');
    put("$T/block/$d/stat", "1 0 0 0 1 0 0 0 0 100 0\n");
}
put("$T/block/loop0/queue/rotational", "0\n");                 // ignored
put(CPC_DISKS_INI, "[\"disk1\"]\nname=\"disk1\"\ndevice=\"sda\"\nspundown=\"1\"\n[\"disk2\"]\nname=\"disk2\"\ndevice=\"sdb\"\nspundown=\"0\"\n");
$b = [];
ok(bd_drives($b) === round(0.8 + 5.0 + 0.5 + 2.0, 1), 'drives: standby hdd + idle hdd + idle ssd + idle nvme');
put("$T/block/sdb/stat", "1 0 0 0 1 0 0 0 0 150 0\n");
ok(bd_drives($b) === round(0.8 + 7.5 + 0.5 + 2.0, 1), 'busy hdd from io_ticks');

// ---- system fans ----
put("$T/hw/hwmon7/name", "nct6798\n");
put("$T/hw/hwmon7/fan1_input", "900\n"); put("$T/hw/hwmon7/fan2_input", "1200\n");
put("$T/hw/hwmon7/fan3_input", "0\n");                     // empty header - not counted
$sf = sys_fans();
ok($sf['avg'] === 1050 && $sf['count'] === 2, 'system fans: 1050 RPM avg across 2 (PSU fan + 0 RPM excluded)');
put("$T/hw/hwmon8/name", "corsaircpro\n");
put("$T/hw/hwmon8/fan1_input", "1500\n"); put("$T/hw/hwmon8/fan1_label", "fan1 4pin\n");
put("$T/hw/hwmon9/name", "amdgpu\n"); put("$T/hw/hwmon9/fan1_input", "3000\n");
$sf = sys_fans();
ok($sf['avg'] === 1200 && $sf['count'] === 3, 'fan controller counted, GPU excluded by default');
ok(count($sf['sources']) === 3 && $sf['sources'][1]['fans'][0]['label'] === 'fan1 4pin', 'per-source list with labels');
$sf = sys_fans(fan_exclude_list(['fan_exclude' => 'nct6798']));
ok($sf['avg'] === 2250 && $sf['count'] === 2, 'user exclude list: drop motherboard, include GPU');
exec("rm -rf $T/hw/hwmon8 $T/hw/hwmon9");
$smp = monitor_sample($H);
ok($smp['sfan'] === 1050 && $smp['nfan'] === 2, 'sample carries system fans');

// ---- history ----
$h = hist_empty();
$t0 = 1700000000; $t0 -= $t0 % 86400;
$s = ['pout' => 100.0, 'pin' => 110.0, 'vrm' => 40.0, 'case' => 30.0, 'fan' => 500, 'cpu' => 50.0, 'gpu' => null, 'drv' => 10.0];
for ($i = 0; $i < 2 * 360 * 6; $i++) {           // 12 h of 10 s samples
    $s['pout'] = 100 + ($i % 6) * 10;             // 100..150 within each minute
    hist_add($h, $t0 + $i * 10, $s);
}
ok(count($h['tiers'][10]['rows']) <= 360, 't10 capped at 1 h');
ok(count($h['tiers'][60]['rows']) === 719, 't60 has 719 closed minutes');
$r = $h['tiers'][60]['rows'][5];
ok($r[1] == 125.0 && $r[2] == 150.0, 'minute avg 125 / max 150');
ok($r[8] == 50.0 && $r[9] === null && $r[10] == 10.0, 'breakdown columns (gpu null)');
hist_save($h);
$now = $t0 + 12 * 3600;
$rg = hist_range('6h', $now);
ok($rg['step'] === 60 && count($rg['rows']) >= 359 && count($rg['rows']) <= 361, '6h range ~360 rows');
ok(abs($rg['kwh'] - 0.66) < 0.01, '6h kWh = 110 W * 6 h = 0.66');
ok($rg['stats']['pout']['max'] == 150.0, 'peak uses per-bucket max');
ok(hist_range('1h', $now)['step'] === 10, '1h uses 10 s tier');
ok(hist_range('bogus', $now)['range'] === '24h', 'bad range -> 24h');
hist_save($h, true);
$fj = json_decode(file_get_contents(CPC_HIST_FLASH), true);
ok(!isset($fj['tiers'][10]) && isset($fj['tiers'][60]), 'flash copy drops t10');
// a restart writes flash after RAM: the 10 s tier must still come back from RAM
hist_save($h); sleep(1); hist_save($h, true);
$re = hist_load();
ok(count($re['tiers'][10]['rows']) > 300 && count($re['tiers'][60]['rows']) === 719, 't10 survives a newer flash copy');

// ---- alerts ----
$c = alert_config(['mode' => 'auto']);
ok($c['enabled'] && $c['vrm'] == 70, 'alert defaults');
$st = []; $sent = [];
$norm = monitor_sample($H);
$hot = $norm; $hot['vrm'] = 75.0;
foreach ([$hot, $hot] as $x) $sent = array_merge($sent, alert_step($st, $x, $c));
ok(!$sent, 'no alert before 3 samples');
$sent = alert_step($st, $hot, $c);
ok(count($sent) === 1 && $sent[0][0] === 'warning' && strpos($sent[0][2], '75.0°C') !== false, 'vrm alert on 3rd sample');
ok(!alert_step($st, $hot, $c), 'no repeat while active');
$warm = $norm; $warm['vrm'] = 68.0;                       // below 70 but within 3 °C hysteresis
for ($i = 0; $i < 5; $i++) ok(!alert_step($st, $warm, $c), 'hysteresis holds');
$sent = [];
for ($i = 0; $i < 3; $i++) $sent = array_merge($sent, alert_step($st, $norm, $c));
ok(count($sent) === 1 && $sent[0][0] === 'normal', 'cleared notice after 3 normal samples');

$blip = []; $st = [];
alert_step($st, $hot, $c); alert_step($st, $norm, $c); alert_step($st, $hot, $c); alert_step($st, $hot, $c);
ok(!$st['active'], 'a broken run resets the trip counter');

$bad = $norm; $bad['v12'] = 11.2;
$st = []; $sent = [];
for ($i = 0; $i < 3; $i++) $sent = array_merge($sent, alert_step($st, $bad, $c));
ok(count($sent) === 1 && strpos($sent[0][1], '+12V') !== false, '12V out of spec');

$stop = $norm; $stop['fan'] = 0.0;
$st = [];
for ($i = 0; $i < 3; $i++) alert_step($st, $stop, $c);
ok(!isset($st['active']['fan']), 'zero-rpm in Default mode at 45 °C is normal');
$cf = alert_config(['mode' => 'curve']); $st = [];
for ($i = 0; $i < 3; $i++) alert_step($st, $stop, $cf);
ok(isset($st['active']['fan']), 'fan 0 RPM in curve mode alerts');

$st = []; $sent = [];
for ($i = 0; $i < 3; $i++) alert_step($st, $hot, $c);
for ($i = 0; $i < 3; $i++) $sent = array_merge($sent, alert_step($st, null, $c));
ok(isset($st['active']['disconnect']) && isset($st['active']['vrm']), 'disconnect raised, vrm kept (not falsely cleared)');

$loaded = $norm; $loaded['load'] = 95.0; $loaded['pout'] = 950.0; $st = [];
for ($i = 0; $i < 3; $i++) alert_step($st, $loaded, $c);
ok(isset($st['active']['load']), 'load alert');
$off = alert_config(['alerts' => '0']); $sent = [];
for ($i = 0; $i < 3; $i++) $sent = array_merge($sent, alert_step($st, $loaded, $off));
ok(!$sent && !$st['active'], 'disabled -> nothing, state cleared');

put(CPC_NOTIFY, "#!/bin/sh\necho \"\$@\" > $T/notified\n"); chmod(CPC_NOTIFY, 0755);
ok(alert_notify('warning', 'x it\'s hot', 'd') && strpos(file_get_contents("$T/notified"), "x it's hot") !== false, 'notify called with quoting');

exec("rm -rf " . escapeshellarg($T));
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
