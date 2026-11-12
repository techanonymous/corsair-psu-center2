# Corsair PSU Center v2

An iCUE-style dashboard for Corsair **HXi / RMi** digital power supplies, running as a
native Unraid plugin.

**v2 by techanonymous** is a fork of [racoutlaw's Corsair PSU Center](https://github.com/racoutlaw/corsair-psu-center).
It adds a power history graph with a per-component breakdown, alerts through Unraid
notifications, and system fan tracking. It's a separate plugin (`corsairpsucenter2`):
installing it removes the original plugin and carries your settings and energy history
across.

Corsair's own iCUE software is Windows-only, so a Corsair digital PSU in an Unraid
server normally means no monitoring and no fan control at all. This plugin adds both,
in its own tab.

![Corsair PSU Center](docs/screenshot.png)

> **Status:** working and in daily use on an ASUS X299 / Unraid 7.2.7 box with an
> HX1000i. Only that model has been tested on real hardware — the other eleven are
> supported by the same detection path but are, honestly, untested. Reports welcome.

---

## Features

### New in v2

**Power history graph** - one chart over the last **1h, 6h, 12h, 24h, 7d, 30d or 365d**,
with four layers you switch on and off independently, so you can line them up and spot
correlations (say, power draw against fan speed). Watts use the left axis, °C and RPM get
their own scales on the right, and hovering shows every visible value at that moment. The
layers you pick are remembered per browser.

- **Power** - wall power and PSU output, with average, peak, kWh used and cost for the range.
- **Breakdown** - a stacked graph of what's driving the PSU's draw: **CPU** (measured
  from the processor's RAPL power counters, Intel and AMD), **GPU** (measured, from
  `nvidia-smi` or the `amdgpu` driver), **drives** (estimated from how many disks are
  spun down, idle or busy, since disks have no power sensor), **everything else**
  (motherboard, RAM, fans, CPU power-stage losses) and **PSU losses** (wall minus output).
  Together they add up to the wall draw. Sources your system doesn't have are left out.
- **Temperature** - VRM and case temperature in °C (dashed lines).
- **Fans** - PSU fan RPM, plus one average line for your system fans. Hover it to see how
  many spinning fans make up the average (dotted lines).

**Daily Energy chart** - kWh per day for the last 30 or 90 days, or per month for the
last 12 months, with cost on hover. It's built from the daily data the plugin already
records, so your full history shows straight away.

History rides on the energy collector's existing 10-second samples, so it adds no PSU
polling. Fine detail (the last hour) stays in RAM. The rest is saved to the flash
every 3 hours and on a clean shutdown (about 230 KB).

**Alerts** - Unraid notifications (the bell, plus any email or push agents you've set
up) when:

- VRM or case temperature goes above your limit (default 70°C and 55°C)
- load goes above your limit (default 90%)
- a +12V, +5V or +3.3V rail drifts outside the ATX ±5% tolerance
- the PSU fan reads 0 RPM when it should be spinning (a fixed speed or curve is set,
  or the VRM is getting hot in Default mode)
- the PSU disappears from USB

A problem has to last 30 seconds before it notifies. You get one notice when it starts
and one when it clears. Active alerts also show as a banner on the page and a count on
the Dashboard tile. Thresholds and a **Send test** button are in the Alerts card.

**System fans** - average RPM and fan count across every fan Unraid can see: the
motherboard's sensor chip and any fan controller with a Linux driver (Corsair Commander
Pro / Core XT, NZXT, Aquacomputer...). The PSU's own fan isn't included, and GPU fans
are left out by default. Each source is listed with its fans, and you can untick a
source to leave it out of the average. Fans at 0 RPM aren't counted.

### Carried over from v1

**Live monitoring** — read from the kernel's `corsair-psu` hwmon driver, so polling
costs no USB traffic and cannot collide with anything else talking to the PSU:

- Per-rail voltage, current and power for +12V, +5V and +3.3V
- Total power out, estimated power in, efficiency, load %
- VRM and case temperatures, fan RPM
- Every tile carries a running min/max and a live sparkline

**Fan control**

- **Default** — hand the fan back to the PSU's own firmware (zero-RPM idle)
- **Fixed %** — hold a constant duty, 30–100%
- **Custom curve** — drag-and-drop curve editor mapping VRM temperature to fan duty
- Presets: Quiet, Balanced, Performance, Max

**+12V OCP rail mode** — toggle between single-rail and multi-rail overcurrent
protection. Single rail uses one combined threshold for the whole +12V output, which
means fewer nuisance trips when several drives spin up at once.

**Energy & cost tracking**

