<?php
// Superhero P.R. rules engine. Pure state machine: no DB, no clock, no I/O.
// applyAction(state, cards, seat, action) -> ['ok'=>true, 'state'=>..., 'private'=>...]
//                                          | ['ok'=>false, 'error'=>CODE, 'detail'=>...]
// Phase 2 vertical slice scope: basic locations, crisis row, resolve + devote
// (R3: decided at resolution), refresh with fallout, income, villain track,
// end scoring. Stubbed until Phase 3: Spin draws/plays (logged), Permits/
// rebuild (L03 disabled), Press Room (L09 disabled), mandates, Fallen Heroes
// (FALLEN:n fallout raises the Villain track 2n instead, per slice note).

require_once __DIR__ . '/Rng.php';
require_once __DIR__ . '/CardSet.php';

final class Engine
{
    public const CURRENCIES = ['AN', 'IN', 'GR', 'RG', 'CO', 'IV'];
    public const EXECS_BY_COUNT = [2 => 5, 3 => 4, 4 => 3, 5 => 3];
    // R17: rebalanced 2026-11-07 from the draft 12/14/16/18, which simulation
    // showed ends ~99% of games by round 4. See docs/RULINGS.md.
    public const VILLAIN_MAX = [2 => 24, 3 => 30, 4 => 36, 5 => 40];
    public const ROUNDS = 8;
    public const ROUND5_EXTRA_EXEC = 1;
    public const SLICE_DISABLED_LOCATIONS = ['L03', 'L09']; // Permits, Press Room (Phase 3)
    public const CLOSE_TARGETS = ['MEDIA' => 'L04', 'BUSINESS' => 'L05', 'NEIGHBORHOODS' => 'L06'];
    public const REWARD_EXTRA = [ // R13: the four known phrases until reward_code lands
        '1 Spin card' => ['SPIN' => 1],
        '2 Analysts' => ['AN' => 2],
        '1 Influencer' => ['IN' => 1],
        '1 Grassroots' => ['GR' => 1],
    ];

    // ---------------------------------------------------------------- setup

    /** @param int[] $userIds seat order = array order */
    public static function newGame(CardSet $cards, array $userIds, int $seed): array
    {
        $n = count($userIds);
        if ($n < 2 || $n > 5) {
            throw new InvalidArgumentException('player count must be 2-5');
        }
        $rng = Rng::seedState($seed);
        $first = Rng::int($rng, $n);

        $players = [];
        foreach (array_values($userIds) as $seat => $uid) {
            $players[] = [
                'seat' => $seat,
                'userId' => $uid,
                'trust' => 0,
                'tokens' => array_fill_keys(self::CURRENCIES, 0),
                'execsTotal' => self::EXECS_BY_COUNT[$n],
                'execsLeft' => self::EXECS_BY_COUNT[$n],
                'passed' => false,   // R16: forced pass, reset each round
                'caseFiles' => [],   // resolved, filed for rebuild (R3)
                'devoted' => [],
                'rebuilt' => [],     // Phase 3
                'resolvedTypes' => [],
                'spinHand' => [],    // Phase 3, SECRET
                'mandate' => null,   // Phase 3, SECRET
                'tipOffUsedRound' => 0, // Phase 3, SECRET
            ];
        }
        // Starting tokens: 4 AN for the first player, +1 per seat clockwise;
        // plus 1 IN and 1 GR each.
        for ($i = 0; $i < $n; $i++) {
            $seat = ($first + $i) % $n;
            $players[$seat]['tokens']['AN'] = 4 + $i;
            $players[$seat]['tokens']['IN'] = 1;
            $players[$seat]['tokens']['GR'] = 1;
        }

        $deck = Rng::shuffle($rng, array_keys($cards->crises));
        $rowSize = $n + 2;
        $row = [];
        for ($p = 0; $p < $rowSize; $p++) {
            $row[] = ['cardId' => array_shift($deck), 'pos' => $p];
        }

        $state = [
            'phase' => 'placement',
            'round' => 1,
            'playerCount' => $n,
            'activeSeat' => $first,
            'firstPlayerSeat' => $first,
            'pendingFirstPlayerSeat' => null,
            'players' => $players,
            'crisisRow' => $row,
            'crisisDeck' => $deck,            // SECRET (order)
            'villain' => ['track' => 0, 'max' => self::VILLAIN_MAX[$n]],
            'fallen' => [],                   // Phase 3
            'board' => [
                'occupancy' => [],            // spotId => [seat, ...]
                'closedThisRound' => [],
                'closedNextRound' => [],
                'rebuiltLocations' => [],     // Phase 3
            ],
            'pending' => null,                // e.g. scout awaiting order
            'stats' => ['falls' => 0, 'villain' => ['threat' => 0, 'fallout' => 0, 'fallen' => 0, 'penalty' => 0]],
            'log' => [],
            'result' => null,
            'rng' => $rng,                    // SECRET
        ];
        self::log($state, null, 'Game created. Round 1 begins.');
        self::log($state, $first, 'has the first player marker.');
        return $state;
    }

