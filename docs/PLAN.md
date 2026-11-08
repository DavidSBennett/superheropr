# Superhero PR - Phase 1 Implementation Plan

Status: Phases 1-3 delivered 2026-11-07. Rulings R1-R21 in docs/RULINGS.md
(R20/R21 applied under the balance mandate, veto-able). Still pending: The
Historians source (section 11) for the auth/chat/widget swap and the sync
cadence check. Remaining: Phase 4 (full balance report, polish).

## 1. Repository layout

```
data/            card CSVs + pr_game_cards.xlsx (source of truth)
server/
  engine/        pure PHP rules engine (no framework, no DB, no I/O)
  api/           thin HTTP endpoints: auth check, load, applyAction, save
  importer/      admin import page + validators
  cli/           simulate.php (headless balance runner)
  migrations/    numbered .sql files
client/          React + Vite + Tailwind source
dist-zips/       pre-built dist zip per delivery
docs/            this plan, rulings, upload instructions
```

## 2. MySQL schema (prefix prg_, coexists with The Historians)

```sql
CREATE TABLE prg_card_sets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(32) NOT NULL UNIQUE,      -- e.g. 2026-11-07-a
  imported_by INT UNSIGNED NOT NULL,        -- users.id (Historians table)
  imported_at DATETIME NOT NULL,
  notes VARCHAR(255) NOT NULL DEFAULT ''
);

CREATE TABLE prg_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_set_id INT UNSIGNED NOT NULL,
  kind ENUM('crisis','mandate','fallen','spin','location') NOT NULL,
  card_id VARCHAR(8) NOT NULL,              -- CR01, MD08, FH03, SP12, L04
  data JSON NOT NULL,                       -- full row from the CSV
  UNIQUE KEY uq_card (card_set_id, kind, card_id),
  FOREIGN KEY (card_set_id) REFERENCES prg_card_sets(id)
);

CREATE TABLE prg_games (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  join_code CHAR(6) NOT NULL UNIQUE,
  status ENUM('lobby','active','finished','abandoned') NOT NULL,
  card_set_id INT UNSIGNED NOT NULL,
  rng_seed BIGINT UNSIGNED NOT NULL,
  state_version INT UNSIGNED NOT NULL DEFAULT 0,
  state MEDIUMTEXT NOT NULL,                -- JSON snapshot, server master
  turn_user_id INT UNSIGNED NULL,           -- feeds the Your Games widget
  result JSON NULL,                         -- final scores, traitor reveal
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY k_turn (turn_user_id, status)
);

CREATE TABLE prg_game_players (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id INT UNSIGNED NOT NULL,
  seat TINYINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_seat (game_id, seat),
  UNIQUE KEY uq_user (game_id, user_id),
  FOREIGN KEY (game_id) REFERENCES prg_games(id)
);

CREATE TABLE prg_actions (                  -- append-only, never updated
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id INT UNSIGNED NOT NULL,
  seq INT UNSIGNED NOT NULL,                -- 1..n per game
  user_id INT UNSIGNED NOT NULL,
  action JSON NOT NULL,
  state_version_after INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_seq (game_id, seq),
  FOREIGN KEY (game_id) REFERENCES prg_games(id)
);
```

Replay guarantee: initial state is a pure function of (rng_seed, card_set,
seat/user list). Folding prg_actions.action in seq order through
applyAction reproduces every snapshot. Chat reuses the Historians chat tables
keyed by a game channel (exact shape pending their source).

## 3. Rules engine (server/engine/, plain PHP)

One pure entry point:

```php
Engine::applyAction(array $state, int $seat, array $action): array
// returns ['ok' => true, 'state' => $newState, 'events' => [...]]
// or      ['ok' => false, 'error' => 'NOT_YOUR_TURN' | 'CANT_AFFORD' | ...]
```

- No DB, no globals, no wall clock. RNG is a seeded xorshift stored inside
  $state['rng']; every draw advances it, so replays are exact.
- Card set is passed in once at game creation and frozen into the state
  (card ids only; full rows loaded per request from prg_cards).
