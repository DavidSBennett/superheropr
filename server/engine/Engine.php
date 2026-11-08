<?php
// Superhero P.R. rules engine. Pure state machine: no DB, no clock, no I/O.
// applyAction(state, cards, seat, action) -> ['ok'=>true, 'state'=>..., 'private'=>...]
//                                          | ['ok'=>false, 'error'=>CODE, 'detail'=>...]
// Phase 3: full rules. Rebuild + owner benefits, Press Room reassignment (R1),
// Spin cards, Fallen Heroes (R6: discarded after redemption), mandates and
// traitors with secret tip-offs (R8), 2-5 players, round-5 exec, forced-pass
// safeguard (R16, unreachable while the Intern Pool is open).
//
// Spot ids: L## basic location, CR## crisis in the row, FH## Fallen Hero in
// play, BCR## a rebuilt location (back of crisis CR##).

require_once __DIR__ . '/Rng.php';
require_once __DIR__ . '/CardSet.php';

final class Engine
{
    public const CURRENCIES = ['AN', 'IN', 'GR', 'RG', 'CO', 'IV'];
    public const EXECS_BY_COUNT = [2 => 5, 3 => 4, 4 => 3, 5 => 3];
    // R17: rebalanced 2026-11-07 from the draft 12/14/16/18, which simulation
    // showed ends ~99% of games by round 4. See docs/RULINGS.md.
    // R21: 3-5p raised again for Phase 3 traitor tip-off pressure.
    public const VILLAIN_MAX = [2 => 24, 3 => 34, 4 => 44, 5 => 48];
    public const COMPROMISED_BY_COUNT = [2 => 0, 3 => 1, 4 => 2, 5 => 2];
    // R20: the Compromised end-game bonus is +1 Trust per this many Villain
    // steps. Written as "+1 per step" when the track maxed at 12-18 (R17 made
    // that 24-48, where per-step hands traitors the game on points).
    public const COMPROMISED_TRUST_DIVISOR = 3; // R20
    public const ROUNDS = 8;
    public const ROUND5_EXTRA_EXEC = 1;
    public const MAX_FALLEN_IN_PLAY = 3;
    public const STARTING_SPIN = 2;
    public const SLICE_DISABLED_LOCATIONS = []; // Phase 3: everything enabled
    public const CLOSE_TARGETS = ['MEDIA' => 'L04', 'BUSINESS' => 'L05', 'NEIGHBORHOODS' => 'L06'];
    public const SHUTTER_CHOICES = ['L04', 'L05', 'L06'];
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

        // Firm Mandates (R-doc): deck of n+1 = fixed Compromised count by
        // player count + random Loyal mandates; one card stays undealt.
        $loyal = Rng::shuffle($rng, array_keys(array_filter($cards->mandates, fn($m) => $m['compromised'] !== '1')));
        $comp = array_keys(array_filter($cards->mandates, fn($m) => $m['compromised'] === '1'));
        $deck = array_merge(
            array_slice($comp, 0, self::COMPROMISED_BY_COUNT[$n]),
            array_slice($loyal, 0, $n + 1 - self::COMPROMISED_BY_COUNT[$n])
        );
        $deck = Rng::shuffle($rng, $deck);

        $spinDeck = Rng::shuffle($rng, array_keys($cards->spin));