- Watt-hours used and running cost for **today, this week, this month, this year, and
  lifetime**, shown as tiles alongside the live sensors
- Enter your electricity rate (per kWh) and each tile shows the cost as well as the kWh
- A lightweight background collector integrates the PSU's input power over time. It
  reads sysfs only — no USB, no Python — and runs in every fan mode
- Totals persist on the boot flash and survive reboots *and* plugin updates. Writes are
  batched (every few minutes) and atomic, so the USB stick isn't worn down and a power
  loss can't corrupt the saved total; the on-screen figure updates live from RAM
- **History** — past months and years are kept for good, so nothing is lost when
  "This Month" rolls over. A **Last Month** tile plus an **Energy History** panel let
  you pick any month or any year, filter to 1 / 3 / 6 / 12 months or All, and see a
  month-by-month list and a by-year summary. It rolls up the daily data already being
  recorded (a few bytes per month); days the server was off simply aren't counted
- **Currency** — choose the symbol shown for costs (USD, EUR, GBP, JPY and more, or a
  custom symbol) and whether it sits before the amount (`$1`) or after it (`1 €`)
- **Your history survives a reinstall** — removing the plugin keeps your settings and
  energy history on the flash, so pulling it to troubleshoot and reinstalling picks
  everything right back up (only the downloaded package files are cleared)

**Dashboard tile** — a compact Corsair PSU tile for Unraid's main **Dashboard**, next to
your other system tiles. It shows live power / load / efficiency, VRM and case
temperatures, fan RPM and mode, and today / this month / lifetime energy and cost — with
an arrow that jumps straight to the full page. It reuses the data the plugin already
collects (sensors + energy), so it adds no extra polling of the PSU.

**Mains voltage (Auto / 115V / 230V)** — efficiency and input power are interpolated for
your mains voltage, normally read straight from the PSU. A few units — notably the
**HX1000i (2022 revision)** — report input voltage at about double the real value over
their USB interface. If yours shows ~230V on a 115V outlet (or the reverse), pin your
real mains from the header control and the input-voltage readout plus the efficiency and
power-in figures stay correct. **Auto** (the default) trusts the PSU, so existing setups
are unaffected.

**Adjustable text size** — the whole UI scales from one control, remembered per browser.

---

---

## Building a release

```
./build.sh              # version = today, e.g. 2026.11.11 (then 2026.11.11a, b... on the same day)
./build.sh 2026.12.01   # or pick one
php tests/run.php       # offline tests for history, alerts, breakdown and fans
```

`build.sh` packs `src/` into `archive/corsairpsucenter2-<version>-x86_64.txz` and writes
the version and md5 into `corsairpsucenter2.plg` and `archive/md5.txt`. The bundled
Python runtime is taken from the newest package already in `archive/` and cached in
`.build/`. Add a `###<version>` entry to `<CHANGES>` in the `.plg` and to
`CHANGELOG.md`, commit, and push. Unraid picks the update up from the `.plg` on GitHub.

Needs bash, GNU tar, xz and md5sum (Linux, WSL or Git Bash).

---

## Supported hardware

Any PSU handled by the kernel `corsair-psu` driver. The model is detected at runtime
from its USB HID product id — nothing is hardcoded.

| | |
|---|---|
| HX series | HX750i, HX850i, HX1000i, HX1000i (2022), HX1200i, HX1200i ATX 3.1, HX1500i |
| RM series | RM650i, RM750i, RM850i, RM1000i |

An unrecognised Corsair PSU still works for everything the kernel exposes (rails,
power, temperatures, fan, and fan control). Only efficiency, input power and load %
are hidden, because those need model-specific coefficients — the plugin will show
`n/a` rather than invent a number.

**Requires Unraid 6.12.0 or newer.**

---

## Install

Unraid → **Plugins** → **Install Plugin**, paste

```
https://raw.githubusercontent.com/techanonymous/corsair-psu-center2/main/corsairpsucenter2.plg
```

and click Install. Then open the **Corsair PSU v2** tab in the top menu.

**Upgrading from the original Corsair PSU Center:** just install v2. It stops the old
plugin's services, removes its package and `.plg`, and copies its `settings.cfg` and
energy history into `/boot/config/plugins/corsairpsucenter2/` (only if v2 doesn't
already have its own). The old `/boot/config/plugins/corsairpsucenter/` folder is left
in place as a backup. Delete it by hand when you're happy.

The package is **fully self-contained** — it bundles its own Python 3.11 runtime *and*
every module it needs, so fan control works even on Unraid systems that have no Python
installed (Unraid does not ship Python by default). No `pip`, no internet access at
install or boot, and nothing to configure by hand. Unraid re-runs the `.plg` on each
boot, so it survives reboots on its own.