- Phases: lobby -> placement -> press -> (auto round-end pipeline) ->
  placement ... -> finished. The round-end pipeline (reset, refresh with
  falls/fallout, income, end check, next round start: round-5 execs, Fallen
  Hero +1s, closures expiring) is deterministic and runs inside the engine
  with no player input; press needs input and is its own phase.
- Villain max is checked after every single increment; the first increment
  that hits max ends the game immediately (R5/R9).

### Action types (client submits intents; all choices ride in the payload)

| type | payload | notes |
|---|---|---|
| PLACE | spot (L/CR/FH id), devote? (bool, required when spot is a crisis - R3), tipOff?, spin? {cardId, target?, shutterLoc?}, rebuild? {cardId}, trade? {giveCur, getCur}, scoutOrder? [0-2 perm] | one atomic turn; incomplete choices = error, no state change |
| PRESS_REASSIGN | same shape as PLACE minus tipOff | only in press phase, only the owner of the next queued press exec (R1); devote flag applies here too when landing on a crisis |

There is no separate devote action: per R3 the devote-or-file choice rides on
the placement that resolves the crisis. Everything a player ever decides is one
of these two action types, which keeps the action log trivially replayable and
async play simple.

## 4. Effect-code catalog (one handler per opcode; unknown opcode = import failure)

Grammar: ops joined by ";". GAIN items joined by ",". CUR is AN IN GR RG CO IV.

| opcode | used by | semantics |
|---|---|---|
| GAIN:item,... (item = CURn, SPINn, TRUSTn) | locations, crisis actions, owner benefits, spin, rewards | acting player (or owner) gains tokens / draws Spin / gains Trust |
| TAX:CUR:n:TRUST or VILLAIN | fallout | every player loses n CUR; each who cannot pay in full loses 2 Trust / raises Villain 1 |
| RICH:CUR:n | fallout, spin attacks | player(s) with most CUR (and at least 1) lose n; all tied lose |
| TRUSTALL:n | fallout | every player loses n Trust (floor 0, R4) |
| TRUSTLEAD:n | fallout, spin attacks | Trust leader(s) lose n (R7) |
| VILLAIN:n | fallout, spin sabotage, tip-off | Villain track +n, max check each step |
| FALLEN:n | fallout | n random picks from the 6; in-play pick or 3 already out = +2 Villain instead |
| CLOSE:MEDIA / BUSINESS / NEIGHBORHOODS | fallout | that location closed next round |
| DISRUPT:MEDIA / CITYHALL / OWNER / SPIN / INCOME / COST | fallen ongoing | the six Fallen Hero effects, keyed by target |
| FIRSTPLAYER | Mayor's Office | take first player marker (blocked by DISRUPT:CITYHALL) |
| REBUILD | Permits | pay back cost (+1 RG under Gridlock), score rebuild_trust, place location |
| TRADE:n | Coalition Summit | up to n optional trades: pay 2 of one CUR, gain 1 of another (R2) |
| PLAYSPIN | Press Room | play 1 Spin card from hand (blocked by DISRUPT:SPIN) |
| REASSIGN | Press Room | marks the space for end-of-round reassignment (R1) |
| SCOUT:n | Newswire, spin | view top n of crisis deck, return in chosen order (private info) |
| RECALL:n | spin | return n of your execs from non-crisis spaces to your pool |
| STEAL:CUR:n | spin attacks | chosen opponent loses up to n CUR; you gain what they lose |
| OPPLOSE:CUR:n | spin attacks | every opponent loses up to n CUR |
| SHUTTER:COMMON | spin attacks | chosen common-currency location closed for the rest of this round |

Mandate condition_code: comma list of crisis types, REBUILT, or VILLAIN -
scored at game end only.

## 5. Game state JSON (server master copy)