    // ---------------------------------------------------------------- apply

    public static function applyAction(array $state, CardSet $cards, int $seat, array $action): array
    {
        try {
            if ($state['phase'] === 'finished') {
                return self::err('GAME_OVER');
            }
            $type = $action['type'] ?? '';
            if ($state['pending'] !== null) {
                if ($type !== 'SCOUT_ORDER') {
                    return self::err('PENDING_CHOICE', 'resolve the pending choice first');
                }
                if ($state['pending']['seat'] !== $seat) {
                    return self::err('NOT_YOUR_CHOICE');
                }
                return self::scoutOrder($state, $cards, $seat, $action);
            }
            if ($type === 'PLACE' || $type === 'PASS') {
                if ($state['activeSeat'] !== $seat) {
                    return self::err('NOT_YOUR_TURN');
                }
            }
            if ($type === 'PLACE') {
                return self::place($state, $cards, $seat, $action);
            }
            if ($type === 'PASS') {
                // R16 (engine safety): you cannot pass while a legal placement
                // exists; with none, the turn must still be able to end.
                if (self::hasLegalPlacement($state, $cards, $seat)) {
                    return self::err('MUST_PLACE', 'a legal placement exists');
                }
                $state['players'][$seat]['passed'] = true;
                self::log($state, $seat, 'has no legal placement and passes for the round.');
                self::advanceAfterPlacement($state, $cards);
                return ['ok' => true, 'state' => $state];
            }
            return self::err('UNKNOWN_ACTION', $type);
        } catch (Throwable $e) {
            return self::err('ENGINE_ERROR', $e->getMessage());
        }
    }

    private static function err(string $code, string $detail = ''): array
    {
        return ['ok' => false, 'error' => $code, 'detail' => $detail];
    }

    // ----------------------------------------------------------- placement

    private static function place(array $state, CardSet $cards, int $seat, array $action): array
    {
        $p = &$state['players'][$seat];
        if ($p['execsLeft'] < 1) {
            return self::err('NO_EXECS');
        }
        $spot = (string)($action['spot'] ?? '');
        $private = null;

        if (str_starts_with($spot, 'CR')) {
            $res = self::placeOnCrisis($state, $cards, $seat, $spot, $action);
            if ($res !== null) { return $res; }
        } elseif (str_starts_with($spot, 'L')) {
            $res = self::placeOnLocation($state, $cards, $seat, $spot, $action, $private);
            if ($res !== null) { return $res; }
        } else {
            return self::err('BAD_SPOT', $spot);
        }

        $state['players'][$seat]['execsLeft']--;
        $state['board']['occupancy'][$spot][] = $seat;

        if ($state['pending'] === null && $state['phase'] !== 'finished') {
            self::advanceAfterPlacement($state, $cards);
        }
        $out = ['ok' => true, 'state' => $state];
        if ($private !== null) { $out['private'] = $private; }
        return $out;
    }

