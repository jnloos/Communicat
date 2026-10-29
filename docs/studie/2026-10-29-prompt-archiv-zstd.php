<?php

// Aufruf: php 2026-10-29-prompt-archiv-zstd.php <methode> [all|sample|top3mid]  |  php ... combine
// ACHTUNG: Der Pfad der DB-KOPIE steht im Skript: $S = dirname(__DIR__); $db = "$S/db/copy.sqlite".
// Skript in <ordner>/scripts/ ablegen, die Kopie nach <ordner>/db/copy.sqlite legen. Nie die echte Datenbank eintragen.
// 'combine' fasst out_zstd_<methode>_<modus>.json zu out_zstd.json zusammen (= 2026-10-29-prompt-archiv-zstd.json).
// Usage: php zstd_measure.php <method> [all|sample|top3mid]   |   php zstd_measure.php combine
// method: zstd1|zstd3|zstd10|zstd15|zstd19|zstd22|gz9|xz6. One process per method => its peak memory is the method's.
// Reads ONLY the scratchpad copy of the DB. Payload as PromptArchive::packing() (json, no base64).
ini_set('memory_limit', '-1');
$S = dirname(__DIR__);
$db = "$S/db/copy.sqlite";

if (($argv[1] ?? '') === 'combine') {
    $out = [];
    foreach (glob("$S/out_zstd_*_*.json") as $f) {
        $j = json_decode(file_get_contents($f), true);
        $out[$j['method']] = $j;
    }
    file_put_contents("$S/out_zstd.json", json_encode($out, JSON_PRETTY_PRINT));
    exit;
}

$method = $argv[1];
$mode = $argv[2] ?? 'all';
$pdo = new PDO("sqlite:$db", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA query_only=1');

$where = "l.prompt is not null and l.prompt != '' and l.prompt not like '[prompt removed after%' and j.project_id is not null";
$sizes = $pdo->query("select j.project_id p, sum(length(cast(l.prompt as blob))) b from prompt_logs l join job_logs j on j.id=l.job_log_id where $where group by 1 order by b desc")->fetchAll(PDO::FETCH_KEY_PAIR);
$ids = array_keys($sizes); // by size, descending
if ($mode === 'sample') {
    $rest = array_slice($ids, 20);
    mt_srand(42);
    shuffle($rest);
    $ids = array_merge(array_slice($ids, 0, 20), array_slice($rest, 0, 20));
} elseif ($mode === 'top3mid') {
    $ids = [$ids[0], $ids[1], $ids[2], $ids[intdiv(count($ids), 2)]];
}

// xz via files (not pipes) so large payloads cannot deadlock; argument array = no shell.
function xz(array $args, string $in): string
{
    $a = tempnam(sys_get_temp_dir(), 'xzi');
    $b = tempnam(sys_get_temp_dir(), 'xzo');
    file_put_contents($a, $in);
    $p = proc_open($args, [0 => ['file', $a, 'r'], 1 => ['file', $b, 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[2]);
    if (proc_close($p) !== 0) {
        throw new RuntimeException('xz failed');
    }
    $r = file_get_contents($b);
    unlink($a);
    unlink($b);

    return $r;
}

if (str_starts_with($method, 'zstd')) {
    $lvl = (int) substr($method, 4);
    $comp = fn (string $s) => zstd_compress($s, $lvl);
    $decomp = fn (string $s) => zstd_uncompress($s);
} elseif ($method === 'gz9') {
    $comp = fn (string $s) => gzencode($s, 9);
    $decomp = fn (string $s) => gzdecode($s);
} elseif ($method === 'xz6') {
    $comp = fn (string $s) => xz(['xz', '-6', '-c'], $s);
    $decomp = fn (string $s) => xz(['xz', '-dc'], $s);
} else {
    exit("unknown method\n");
}

$stmt = $pdo->prepare("select l.id, l.prompt from prompt_logs l join job_logs j on j.id=l.job_log_id where $where and j.project_id=? order by l.id");
$rows = [];
$mismatch = 0;
$t0 = hrtime(true);
foreach ($ids as $pid) {
    $stmt->execute([$pid]);
    $texts = [];
    foreach ($stmt as $r) {
        $texts[$r['id']] = $r['prompt'];
    }
    $json = json_encode($texts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    unset($texts);

    $t = hrtime(true);
    $c = $comp($json);
    $tc = (hrtime(true) - $t) / 1e6;

    $best = INF;
    $ok = true;
    for ($i = 0; $i < 3; $i++) { // min of 3 warm runs
        $t = hrtime(true);
        $d = $decomp($c);
        $best = min($best, (hrtime(true) - $t) / 1e6);
        $ok = $ok && $d === $json;
        unset($d);
    }
    if (! $ok) {
        $mismatch++;
    }
    $rows[$pid] = ['raw' => strlen($json), 'comp' => strlen($c), 'ms_comp' => $tc, 'ms_decomp' => $best, 'ok' => $ok];
    fwrite(STDERR, sprintf("%s %d %d -> %d  %.0f/%.0f ms  (%.0fs)\n", $method, $pid, strlen($json), strlen($c), $tc, $best, (hrtime(true) - $t0) / 1e9));
    unset($json, $c);
}
file_put_contents("$S/out_zstd_{$method}_$mode.json", json_encode([
    'method' => $method.($mode === 'all' ? '' : "[$mode]"), 'mode' => $mode, 'projects' => count($rows), 'mismatches' => $mismatch,
    'peak_mem_real' => memory_get_peak_usage(true), 'wall_s' => (hrtime(true) - $t0) / 1e9, 'rows' => $rows,
]));
echo "$method $mode done, mismatches=$mismatch\n";
