<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('error_log', '/tmp/php_errors.log');
set_time_limit(120);

require_once 'average_temp_humid.php';

$locations = [
    1 => 'Wynyard',
    2 => 'Launceston',
    3 => 'Smithton',
    4 => 'Hobart',
    5 => 'Campania',
];

$inputDate   = date('Y-m-d');
$defaultSite = 2;
$siteId      = (isset($_GET['site']) && isset($locations[(int)$_GET['site']]))
    ? (int)$_GET['site']
    : $defaultSite;
$averageData  = calculateHalfHourlyAverages($inputDate, $siteId);
$predictions  = generateMinMaxPredictions($siteId, $inputDate);
$locationName = $predictions['site_name'];

$displayDate  = date('F j, Y', strtotime($inputDate));
$liveData     = fetchLiveWeatherData($siteId, $inputDate);
$todayActuals = getTodayActuals($siteId, $inputDate);
$actualData   = $liveData
    ?? ($todayActuals ? array_merge($todayActuals, ['source' => 'Training CSV']) : null);

function calcAccuracy($predicted, $actual) {
    if (abs($actual) < 0.001) return null;
    return round(max(0.0, 100.0 - abs($predicted - $actual) / abs($actual) * 100.0), 1);
}

function accClass($pct) {
    if ($pct === null) return '';
    if ($pct >= 90) return 'good';
    if ($pct >= 70) return 'ok';
    return 'bad';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weather Dashboard – <?php echo htmlspecialchars($locationName); ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="canvasjs3.6/canvasjs.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        :root {
            --temp-color: #e85d04;
            --humid-color: #0096c7;
            --card-radius: 16px;
            --shadow: 0 4px 24px rgba(0,0,0,.08);
        }

        body {
            background: #f0f4f8;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
        }

        /* ── Header ─────────────────────────────────────────────── */
        .dashboard-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #fff;
            padding: 2.5rem 2rem 3.5rem;
            position: relative;
            overflow: hidden;
        }
        .dashboard-header::before {
            content: '';
            position: absolute;
            width: 400px; height: 400px;
            border-radius: 50%;
            background: rgba(255,255,255,.04);
            top: -120px; right: -80px;
        }
        .dashboard-header::after {
            content: '';
            position: absolute;
            width: 250px; height: 250px;
            border-radius: 50%;
            background: rgba(255,255,255,.03);
            bottom: -80px; left: 10%;
        }
        .header-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .header-label {
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: .02em;
            opacity: .9;
            white-space: nowrap;
        }
        .header-divider {
            width: 1px; height: 22px;
            background: rgba(255,255,255,.2);
        }
        .header-date {
            font-size: .9rem;
            opacity: .7;
            white-space: nowrap;
        }
        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 999px;
            padding: .3rem .85rem;
            font-size: .78rem;
            white-space: nowrap;
        }

        /* ── Location dropdown ───────────────────────────────────── */
        .location-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            background: rgba(255,255,255,.13);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 12px;
            transition: background .2s;
            overflow: visible;
        }
        .location-wrap:hover { background: rgba(255,255,255,.2); }
        .loc-icon {
            position: absolute;
            left: .85rem;
            font-size: 1.15rem;
            color: #fff;
            pointer-events: none;
            z-index: 1;
        }
        .loc-chevron {
            position: absolute;
            right: .85rem;
            font-size: .8rem;
            color: rgba(255,255,255,.7);
            pointer-events: none;
            z-index: 1;
        }
        .location-select {
            background: transparent;
            border: none;
            color: #fff;
            font-size: 1.9rem;
            font-weight: 700;
            line-height: 1;
            padding: .45rem 2.8rem .45rem 2.5rem;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            outline: none;
            position: relative;
            z-index: 0;
        }
        .location-select option { background: #1a1a2e; color: #fff; font-size: 1rem; }

        /* ── Metric cards ────────────────────────────────────────── */
        .cards-row {
            margin-top: -2rem;
            padding: 0 1.5rem;
        }
        .metric-card {
            background: #fff;
            border-radius: var(--card-radius);
            box-shadow: var(--shadow);
            padding: 1.4rem 1.6rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            height: 100%;
            transition: transform .15s, box-shadow .15s;
        }
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 32px rgba(0,0,0,.12);
        }
        .metric-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .metric-icon.temp-max  { background: #fff1e6; color: var(--temp-color); }
        .metric-icon.temp-min  { background: #e8f4fd; color: #2196f3; }
        .metric-icon.humid-max { background: #e0f7fa; color: var(--humid-color); }
        .metric-icon.humid-min { background: #f3e5f5; color: #8e24aa; }
        .metric-label {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #888;
            margin-bottom: .15rem;
        }
        .metric-value {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
            color: #1a1a2e;
        }
        .metric-value sup {
            font-size: .85rem;
            font-weight: 500;
            color: #666;
        }
        .metric-tag {
            font-size: .7rem;
            background: #f5f5f5;
            border-radius: 999px;
            padding: .15rem .55rem;
            color: #777;
            margin-top: .4rem;
            display: inline-block;
        }

        /* ── Chart card ──────────────────────────────────────────── */
        .chart-card {
            background: #fff;
            border-radius: var(--card-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem 1.75rem;
        }
        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .75rem;
            margin-bottom: 1.25rem;
        }
        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
        }
        .chart-title small {
            font-weight: 400;
            font-size: .8rem;
            color: #888;
            margin-left: .4rem;
        }
        .toggle-group {
            display: flex;
            background: #f0f4f8;
            border-radius: 999px;
            padding: 3px;
            gap: 2px;
        }
        .toggle-btn {
            border: none;
            background: transparent;
            border-radius: 999px;
            padding: .35rem 1.1rem;
            font-size: .82rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: .35rem;
        }
        .toggle-btn.active {
            background: #fff;
            color: #1a1a2e;
            box-shadow: 0 1px 6px rgba(0,0,0,.1);
        }
        .toggle-btn.active.temp  { color: var(--temp-color); }
        .toggle-btn.active.humid { color: var(--humid-color); }

        #chartContainer { height: 340px; width: 100%; }

        /* ── Accuracy banner ─────────────────────────────────────── */
        .accuracy-strip {
            background: linear-gradient(90deg, #1a1a2e, #0f3460);
            color: #fff;
            border-radius: var(--card-radius);
            padding: 1rem 1.5rem;
        }
        .acc-item { text-align: center; }
        .acc-label { font-size: .7rem; opacity: .6; text-transform: uppercase; letter-spacing: .08em; }
        .acc-val   { font-size: 1.1rem; font-weight: 700; }
        .acc-val.good  { color: #4ade80; }
        .acc-val.ok    { color: #fbbf24; }
        .acc-divider { width: 1px; background: rgba(255,255,255,.15); align-self: stretch; }

        /* ── Today accuracy table ────────────────────────────────── */
        .today-acc-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .today-acc-table thead th {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #888;
            padding: .6rem 1rem;
            border-bottom: 2px solid #f0f4f8;
            text-align: center;
        }
        .today-acc-table thead th:first-child { text-align: left; }
        .today-acc-table tbody td {
            padding: .75rem 1rem;
            font-size: .95rem;
            color: #1a1a2e;
            border-bottom: 1px solid #f0f4f8;
            text-align: center;
            vertical-align: middle;
        }
        .today-acc-table tbody td:first-child {
            text-align: left;
            font-weight: 600;
        }
        .today-acc-table tbody tr:last-child td { border-bottom: none; }
        .acc-pill {
            display: inline-block;
            padding: .2rem .65rem;
            border-radius: 999px;
            font-weight: 700;
            font-size: .88rem;
        }
        .acc-pill.good { background: #dcfce7; color: #16a34a; }
        .acc-pill.ok   { background: #fef9c3; color: #b45309; }
        .acc-pill.bad  { background: #fee2e2; color: #dc2626; }
        .acc-pill.na   { background: #f3f4f6; color: #9ca3af; }
        .today-no-data {
            background: #fff;
            border-radius: var(--card-radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            text-align: center;
            color: #888;
        }
    </style>
</head>
<body>

<!-- ── Header ───────────────────────────────────────────────────────── -->
<div class="dashboard-header">
    <div class="container-fluid px-4">
        <div class="header-row">
            <span class="header-label">Weather Dashboard</span>
            <div class="header-divider d-none d-sm-block"></div>

            <!-- Location dropdown pill — single select, icons layered on top -->
            <div class="location-wrap">
                <i class="bi bi-geo-alt-fill loc-icon"></i>
                <select class="location-select" onchange="window.location.href='?site='+this.value">
                    <?php foreach ($locations as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo $siteId === $id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <i class="bi bi-chevron-down loc-chevron"></i>
            </div>

            <div class="header-divider d-none d-sm-block"></div>
            <span class="header-date"><i class="bi bi-calendar3" style="opacity:.6;margin-right:.35rem;"></i><?php echo $displayDate; ?></span>
            <div class="header-divider d-none d-sm-block"></div>
            <div class="header-badge">
                <i class="bi bi-cpu"></i>
                ML Predictions &nbsp;·&nbsp; Linear Regression
            </div>
        </div>
    </div>
</div>

<!-- ── Prediction cards ──────────────────────────────────────────────── -->
<div class="container-fluid cards-row mb-4">
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-icon temp-max"><i class="bi bi-thermometer-high"></i></div>
                <div>
                    <div class="metric-label">Max Temp</div>
                    <div class="metric-value">
                        <?php echo $predictions['maxTemperaturePrediction']; ?><sup>°C</sup>
                    </div>
                    <span class="metric-tag">Predicted</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-icon temp-min"><i class="bi bi-thermometer-low"></i></div>
                <div>
                    <div class="metric-label">Min Temp</div>
                    <div class="metric-value">
                        <?php echo $predictions['minTemperaturePrediction']; ?><sup>°C</sup>
                    </div>
                    <span class="metric-tag">Predicted</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-icon humid-max"><i class="bi bi-droplet-fill"></i></div>
                <div>
                    <div class="metric-label">Max Humidity</div>
                    <div class="metric-value">
                        <?php echo $predictions['maxHumidityPrediction']; ?><sup>%</sup>
                    </div>
                    <span class="metric-tag">Predicted</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-icon humid-min"><i class="bi bi-droplet-half"></i></div>
                <div>
                    <div class="metric-label">Min Humidity</div>
                    <div class="metric-value">
                        <?php echo $predictions['minHumidityPrediction']; ?><sup>%</sup>
                    </div>
                    <span class="metric-tag">Predicted</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Chart ────────────────────────────────────────────────────────── -->
<div class="container-fluid px-4 mb-4">
    <div class="chart-card">
        <div class="chart-header">
            <h2 class="chart-title">
                Historical Averages
                <small>half-hourly · same day/month across all years</small>
            </h2>
            <div class="toggle-group">
                <button class="toggle-btn active temp" id="btn-temp" onclick="showChart('temp')">
                    <i class="bi bi-thermometer-half"></i> Temperature
                </button>
                <button class="toggle-btn humid" id="btn-humid" onclick="showChart('humid')">
                    <i class="bi bi-droplet"></i> Humidity
                </button>
            </div>
        </div>
        <div id="chartContainer"></div>
    </div>
</div>

<!-- ── Model accuracy strip ─────────────────────────────────────────── -->
<div class="container-fluid px-4 mb-5">
    <div class="accuracy-strip">
        <div class="d-flex align-items-center gap-3 flex-wrap justify-content-around">
            <div class="acc-item">
                <div class="acc-label">Model</div>
                <div class="acc-val" style="color:#fff;">Linear Regression</div>
            </div>
            <div class="acc-divider d-none d-md-block"></div>
            <div class="acc-item">
                <div class="acc-label">Max Temp R²</div>
                <div class="acc-val good">0.84</div>
            </div>
            <div class="acc-divider d-none d-md-block"></div>
            <div class="acc-item">
                <div class="acc-label">Min Temp R²</div>
                <div class="acc-val good">0.84</div>
            </div>
            <div class="acc-divider d-none d-md-block"></div>
            <div class="acc-item">
                <div class="acc-label">Max Humidity R²</div>
                <div class="acc-val ok">0.68</div>
            </div>
            <div class="acc-divider d-none d-md-block"></div>
            <div class="acc-item">
                <div class="acc-label">Min Humidity R²</div>
                <div class="acc-val good">0.82</div>
            </div>
            <div class="acc-divider d-none d-md-block"></div>
            <div class="acc-item">
                <div class="acc-label">Training Samples</div>
                <div class="acc-val" style="color:#fff;">19,980</div>
            </div>
            <div class="acc-divider d-none d-md-block"></div>
            <div class="acc-item">
                <div class="acc-label">Data Range</div>
                <div class="acc-val" style="color:#fff;">2015 – 2026</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Today's Accuracy Section ──────────────────────────────────────────── -->
<div class="container-fluid px-4 mb-5">
    <?php if ($actualData): ?>
    <?php
        $accMaxTemp  = calcAccuracy($predictions['maxTemperaturePrediction'], $actualData['actualMaxTemp']);
        $accMinTemp  = calcAccuracy($predictions['minTemperaturePrediction'], $actualData['actualMinTemp']);
        $accMaxHumid = calcAccuracy($predictions['maxHumidityPrediction'],    $actualData['actualMaxHumid']);
        $accMinHumid = calcAccuracy($predictions['minHumidityPrediction'],    $actualData['actualMinHumid']);

        $rows = [
            [
                'label' => '<i class="bi bi-thermometer-high" style="color:var(--temp-color)"></i>&nbsp; Max Temperature',
                'unit'  => '°C',
                'pred'  => $predictions['maxTemperaturePrediction'],
                'act'   => $actualData['actualMaxTemp'],
                'acc'   => $accMaxTemp,
            ],
            [
                'label' => '<i class="bi bi-thermometer-low" style="color:#2196f3"></i>&nbsp; Min Temperature',
                'unit'  => '°C',
                'pred'  => $predictions['minTemperaturePrediction'],
                'act'   => $actualData['actualMinTemp'],
                'acc'   => $accMinTemp,
            ],
            [
                'label' => '<i class="bi bi-droplet-fill" style="color:var(--humid-color)"></i>&nbsp; Max Humidity',
                'unit'  => '%',
                'pred'  => $predictions['maxHumidityPrediction'],
                'act'   => $actualData['actualMaxHumid'],
                'acc'   => $accMaxHumid,
            ],
            [
                'label' => '<i class="bi bi-droplet-half" style="color:#8e24aa"></i>&nbsp; Min Humidity',
                'unit'  => '%',
                'pred'  => $predictions['minHumidityPrediction'],
                'act'   => $actualData['actualMinHumid'],
                'acc'   => $accMinHumid,
            ],
        ];

        $avgAcc = array_filter([$accMaxTemp, $accMinTemp, $accMaxHumid, $accMinHumid], fn($v) => $v !== null);
        $overallAcc = count($avgAcc) ? round(array_sum($avgAcc) / count($avgAcc), 1) : null;
    ?>
    <div class="chart-card">
        <div class="chart-header">
            <h2 class="chart-title">
                <i class="bi bi-bullseye" style="color:#e85d04"></i>
                Today's Prediction Accuracy
                <small><?php echo $displayDate; ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($locationName); ?></small>
            </h2>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if (isset($actualData['source'])): ?>
                <span style="font-size:.75rem;background:<?php echo $actualData['source']==='Open-Meteo API' ? '#e0f7fa' : '#f3f4f6'; ?>;color:<?php echo $actualData['source']==='Open-Meteo API' ? '#00796b' : '#6b7280'; ?>;border-radius:999px;padding:.2rem .7rem;font-weight:600;">
                    <i class="bi bi-<?php echo $actualData['source']==='Open-Meteo API' ? 'cloud-check' : 'database'; ?>"></i>
                    <?php echo htmlspecialchars($actualData['source']); ?>
                </span>
                <?php endif; ?>
            <?php if ($overallAcc !== null): ?>
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size:.78rem;color:#888;text-transform:uppercase;letter-spacing:.08em;">Overall</span>
                    <span class="acc-pill <?php echo accClass($overallAcc); ?>" style="font-size:1rem;padding:.3rem .9rem;">
                        <?php echo $overallAcc; ?>%
                    </span>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="today-acc-table">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Predicted</th>
                        <th>Actual</th>
                        <th>Difference</th>
                        <th>Accuracy</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $diff = round($r['pred'] - $r['act'], 1);
                    $diffSign = $diff > 0 ? '+' : '';
                    $cls = accClass($r['acc']);
                ?>
                    <tr>
                        <td><?php echo $r['label']; ?></td>
                        <td><?php echo $r['pred'] . $r['unit']; ?></td>
                        <td><?php echo $r['act'] . $r['unit']; ?></td>
                        <td style="color:<?php echo $diff >= 0 ? '#e85d04' : '#0096c7'; ?>; font-weight:600;">
                            <?php echo $diffSign . $diff . $r['unit']; ?>
                        </td>
                        <td>
                            <?php if ($r['acc'] !== null): ?>
                                <span class="acc-pill <?php echo $cls; ?>"><?php echo $r['acc']; ?>%</span>
                            <?php else: ?>
                                <span class="acc-pill na">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="today-no-data">
        <i class="bi bi-calendar-x" style="font-size:2rem;color:#ccc;"></i>
        <div class="mt-2" style="font-size:1rem;">No actual sensor data found for <?php echo $displayDate; ?></div>
        <div style="font-size:.82rem;margin-top:.4rem;">Accuracy comparison will appear once data is recorded for this date.</div>
    </div>
    <?php endif; ?>
</div>

<script>
    const inputDate  = '<?php echo $inputDate; ?>';
    const chartData  = <?php echo json_encode($averageData); ?>;
    let   activeMode = 'temp';

    function showChart(mode) {
        activeMode = mode;
        $('#btn-temp').toggleClass('active temp', mode === 'temp');
        $('#btn-humid').toggleClass('active humid', mode === 'humid');
        mode === 'temp' ? renderTempChart() : renderHumidChart();
    }

    function renderTempChart() {
        const pts = chartData
            .filter(d => parseFloat(d.avgTemp) > 0)
            .map(d => ({ x: new Date(`${inputDate} ${d.time}`), y: parseFloat(d.avgTemp) }));

        new CanvasJS.Chart("chartContainer", {
            animationEnabled: true,
            theme: "light2",
            backgroundColor: "transparent",
            title: { text: "" },
            axisX: {
                valueFormatString: "HH:mm",
                labelFontSize: 11,
                tickLength: 4,
                lineThickness: 1,
                gridThickness: 0,
            },
            axisY: {
                title: "°C",
                titleFontSize: 12,
                labelFontSize: 11,
                includeZero: false,
                gridThickness: 1,
                gridColor: "#f0f0f0",
                lineThickness: 0,
            },
            toolTip: {
                borderThickness: 0,
                cornerRadius: 8,
                content: "<b>{x}</b><br>Avg Temp: <b>{y} °C</b>"
            },
            data: [{
                type: "splineArea",
                color: "#e85d04",
                fillOpacity: .15,
                lineThickness: 2.5,
                markerSize: 0,
                dataPoints: pts
            }]
        }).render();
    }

    function renderHumidChart() {
        const pts = chartData
            .filter(d => parseFloat(d.avgHumid) > 0)
            .map(d => ({ x: new Date(`${inputDate} ${d.time}`), y: parseFloat(d.avgHumid) }));

        new CanvasJS.Chart("chartContainer", {
            animationEnabled: true,
            theme: "light2",
            backgroundColor: "transparent",
            title: { text: "" },
            axisX: {
                valueFormatString: "HH:mm",
                labelFontSize: 11,
                tickLength: 4,
                lineThickness: 1,
                gridThickness: 0,
            },
            axisY: {
                title: "%",
                titleFontSize: 12,
                labelFontSize: 11,
                includeZero: false,
                maximum: 100,
                gridThickness: 1,
                gridColor: "#f0f0f0",
                lineThickness: 0,
            },
            toolTip: {
                borderThickness: 0,
                cornerRadius: 8,
                content: "<b>{x}</b><br>Avg Humidity: <b>{y}%</b>"
            },
            data: [{
                type: "splineArea",
                color: "#0096c7",
                fillOpacity: .15,
                lineThickness: 2.5,
                markerSize: 0,
                dataPoints: pts
            }]
        }).render();
    }

    window.onload = () => showChart('temp');
</script>
</body>
</html>
