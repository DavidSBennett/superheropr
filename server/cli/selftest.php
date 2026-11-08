<?php
// Headless engine self-test: plays full bot games through the same engine the
// server uses, checks invariants every step, and proves replay determinism.
// Usage: php selftest.php [games=50] [players=2] [seed=1]

require_once __DIR__ . '/../engine/Engine.php';
require_once __DIR__ . '/../engine/Validate.php';
require_once __DIR__ . '/Bot.php';

$games = (int)($argv[1] ?? 50);
$players = (int)($argv[2] ?? 2);
$baseSeed = (int)($argv[3] ?? 1);

[$cards, $errors, $report] = Validate::loadCsvDir(__DIR__ . '/../../data');
if ($cards === null) {
    fwrite(STDERR, "card data invalid:\n  " . implode("\n  ", $errors) . "\n");
    exit(1);
}
echo "card data: " . implode('; ', $report) . "\n";

function invariants(array $state, CardSet $cards): void
{
    $spinTotal = count($state['spinDeck']) + count($state['spinDiscard']);
    $mandates = [];
    foreach ($state['players'] as $p) {
        foreach ($p['tokens'] as $cur => $n) {
            if ($n < 0) { throw new RuntimeException("negative $cur for seat {$p['seat']}"); }
        }
        if ($p['trust'] < 0) { throw new RuntimeException("negative trust seat {$p['seat']}"); } // R4
        if ($p['execsLeft'] < 0 || $p['execsLeft'] > $p['execsTotal']) { throw new RuntimeException('exec count broken'); }
        $spinTotal += count($p['spinHand']);
        if ($p['mandate'] === null) { throw new RuntimeException('player without mandate'); }
        if (isset($mandates[$p['mandate']])) { throw new RuntimeException('duplicate mandate dealt'); }
        $mandates[$p['mandate']] = true;
    }
    if ($spinTotal !== count($cards->spin)) {
        throw new RuntimeException("spin cards not conserved: $spinTotal");
    }
    if (count($state['fallen']) > Engine::MAX_FALLEN_IN_PLAY) { throw new RuntimeException('too many fallen in play'); }
    if (count($state['fallen']) + count($state['fallenDiscard']) > count($cards->fallen)) {
        throw new RuntimeException('fallen cards not conserved');
    }
    if ($state['villain']['track'] > $state['villain']['max']) { throw new RuntimeException('villain over max'); }
    if (count($state['crisisRow']) > $state['playerCount'] + 2) { throw new RuntimeException('row too long'); }
    foreach ($state['crisisRow'] as $slot) { $cards->crisis($slot['cardId']); }
    foreach ($state['board']['rebuiltLocations'] as $rl) { $cards->crisis($rl['cardId']); }
}

$causes = ['round8' => 0, 'villainmax' => 0];
$passes = 0;
$villainSum = 0;
$trustSum = 0;
$trustN = 0;

for ($g = 0; $g < $games; $g++) {
    $seed = $baseSeed + $g;
    $userIds = range(101, 100 + $players);
    $state = Engine::newGame($cards, $userIds, $seed);
    $botRng = Rng::seedState($seed ^ 0x5EED);
    $actionsTaken = [];
    $steps = 0;
    while ($state['phase'] !== 'finished') {
        if (++$steps > 2000) { throw new RuntimeException("game $seed did not terminate"); }
        $seat = $state['pending']['seat'] ?? $state['activeSeat'];
        $action = Bot::action(Rng::int($botRng, 2) ? 'defensive' : 'greedy', $state, $cards, $seat, $botRng);
        $res = Engine::applyAction($state, $cards, $seat, $action);
        if (!$res['ok']) {
            throw new RuntimeException("game $seed step $steps: bot made illegal move {$res['error']} " . json_encode($action));
        }
        if ($action['type'] === 'PASS') { $passes++; }
        $actionsTaken[] = [$seat, $action];
        $state = $res['state'];
        invariants($state, $cards);
    }
    $causes[$state['result']['cause']]++;
    $villainSum += $state['villain']['track'];
    foreach ($state['result']['scores'] as $s) { $trustSum += $s['total']; $trustN++; }

    // Replay determinism: same seed + same actions = identical final state.
    $replay = Engine::newGame($cards, $userIds, $seed);
    foreach ($actionsTaken as [$seat, $action]) {
        $r = Engine::applyAction($replay, $cards, $seat, $action);
        if (!$r['ok']) { throw new RuntimeException("game $seed replay diverged: {$r['error']}"); }
        $replay = $r['state'];
    }
    if (json_encode($replay) !== json_encode($state)) {
        throw new RuntimeException("game $seed replay produced a different final state");
    }

    // Viewer filtering never leaks: no rng, no deck order, no foreign secrets.
    $filtered = Engine::filterForViewer($state, 0);
    $j = json_encode($filtered);
    if (isset($filtered['rng']) || isset($filtered['crisisDeck'])) {
        throw new RuntimeException('filter leaked rng/deck');
    }
    foreach ($filtered['players'] as $fp) {
        if ($fp['seat'] !== 0 && (isset($fp['mandate']) || isset($fp['spinHand']))) {
            throw new RuntimeException('filter leaked a foreign secret');
        }
    }
}

printf("OK: %d games x %dp. ends: round8=%d villainmax=%d. avg villain=%.1f avg final score=%.1f. forced passes=%d\n",
    $games, $players, $causes['round8'], $causes['villainmax'],
    $villainSum / $games, $trustN ? $trustSum / $trustN : 0, $passes);
printf("determinism: %d games replayed to identical state hashes\n", $games);
