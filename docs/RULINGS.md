# Rulings - Phase 1

All 15 rulings decided by David on 2026-11-07. R3 and R6 differ from the
original recommendation; their text below is the final rule. Everything else
was approved as recommended.

| Ruling | Decision |
|---|---|
| R1 | Approved: owner moves their own Press Room exec to any open space |
| R2 | Approved: pay 2 of one currency, gain 1 of another, once, optional |
| R3 | CHANGED: devote-or-file decided immediately on resolving the crisis |
| R4 | Approved: Trust never goes below 0 |
| R5 | Approved: no-traitor Villain max ends game, normal scoring |
| R6 | CHANGED: a redeemed Fallen Hero is discarded, not returned to pool |
| R7 | Approved: all tied Trust leaders lose |
| R8 | Approved: tip-off on any placement incl. reassignment and redemption |
| R9 | Approved: full round-8 end sequence runs; Villain max always wins out |
| R10 | Approved: no hand limit; reshuffle discards when the deck empties |
| R11 | Approved: one rebuild per Permits placement |
| R12 | Approved: shared victory on full tie |
| R13 | Approved: add reward_code column; importer maps the 4 phrases until then |
| R14 | Approved: pr_game_cards_1.xlsx is canonical |
| R15 | Approved: delivered CSV schema is canonical |
| R16 | PENDING: forced pass when no legal placement exists (engine safety) |

## Gameplay rulings

**R1. Press Room reassignment - who chooses, and what is legal?**
The doc says each Press Room exec "moves to another open space, in Press Room
space order" but not who picks the destination.
Recommendation: the exec's owner chooses, resolved one at a time in Press Room
space order (space 1 first). Any open basic location, rebuilt location, crisis,
or Fallen Hero is legal, under normal placement rules (crisis/redemption still
requires holding the full cost). The action resolves immediately. If no legal
destination exists, the exec returns to its owner with no effect. This mirrors
Waterdeep Harbor.

**R2. Coalition Summit trade direction.**
"Trade 2 tokens of one type for 1 of another, once."
Recommendation: after taking the CO, the player may once pay 2 tokens of a
single currency to the supply and take 1 token of any other currency. Optional.

**R3. Devotion timing. (DECIDED - differs from recommendation)**
The player decides the card's fate immediately upon resolving the crisis, as
part of the same action: either devote it (onto the player board, +1 of its
devotion currency every round from this round's income step) or file it in
Case Files, where its only future use is rebuilding at Permits. There is no
later free devote action. A filed card that is never rebuilt simply scores
nothing. The same choice applies when a crisis is resolved via a Press Room
reassignment.

**R4. Trust floor.**
Fallout and attacks can push Trust below 0.
Recommendation: Trust never goes below 0 at any point (LoW convention). A loss
that would go negative is lost to the floor, not banked.

**R5. Villain track maxes with no Compromised firm in the game.**
Always possible at 2 players (no Compromised card in the deck) and at 3-5
players when the Compromised card is the undealt one.
Recommendation: the game still ends immediately; run normal final scoring
(mandates, leftover tokens) and most Trust wins. The track then acts as a
shared timer rather than a loss condition. Alternative: everyone loses -
thematic but feels bad in a competitive 2p game.

**R6. Redeemed Fallen Hero - discarded or back in the pool? (DECIDED - differs
from recommendation)**
The design doc said both; David ruled: the card is discarded and never returns.
Implementation consequence: FALLEN:1 still picks 1 of the original 6 at random;
if the pick is in play OR already discarded, the Villain track rises 2 instead.
Redemptions therefore make later "hero turns" fallouts more likely to become
straight +2 Villain, which fits the escalation curve.

**R7. TRUSTLEAD ties.**
RICH explicitly hits all tied players; TRUSTLEAD does not say.
Recommendation: all players tied for most Trust lose the full amount, matching
RICH.

**R8. Tip-off scope.**
"Once per round, when placing an exec."
Recommendation: any exec placement by the Compromised player counts, including
a Press Room reassignment and a Fallen Hero redemption. Still once per round.

**R9. Round 8 end sequence.**
The sequence runs Press Room, reset, refresh (with fallout), income, then the
end check. So the final refresh can still max the Villain track after all round
8 placements.
Recommendation: run the full sequence as written; a Villain max at any moment,
including the final refresh, ends the game with the traitor win (or R5). This
keeps last-round crisis-ignoring dangerous, which matches "big crises hurt most
when ignored."

**R10. Spin deck limits.**
Recommendation: no hand limit. When the Spin deck empties, reshuffle the
discards. If both are empty, a draw does nothing.

**R11. Rebuilds per Permits placement.**
Recommendation: one exec placed at Permits rebuilds exactly one card.

**R12. Final tiebreak.**
After most leftover tokens, a tie can remain.
Recommendation: shared victory.

**R16. Forced pass (added during Phase 2, needs your confirmation).**
"You can't pass while you still have execs" can deadlock: in the slice (Permits
and Press Room disabled) 2 players x 5 execs is 10 placements against 8
location spaces plus however many crises are affordable; closures make it worse.
Implemented: a PASS action the server accepts only when the player has no legal
placement at all; the player sits out the rest of the round and their remaining
execs return at reset. The engine rejects a pass whenever any legal placement
exists. In the full game (unlimited Permits) this should almost never fire,
but it keeps the engine deadlock-free.

## Data rulings

**R13. reward_extra is free text.**
crises.csv carries "1 Spin card", "2 Analysts", "1 Influencer", "1 Grassroots".
Recommendation: add a reward_code column to the xlsx and CSV (GAIN:SPIN1,
GAIN:AN2, GAIN:IN1, GAIN:GR1). Until then the importer maps exactly these four
phrases and fails loudly on anything else (already enforced in the Phase 1
validation pass).

**R14. Which xlsx is the source of truth?**
pr_game_cards.xlsx has only the crises and mandates sheets; pr_game_cards_1.xlsx
adds the fallen_heroes sheet with the same crises/mandates content.
Recommendation (applied in repo, easy to swap): pr_game_cards_1.xlsx adopted as
canonical and stored as data/pr_game_cards.xlsx. Also, the *_excel.csv variants
carry a UTF-8 BOM, which violates the data rules; the plain variants (no BOM)
were adopted.

**R15. Crisis CSV schema vs design doc schema.**
The design doc schema omits reward_extra and rebuild_trust and includes flavor;
the delivered CSV is the reverse.
Recommendation: the delivered CSV schema is canonical. The importer validates
rebuild_trust == ceil(weighted rebuild cost / 2) rather than trusting it
(passes today for all 48 rows). A flavor column can be appended later without
engine changes.

## Non-rulings (engine semantics I will implement as stated, listed for transparency)

- Closures (CLOSE, SHUTTER, DISRUPT) block new placement only; they never evict
  an exec already on the space.
- The Gridlock closing Mayor's Office means the first player marker cannot move
  while it is in play.
- Income runs after round 8 (sequence step 6 precedes the end check), so
  leftover-token scoring includes final income.
- Starting Analysts escalate by seat order after the random first player: seats
  get 4, 5, 6, 7, 8 AN.
- The Escalator's +2 AN applies to the "must hold the full cost" placement
  check as well as the payment.
- Fallen Hero +1 Villain fires at the start of each round while in play,
  beginning the round after it enters (it enters during refresh).