        $players = [];
        foreach (array_values($userIds) as $seat => $uid) {
            $players[] = [
                'seat' => $seat,
                'userId' => $uid,
                'trust' => 0,
                'tokens' => array_fill_keys(self::CURRENCIES, 0),
                'execsTotal' => self::EXECS_BY_COUNT[$n],
                'execsLeft' => self::EXECS_BY_COUNT[$n],
                'passed' => false,            // R16 safeguard
                'caseFiles' => [],            // resolved, filed for rebuild (R3)
                'devoted' => [],
                'rebuilt' => [],
                'resolvedTypes' => [],
                'spinHand' => array_splice($spinDeck, 0, self::STARTING_SPIN), // SECRET
                'mandate' => array_shift($deck),                              // SECRET
                'tipOffUsedRound' => 0,                                       // SECRET
                'tipOffCount' => 0,                                           // SECRET
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

        $crisisDeck = Rng::shuffle($rng, array_keys($cards->crises));
        $row = [];
        for ($p = 0; $p < $n + 2; $p++) {
            $row[] = ['cardId' => array_shift($crisisDeck), 'pos' => $p];
        }

        $state = [
            'phase' => 'placement',           // placement | press | finished
            'round' => 1,
            'playerCount' => $n,
            'activeSeat' => $first,
            'firstPlayerSeat' => $first,
            'pendingFirstPlayerSeat' => null,
            'players' => $players,
            'crisisRow' => $row,
            'crisisDeck' => $crisisDeck,      // SECRET (order)
            'spinDeck' => $spinDeck,          // SECRET (order)
            'spinDiscard' => [],              // SECRET (list; count is public)
            'villain' => ['track' => 0, 'max' => self::VILLAIN_MAX[$n]],
            'fallen' => [],                   // in play (public)
            'fallenDiscard' => [],            // redeemed, gone for good (R6)
            'board' => [
                'occupancy' => [],            // spotId => [seat, ...]
                'closedThisRound' => [],
                'closedNextRound' => [],
                'rebuiltLocations' => [],     // [{cardId, owner}]
            ],
            'pressQueue' => [],               // press phase: [{seat}] in space order
            'pending' => null,                // scout awaiting order
            'stats' => ['falls' => 0, 'villain' => ['threat' => 0, 'fallout' => 0, 'fallen' => 0, 'penalty' => 0, 'spin' => 0, 'tipoff' => 0]],
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
            switch ($type) {
                case 'PLACE':
                    if ($state['phase'] !== 'placement') { return self::err('WRONG_PHASE'); }
                    if ($state['activeSeat'] !== $seat) { return self::err('NOT_YOUR_TURN'); }
                    return self::place($state, $cards, $seat, $action);
                case 'PRESS_REASSIGN':
                    if ($state['phase'] !== 'press') { return self::err('WRONG_PHASE'); }
                    if (($state['pressQueue'][0]['seat'] ?? null) !== $seat) { return self::err('NOT_YOUR_TURN'); }
                    return self::pressReassign($state, $cards, $seat, $action);
                case 'PASS':
                    if ($state['phase'] !== 'placement') { return self::err('WRONG_PHASE'); }
                    if ($state['activeSeat'] !== $seat) { return self::err('NOT_YOUR_TURN'); }
                    // R16 safeguard: unreachable while the Intern Pool is open.
                    if (self::hasLegalPlacement($state, $cards, $seat)) {
                        return self::err('MUST_PLACE', 'a legal placement exists');
                    }
                    $state['players'][$seat]['passed'] = true;
                    self::log($state, $seat, 'has no legal placement and passes for the round.');
                    self::advanceAfterPlacement($state, $cards);
                    return ['ok' => true, 'state' => $state];
                default:
                    return self::err('UNKNOWN_ACTION', $type);
            }
        } catch (Throwable $e) {
            return self::err('ENGINE_ERROR', $e->getMessage());
        }
    }

    private static function err(string $code, string $detail = ''): array
    {
        return ['ok' => false, 'error' => $code, 'detail' => $detail];
    }

    // -------------------------------------------------------- fallen disrupts

    /** @return string[] active DISRUPT targets, e.g. ['MEDIA','COST'] */
    private static function disrupts(array $state, CardSet $cards): array
    {
        $out = [];
        foreach ($state['fallen'] as $id) {
            $code = $cards->fallen[$id]['ongoing_code']; // DISRUPT:X
            $out[] = explode(':', $code)[1];
        }
        return $out;
    }

    /** Locations closed right now: fallout closures, shuttered, disrupted. */
    private static function closedLocations(array $state, CardSet $cards): array
    {
        $closed = $state['board']['closedThisRound'];
        $d = self::disrupts($state, $cards);
        if (in_array('CITYHALL', $d, true)) { $closed[] = 'L01'; $closed[] = 'L02'; }
        if (in_array('SPIN', $d, true)) { $closed[] = 'L09'; }
        return $closed;
    }

    // ----------------------------------------------------------- placement

    private static function place(array $state, CardSet $cards, int $seat, array $action): array
    {
        if ($state['players'][$seat]['execsLeft'] < 1) {
            return self::err('NO_EXECS');
        }
        $spot = (string)($action['spot'] ?? '');
        $private = null;
        $res = self::resolveSpot($state, $cards, $seat, $spot, $action, $private);
        if ($res !== null) { return $res; }

        $state['players'][$seat]['execsLeft']--;
        $state['board']['occupancy'][$spot][] = $seat;

        $res = self::maybeTipOff($state, $cards, $seat, $action);
        if ($res !== null) { return $res; }

        if ($state['pending'] === null && $state['phase'] === 'placement') {
            self::advanceAfterPlacement($state, $cards);
        }
        $out = ['ok' => true, 'state' => $state];
        if ($private !== null) { $out['private'] = $private; }
        return $out;
    }

    private static function pressReassign(array $state, CardSet $cards, int $seat, array $action): array
    {
        $spot = (string)($action['spot'] ?? '');
        if ($spot === 'L09') {
            return self::err('BAD_SPOT', 'a Press Room exec moves to another space (R18)');
        }
        $private = null;
        $res = self::resolveSpot($state, $cards, $seat, $spot, $action, $private);
        if ($res !== null) { return $res; }

        // Move the exec: one instance out of the Press Room, onto the target.
        $occ = &$state['board']['occupancy'];
        $idx = array_search($seat, $occ['L09'] ?? [], true);
        if ($idx !== false) {
            array_splice($occ['L09'], $idx, 1);
        }
        $occ[$spot][] = $seat;

        $res = self::maybeTipOff($state, $cards, $seat, $action); // R8
        if ($res !== null) { return $res; }

        if ($state['pending'] === null && $state['phase'] === 'press') {
            self::pressAdvance($state, $cards);
        }
        $out = ['ok' => true, 'state' => $state];
        if ($private !== null) { $out['private'] = $private; }
        return $out;
    }

    /** Secret traitor tip-off riding on a placement (R8). @return array|null error */
    private static function maybeTipOff(array &$state, CardSet $cards, int $seat, array $action): ?array
    {
        if (empty($action['tipOff'])) { return null; }
        $p = &$state['players'][$seat];
        $mandate = $p['mandate'] !== null ? $cards->mandates[$p['mandate']] : null;
        if ($mandate === null || $mandate['compromised'] !== '1') {
            return self::err('NOT_COMPROMISED');
        }
        if ($p['tipOffUsedRound'] >= $state['round']) {
            return self::err('TIPOFF_USED', 'once per round');
        }
        $p['tipOffUsedRound'] = $state['round'];
        $p['tipOffCount']++;
        // The log never carries the seat; prg_actions records authorship server-side.
        self::log($state, null, 'An anonymous tip reached the villains.');
        self::villainAdd($state, $cards, 1, 'Anonymous tip', 'tipoff');
        return null;
    }

    /**
     * Resolve the effect of putting this seat's exec on $spot.
     * @return array|null error result, or null on success (mutates $state)
     */
    private static function resolveSpot(array &$state, CardSet $cards, int $seat, string $spot, array $action, ?array &$private): ?array
    {
        if (str_starts_with($spot, 'CR')) {
            return self::resolveCrisis($state, $cards, $seat, $spot, $action);
        }
        if (str_starts_with($spot, 'FH')) {
            return self::resolveRedeem($state, $cards, $seat, $spot);
        }
        if (str_starts_with($spot, 'B')) {
            return self::resolveRebuiltLocation($state, $cards, $seat, $spot);
        }
        if (str_starts_with($spot, 'L')) {
            return self::resolveBasicLocation($state, $cards, $seat, $spot, $action, $private);
        }
        return self::err('BAD_SPOT', $spot);
    }

    private static function resolveCrisis(array &$state, CardSet $cards, int $seat, string $spot, array $action): ?array
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
        if (in_array('COST', self::disrupts($state, $cards), true)) { // The Escalator
            $cost['AN'] = ($cost['AN'] ?? 0) + 2;
        }
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
                    self::drawSpin($state, $cards, $seat, $n);
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

    private static function resolveRedeem(array &$state, CardSet $cards, int $seat, string $spot): ?array
    {
        if (!in_array($spot, $state['fallen'], true)) {
            return self::err('NOT_IN_PLAY', $spot);
        }
        if (count($state['board']['occupancy'][$spot] ?? []) >= 1) {
            return self::err('LOCATION_FULL');
        }
        $card = $cards->fallen[$spot];
        $p = &$state['players'][$seat];
        $cost = [];
        foreach (self::CURRENCIES as $cur) {
            $n = (int)$card['redeem_' . strtolower($cur)];
            if ($n > 0) { $cost[$cur] = $n; }
        }
        foreach ($cost as $cur => $n) {
            if ($p['tokens'][$cur] < $n) {
                return self::err('CANT_AFFORD', "$cur x$n");
            }
        }
        foreach ($cost as $cur => $n) {
            $p['tokens'][$cur] -= $n;
        }
        $p['trust'] += (int)$card['trust'];
        $state['fallen'] = array_values(array_diff($state['fallen'], [$spot]));
        $state['fallenDiscard'][] = $spot; // R6: gone for good
        self::log($state, $seat, "redeemed {$card['name']} (+{$card['trust']} Trust). The hero departs for good.");
        return null;
    }

    private static function resolveRebuiltLocation(array &$state, CardSet $cards, int $seat, string $spot): ?array
    {
        $cardId = substr($spot, 1);
        $entry = null;
        foreach ($state['board']['rebuiltLocations'] as $rl) {
            if ($rl['cardId'] === $cardId) { $entry = $rl; break; }
        }
        if ($entry === null) {
            return self::err('BAD_SPOT', $spot);
        }
        if (count($state['board']['occupancy'][$spot] ?? []) >= 1) {
            return self::err('LOCATION_FULL');
        }
        $card = $cards->crisis($cardId);
        self::log($state, $seat, "placed an exec at {$card['back_name']}.");
        foreach (explode(';', $card['action_code']) as $op) {
            $res = self::applyGainLikeOp($state, $cards, $seat, $op, true);
            if ($res !== null) { return $res; }
        }
        if ($entry['owner'] !== $seat && !in_array('OWNER', self::disrupts($state, $cards), true)) {
            foreach (explode(';', $card['owner_code']) as $op) {
                $res = self::applyGainLikeOp($state, $cards, $entry['owner'], $op, true);
                if ($res !== null) { return $res; }
            }
            self::log($state, $entry['owner'], "collects the owner benefit: {$card['owner_text']}.");
        }
        return null;
    }

    private static function resolveBasicLocation(array &$state, CardSet $cards, int $seat, string $spot, array $action, ?array &$private): ?array
    {
        $loc = $cards->locations[$spot] ?? null;
        if ($loc === null) {
            return self::err('BAD_SPOT', $spot);
        }
        if (in_array($spot, self::closedLocations($state, $cards), true)) {
            return self::err('LOCATION_CLOSED');
        }
        $spaces = (int)$loc['spaces']; // 0 = unlimited
        $occ = count($state['board']['occupancy'][$spot] ?? []);
        if ($spaces > 0 && $occ >= $spaces) {
            return self::err('LOCATION_FULL');
        }
        self::log($state, $seat, "placed an exec at {$loc['name']}.");
        foreach (explode(';', $loc['action_code']) as $op) {
            [$name, $arg] = array_pad(explode(':', $op, 2), 2, '');
            switch ($name) {
                case 'GAIN':
                    $res = self::applyGainLikeOp($state, $cards, $seat, $op, $spot === 'L04');
                    if ($res !== null) { return $res; }
                    break;
                case 'FIRSTPLAYER':
                    $state['pendingFirstPlayerSeat'] = $seat;
                    self::log($state, $seat, 'takes the first player marker (effective next round).');
                    break;
                case 'TRADE': {
                    $trade = $action['trade'] ?? null;
                    if ($trade === null) { break; } // optional
                    $give = $trade['give'] ?? '';
                    $get = $trade['get'] ?? '';
                    $p = &$state['players'][$seat];
                    if (!in_array($give, self::CURRENCIES, true) || !in_array($get, self::CURRENCIES, true) || $give === $get) {
                        return self::err('BAD_TRADE');
                    }
                    if ($p['tokens'][$give] < 2) {
                        return self::err('CANT_AFFORD', "trade needs 2 $give");
                    }
                    $p['tokens'][$give] -= 2;
                    $p['tokens'][$get] += 1;
                    self::log($state, $seat, "trades 2 $give for 1 $get.");
                    break;
                }
                case 'SCOUT': {
                    $k = min((int)$arg, count($state['crisisDeck']));
                    if ($k < 1) {
                        self::log($state, $seat, 'finds the crisis deck empty.');
                        break;
                    }
                    $top = array_slice($state['crisisDeck'], 0, $k);
                    $state['pending'] = ['type' => 'scout', 'seat' => $seat, 'count' => $k, 'cards' => $top];
                    $private = ['scout' => $top];
                    self::log($state, $seat, "looks at the top $k crises in the deck.");
                    break;
                }
                case 'PLAYSPIN': {
                    $res = self::playSpin($state, $cards, $seat, $action['spin'] ?? null, $private);
                    if ($res !== null) { return $res; }
                    break;
                }
                case 'REASSIGN':
                    break; // handled at end of round from occupancy (R1)
                case 'REBUILD': {
                    $res = self::rebuild($state, $cards, $seat, $action['rebuild'] ?? null);
                    if ($res !== null) { return $res; }
                    break;
                }
                default:
                    throw new RuntimeException("unhandled location op $op");
            }
        }
        return null;
    }

    /** GAIN/TRUST ops shared by locations, rebuilt actions and owner benefits.
     *  $mediaDisruptable: IN gains here pay 1 fewer under The Dead Air Anchor. */
    private static function applyGainLikeOp(array &$state, CardSet $cards, int $seat, string $op, bool $mediaDisruptable = false): ?array
    {
        [$name, $arg] = array_pad(explode(':', $op, 2), 2, '');
        if ($name !== 'GAIN') {
            throw new RuntimeException("unhandled op $op");
        }
        $mediaHit = $mediaDisruptable && in_array('MEDIA', self::disrupts($state, $cards), true);
        $p = &$state['players'][$seat];
        foreach (explode(',', $arg) as $item) {
            if (preg_match('/^(AN|IN|GR|RG|CO|IV)(\d+)$/', $item, $m)) {
                $n = (int)$m[2];
                if ($m[1] === 'IN' && $mediaHit) {
                    $n = max(0, $n - 1);
                    self::log($state, $seat, 'The Dead Air Anchor cuts the Influencer payout by 1.');
                }
                if ($n > 0) {
                    $p['tokens'][$m[1]] += $n;
                    self::log($state, $seat, "gains $n {$m[1]}.");
                }
            } elseif (preg_match('/^SPIN(\d+)$/', $item, $m)) {
                self::drawSpin($state, $cards, $seat, (int)$m[1]);
            } elseif (preg_match('/^TRUST(\d+)$/', $item, $m)) {
                $p['trust'] += (int)$m[1];
                self::log($state, $seat, "gains {$m[1]} Trust.");
            } else {
                throw new RuntimeException("bad GAIN item $item");
            }
        }
        return null;
    }

    private static function rebuild(array &$state, CardSet $cards, int $seat, ?array $payload): ?array
    {
        $cardId = (string)($payload['cardId'] ?? '');
        $p = &$state['players'][$seat];
        if (!in_array($cardId, $p['caseFiles'], true)) {
            return self::err('NOT_IN_CASE_FILES', $cardId);
        }
        $card = $cards->crisis($cardId);
        $cost = [];
        foreach (self::CURRENCIES as $cur) {
            $n = (int)$card['rebuild_' . strtolower($cur)];
            if ($n > 0) { $cost[$cur] = $n; }
        }
        if (in_array('CITYHALL', self::disrupts($state, $cards), true)) { // The Gridlock
            $cost['RG'] = ($cost['RG'] ?? 0) + 1;
        }
        foreach ($cost as $cur => $n) {
            if ($p['tokens'][$cur] < $n) {
                return self::err('CANT_AFFORD', "$cur x$n");
            }
        }
        foreach ($cost as $cur => $n) {
            $p['tokens'][$cur] -= $n;
        }
        $p['trust'] += (int)$card['rebuild_trust'];
        $p['caseFiles'] = array_values(array_diff($p['caseFiles'], [$cardId]));
        $p['rebuilt'][] = $cardId;
        $state['board']['rebuiltLocations'][] = ['cardId' => $cardId, 'owner' => $seat];
        self::log($state, $seat, "rebuilt {$card['name']} into {$card['back_name']} (+{$card['rebuild_trust']} Trust).");
        return null;
    }

    // ---------------------------------------------------------------- spin

    private static function drawSpin(array &$state, CardSet $cards, int $seat, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            if (!$state['spinDeck']) {
                if (!$state['spinDiscard']) {
                    self::log($state, $seat, 'finds the Spin deck empty.');
                    return;
                }
                $state['spinDeck'] = Rng::shuffle($state['rng'], $state['spinDiscard']);
                $state['spinDiscard'] = [];
                self::log($state, null, 'The Spin discards are reshuffled into a new deck.');
            }
            $state['players'][$seat]['spinHand'][] = array_shift($state['spinDeck']);
            self::log($state, $seat, 'draws a Spin card.');
        }
    }

