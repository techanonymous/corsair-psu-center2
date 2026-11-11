<?php
/*
 * Corsair PSU Center - energy collector daemon.
 *
 * Samples the PSU's estimated INPUT power (wall draw) from sysfs and integrates
 * it into watt-hours, persistently, so the dashboard can show usage + cost per
 * day / week / month / year / lifetime.
 *
 * Deliberately independent of the fan-curve daemon (fand.sh): that one only
 * runs in curve mode, but energy must accrue in every fan mode. Reads sysfs
 * only - no USB, no python, no contention with liquidctl.
 *
 * Persistence respects the Unraid USB flash: the running counter lives in this
 * process's memory and is checkpointed to flash only every CKPT seconds (and on
 * a clean stop), not every sample.
 */

require_once __DIR__ . '/lib.php';

$SAMPLE = 10;    // seconds between samples
$CKPT   = 300;   // seconds between flash checkpoints

// ---- single instance (confirm the pid is really us) ----
if (file_exists(CPC_EPID)) {
    $old = trim(@file_get_contents(CPC_EPID));
    if ($old !== '' && file_exists("/proc/$old")) {
        $cl = @file_get_contents("/proc/$old/cmdline");
        if ($cl !== false && strpos($cl, 'energyd.php') !== false) {
            fwrite(STDERR, "energyd already running (pid $old)\n");
            exit(0);
        }
    }
}
@mkdir(CPC_EDIR, 0755, true);
@file_put_contents(CPC_EPID, getmypid());

$state = energy_load_state();
if (empty($state['since'])) $state['since'] = time();

function elive() {   // RAM copy every sample - free (tmpfs), keeps the UI live
    global $state;
    @file_put_contents(CPC_LIVE, json_encode($state));
}
function eflash() {  // persist to USB flash - infrequent, spares the stick.
    // Atomic: write a temp file then rename over the real one, so a crash or
    // power loss mid-write can never corrupt the saved total (worst case the
    // previous good checkpoint survives untouched).
    global $state;
    $tmp = CPC_STATE . '.tmp';
    if (@file_put_contents($tmp, json_encode($state)) !== false) @rename($tmp, CPC_STATE);
}

// ---- power-draw history (for the charts) ----
// Per-minute rows (avg/min/max of the 10 s samples) are kept in RAM for
// CPC_MINKEEP; each finished local hour is appended to flash as one line. The
// open minute/hour buckets ride along in the RAM file, so a daemon restart
// (plugin update) carries on where it left off.
$pw = power_load_live();
if (!is_array($pw['min'])) $pw['min'] = [];

function pw_save() {   // RAM only (tmpfs)
    global $pw;
    $tmp = CPC_PLIVE . '.tmp';
    if (@file_put_contents($tmp, json_encode($pw)) !== false) @rename($tmp, CPC_PLIVE);
}
function pw_flush_hour() {   // one short append to flash per hour
    global $pw;
    if (!empty($pw['hour']['n']))
        @file_put_contents(CPC_HOURLY, implode(',', pw_row($pw['hour'])) . "\n", FILE_APPEND);
    $pw['hour'] = null;
}
function pw_close_minute() {
    global $pw;
    if (!empty($pw['cur']['n'])) $pw['min'][] = pw_row($pw['cur']);
    $pw['cur'] = null;
    $cut = time() - CPC_MINKEEP;
    while ($pw['min'] && $pw['min'][0][0] < $cut) array_shift($pw['min']);
}
/* Feed one sample (watts, or null when unreadable). Saves the RAM copy whenever
 * a minute closes. */
function pw_sample($w, $now) {
    global $pw;
    $mk = intdiv($now, 60) * 60;
    $hk = pw_hour_start($now);
    $closed = false;
    if ($pw['cur']  && $pw['cur']['t']  != $mk) { pw_close_minute(); $closed = true; }
    if ($pw['hour'] && $pw['hour']['t'] != $hk) { pw_flush_hour();   $closed = true; }
    if ($w !== null) {
        if (!$pw['cur'])  $pw['cur']  = pw_bucket($mk);
        if (!$pw['hour']) $pw['hour'] = pw_bucket($hk);
        pw_add($pw['cur'], $w);
        pw_add($pw['hour'], $w);
    }
    if ($closed) pw_save();
}

// graceful stop: flush + drop pidfile
$RUN = true;
pcntl_async_signals(true);
$stop = function () use (&$RUN) { $RUN = false; };
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT,  $stop);
register_shutdown_function(function () {
    eflash();
    // Write the partial hour to flash too, so a reboot loses nothing; a restart in
    // the same hour just adds a second row for it, which the reader merges.
    pw_close_minute(); pw_flush_hour(); pw_save();
    if (file_exists(CPC_EPID) && trim(@file_get_contents(CPC_EPID)) == getmypid()) @unlink(CPC_EPID);
});

elive(); eflash();       // write both immediately so the UI has something
$lastCkpt = time();

while ($RUN) {
    $now = time();
    $H = hwmon_path();
    $w = null;
    if ($H) {
        $psu = detect_psu($H);
        list($pIn, $eff) = input_power($H, $psu);
        // chart the wall draw; on a model with no efficiency data fall back to
        // the PSU's own output reading rather than recording nothing
        if ($pIn !== null && $pIn > 0) $w = $pIn;
        else { $po = rd("$H/power1_input", 1000000); if ($po !== null && $po > 0) $w = round($po, 2); }
        if ($pIn !== null && $pIn > 0) {
            $dt = $state['last_ts'] ? ($now - $state['last_ts']) : 0;
            // Only integrate over a plausible sample gap. A large gap means the
            // daemon (or box) was down - don't invent energy for that time.
            if ($dt > 0 && $dt <= 2 * $SAMPLE + 5) {
                $wh    = $pIn * $dt / 3600.0;
                $today = date('Y-m-d', $now);
                if ($state['today'] !== $today) {          // day rollover
                    if ($state['today'] !== '' && $state['today_wh'] > 0)
                        @file_put_contents(CPC_DAILY,
                            $state['today'] . ',' . round($state['today_wh'], 3) . "\n",
                            FILE_APPEND);
                    $state['today']    = $today;
                    $state['today_wh'] = 0.0;
                }
                $state['total_wh'] += $wh;
                $state['today_wh'] += $wh;
            }
        }
    }
    $state['last_ts'] = $now;
    pw_sample($w, $now);

    elive();                                                  // live RAM copy every sample
    if ($now - $lastCkpt >= $CKPT) { eflash(); $lastCkpt = $now; }   // flash checkpoint
    sleep($SAMPLE);
}

eflash();   // clean exit persist (shutdown function also flushes as a backstop)