    /** @return array|null error result, or null on success (mutates $state) */
    private static function placeOnCrisis(array &$state, CardSet $cards, int $seat, string $spot, array $action): ?array
    {
        $idx = null;
        foreach ($state['crisisRow'] as $i => $slot) {
            if ($slot['cardId'] === $spot) { $idx = $i; break; }
        }
        if ($idx === null) {
            return self::err('CRISIS_NOT_IN_ROW', $spot);
        }
        $devote = $action['devote'] ?? null;
        if (!is_bool($devote)) {
            return self::err('DEVOTE_CHOICE_REQUIRED'); // R3
        }
        $card = $cards->crisis($spot);
        $cost = $cards->crisisCost($spot);
        $p = &$state['players'][$seat];
        foreach ($cost as $cur => $n) {
            if ($p['tokens'][$cur] < $n) {
                return self::err('CANT_AFFORD', "$cur x$n");
            }
        }
        foreach ($cost as $cur => $n) {
            $p['tokens'][$cur] -= $n;
        }
        $p['trust'] += (int)$card['trust'];
        $type = $card['type'];
        $p['resolvedTypes'][$type] = ($p['resolvedTypes'][$type] ?? 0) + 1;
        array_splice($state['crisisRow'], $idx, 1); // slot empties until refresh slides
        self::log($state, $seat, "resolved {$card['name']} (+{$card['trust']} Trust).");

        $extra = trim((string)$card['reward_extra']);
        if ($extra !== '') {
            foreach (self::REWARD_EXTRA[$extra] as $what => $n) {
                if ($what === 'SPIN') {
                    self::log($state, $seat, 'would draw a Spin card (Spin deck arrives in Phase 3).');
                } else {
                    $p['tokens'][$what] += $n;
                    self::log($state, $seat, "gains extra reward: $n $what.");
                }
            }
        }
        if ($devote) {
            $p['devoted'][] = $spot;
            self::log($state, $seat, "devoted {$card['name']} (+1 {$card['devote_currency']} each round).");
        } else {
            $p['caseFiles'][] = $spot;
            self::log($state, $seat, "filed {$card['name']} in their Case Files.");
        }
        return null;
    }

    /** @return array|null error result, or null on success (mutates $state) */
    private static function placeOnLocation(array &$state, CardSet $cards, int $seat, string $spot, array $action, ?array &$private): ?array
    {
        $loc = $cards->locations[$spot] ?? null;
        if ($loc === null) {
            return self::err('BAD_SPOT', $spot);
        }
        if (in_array($spot, self::SLICE_DISABLED_LOCATIONS, true)) {
            return self::err('LOCATION_DISABLED', 'available in Phase 3');
        }
        if (in_array($spot, $state['board']['closedThisRound'], true)) {
            return self::err('LOCATION_CLOSED');
        }
        $spaces = (int)$loc['spaces']; // 0 = unlimited
        $occ = count($state['board']['occupancy'][$spot] ?? []);
        if ($spaces > 0 && $occ >= $spaces) {
            return self::err('LOCATION_FULL');
        }
        self::log($state, $seat, "placed an exec at {$loc['name']}.");
        foreach (explode(';', $loc['action_code']) as $op) {
            $res = self::applyLocationOp($state, $cards, $seat, $op, $action, $private);
            if ($res !== null) { return $res; }
        }
        return null;
    }

    /** @return array|null error result, or null on success */
    private static function applyLocationOp(array &$state, CardSet $cards, int $seat, string $op, array $action, ?array &$private): ?array
    {
        $p = &$state['players'][$seat];
        [$name, $arg] = array_pad(explode(':', $op, 2), 2, '');
        switch ($name) {
            case 'GAIN':
                foreach (explode(',', $arg) as $item) {
                    if (preg_match('/^(AN|IN|GR|RG|CO|IV)(\d+)$/', $item, $m)) {
                        $p['tokens'][$m[1]] += (int)$m[2];
                        self::log($state, $seat, "gains {$m[2]} {$m[1]}.");
                    } elseif (preg_match('/^SPIN(\d+)$/', $item)) {
                        self::log($state, $seat, 'would draw a Spin card (Phase 3).');
                    } elseif (preg_match('/^TRUST(\d+)$/', $item, $m)) {
                        $p['trust'] += (int)$m[1];
                    } else {
                        throw new RuntimeException("bad GAIN item $item");
                    }
                }
                return null;
            case 'FIRSTPLAYER':
                $state['pendingFirstPlayerSeat'] = $seat;
                self::log($state, $seat, 'takes the first player marker (effective next round).');
                return null;
            case 'TRADE':
                $trade = $action['trade'] ?? null;
                if ($trade === null) { return null; } // optional
                $give = $trade['give'] ?? '';
                $get = $trade['get'] ?? '';
                if (!in_array($give, self::CURRENCIES, true) || !in_array($get, self::CURRENCIES, true) || $give === $get) {
                    return self::err('BAD_TRADE');
                }
                if ($p['tokens'][$give] < 2) {
                    return self::err('CANT_AFFORD', "trade needs 2 $give");
                }
                $p['tokens'][$give] -= 2;
                $p['tokens'][$get] += 1;
                self::log($state, $seat, "trades 2 $give for 1 $get.");
                return null;
            case 'SCOUT':
                $k = min((int)$arg, count($state['crisisDeck']));
                if ($k < 1) {
                    self::log($state, $seat, 'finds the crisis deck empty.');
                    return null;
                }
                $top = array_slice($state['crisisDeck'], 0, $k);
                $state['pending'] = ['type' => 'scout', 'seat' => $seat, 'count' => $k, 'cards' => $top];
                $private = ['scout' => $top];
                self::log($state, $seat, "looks at the top $k crises in the deck.");
                return null;
            default:
                throw new RuntimeException("unhandled location op $op");
        }
    }