    private static function playSpin(array &$state, CardSet $cards, int $seat, ?array $spin, ?array &$private): ?array
    {
        $cardId = (string)($spin['cardId'] ?? '');
        $p = &$state['players'][$seat];
        if (!in_array($cardId, $p['spinHand'], true)) {
            return self::err('NOT_IN_HAND', $cardId);
        }
        $card = $cards->spin[$cardId];
        $p['spinHand'] = array_values(array_diff($p['spinHand'], [$cardId]));
        $state['spinDiscard'][] = $cardId;
        self::log($state, $seat, "plays {$card['name']} ({$card['kind']}): {$card['effect_text']}");

        foreach (explode(';', $card['effect_code']) as $op) {
            $parts = explode(':', $op);
            switch ($parts[0]) {
                case 'GAIN':
                    self::applyGainLikeOp($state, $cards, $seat, $op);
                    break;
                case 'SCOUT': {
                    $k = min((int)$parts[1], count($state['crisisDeck']));
                    if ($k < 1) { break; }
                    $top = array_slice($state['crisisDeck'], 0, $k);
                    $state['pending'] = ['type' => 'scout', 'seat' => $seat, 'count' => $k, 'cards' => $top];
                    $private = ['scout' => $top];
                    break;
                }
                case 'RECALL': {
                    $recallSpot = (string)($spin['recallSpot'] ?? '');
                    if (str_starts_with($recallSpot, 'CR') || str_starts_with($recallSpot, 'FH')) {
                        return self::err('BAD_RECALL', 'not from a crisis or Fallen Hero');
                    }
                    $occ = &$state['board']['occupancy'];
                    $idx = array_search($seat, $occ[$recallSpot] ?? [], true);
                    if ($idx === false) {
                        return self::err('BAD_RECALL', 'no exec of yours there');
                    }
                    array_splice($occ[$recallSpot], $idx, 1);
                    $p['execsLeft'] = min($p['execsTotal'], $p['execsLeft'] + 1);
                    self::log($state, $seat, 'recalls an exec; the space reopens.');
                    break;
                }
                case 'STEAL': {
                    $target = $spin['target'] ?? null;
                    if (!is_int($target) || $target === $seat || !isset($state['players'][$target])) {
                        return self::err('BAD_TARGET');
                    }
                    $cur = $parts[1];
                    $n = min((int)$parts[2], $state['players'][$target]['tokens'][$cur]);
                    $state['players'][$target]['tokens'][$cur] -= $n;
                    $p['tokens'][$cur] += $n;
                    self::log($state, $seat, "takes $n $cur from seat $target.");
                    break;
                }
                case 'OPPLOSE': {
                    $cur = $parts[1];
                    foreach ($state['players'] as &$o) {
                        if ($o['seat'] === $seat) { continue; }
                        $lost = min((int)$parts[2], $o['tokens'][$cur]);
                        $o['tokens'][$cur] -= $lost;
                        if ($lost > 0) {
                            self::log($state, $o['seat'], "loses $lost $cur.");
                        }
                    }
                    unset($o);
                    break;
                }
                case 'SHUTTER': {
                    $loc = (string)($spin['shutterLoc'] ?? '');
                    if (!in_array($loc, self::SHUTTER_CHOICES, true)) {
                        return self::err('BAD_SHUTTER', 'choose L04, L05 or L06');
                    }
                    $state['board']['closedThisRound'][] = $loc;
                    self::log($state, $seat, "closes {$cards->locations[$loc]['name']} for the rest of the round.");
                    break;
                }
                case 'RICH':
                case 'TRUSTALL':
                case 'TRUSTLEAD':
                case 'VILLAIN':
                    self::execFalloutOp($state, $cards, $op, 'spin');
                    if ($state['phase'] === 'finished') { return null; }
                    break;
                default:
                    throw new RuntimeException("unhandled spin op $op");
            }
        }
        return null;
    }

