<?php
// Balance simulation runner (Phase 4 tool, pulled forward for the Villain
// track pass). Same engine the server runs. Examples:
//   php simulate.php --players=2 --games=2000 --seed=1 --bots=defensive
//   php simulate.php --players=3 --games=2000 --vmax=16 --bots=defensive,defensive,greedy
//   php simulate.php --players=2 --games=500 --csv=out.csv
require_once __DIR__ . '/../engine/Engine.php';
require_once __DIR__ . '/../engine/Validate.php';
require_once __DIR__ . '/Bot.php';

$opt = getopt('', ['players:', 'games:', 'seed:', 'vmax:', 'compdiv:', 'bots:', 'csv:', 'quiet']);
$players = (int)($opt['players'] ?? 2);
$games = (int)($opt['games'] ?? 1000);
$baseSeed = (int)($opt['seed'] ?? 1);
$vmax = isset($opt['vmax']) ? (int)$opt['vmax'] : null;
$botKinds = array_pad(explode(',', $opt['bots'] ?? 'defensive'), $players, null);
$botKinds = array_map(fn($b) => $b ?: 'defensive', array_slice($botKinds, 0, $players));
$csvPath = $opt['csv'] ?? null;

[$cards, $errors] = Validate::loadCsvDir(__DIR__ . '/../../data');
if ($cards === null) {
    fwrite(STDERR, "card data invalid:\n  " . implode("\n  ", $errors) . "\n");
    exit(1);
}

$rows = [];
$agg = [
    'villainmax' => 0, 'round8' => 0, 'traitorWins' => 0, 'traitorPointWins' => 0, 'endRoundSum' => 0, 'villainSum' => 0,
    'fallsSum' => 0, 'src' => ['threat' => 0, 'fallout' => 0, 'fallen' => 0, 'penalty' => 0, 'spin' => 0, 'tipoff' => 0],
    'winScoreSum' => 0, 'posWins' => array_fill(0, $players, 0),
];

for ($g = 0; $g < $games; $g++) {
    $seed = $baseSeed + $g;
    $state = Engine::newGame($cards, range(101, 100 + $players), $seed);
    $firstSeat = $state['firstPlayerSeat'];
    if ($vmax !== null) { $state['villain']['max'] = $vmax; }
    if (isset($opt['compdiv'])) { $state['compromisedDivisor'] = (int)$opt['compdiv']; }
    $botRng = Rng::seedState($seed ^ 0x5EED);
    $steps = 0;
    while ($state['phase'] !== 'finished') {
        if (++$steps > 3000) { throw new RuntimeException("seed $seed did not terminate"); }
        $seat = $state['pending']['seat'] ?? $state['activeSeat'];
        $action = Bot::action($botKinds[$seat], $state, $cards, $seat, $botRng);
        $res = Engine::applyAction($state, $cards, $seat, $action);
        if (!$res['ok']) {
            throw new RuntimeException("seed $seed: illegal bot move {$res['error']} " . json_encode($action));
        }
        $state = $res['state'];
    }
    $r = $state['result'];
    $agg[$r['cause']]++;
    if (!empty($r['traitorWin'])) { $agg['traitorWins']++; }
    if ($r['cause'] === 'round8') {
        $compSeats = array_column(array_filter($r['mandates'], fn($m) => $m['compromised']), 'seat');
        if (array_intersect($r['winners'], $compSeats)) { $agg['traitorPointWins']++; }
    }
    $agg['endRoundSum'] += $state['round'];
    $agg['villainSum'] += $state['villain']['track'];
    $agg['fallsSum'] += $state['stats']['falls'];
    foreach ($state['stats']['villain'] as $k => $v) { $agg['src'][$k] += $v; }
    $winScore = max(array_column($r['scores'], 'total'));
    $agg['winScoreSum'] += $winScore;
    foreach ($r['winners'] as $w) {
        $agg['posWins'][($w - $firstSeat + $players) % $players]++;
    }
    $rows[] = [
        $seed, $players, $state['villain']['max'], $r['cause'], $state['round'],
        $state['villain']['track'], $state['stats']['falls'], $winScore,
        implode('|', $r['winners']),
    ];
}

if ($csvPath) {
    $f = fopen($csvPath, 'w');
    fwrite($f, "seed,players,vmax,cause,end_round,villain,falls,win_score,winners\r\n");
    foreach ($rows as $row) { fwrite($f, implode(',', $row) . "\r\n"); }
    fclose($f);
}

printf(
    "players=%d vmax=%s bots=%s games=%d | villainmax %4.1f%% (traitor wins %.1f%%) | mean end round %.2f | mean villain %.1f | mean falls %.1f | villain threat/fallout/fallen/penalty/spin/tipoff %.1f/%.1f/%.1f/%.1f/%.1f/%.1f | mean winning score %.1f\n",
    $players, $vmax ?? Engine::VILLAIN_MAX[$players], implode(',', $botKinds), $games,
    100 * $agg['villainmax'] / $games, 100 * ($agg['traitorWins'] + $agg['traitorPointWins']) / $games,
    $agg['endRoundSum'] / $games, $agg['villainSum'] / $games,
    $agg['fallsSum'] / $games,
    $agg['src']['threat'] / $games, $agg['src']['fallout'] / $games,
    $agg['src']['fallen'] / $games, $agg['src']['penalty'] / $games,
    ($agg['src']['spin'] ?? 0) / $games, ($agg['src']['tipoff'] ?? 0) / $games,
    $agg['winScoreSum'] / $games
);
printf("  wins by turn-order position (first player = P1): %s\n",
    implode('  ', array_map(
        fn($i, $w) => sprintf('P%d %.0f%%', $i + 1, 100 * $w / max(1, $games)),
        array_keys($agg['posWins']), $agg['posWins']
    )));