To remove it, use the Plugins page. Uninstall stops the fan service, hands the fan
back to the PSU firmware, and removes everything it installed.

---

## How it works

Two deliberately separate paths:

- **Telemetry → sysfs.** The kernel `corsair-psu` hwmon driver publishes all sensors
  under `/sys/class/hwmon/hwmonN/`. Reading them is instant, involves no USB traffic,
  and cannot conflict with other software.
- **Control → USB HID via `liquidctl`.** Required, because the kernel driver exposes
  `pwm1` and `pwm1_enable` **read-only** — there is no write path through sysfs. Only
  the two write operations (fan duty, OCP mode) go over USB, targeted by vendor and
  product id.

**Efficiency and input power are estimates.** The PSU does not report input power, so
it is derived from a per-model quadratic fit of output power, interpolated for mains
voltage. The coefficients come from
[liquidctl](https://github.com/liquidctl/liquidctl)'s `corsair_hid_psu` driver. If your
PSU misreports its input voltage, the **Mains voltage** control (Auto / 115V / 230V) in
the header lets you pin the real value so these estimates stay accurate.

### Why a background service for the fan curve

These PSUs **cannot store a fan curve in firmware** — `set_speed_profile` is
`NotSupportedByDevice` for this family; the hardware only accepts a single fixed duty.
iCUE on Windows works around this by polling temperature and rewriting the duty, and
this plugin does the same: a small daemon reads VRM temperature every 10 seconds and
re-applies the appropriate duty, writing only when the value actually changes.

Consequences worth knowing:

- **Minimum duty is 30%.** `liquidctl` clamps below that.
- **Curve mode depends on the service running.** The UI tells you if it isn't.
- Choosing **Default** stops the service and returns control to the PSU.

---

---

## Building a release

```
./build.sh              # version = today, e.g. 2026.11.11 (then 2026.11.11a, b... on the same day)
./build.sh 2026.12.01   # or pick one
php tests/run.php       # offline tests for history, alerts, breakdown and fans
```

`build.sh` packs `src/` into `archive/corsairpsucenter2-<version>-x86_64.txz` and writes
the version and md5 into `corsairpsucenter2.plg` and `archive/md5.txt`. The bundled
Python runtime is taken from the newest package already in `archive/` and cached in
`.build/`. Add a `###<version>` entry to `<CHANGES>` in the `.plg` and to
`CHANGELOG.md`, commit, and push. Unraid picks the update up from the `.plg` on GitHub.

Needs bash, GNU tar, xz and md5sum (Linux, WSL or Git Bash).

---

## Support

For v2, please open a **[GitHub issue](https://github.com/techanonymous/corsair-psu-center2/issues)**.
The original plugin's
[Unraid forum thread](https://forums.unraid.net/topic/200056-plugin-corsair-psu-center-icue-style-dashboard-and-fan-control-for-corsair-hxirmi-psus/)
covers v1.

Reports from **other PSU models are especially welcome.** Only the HX1000i has been
tested on real hardware; the other eleven share the same detection path but nobody has
confirmed them yet. If you run one, it's useful to know whether the model name, wattage
and efficiency read correctly.

---

## Layout

```
corsairpsucenter2.plg    the installer (also removes v1)
build.sh                 packs src/ into the .txz, updates version + md5
archive/                 the .txz packages + md5 list (v1 packages kept for history)
src/                     readable sources (also inside the package)
  api.php                telemetry + control endpoint
  lib.php                shared PSU table, sysfs + energy helpers, config
  monitor.php            history tiers, power breakdown, system fans, alerts
  energyd.php            background collector: energy, history, alerts
  fand.sh                fan curve daemon
  CorsairPSUCenter2.page the UI
  CorsairPSUCenter2-dashboard.page  the Unraid Dashboard tile
  icons/                 plugin icon
  README.md              the short description on Unraid's Plugins page
tests/run.php            offline tests (fake sysfs, no hardware needed)
ca_profile.xml           Community Applications repository profile
plugins/                 Community Applications plugin template
```

---

## Credits

Corsair PSU Center v1 by [racoutlaw](https://github.com/racoutlaw/corsair-psu-center).
v2 additions by techanonymous.
Device protocol, model table and efficiency coefficients come from
[liquidctl](https://github.com/liquidctl/liquidctl), which does the hard part of
talking to these PSUs. The `corsair-psu` hwmon driver is part of the Linux kernel.

## License

GPL-2.0, matching liquidctl and the Unraid plugin ecosystem.