    private static function scoutOrder(array $state, CardSet $cards, int $seat, array $action): array
    {
        $pend = $state['pending'];
        $k = $pend['count'];
        $order = $action['order'] ?? null;
        $sorted = is_array($order) ? array_values($order) : null;
        $check = $sorted === null ? null : array_unique(array_map('intval', $sorted));
        if ($check === null || count($check) !== $k || min($check) !== 0 || max($check) !== $k - 1) {
            return self::err('BAD_SCOUT_ORDER', 'need a permutation of 0..' . ($k - 1));
        }
        $newTop = [];
        foreach ($sorted as $i) {
            $newTop[] = $pend['cards'][(int)$i];
        }
        array_splice($state['crisisDeck'], 0, $k, $newTop);
        $state['pending'] = null;
        self::log($state, $seat, 'puts them back in an order of their choosing.');
        if ($state['phase'] === 'press') {
            self::pressAdvance($state, $cards);
        } else {
            self::advanceAfterPlacement($state, $cards);
        }
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
        // Placement done: Press Room reassignment, in space order (R1).
        $press = $state['board']['occupancy']['L09'] ?? [];
        if ($press) {
            $state['phase'] = 'press';
            $state['pressQueue'] = array_map(fn($s) => ['seat' => $s], $press);
            $state['activeSeat'] = $press[0];
            self::log($state, null, 'Press Room reassignment: those execs move to other open spaces.');
            return;
        }
        self::finishRound($state, $cards);
    }

