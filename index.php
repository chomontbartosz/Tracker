<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo 'Brak pliku config.php. Skopiuj config.example.php do config.php i uzupełnij token.';
    exit;
}
$config = require $configPath;
$token = $config['toggl_api_token'] ?? '';
$limitHours = (float)($config['monthly_limit_hours'] ?? 40);

function togglHttp(string $url, string $token, string $mode): array
{
    $headers = ['Content-Type: application/json'];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($mode === 'bearer') {
        $headers[] = 'Authorization: Bearer ' . $token;
    } else {
        $opts[CURLOPT_USERPWD] = $token . ':api_token';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    if ($body === false) {
        throw new RuntimeException('cURL error: ' . $err);
    }
    return ['code' => (int)$code, 'body' => (string)$body];
}

function togglRequest(string $url, string $token): array
{
    // Try Bearer first (new-style tokens like "toggl_sk_..."),
    // then Basic auth (legacy 32-char hex tokens).
    $modes = ['bearer', 'basic'];
    $lastCode = 0;
    $lastBody = '';
    foreach ($modes as $mode) {
        $res = togglHttp($url, $token, $mode);
        if ($res['code'] >= 200 && $res['code'] < 300) {
            $data = json_decode($res['body'], true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid JSON from ' . $url);
            }
            return $data;
        }
        $lastCode = $res['code'];
        $lastBody = $res['body'];
        if ($res['code'] !== 401 && $res['code'] !== 403) {
            break;
        }
    }
    throw new RuntimeException("HTTP $lastCode from $url: $lastBody");
}

function formatDuration(int $seconds): string
{
    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%s%02d:%02d:%02d', $sign, $h, $m, $s);
}

$error = null;
$rows = [];
$totalSeconds = 0;

try {
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    $now = new DateTimeImmutable('now', $tz);
    $startOfMonth = $now->modify('first day of this month')->setTime(0, 0, 0);
    $endOfMonth = $now->modify('first day of next month')->setTime(0, 0, 0);

    $startIso = $startOfMonth->format(DateTimeInterface::RFC3339);
    $endIso = $endOfMonth->format(DateTimeInterface::RFC3339);

    $url = 'https://api.track.toggl.com/api/v9/me/time_entries'
        . '?start_date=' . urlencode($startIso)
        . '&end_date=' . urlencode($endIso);

    $entries = togglRequest($url, $token);

    $grouped = [];
    foreach ($entries as $e) {
        $desc = trim((string)($e['description'] ?? ''));
        if ($desc === '') {
            $desc = '(bez opisu)';
        }
        $dur = (int)($e['duration'] ?? 0);
        // Running entries have negative duration; skip them for the sum
        // or compute live time. We compute live elapsed for running ones.
        if ($dur < 0) {
            $dur = time() + $dur; // toggl convention: -1 * (start unix - now)
            if ($dur < 0) {
                $dur = 0;
            }
        }
        if (!isset($grouped[$desc])) {
            $grouped[$desc] = 0;
        }
        $grouped[$desc] += $dur;
        $totalSeconds += $dur;
    }

    arsort($grouped, SORT_NUMERIC);
    foreach ($grouped as $name => $secs) {
        $rows[] = ['name' => $name, 'seconds' => $secs];
    }
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

$limitSeconds = (int)round($limitHours * 3600);
$percent = $limitSeconds > 0 ? ($totalSeconds / $limitSeconds) * 100 : 0;
$percentDisplay = round($percent, 1);
$barWidth = min(100, max(0, $percent));
$overLimit = $percent > 100;

$monthLabel = (new DateTimeImmutable('now'))->format('Y-m');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Toggl Tracker — <?= htmlspecialchars($monthLabel) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <header>
        <h1>Toggl — bieżący miesiąc (<?= htmlspecialchars($monthLabel) ?>)</h1>
        <p class="subtitle">Limit: <?= htmlspecialchars((string)$limitHours) ?> h = 100%</p>
    </header>

    <?php if ($error): ?>
        <div class="error">
            <strong>Błąd:</strong>
            <pre><?= htmlspecialchars($error) ?></pre>
        </div>
    <?php else: ?>
        <table class="entries">
            <thead>
            <tr>
                <th>#</th>
                <th>Zadanie</th>
                <th class="num">Czas</th>
                <th class="num">Udział</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="4" class="empty">Brak wpisów w tym miesiącu.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r):
                    $share = $totalSeconds > 0 ? ($r['seconds'] / $totalSeconds) * 100 : 0;
                ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($r['name']) ?></td>
                        <td class="num"><?= formatDuration($r['seconds']) ?></td>
                        <td class="num"><?= number_format($share, 1, ',', ' ') ?>%</td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="2">Suma</th>
                <th class="num"><?= formatDuration($totalSeconds) ?></th>
                <th class="num">100%</th>
            </tr>
            </tfoot>
        </table>

        <section class="limit">
            <div class="limit-header">
                <span>Wykorzystanie limitu (<?= htmlspecialchars((string)$limitHours) ?> h)</span>
                <span class="<?= $overLimit ? 'over' : '' ?>">
                    <?= number_format($percentDisplay, 1, ',', ' ') ?>%
                </span>
            </div>
            <div class="bar">
                <div class="bar-fill <?= $overLimit ? 'over' : '' ?>"
                     style="width: <?= $barWidth ?>%"></div>
                <?php if ($overLimit): ?>
                    <div class="bar-overflow"
                         style="width: <?= min(100, $percent - 100) ?>%"></div>
                <?php endif; ?>
            </div>
            <p class="limit-summary">
                <?= formatDuration($totalSeconds) ?>
                z <?= formatDuration($limitSeconds) ?>
                <?php if ($overLimit): ?>
                    <strong class="over">— przekroczono o <?= formatDuration($totalSeconds - $limitSeconds) ?></strong>
                <?php else: ?>
                    — pozostało <?= formatDuration(max(0, $limitSeconds - $totalSeconds)) ?>
                <?php endif; ?>
            </p>
        </section>
    <?php endif; ?>

    <footer>
        <button id="refreshBtn" type="button">Odśwież</button>
    </footer>
</main>
<script src="app.js"></script>
</body>
</html>
