<?php

// Facts per replayed state that the analysis needs; no API calls.
require __DIR__.'/lib.php';
$meta = [];
foreach (CELLS as $id => [$kind]) {
    $cell = loadCell($id);
    $states = array_map(fn ($s) => array_diff_key($s, ['history' => 1]), states($cell));
    $meta[$id] = ['kind' => $kind, 'focus' => $cell['focus']->promptId, 'candidates' => array_map(fn ($e) => $e->promptId, $cell['experts']), 'states' => array_values($states)];
}
file_put_contents(__DIR__.'/meta.json', json_encode($meta));
echo "ok\n";