    private static function pressAdvance(array &$state, CardSet $cards): void
    {
        array_shift($state['pressQueue']);
        if ($state['pressQueue']) {
            $state['activeSeat'] = $state['pressQueue'][0]['seat'];
            return;
        }
        $state['phase'] = 'placement';
        self::finishRound($state, $cards);
    }

    /** R16 helper: any open spot or affordable crisis/redeem for this seat? */
    public static function hasLegalPlacement(array $state, CardSet $cards, int $seat): bool
    {
        $closed = self::closedLocations($state, $cards);
        foreach ($cards->locations as $id => $loc) {
            if (in_array($id, $closed, true)) { continue; }
            $spaces = (int)$loc['spaces'];
            if ($spaces === 0 || count($state['board']['occupancy'][$id] ?? []) < $spaces) {
                return true;
            }
        }
        foreach ($state['board']['rebuiltLocations'] as $rl) {
            if (count($state['board']['occupancy']['B' . $rl['cardId']] ?? []) < 1) {
                return true;
            }
        }
        $tokens = $state['players'][$seat]['tokens'];
        $escalate = in_array('COST', self::disrupts($state, $cards), true) ? 2 : 0;
        foreach ($state['crisisRow'] as $slot) {
            $cost = $cards->crisisCost($slot['cardId']);
            $cost['AN'] = ($cost['AN'] ?? 0) + $escalate;
            $ok = true;
            foreach ($cost as $cur => $n) {
                if ($tokens[$cur] < $n) { $ok = false; break; }
            }
            if ($ok) { return true; }
        }
        return false;
    }