    private static function scoutOrder(array $state, CardSet $cards, int $seat, array $action): array
    {
        $pend = $state['pending'];
        $k = $pend['count'];
        $order = $action['order'] ?? null;
        $sorted = is_array($order) ? array_values($order) : null;
        $check = $sorted === null ? null : array_unique(array_map('intval', $sorted));
        if ($check === null || count($check) !== $k || min($check) !== 0 || max($check) !== $k - 1) {
            return self::err('BAD_SCOUT_ORDER', "need a permutation of 0..".($k - 1));
        }
        $newTop = [];
        foreach ($sorted as $i) {
            $newTop[] = $pend['cards'][(int)$i];
        }
        array_splice($state['crisisDeck'], 0, $k, $newTop);
        $state['pending'] = null;
        self::log($state, $seat, 'puts them back in an order of their choosing.');
        self::advanceAfterPlacement($state, $cards);
        return ['ok' => true, 'state' => $state];
    }

    // --------------------------------------------------------- round flow

    private static function advanceAfterPlacement(array &$state, CardSet $cards): void
    {
        $n = $state['playerCount'];
        for ($i = 1; $i <= $n; $i++) {
            $seat = ($state['activeSeat'] + $i) % $n;
            $p = $state['players'][$seat];
            if ($p['execsLeft'] > 0 && !$p['passed']) {
                $state['activeSeat'] = $seat;
                return;
            }
        }
        self::endRound($state, $cards);
    }

    /** R16 helper: any open location or affordable crisis for this seat? */
    public static function hasLegalPlacement(array $state, CardSet $cards, int $seat): bool
    {
        foreach ($cards->locations as $id => $loc) {
            if (in_array($id, self::SLICE_DISABLED_LOCATIONS, true)) { continue; }
            if (in_array($id, $state['board']['closedThisRound'], true)) { continue; }
            $spaces = (int)$loc['spaces'];
            if ($spaces === 0 || count($state['board']['occupancy'][$id] ?? []) < $spaces) {
                return true;
            }
        }
        $tokens = $state['players'][$seat]['tokens'];
        foreach ($state['crisisRow'] as $slot) {
            $ok = true;
            foreach ($cards->crisisCost($slot['cardId']) as $cur => $n) {
                if ($tokens[$cur] < $n) { $ok = false; break; }
            }
            if ($ok) { return true; }
        }
        return false;
    }

