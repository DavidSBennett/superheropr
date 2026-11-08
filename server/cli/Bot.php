<?php
// Bots for the simulation runner and self-test, full Phase 3 rules.
// - greedy: chases the highest-trust crisis, hoards.
// - defensive: firefights the oldest row slots and redeems Fallen Heroes.
// Both play Spin cards, rebuild, use rebuilt locations, and (when
// Compromised) tip off the villains about half the time.

require_once __DIR__ . '/../engine/Engine.php';

final class Bot
{
    public static function action(string $kind, array $state, CardSet $cards, int $seat, array &$rng): array
    {
        if ($state['pending'] !== null) {
            $k = $state['pending']['count'];
            return ['type' => 'SCOUT_ORDER', 'order' => Rng::shuffle($rng, range(0, $k - 1))];
        }
        $isPress = $state['phase'] === 'press';
        $action = self::choose($kind, $state, $cards, $seat, $rng, $isPress);
        $action['type'] = $isPress ? 'PRESS_REASSIGN' : 'PLACE';
        // Compromised tip-off (R8): on any placement, ~half the time.
        $p = $state['players'][$seat];
        $mandate = $p['mandate'] !== null ? $cards->mandates[$p['mandate']] : null;
        if ($mandate !== null && $mandate['compromised'] === '1'
            && $p['tipOffUsedRound'] < $state['round'] && Rng::int($rng, 2) === 0) {
            $action['tipOff'] = true;
        }
        return $action;
    }

    private static function choose(string $kind, array $state, CardSet $cards, int $seat, array &$rng, bool $isPress): array
    {
        $p = $state['players'][$seat];
        $occ = $state['board']['occupancy'];
        $escalate = 0;
        $closed = $state['board']['closedThisRound'];
        foreach ($state['fallen'] as $id) {
            $d = explode(':', $cards->fallen[$id]['ongoing_code'])[1];
            if ($d === 'COST') { $escalate = 2; }
            if ($d === 'CITYHALL') { $closed[] = 'L01'; $closed[] = 'L02'; }
            if ($d === 'SPIN') { $closed[] = 'L09'; }
        }

        $affordCrisis = function (string $cardId) use ($cards, $p, $escalate): bool {
            $cost = $cards->crisisCost($cardId);
            $cost['AN'] = ($cost['AN'] ?? 0) + $escalate;
            foreach ($cost as $cur => $n) {
                if ($p['tokens'][$cur] < $n) { return false; }
            }
            return true;
        };
        $devote = function () use (&$rng, $p): bool {
            return count($p['devoted']) < 2 && Rng::int($rng, 3) === 0;
        };

        // 1. defensive: an affordable crisis about to fall, highest threat first
        $affordable = array_values(array_filter($state['crisisRow'], fn($s) => $affordCrisis($s['cardId'])));
        if ($kind === 'defensive') {
            $danger = array_values(array_filter($affordable, fn($s) => $s['pos'] <= 1));
            usort($danger, fn($a, $b) =>
                (int)$cards->crisis($b['cardId'])['threat'] <=> (int)$cards->crisis($a['cardId'])['threat']);
            if ($danger) {
                return ['spot' => $danger[0]['cardId'], 'devote' => $devote()];
            }
            // 2. defensive: redeem an affordable Fallen Hero (stops the +1/round)
            foreach ($state['fallen'] as $fh) {
                if (count($occ[$fh] ?? []) > 0) { continue; }
                $ok = true;
                foreach (Engine::CURRENCIES as $cur) {
                    if ($p['tokens'][$cur] < (int)$cards->fallen[$fh]['redeem_' . strtolower($cur)]) { $ok = false; break; }
                }
                if ($ok) { return ['spot' => $fh]; }
            }
        }
        // 3. best affordable crisis by trust
        if ($affordable) {
            usort($affordable, fn($a, $b) =>
                (int)$cards->crisis($b['cardId'])['trust'] <=> (int)$cards->crisis($a['cardId'])['trust']);
            return ['spot' => $affordable[0]['cardId'], 'devote' => $devote()];
        }
        // 4. rebuild a case file sometimes
        if (!$isPress && $p['caseFiles'] && !in_array('L03', $closed, true) && Rng::int($rng, 2) === 0) {
            $extraRg = in_array('CITYHALL', array_map(
                fn($id) => explode(':', $cards->fallen[$id]['ongoing_code'])[1], $state['fallen']), true) ? 1 : 0;
            foreach ($p['caseFiles'] as $cf) {
                $card = $cards->crisis($cf);
                $ok = true;
                foreach (Engine::CURRENCIES as $cur) {
                    $need = (int)$card['rebuild_' . strtolower($cur)] + ($cur === 'RG' ? $extraRg : 0);
                    if ($p['tokens'][$cur] < $need) { $ok = false; break; }
                }
                if ($ok) {
                    return ['spot' => 'L03', 'rebuild' => ['cardId' => $cf]];
                }
            }
        }
        // 5. play a Spin card sometimes (never during press: L09 excluded)
        if (!$isPress && $p['spinHand'] && !in_array('L09', $closed, true)
            && count($occ['L09'] ?? []) < 3 && Rng::int($rng, 3) === 0) {
            foreach ($p['spinHand'] as $sp) {
                $code = $cards->spin[$sp]['effect_code'];
                if (str_contains($code, 'RECALL')) { continue; }
                $spin = ['cardId' => $sp];
                if (str_contains($code, 'STEAL')) {
                    $others = array_values(array_filter(range(0, $state['playerCount'] - 1), fn($s) => $s !== $seat));
                    $spin['target'] = $others[Rng::int($rng, count($others))];
                }
                if (str_contains($code, 'SHUTTER')) {
                    $spin['shutterLoc'] = Engine::SHUTTER_CHOICES[Rng::int($rng, 3)];
                }
                return ['spot' => 'L09', 'spin' => $spin];
            }
        }
        // 6. an open rebuilt location (exercises owner benefits)
        $rebuiltOpen = [];
        foreach ($state['board']['rebuiltLocations'] as $rl) {
            if (count($occ['B' . $rl['cardId']] ?? []) < 1) { $rebuiltOpen[] = 'B' . $rl['cardId']; }
        }
        if ($rebuiltOpen && Rng::int($rng, 2) === 0) {
            return ['spot' => $rebuiltOpen[Rng::int($rng, count($rebuiltOpen))]];
        }
        // 7. gather toward a crisis (defensive: oldest first)
        $openBasic = [];
        foreach ($cards->locations as $id => $loc) {
            if ($id === 'L09' || in_array($id, $closed, true)) { continue; }
            $spaces = (int)$loc['spaces'];
            if ($spaces === 0 || count($occ[$id] ?? []) < $spaces) { $openBasic[] = $id; }
        }
        $pref = ['AN' => 'L05', 'IN' => 'L04', 'GR' => 'L06', 'RG' => 'L01', 'CO' => 'L07', 'IV' => 'L08'];
        $targets = $state['crisisRow'];
        usort($targets, fn($a, $b) => $a['pos'] <=> $b['pos']);
        if ($kind !== 'defensive') { $targets = array_reverse($targets); }
        foreach ($targets as $slot) {
            foreach ($cards->crisisCost($slot['cardId']) as $cur => $n) {
                if ($p['tokens'][$cur] < $n && in_array($pref[$cur], $openBasic, true)) {
                    $action = ['spot' => $pref[$cur]];
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
            if (in_array($id, $openBasic, true)) { return ['spot' => $id]; }
        }
        return ['spot' => 'L11']; // Intern Pool: always open (R16)
    }
}
