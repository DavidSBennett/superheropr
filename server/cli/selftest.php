<?php
// Headless engine self-test: plays full bot games through the same engine the
// server uses, checks invariants every step, and proves replay determinism.
// Usage: php selftest.php [games=50] [players=2] [seed=1]

require_once __DIR__ . '/../engine/Engine.php';
require_once __DIR__ . '/../engine/Validate.php';

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
    foreach ($state['players'] as $p) {
        foreach ($p['tokens'] as $cur => $n) {
            if ($n < 0) { throw new RuntimeException("negative $cur for seat {$p['seat']}"); }
        }
        if ($p['trust'] < 0) { throw new RuntimeException("negative trust seat {$p['seat']}"); } // R4
        if ($p['execsLeft'] < 0 || $p['execsLeft'] > $p['execsTotal']) { throw new RuntimeException('exec count broken'); }
    }
    if ($state['villain']['track'] > $state['villain']['max']) { throw new RuntimeException('villain over max'); }
    if (count($state['crisisRow']) > $state['playerCount'] + 2) { throw new RuntimeException('row too long'); }
    foreach ($state['crisisRow'] as $slot) { $cards->crisis($slot['cardId']); }
}

/** Deterministic simple bot. Returns an action for the seat to act. */
function botAction(array $state, CardSet $cards, int $seat, array &$botRng): array
{
    if ($state['pending'] !== null) {
        $k = $state['pending']['count'];
        $order = range(0, $k - 1);
        return ['type' => 'SCOUT_ORDER', 'order' => Rng::shuffle($botRng, $order)];
    }
    $p = $state['players'][$seat];
    // Resolve the highest-trust affordable crisis.
    $best = null;
    foreach ($state['crisisRow'] as $slot) {
        $ok = true;
        foreach ($cards->crisisCost($slot['cardId']) as $cur => $n) {
            if ($p['tokens'][$cur] < $n) { $ok = false; break; }
        }
        if ($ok) {
            $t = (int)$cards->crisis($slot['cardId'])['trust'];
            if ($best === null || $t > $best[1]) { $best = [$slot['cardId'], $t]; }
        }
    }
    if ($best !== null) {
        $devote = count($p['devoted']) < 2 && Rng::int($botRng, 3) === 0;
        return ['type' => 'PLACE', 'spot' => $best[0], 'devote' => $devote];
    }
    // Otherwise the open location granting the currency we are most short of
    // for the cheapest crisis; fall back to any open location.
    $open = [];
    foreach ($cards->locations as $id => $loc) {
        if (in_array($id, Engine::SLICE_DISABLED_LOCATIONS, true)) { continue; }
        if (in_array($id, $state['board']['closedThisRound'], true)) { continue; }
        $spaces = (int)$loc['spaces'];
        if ($spaces === 0 || count($state['board']['occupancy'][$id] ?? []) < $spaces) { $open[] = $id; }
    }
    if (!$open) {
        return ['type' => 'PASS'];
    }
    $pref = ['AN' => 'L05', 'IN' => 'L04', 'GR' => 'L06', 'RG' => 'L01', 'CO' => 'L07', 'IV' => 'L08'];
    $need = null;
    $needGap = -1;
    foreach ($state['crisisRow'] as $slot) {
        foreach ($cards->crisisCost($slot['cardId']) as $cur => $n) {
            $gap = $n - $p['tokens'][$cur];
            if ($gap > 0 && $gap > $needGap && in_array($pref[$cur], $open, true)) {
                $need = $pref[$cur];
                $needGap = $gap;
            }
        }
    }
    $spot = $need ?? $open[Rng::int($botRng, count($open))];
    $action = ['type' => 'PLACE', 'spot' => $spot];
    if ($spot === 'L07') {
        foreach (Engine::CURRENCIES as $cur) {
            if ($cur !== 'IV' && $p['tokens'][$cur] >= 4) {
                $action['trade'] = ['give' => $cur, 'get' => 'IV'];
                break;
            }
        }
    }
    return $action;
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
        $action = botAction($state, $cards, $seat, $botRng);
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
