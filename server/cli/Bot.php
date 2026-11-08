<?php
// Bots for the simulation runner and self-test. Deterministic given the rng
// state they are handed.
// - greedy: resolves the highest-trust affordable crisis, hoards.
// - defensive: firefights the oldest row slots first (what a human does to
//   starve the Villain track), then takes value.

require_once __DIR__ . '/../engine/Engine.php';

final class Bot
{
    public static function action(string $kind, array $state, CardSet $cards, int $seat, array &$rng): array
    {
        if ($state['pending'] !== null) {
            $k = $state['pending']['count'];
            return ['type' => 'SCOUT_ORDER', 'order' => Rng::shuffle($rng, range(0, $k - 1))];
        }
        $p = $state['players'][$seat];

        $affordable = [];
        foreach ($state['crisisRow'] as $slot) {
            $ok = true;
            foreach ($cards->crisisCost($slot['cardId']) as $cur => $n) {
                if ($p['tokens'][$cur] < $n) { $ok = false; break; }
            }
            if ($ok) { $affordable[] = $slot; }
        }

        $pick = null;
        if ($kind === 'defensive') {
            // 1. an affordable crisis about to fall, highest threat first
            $danger = array_filter($affordable, fn($s) => $s['pos'] <= 1);
            usort($danger, fn($a, $b) =>
                (int)$cards->crisis($b['cardId'])['threat'] <=> (int)$cards->crisis($a['cardId'])['threat']);
            $pick = $danger[0]['cardId'] ?? null;
        }
        if ($pick === null && $affordable) {
            // 2. best trust among affordable
            usort($affordable, fn($a, $b) =>
                (int)$cards->crisis($b['cardId'])['trust'] <=> (int)$cards->crisis($a['cardId'])['trust']);
            $pick = $affordable[0]['cardId'];
        }
        if ($pick !== null) {
            $devote = count($p['devoted']) < 2 && Rng::int($rng, 3) === 0;
            return ['type' => 'PLACE', 'spot' => $pick, 'devote' => $devote];
        }

        // 3. gain toward the oldest crisis we cannot afford yet
        $open = [];
        foreach ($cards->locations as $id => $loc) {
            if (in_array($id, Engine::SLICE_DISABLED_LOCATIONS, true)) { continue; }
            if (in_array($id, $state['board']['closedThisRound'], true)) { continue; }
            $spaces = (int)$loc['spaces'];
            if ($spaces === 0 || count($state['board']['occupancy'][$id] ?? []) < $spaces) { $open[] = $id; }
        }
        $pref = ['AN' => 'L05', 'IN' => 'L04', 'GR' => 'L06', 'RG' => 'L01', 'CO' => 'L07', 'IV' => 'L08'];
        $targets = $state['crisisRow'];
        usort($targets, fn($a, $b) => $a['pos'] <=> $b['pos']); // oldest first
        if ($kind !== 'defensive') { $targets = array_reverse($targets); }
        foreach ($targets as $slot) {
            foreach ($cards->crisisCost($slot['cardId']) as $cur => $n) {
                if ($p['tokens'][$cur] < $n && in_array($pref[$cur], $open, true)) {
                    $action = ['type' => 'PLACE', 'spot' => $pref[$cur]];
                    if ($pref[$cur] === 'L07') {
                        foreach (Engine::CURRENCIES as $c) {
                            if ($c !== 'CO' && $p['tokens'][$c] >= 4) {
                                $action['trade'] = ['give' => $c, 'get' => 'CO'];
                                break;
                            }
                        }
                    }
                    return $action;
                }
            }
        }
        foreach (['L05', 'L04', 'L06', 'L01', 'L08', 'L07', 'L10', 'L02'] as $id) {
            if (in_array($id, $open, true)) { return ['type' => 'PLACE', 'spot' => $id]; }
        }
        return ['type' => 'PLACE', 'spot' => 'L11']; // Intern Pool: always open (R16)
    }
}