    private static function endRound(array &$state, CardSet $cards): void
    {
        // Press Room reassignment would run here (Phase 3, L09 disabled).
        // Reset.
        $state['board']['occupancy'] = [];
        foreach ($state['players'] as &$p) {
            $p['execsLeft'] = $p['execsTotal'];
            $p['passed'] = false;
        }
        unset($p);

        // Refresh: unresolved crises in the two oldest positions fall.
        $falls = [];
        $keep = [];
        foreach ($state['crisisRow'] as $slot) {
            if ($slot['pos'] <= 1) { $falls[] = $slot['cardId']; } else { $keep[] = $slot['cardId']; }
        }
        foreach ($falls as $cardId) {
            $card = $cards->crisis($cardId);
            $state['stats']['falls']++;
            self::log($state, null, "{$card['name']} fell. {$card['fallout_text']}");
            self::applyFallout($state, $cards, $card['fallout_code']);
            if ($state['phase'] === 'finished') { return; }
            self::villainAdd($state, $cards, (int)$card['threat'], "Threat from {$card['name']}", 'threat');
            if ($state['phase'] === 'finished') { return; }
        }
        // Slide, then refill to playerCount + 2.
        $row = [];
        foreach ($keep as $i => $cardId) {
            $row[] = ['cardId' => $cardId, 'pos' => $i];
        }
        $want = $state['playerCount'] + 2;
        while (count($row) < $want && count($state['crisisDeck']) > 0) {
            $row[] = ['cardId' => array_shift($state['crisisDeck']), 'pos' => count($row)];
        }
        $state['crisisRow'] = $row;

        // Income.
        foreach ($state['players'] as &$p) {
            foreach ($p['devoted'] as $cardId) {
                $cur = $cards->crisis($cardId)['devote_currency'];
                $p['tokens'][$cur] += 1;
            }
        }
        unset($p);

        // End of game?
        if ($state['round'] >= self::ROUNDS) {
            self::endGame($state, $cards, 'round8');
            return;
        }

        // Next round start.
        $state['round']++;
        $state['board']['closedThisRound'] = $state['board']['closedNextRound'];
        $state['board']['closedNextRound'] = [];
        if ($state['pendingFirstPlayerSeat'] !== null) {
            $state['firstPlayerSeat'] = $state['pendingFirstPlayerSeat'];
            $state['pendingFirstPlayerSeat'] = null;
        }
        if ($state['round'] === 5) {
            foreach ($state['players'] as &$p) {
                $p['execsTotal'] += self::ROUND5_EXTRA_EXEC;
                $p['execsLeft'] = $p['execsTotal'];
            }
            unset($p);
            self::log($state, null, 'Round 5: every firm hires an extra exec.');
        }
        // Fallen Hero +1s would fire here (Phase 3).
        $state['activeSeat'] = $state['firstPlayerSeat'];
        self::log($state, null, "Round {$state['round']} begins.");
    }

    // ------------------------------------------------------------ effects

    private static function applyFallout(array &$state, CardSet $cards, string $code): void
    {
        foreach (explode(';', $code) as $op) {
            $parts = explode(':', $op);
            switch ($parts[0]) {
                case 'TAX': { // TAX:CUR:n:TRUST|VILLAIN
                    [, $cur, $n, $pen] = $parts;
                    $n = (int)$n;
                    foreach ($state['players'] as &$p) {
                        if ($p['tokens'][$cur] >= $n) {
                            $p['tokens'][$cur] -= $n;
                        } else {
                            $p['tokens'][$cur] = 0;
                            if ($pen === 'TRUST') {
                                $p['trust'] = max(0, $p['trust'] - 2); // R4
                                self::log($state, $p['seat'], "could not pay $n $cur and loses 2 Trust.");
                            } else {
                                self::log($state, $p['seat'], "could not pay $n $cur; the Villain track rises 1.");
                                self::villainAdd($state, $cards, 1, 'Fallout penalty', 'penalty');
                                if ($state['phase'] === 'finished') { return; }
                            }
                        }
                    }
                    unset($p);
                    break;
                }
                case 'RICH': { // RICH:CUR:n - most of CUR loses n; all tied lose
                    [, $cur, $n] = $parts;
                    $max = 0;
                    foreach ($state['players'] as $p) {
                        $max = max($max, $p['tokens'][$cur]);
                    }
                    if ($max < 1) { break; }
                    foreach ($state['players'] as &$p) {
                        if ($p['tokens'][$cur] === $max) {
                            $p['tokens'][$cur] = max(0, $max - (int)$n);
                            self::log($state, $p['seat'], "had the most $cur and loses $n.");
                        }
                    }
                    unset($p);
                    break;
                }
                case 'TRUSTALL': {
                    $n = (int)$parts[1];
                    foreach ($state['players'] as &$p) {
                        $p['trust'] = max(0, $p['trust'] - $n);
                    }
                    unset($p);
                    break;
                }
                case 'TRUSTLEAD': { // R7: all tied leaders lose
                    $n = (int)$parts[1];
                    $max = 0;
                    foreach ($state['players'] as $p) {
                        $max = max($max, $p['trust']);
                    }
                    if ($max < 1) { break; }
                    foreach ($state['players'] as &$p) {
                        if ($p['trust'] === $max) {
                            $p['trust'] = max(0, $p['trust'] - $n);
                            self::log($state, $p['seat'], "led on Trust and loses $n.");
                        }
                    }
                    unset($p);
                    break;
                }
                case 'VILLAIN':
                    self::villainAdd($state, $cards, (int)$parts[1], 'Fallout', 'fallout');
                    if ($state['phase'] === 'finished') { return; }
                    break;
                case 'FALLEN':
                    // Slice behavior: no Fallen Heroes yet; +2 Villain per turn instead.
                    self::log($state, null, 'A hero turns... (Fallen Heroes arrive in Phase 3; the Villain track rises 2 instead.)');
                    self::villainAdd($state, $cards, 2 * (int)$parts[1], 'Hero turned', 'fallen');
                    if ($state['phase'] === 'finished') { return; }
                    break;
                case 'CLOSE': {
                    $loc = self::CLOSE_TARGETS[$parts[1]] ?? null;
                    if ($loc === null) {
                        throw new RuntimeException("bad CLOSE target {$parts[1]}");
                    }
                    $state['board']['closedNextRound'][] = $loc;
                    break;
                }
                default:
                    throw new RuntimeException("unhandled fallout op $op");
            }
            if ($state['phase'] === 'finished') { return; }
        }
    }