```jsonc
{
  "v": 57, "phase": "placement", "round": 3,
  "activeSeat": 2, "firstPlayerSeat": 1, "pendingFirstPlayerSeat": null,
  "players": [{
    "seat": 0, "userId": 41, "trust": 12,
    "tokens": {"AN":3, "IN":1, "GR":0, "RG":0, "CO":2, "IV":1},
    "execsTotal": 4, "execsLeft": 2,
    "caseFiles": ["CR07"], "devoted": ["CR09"], "rebuilt": ["CR01"],
    "resolvedTypes": {"Financial":1, "Media":1},      // mandate scoring
    "spinHand": ["SP03","SP11"],                       // SECRET
    "mandate": "MD02",                                 // SECRET
    "tipOffUsedRound": 3                               // SECRET
  }],
  "crisisRow": [{"cardId":"CR12","pos":0}, ...],       // pos 0 = oldest
  "crisisDeck": ["CR31", ...],                         // SECRET (order)
  "spinDeck": ["SP22", ...], "spinDiscard": ["SP08"],  // deck order SECRET
  "villain": {"track": 5, "max": 16},
  "fallen": [{"cardId":"FH02","takenBy":null}],        // in play, open slot
  "fallenPool": ["FH01","FH03", ...],                  // pool membership public
  "board": {
    "occupancy": {"L04": [{"seat":1}], "CR12": [{"seat":0}]},
    "closedThisRound": ["L04"], "closedNextRound": [],
    "rebuiltLocations": [{"cardId":"CR01","owner":0}]
  },
  "pressQueue": [{"space":0,"seat":2}],                // press phase only
  "log": [{"seq":55,"text":"...","secret":false}],
  "rng": {"s0": 123456789, "s1": 987654321}            // SECRET
}
```

## 6. Per-viewer filtering (applied server-side before every response)

| field | what a non-owner viewer gets |
|---|---|
| players[i].spinHand | count only ("spinCount": 2) |
| players[i].mandate | removed entirely (revealed in result at game end) |
| players[i].tipOffUsedRound | removed; tip-offs appear in the log as "An anonymous tip reached the villains" with no seat |
| crisisDeck / spinDeck / spinDiscard order | counts only |
| SCOUT results | returned once, only to the scouting player, in the act response; never stored in shared state |
| rng | never sent to anyone |
| log entries flagged secret | authorship stripped or entry omitted per flag |

Spectators (if enabled later) get the strictest filter. The tip-off author is
only ever written to prg_actions (server side) and revealed in result.

## 7. PHP endpoints (server/api/, all JSON; session auth reused from The Historians)

| endpoint | method | request | response |
|---|---|---|---|
| games_create.php | POST | {playerCount 2-5} | {gameId, joinCode} |
| games_join.php | POST | {joinCode} | {gameId, seat} |
| games_start.php | POST | {gameId} | {version} (creator only, deals everything) |
| games_mine.php | GET | - | [{gameId, status, round, yourTurn, players}] for the Your Games widget |
| state.php | GET | ?game=ID&since=V | {unchanged:true} if version==V, else {version, state (filtered), you:{seat}} |
| act.php | POST | {gameId, expectedVersion, action} | {version, state} or 409 {error:"STALE", version, state} or 422 {error, detail} |
| admin/import.php | POST | multipart xlsx or 5 CSVs + version label | {ok, report:[...]} or {ok:false, errors:[...]} |
| admin/replay.php | GET | ?game=ID (admin) | action log + recomputed snapshots for debugging |

act.php concurrency: UPDATE prg_games SET state=?, state_version=state_version+1
WHERE id=? AND state_version=? - zero rows affected means stale; the client
re-renders from the returned fresh state and the user retries.

## 8. Sync approach (PROVISIONAL - finalized after I see The Historians source)

Per the constraints (no WebSockets, no long processes): short polling with a
version number and conditional responses.

- Poll state.php?since=V: every 3s while it is your turn or the tab is
  focused and the game is live; 10s otherwise; 60s when the tab is hidden
  (Page Visibility API). {unchanged:true} responses are ~30 bytes.
- Every mutating response carries the new version, so the actor never waits
  on a poll.
- "Your turn" rides on games_mine.php for the widget; same cadence The
  Historians uses there today.

I will match whatever The Historians already does for polling cadence,
session auth, and response envelopes once I have the source - section 11.

