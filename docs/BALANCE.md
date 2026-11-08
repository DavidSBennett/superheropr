# Balance report - Phase 4 (2026-11-07)

Method: server/cli/simulate.php, the same engine the site runs, seeded and
replay-deterministic. "Defensive" bots firefight the two oldest row slots,
redeem Fallen Heroes, rebuild, play Spin, and (when Compromised) tip off at
every legal opportunity about half the time. Bots have no social deduction:
loyal bots never react to tip-off patterns, traitor bots never hold back. That
makes every traitor number below a worst case, and the villain numbers a
pessimistic bound on competent human play.

All runs use the shipped constants: Villain maxima 24/34/44/48 (R21),
Compromised bonus +1 Trust per 3 full steps (R20), 8 rounds, round-5 exec.

## Headline table (2000 games per row, defensive bots)

| Players | Villain max | Games ending on Villain max | Mean end round | Mean falls | Traitor wins (any path) |
|---|---|---|---|---|---|
| 2 | 24 | 62% | 7.16 | 6.2 | n/a (no traitor at 2p) |
| 3 | 34 | 72% | 7.10 | 7.3 | 67% |
| 4 | 44 | 78% | 7.21 | 8.6 | 91% |
| 5 | 48 | 81% | 7.21 | 9.4 | 93% |

## Where the Villain track comes from (mean per game, capped runs)

| Players | Threat (falls) | Fallout surges | Fallen Heroes | Penalties | Spin sabotage | Tip-offs |
|---|---|---|---|---|---|---|
| 2 | 14.8 | 2.7 | 1.7 | 0.4 | 3.0 | 0 |
| 3 | 17.5 | 3.1 | 2.2 | 1.0 | 4.0 | 5.1 |
| 4 | 20.7 | 3.5 | 2.8 | 1.7 | 4.3 | 10.3 |
| 5 | 22.3 | 3.7 | 3.3 | 2.6 | 4.8 | 10.8 |

Threat from fallen crises stays the dominant driver (60-66%); tip-offs are
the second force at 4-5p where two traitors are usually dealt.

## Natural accumulation with no cap (1000 games, villain max disabled)

| Players | Mean track after 8 full rounds | Tip-offs included |
|---|---|---|
| 2 | 25.8 | 0 |
| 3 | 38.6 | 6.1 |
| 4 | 50.5 | 12.0 |
| 5 | 55.5 | 12.4 |

The caps sit at 86-93% of these means: close games by design.

## Turn-order fairness

Win share by position from the first player (2000 games per count):

- 2p: P1 47% / P2 53%
- 3p: 32 / 33 / 35
- 4p: 36 / 40 / 38 / 38 (shared wins count for each winner)
- 5p: 33 / 32 / 32 / 33 / 30

No meaningful first-player advantage; the escalating starting Analysts
(4/5/6/7/8) are doing their job. The slight late-seat edge at 2-3p is within
noise across seeds.

## Judgments and watch items

1. Villain pacing (R17/R21): games now run 7.1-7.2 rounds on average at every
   count and the track still ends most bot games. Because bots neither deter
   nor deduce, live rates will land lower. Healthy for now.
2. Traitor strength at 4-5p is the number to watch in live play: 91-93% of
   bot games end with a traitor win when traitors tip relentlessly and nobody
   adapts. Humans will do both. If live games at 4-5p keep ending villainmax,
   first levers in order: tip-off becomes once every OTHER round at 4-5p;
   or maxima 46/50; or only 1 Compromised card at 4p.
3. The Compromised points path (R20, per 3 steps) pays +10 to +15 in capped
   games: in line with loyal mandates (+9 to +15 typical). Settled unless
   live play says otherwise.
4. Scores rise with player count pressure: mean winning totals 73/59/50/48.
   Expected: more falls tax everyone, and more rivals contest the row.
5. 2p remains the gentlest mode (no traitor, cap 24, 62% bot villainmax).

Reproduce any row:

```bash
php server/cli/simulate.php --players=4 --games=2000 --seed=71 --bots=defensive
```

Flags: --vmax=N and --compdiv=N try alternatives without code changes;
--csv=path dumps one row per game for deeper cuts.