    private static function villainAdd(array &$state, CardSet $cards, int $n, string $why, string $src = 'fallout'): void
    {
        if ($n <= 0) { return; }
        $state['stats']['villain'][$src] += $n;
        $v = &$state['villain'];
        $v['track'] = min($v['max'], $v['track'] + $n);
        self::log($state, null, "Villain track rises $n to {$v['track']}/{$v['max']} ($why).");
        if ($v['track'] >= $v['max']) {
            self::endGame($state, $cards, 'villainmax');
        }
    }

    // ------------------------------------------------------------ scoring

    private static function endGame(array &$state, CardSet $cards, string $cause): void
    {
        $scores = [];
        foreach ($state['players'] as $p) {
            $tokens = array_sum($p['tokens']);
            // Mandate bonuses land in Phase 3 (no mandates dealt in the slice).
            $scores[$p['seat']] = [
                'trust' => $p['trust'],
                'tokenBonus' => intdiv($tokens, 3),
                'tokens' => $tokens,
                'total' => $p['trust'] + intdiv($tokens, 3),
            ];
        }
        $best = max(array_column($scores, 'total'));
        $leaders = array_keys(array_filter($scores, fn($s) => $s['total'] === $best));
        if (count($leaders) > 1) { // tiebreak: most leftover tokens; then shared (R12)
            $bestTok = max(array_map(fn($s) => $scores[$s]['tokens'], $leaders));
            $leaders = array_values(array_filter($leaders, fn($s) => $scores[$s]['tokens'] === $bestTok));
        }
        $state['result'] = ['cause' => $cause, 'scores' => $scores, 'winners' => $leaders];
        $state['phase'] = 'finished';
        $state['pending'] = null;
        self::log($state, null, $cause === 'villainmax'
            ? 'The Villain track maxed out. The game ends at once.'
            : 'Round 8 is complete. Final scoring.');
    }

    // ------------------------------------------------------------- helpers

    private static function log(array &$state, ?int $seat, string $text): void
    {
        $state['log'][] = ['r' => $state['round'], 's' => $seat, 't' => $text];
        if (count($state['log']) > 250) {
            $state['log'] = array_slice($state['log'], -200);
        }
    }

    // ---------------------------------------------------------- filtering

    /** Strip server secrets; $viewerSeat null = spectator. */
    public static function filterForViewer(array $state, ?int $viewerSeat): array
    {
        unset($state['rng']);
        $state['crisisDeckCount'] = count($state['crisisDeck']);
        unset($state['crisisDeck']);
        foreach ($state['players'] as &$p) {
            $p['spinCount'] = count($p['spinHand']);
            if ($p['seat'] !== $viewerSeat) {
                unset($p['spinHand'], $p['mandate'], $p['tipOffUsedRound']);
            }
        }
        unset($p);
        if ($state['pending'] !== null && ($state['pending']['seat'] ?? null) !== $viewerSeat) {
            unset($state['pending']['cards']);
        }
        return $state;
    }
}