## 9. React component tree (client/)

```
App
+- AuthContext / RequireAuth (reused from The Historians)
+- routes
   +- LobbyPage            create / join by code
   +- GamePage
      +- useGameState()    polling hook: version, filtered state, act()
      +- BoardPane
      |  +- BasicLocationGrid > LocationSpace (occupancy, closed badge)
      |  +- RebuiltRow      > RebuiltLocation (owner ring, benefit tooltip)
      |  +- CrisisRow       > CrisisCard (cost icons+letters, trust, threat,
      |  |                    fallout tooltip, affordability highlight)
      |  +- FallenHeroRow   > FallenHeroCard (ongoing effect, redeem cost)
      |  +- VillainTrack    (steps, max marker, danger styling at 80%)
      +- PlayerRail         > PlayerPanel (trust, tokens, execs, case files,
      |                       devoted, rebuilt; self panel expanded)
      +- HandPane           SpinHand (self only) + MandateCard (self only)
      +- TurnBar            whose turn, legal-action hints, dialogs:
      |                     TradeDialog, SpinPlayDialog (target/shutter pick),
      |                     ScoutDialog (reorder), RebuildPicker,
      |                     TipOffToggle (Compromised only), PressReassign
      +- GameLog            public log, autoscroll
      +- ChatPanel          (reused)  /  Toasts (reused)
      +- SoundManager       Web Audio synth: place, resolve, fallout, villain
                            tick, your-turn chime; mute toggle persisted
```

Accessibility: WCAG AA tokens on dark background, no opacity-derived text
colors, every currency icon paired with its two-letter code, closed/occupied
states carried by icon + text, full keyboard play for placement.

## 10. Importer and simulation

Importer (admin only): accepts pr_game_cards.xlsx (sheets crises, mandates,
fallen_heroes) or the 5 CSVs. Validations, all fatal unless noted: exact
header names; expected row counts (48/9/6/30/11) with override checkbox;
ASCII-only; UTF-8 no BOM; CRLF; unique ids; enum fields (type, kind,
devote_currency); every *_code parses against the section 4 grammar;
rebuild_trust == ceil(weighted/2); reward_extra in the known set (R13);
threat-vs-weight formula check (warning only). Output: per-row error table.
Success writes a new prg_card_sets version; existing games keep their version.

Simulation (server/cli/simulate.php, same engine):
`php simulate.php --players=4 --games=5000 --seed=1 --bots=greedy,greedy,random,greedy`
Bots: random (uniform legal action) and greedy (1-ply: resolve affordable
crisis with best Trust/weight, else best token gain toward cheapest row
crisis; devotes duplicates; traitor bot tips off when behind). Outputs:
games.csv (one row per game: winner, trust per seat, villain final/max,
end cause, traitor dealt/won, rebuilds, falls) and summary.csv (by player
count: villain-max rate, mean round of max, trust mean/p25/p75 by seat,
first-player advantage). Targets to tune against: villain-max ends well under
half of no-traitor games; seat-1 advantage small; Trust curves rise smoothly.

## 11. What I need from you

1. The Historians source - before I freeze section 8 and reuse auth/chat:
   AuthContext/RequireAuth and login flow, the Your Games widget and its
   endpoint, chat tables + endpoints, toast component, one existing polling
   endpoint (envelope + cadence), users table schema, and the public_html
   layout it deploys to.
2. Approval or edits on docs/RULINGS.md (R1-R15).
3. Approval or edits on data/spin.csv (30 cards; effects use only section 4
   opcodes).

## 12. Phase map (unchanged from the brief)

- Phase 2 vertical slice: engine + importer + 2p game: basic locations,
  crisis row, resolve/devote, refresh, income, end scoring, polling UI.
- Phase 3 full rules: rebuild + owner benefits, Press Room, Spin, Fallen
  Heroes, Villain track, mandates/traitors, 3-5p, round-5 exec.
- Phase 4 simulation + balance report, sound, tooltips, accessibility pass.
- Every delivery: source, dist zip, migrations, upload instructions.