    private static function finishRound(array &$state, CardSet $cards): void
    {
        // Reset.
        $state['board']['occupancy'] = [];
        $state['pressQueue'] = [];
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

        // Income (+1 per devoted card; The Embezzler caps it at the first 2).
        $cap = in_array('INCOME', self::disrupts($state, $cards), true) ? 2 : PHP_INT_MAX;
        foreach ($state['players'] as &$p) {
            foreach (array_slice($p['devoted'], 0, $cap) as $cardId) {
                $cur = $cards->crisis($cardId)['devote_currency'];
                $p['tokens'][$cur] += 1;
            }
            if ($cap !== PHP_INT_MAX && count($p['devoted']) > $cap) {
                self::log($state, $p['seat'], 'The Embezzler skims the rest of the devotion income.');
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
        // Each Fallen Hero raises the Villain track at the start of the round.
        foreach ($state['fallen'] as $id) {
            self::villainAdd($state, $cards, 1, "{$cards->fallen[$id]['name']} at large", 'fallen');
            if ($state['phase'] === 'finished') { return; }
        }
        $state['activeSeat'] = $state['firstPlayerSeat'];
        self::log($state, null, "Round {$state['round']} begins.");
    }

    // ------------------------------------------------------------ effects

    private static function applyFallout(array &$state, CardSet $cards, string $code): void
    {
        foreach (explode(';', $code) as $op) {
            self::execFalloutOp($state, $cards, $op, 'fallout');
            if ($state['phase'] === 'finished') { return; }
        }
    }

    private static function execFalloutOp(array &$state, CardSet $cards, string $op, string $src): void
    {
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
                self::villainAdd($state, $cards, (int)$parts[1], 'Villain surge', $src);
                break;
            case 'FALLEN':
                for ($i = 0; $i < (int)$parts[1]; $i++) {
                    self::heroTurns($state, $cards);
                    if ($state['phase'] === 'finished') { return; }
                }
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
    }

    /** FALLEN:1 - roll 1 of the 6; unavailable (in play, discarded, or 3 out) = +2 Villain. */
    private static function heroTurns(array &$state, CardSet $cards): void
    {
        $all = array_keys($cards->fallen);
        sort($all);
        if (count($state['fallen']) >= self::MAX_FALLEN_IN_PLAY) {
            self::log($state, null, 'A hero turns, but the villains are already at full strength.');
            self::villainAdd($state, $cards, 2, 'Hero turned (3 already out)', 'fallen');
            return;
        }
        $pick = $all[Rng::int($state['rng'], count($all))];
        if (in_array($pick, $state['fallen'], true) || in_array($pick, $state['fallenDiscard'], true)) {
            self::log($state, null, 'A hero wavers but holds on. The villains grow bolder.');
            self::villainAdd($state, $cards, 2, 'Hero turned (unavailable)', 'fallen');
            return;
        }
        $state['fallen'][] = $pick;
        $card = $cards->fallen[$pick];
        self::log($state, null, "{$card['name']} has turned! {$card['ongoing_text']}");
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
        $mandates = [];
        $compSeats = [];
        foreach ($state['players'] as $p) {
            $tokens = array_sum($p['tokens']);
            $mandate = $cards->mandates[$p['mandate']];
            $bonus = 0;
            if ($mandate['compromised'] === '1') {
                $div = $state['compromisedDivisor'] ?? self::COMPROMISED_TRUST_DIVISOR;
                $bonus = intdiv($state['villain']['track'], max(1, $div)) * (int)$mandate['bonus_per'];
                $compSeats[] = $p['seat'];
            } elseif ($mandate['condition_code'] === 'REBUILT') {
                $bonus = count($p['rebuilt']) * (int)$mandate['bonus_per'];
            } else {
                foreach (explode(',', $mandate['condition_code']) as $type) {
                    $bonus += ($p['resolvedTypes'][$type] ?? 0) * (int)$mandate['bonus_per'];
                }
            }
            $scores[$p['seat']] = [
                'trust' => $p['trust'],
                'mandateBonus' => $bonus,
                'tokenBonus' => intdiv($tokens, 3),
                'tokens' => $tokens,
                'total' => $p['trust'] + $bonus + intdiv($tokens, 3),
            ];
            $mandates[] = [
                'seat' => $p['seat'],
                'mandateId' => $p['mandate'],
                'name' => $mandate['name'],
                'compromised' => $mandate['compromised'] === '1',
                'tipOffs' => $p['tipOffCount'],
            ];
        }
        if ($cause === 'villainmax' && $compSeats) {
            $winners = $compSeats; // traitors win together
        } else {
            $best = max(array_column($scores, 'total'));
            $winners = array_keys(array_filter($scores, fn($s) => $s['total'] === $best));
            if (count($winners) > 1) { // tiebreak: most leftover tokens; then shared (R12)
                $bestTok = max(array_map(fn($s) => $scores[$s]['tokens'], $winners));
                $winners = array_values(array_filter($winners, fn($s) => $scores[$s]['tokens'] === $bestTok));
            }
        }
        $state['result'] = [
            'cause' => $cause,
            'scores' => $scores,
            'winners' => $winners,
            'mandates' => $mandates, // the end-of-game reveal
            'traitorWin' => $cause === 'villainmax' && (bool)$compSeats,
        ];
        $state['phase'] = 'finished';
        $state['pending'] = null;
        self::log($state, null, $cause === 'villainmax'
            ? ($compSeats ? 'The Villain track maxed out. The city falls to the Compromised firms!'
                          : 'The Villain track maxed out. The game ends at once.')
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
        $state['spinDeckCount'] = count($state['spinDeck']);
        $state['spinDiscardCount'] = count($state['spinDiscard']);
        unset($state['crisisDeck'], $state['spinDeck'], $state['spinDiscard']);
        foreach ($state['players'] as &$p) {
            $p['spinCount'] = count($p['spinHand']);
            if ($p['seat'] !== $viewerSeat) {
                unset($p['spinHand'], $p['mandate'], $p['tipOffUsedRound'], $p['tipOffCount']);
            }
        }
        unset($p);
        if ($state['pending'] !== null && ($state['pending']['seat'] ?? null) !== $viewerSeat) {
            unset($state['pending']['cards']);
        }
        return $state;
    }
}
