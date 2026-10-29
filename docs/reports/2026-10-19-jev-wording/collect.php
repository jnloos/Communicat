<?php

// php collect.php V0|V1|V2|V3 — one worker per variant; cache is append-only JSONL, so a rerun resumes.
require __DIR__.'/lib.php';

use App\Discussion\Values\JevChoice;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;

$variant = $argv[1] ?? throw new InvalidArgumentException('variant');
$file = __DIR__."/cache-$variant.jsonl";
$done = [];
if (is_file($file)) {
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $row = json_decode($line, true);
        $done[$row['key']] = true;
    }
}

$twinLabels = [];
$total = 0;
$made = 0;
$failed = 0;
foreach (CELLS as $id => [$kind, $twin]) {
    $cell = loadCell($id);
    $twinLabels = loadCell($twin)['labels'];
    foreach (states($cell) as $state) {
        foreach (['own', 'shuf'] as $order) {
            foreach (['own', 'alt'] as $roster) {
                $total++;
                $key = "$id:{$state['t']}:$order:$roster";
                if (isset($done[$key])) {
                    continue;
                }
                $r = request($cell, $state, $variant, $order, $roster, $twinLabels);
                $probs = null;
                $error = null;
                for ($attempt = 1; $attempt <= 4; $attempt++) {
                    try {
                        $response = Classification::of($r['state'])
                            ->questions(['speaker' => new Choice($r['instructions'], $r['options'])])
                            ->timeout(60)->classify('typesafe', 'jev-1.13.0');
                        $read = JevChoice::read($response->answers['speaker'] ?? null, array_keys($r['options']));
                        $probs = $read?->probabilities;
                        $error = $read === null ? 'unreadable' : null;
                        break;
                    } catch (Throwable $e) {
                        $error = class_basename($e).': '.mb_substr($e->getMessage(), 0, 120);
                        usleep(500_000 * 2 ** $attempt);
                    }
                }
                file_put_contents($file, json_encode(['key' => $key, 'probs' => $probs, 'order' => array_keys($r['options']), 'error' => $error])."\n", FILE_APPEND | LOCK_EX);
                $made++;
                $failed += $probs === null;
                usleep(150_000);
                if ($made % 100 === 0) {
                    fwrite(STDERR, "$variant: $made new, $failed failed, at $key\n");
                }
            }
        }
    }
}
fwrite(STDERR, "$variant finished: $made new, $failed failed, $total planned\n");
