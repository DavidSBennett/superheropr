import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { get, post } from './api.js';
import { useAuth } from './auth.jsx';
import { sfx, isMuted, setMuted } from './audio.js';
import { Button, Chip, CostChips, CURRENCY_ORDER, Dialog, useToast } from './ui.jsx';

function costFrom(card, prefix) {
  const out = {};
  for (const c of CURRENCY_ORDER) {
    const n = +card[prefix + c.toLowerCase()];
    if (n > 0) out[c] = n;
  }
  return out;
}
function canAfford(tokens, cost) {
  return Object.entries(cost).every(([c, n]) => (tokens[c] || 0) >= n);
}
function addCost(cost, cur, n) {
  if (n <= 0) return cost;
  return { ...cost, [cur]: (cost[cur] || 0) + n };
}

// ------------------------------------------------------------ polling hook

function useGame(gameId) {
  const [data, setData] = useState(null);
  const [cards, setCards] = useState(null);
  const dataRef = useRef(null);
  const toast = useToast();

  useEffect(() => {
    get(`cards.php?game=${gameId}`).then(setCards).catch((e) => toast(e.message));
  }, [gameId]);

  const refresh = useCallback(async () => {
    const since = dataRef.current?.version ?? -1;
    try {
      const r = await get(`state.php?game=${gameId}&since=${since}`);
      if (!r.unchanged) {
        dataRef.current = r;
        setData(r);
      }
    } catch {
      /* transient poll failure: next tick retries */
    }
  }, [gameId]);

  useEffect(() => {
    dataRef.current = null;
    setData(null);
    refresh();
    let timer = null;
    const tick = () => {
      const d = dataRef.current;
      const mySeat = d?.you?.seat;
      const waitingOnMe =
        d?.state &&
        d.state.phase !== 'finished' &&
        (d.state.pending ? d.state.pending.seat === mySeat : d.state.activeSeat === mySeat);
      const ms = document.hidden ? 30000 : d?.status === 'lobby' ? 4000 : waitingOnMe ? 3000 : 5000;
      timer = setTimeout(async () => {
        await refresh();
        tick();
      }, ms);
    };
    tick();
    return () => clearTimeout(timer);
  }, [gameId, refresh]);

  const act = useCallback(
    async (action) => {
      const d = dataRef.current;
      try {
        const r = await post('act.php', { gameId, expectedVersion: d.version, action });
        const next = {
          ...d,
          version: r.version,
          state: r.state,
          status: r.state.phase === 'finished' ? 'finished' : d.status,
        };
        dataRef.current = next;
        setData(next);
        return r;
      } catch (e) {
        if (e.code === 'STALE' && e.payload.state) {
          const next = { ...d, version: e.payload.version, state: e.payload.state };
          dataRef.current = next;
          setData(next);
          toast('The game moved on; take another look.');
        } else {
          sfx.error();
          toast(e.message);
        }
        return null;
      }
    },
    [gameId, toast]
  );

  return { data, cards, act, refresh };
}

// ----------------------------------------------------------------- pieces

function VillainTrack({ villain }) {
  const pct = Math.round((villain.track / villain.max) * 100);
  const hot = villain.track >= villain.max * 0.75;
  return (
    <div className="min-w-40">
      <div className="mb-1 flex justify-between text-xs font-bold">
        <span>Villain track</span>
        <span className={hot ? 'text-rose-300' : ''}>
          {villain.track}/{villain.max}
        </span>
      </div>
      <div
        className="h-3 overflow-hidden rounded bg-[var(--panel-raised)]"
        role="img"
        aria-label={`Villain track at ${villain.track} of ${villain.max}`}
      >
        <div className={`h-full ${hot ? 'bg-rose-500' : 'bg-orange-400'}`} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

function CrisisCard({ card, slot, cost, affordable, clickable, onPick }) {
  const oldest = slot.pos <= 1;
  return (
    <button
      disabled={!clickable || !affordable}
      onClick={() => onPick(card.id)}
      title={`Fallout if it falls: ${card.fallout_text}`}
      className={`flex w-52 shrink-0 flex-col gap-1 rounded-lg border p-2 text-left ${
        oldest ? 'border-orange-400' : 'border-[var(--line)]'
      } bg-[var(--panel)] ${clickable && affordable ? 'cursor-pointer hover:bg-[var(--panel-raised)]' : ''}`}
    >
      <div className="flex items-start justify-between gap-1">
        <span className="text-sm font-bold leading-tight">{card.name}</span>
        <span className="rounded bg-indigo-300 px-1.5 text-sm font-black text-indigo-950">{card.trust}</span>
      </div>
      <div className="text-xs text-[var(--ink-soft)]">
        {card.type} - Threat {card.threat}
        {oldest && <span className="ml-1 font-bold text-orange-300">falls next refresh</span>}
      </div>
      <CostChips cost={cost} />
      {card.reward_extra && <div className="text-xs text-emerald-300">+ {card.reward_extra}</div>}
      {!affordable && clickable && <div className="text-xs text-[var(--ink-soft)]">cannot afford</div>}
    </button>
  );
}

function FallenCard({ card, cost, occupied, affordable, clickable, onPick }) {
  return (
    <button
      disabled={!clickable || !affordable || occupied}
      onClick={() => onPick(card.id)}
      className={`flex w-60 shrink-0 flex-col gap-1 rounded-lg border border-rose-400 bg-[var(--panel)] p-2 text-left ${
        clickable && affordable && !occupied ? 'cursor-pointer hover:bg-[var(--panel-raised)]' : ''
      }`}
    >
      <div className="flex items-start justify-between gap-1">
        <span className="text-sm font-black text-rose-300">{card.name}</span>
        <span className="rounded bg-indigo-300 px-1.5 text-sm font-black text-indigo-950">{card.trust}</span>
      </div>
      <div className="text-xs">{card.ongoing_text}</div>
      <div className="text-xs text-[var(--ink-soft)]">Redeem: <CostChips cost={cost} /></div>
      {occupied && <div className="text-xs font-bold">being redeemed</div>}
    </button>
  );
}

function LocationCard({ id, name, actionText, spaces, occupants, closed, note, names, clickable, onPick }) {
  const full = spaces > 0 && occupants.length >= spaces;
  const usable = clickable && !closed && !full;
  return (
    <button
      disabled={!usable}
      onClick={() => onPick(id)}
      className={`flex flex-col gap-1 rounded-lg border border-[var(--line)] bg-[var(--panel)] p-2 text-left ${
        usable ? 'cursor-pointer hover:bg-[var(--panel-raised)]' : ''
      }`}
    >
      <div className="flex items-center justify-between gap-1">
        <span className="text-sm font-bold">{name}</span>
        <span className="text-xs text-[var(--ink-soft)]">
          {spaces === 0 ? 'unlimited' : `${occupants.length}/${spaces}`}
        </span>
      </div>
      <div className="text-xs text-[var(--ink-soft)]">{actionText}</div>
      <div className="flex flex-wrap gap-1 text-xs">
        {closed && <span className="rounded bg-rose-500 px-1.5 font-bold text-white">closed</span>}
        {note && <span className="rounded bg-sky-700 px-1.5 font-bold text-white">{note}</span>}
        {occupants.map((seat, i) => (
          <span key={i} className="rounded bg-[var(--panel-raised)] px-1.5 font-semibold">
            {names[seat]}
          </span>
        ))}
      </div>
    </button>
  );
}

function PlayerPanel({ p, name, isYou, isActive, cards }) {
  return (
    <div
      className={`rounded-lg border p-3 ${isActive ? 'border-emerald-400' : 'border-[var(--line)]'} bg-[var(--panel)]`}
    >
      <div className="mb-1 flex items-center justify-between">
        <span className="font-bold">
          {name}
          {isYou && <span className="text-[var(--ink-soft)]"> (you)</span>}
        </span>
        <span className="rounded bg-indigo-300 px-2 font-black text-indigo-950" title="Public Trust">
          {p.trust}
        </span>
      </div>
      <div className="mb-1 text-xs text-[var(--ink-soft)]">
        Execs: {'●'.repeat(p.execsLeft)}
        {'○'.repeat(Math.max(0, p.execsTotal - p.execsLeft))} - Spin cards: {p.spinCount}
      </div>
      <div className="mb-2 flex flex-wrap gap-1">
        {CURRENCY_ORDER.map((c) => (
          <Chip key={c} cur={c} n={p.tokens[c] || 0} />
        ))}
      </div>
      {p.devoted.length > 0 && (
        <div className="text-xs">
          <span className="font-semibold">Devoted:</span>{' '}
          {p.devoted.map((id) => `${cards.crises[id].name} (+1 ${cards.crises[id].devote_currency})`).join(', ')}
        </div>
      )}
      {p.caseFiles.length > 0 && (
        <div className="text-xs">
          <span className="font-semibold">Case Files:</span> {p.caseFiles.map((id) => cards.crises[id].name).join(', ')}
        </div>
      )}
      {p.rebuilt.length > 0 && (
        <div className="text-xs">
          <span className="font-semibold">Rebuilt:</span> {p.rebuilt.map((id) => cards.crises[id].back_name).join(', ')}
        </div>
      )}
    </div>
  );
}

function GameLog({ log, names }) {
  const ref = useRef(null);
  useEffect(() => {
    if (ref.current) ref.current.scrollTop = ref.current.scrollHeight;
  }, [log.length]);
  return (
    <div
      ref={ref}
      className="max-h-56 overflow-y-auto rounded-lg border border-[var(--line)] bg-[var(--panel)] p-2 text-xs leading-relaxed"
    >
      {log.map((e, i) => (
        <div key={i}>
          <span className="text-[var(--ink-soft)]">R{e.r}</span>{' '}
          {e.s !== null && <span className="font-semibold">{names[e.s]} </span>}
          {e.t}
        </div>
      ))}
    </div>
  );
}

const KIND_STYLE = {
  Utility: 'bg-emerald-300 text-emerald-950',
  Attack: 'bg-rose-300 text-rose-950',
  Sabotage: 'bg-amber-300 text-amber-950',
};

function HandPane({ me, cards, st }) {
  const mandate = me.mandate ? cards.mandates[me.mandate] : null;
  return (
    <div className="space-y-2">
      <h2 className="text-sm font-bold uppercase tracking-wide text-[var(--ink-soft)]">Your hand</h2>
      <div className="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-3 text-xs">
        {me.spinHand.length === 0 && <div className="text-[var(--ink-soft)]">No Spin cards.</div>}
        {me.spinHand.map((id, i) => {
          const c = cards.spin[id];
          return (
            <div key={`${id}-${i}`} className="mb-1 flex items-baseline gap-2">
              <span className={`rounded px-1 font-bold ${KIND_STYLE[c.kind]}`}>{c.kind[0]}</span>
              <span>
                <b>{c.name}</b> - {c.effect_text}
              </span>
            </div>
          );
        })}
        <div className="mt-1 text-[var(--ink-soft)]">Play Spin cards at the Press Room.</div>
      </div>
      {mandate && (
        <div
          className={`rounded-lg border p-3 text-xs ${
            mandate.compromised === '1' ? 'border-rose-400' : 'border-[var(--line)]'
          } bg-[var(--panel)]`}
        >
          <div className="mb-1 font-bold">
            Firm Mandate: {mandate.name}
            {mandate.compromised === '1' && <span className="ml-1 text-rose-300">(secret)</span>}
          </div>
          <div>{mandate.condition_text}</div>
          {mandate.compromised === '1' && (
            <div className="mt-1 text-[var(--ink-soft)]">
              Tip-off this round: {me.tipOffUsedRound >= st.round ? 'already sent' : 'available'}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ main

export default function Game({ gameId, onExit }) {
  const { user } = useAuth();
  const { data, cards, act, refresh } = useGame(gameId);
  const toast = useToast();
  const [dlg, setDlg] = useState(null);
  const [tipArm, setTipArm] = useState(false);
  const [muted, setMutedState] = useState(isMuted());
  const prevRef = useRef({ version: 0, villain: 0, myTurn: false, finished: false });

  const st = data?.state;
  const mySeat = data?.you?.seat;
  const names = useMemo(() => {
    const m = {};
    (data?.names || []).forEach((n) => (m[n.seat] = n.name));
    return m;
  }, [data]);

  useEffect(() => {
    if (!st) return;
    const prev = prevRef.current;
    const myTurn =
      st.phase !== 'finished' && (st.pending ? st.pending.seat === mySeat : st.activeSeat === mySeat);
    if (data.version !== prev.version && prev.version !== 0) {
      if (st.phase === 'finished' && !prev.finished) sfx.gameOver();
      else if (st.villain.track > prev.villain) sfx.villain();
      else if (myTurn && !prev.myTurn) sfx.yourTurn();
    }
    prevRef.current = { version: data.version, villain: st.villain.track, myTurn, finished: st.phase === 'finished' };
  }, [data]);

  if (!data || !cards) return <div className="p-8 text-center">Loading game...</div>;

  if (data.status === 'lobby') {
    const isCreator = data.createdBy === data.you.userId;
    return (
      <div className="mx-auto max-w-md p-8 text-center">
        <h1 className="mb-2 text-2xl font-black">Superhero P.R.</h1>
        <p className="mb-4">
          Join code: <span className="font-mono text-xl font-bold">{data.joinCode}</span>
        </p>
        <p className="mb-6 text-sm text-[var(--ink-soft)]">
          {data.names.length}/{data.playerCount} firms at the table: {data.names.map((n) => n.name).join(', ')}
        </p>
        {isCreator && (
          <Button
            disabled={data.names.length !== data.playerCount}
            onClick={async () => {
              try {
                await post('games_start.php', { gameId });
                refresh();
              } catch (e) {
                toast(e.message);
              }
            }}
          >
            Start game
          </Button>
        )}
        <div className="mt-6">
          <button className="text-sm underline" onClick={onExit}>
            back to lobby
          </button>
        </div>
      </div>
    );
  }

  const me = st.players[mySeat];
  const isPress = st.phase === 'press';
  const actionType = isPress ? 'PRESS_REASSIGN' : 'PLACE';
  const myTurn =
    st.phase !== 'finished' && (st.pending ? st.pending.seat === mySeat : st.activeSeat === mySeat);
  const placementTurn = myTurn && !st.pending;

  // Fallen Hero disruptions, mirrored client-side for display and gating.
  const disrupts = st.fallen.map((id) => cards.fallen[id].ongoing_code.split(':')[1]);
  const closedNow = [...st.board.closedThisRound];
  if (disrupts.includes('CITYHALL')) closedNow.push('L01', 'L02');
  if (disrupts.includes('SPIN')) closedNow.push('L09');
  const escalate = disrupts.includes('COST') ? 2 : 0;
  const gridlockRg = disrupts.includes('CITYHALL') ? 1 : 0;

  const crisisCostOf = (card) => addCost(costFrom(card, 'cost_'), 'AN', escalate);
  const occ = (spot) => st.board.occupancy[spot] || [];

  const sendAction = async (action) => {
    const full = { ...action, type: actionType };
    if (tipArm) full.tipOff = true;
    const r = await act(full);
    if (r) {
      sfx.place();
      setTipArm(false);
    }
    setDlg(null);
    return r;
  };

  const pickSpot = (spot) => {
    if (spot.startsWith('CR')) setDlg({ type: 'crisis', cardId: spot });
    else if (spot.startsWith('FH')) setDlg({ type: 'redeem', cardId: spot });
    else if (spot === 'L03') {
      if (!me.caseFiles.length) toast('Nothing in your Case Files to rebuild.');
      else setDlg({ type: 'rebuild' });
    } else if (spot === 'L07') setDlg({ type: 'trade' });
    else if (spot === 'L09') {
      if (!me.spinHand.length) toast('You need a Spin card to use the Press Room.');
      else setDlg({ type: 'spin' });
    } else sendAction({ spot });
  };

  const scoutPending = st.pending && st.pending.seat === mySeat && st.pending.cards;
  const result = st.result;
  const canTip = me.mandate && cards.mandates[me.mandate]?.compromised === '1' && me.tipOffUsedRound < st.round;

  return (
    <div className="mx-auto max-w-6xl p-4">
      <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-baseline gap-3">
          <h1 className="text-xl font-black tracking-tight">Superhero P.R.</h1>
          <span className="text-sm text-[var(--ink-soft)]">
            Round {st.round}/8 - crises {st.crisisDeckCount} - spin {st.spinDeckCount}
          </span>
        </div>
        <VillainTrack villain={st.villain} />
        <div className="flex items-center gap-3">
          <button
            className="text-sm underline"
            onClick={() => {
              setMuted(!muted);
              setMutedState(!muted);
            }}
          >
            {muted ? 'unmute' : 'mute'}
          </button>
          <button className="text-sm underline" onClick={onExit}>
            lobby
          </button>
        </div>
      </header>

      {st.phase !== 'finished' && (
        <div
          className={`mb-4 flex flex-wrap items-center gap-3 rounded-lg px-3 py-2 text-sm font-bold ${
            myTurn ? 'bg-emerald-600 text-white' : 'bg-[var(--panel)]'
          }`}
        >
          <span>
            {myTurn
              ? scoutPending
                ? 'Your call: reorder the top of the crisis deck.'
                : isPress
                  ? 'Press Room: move your exec to another open space.'
                  : 'Your turn: place an exec.'
              : `Waiting on ${names[st.pending ? st.pending.seat : st.activeSeat]}...`}
          </span>
          {myTurn && canTip && !scoutPending && (
            <label className="flex items-center gap-1 rounded bg-rose-900/70 px-2 py-0.5 text-xs text-rose-100">
              <input type="checkbox" checked={tipArm} onChange={(e) => setTipArm(e.target.checked)} />
              send anonymous tip with this placement
            </label>
          )}
        </div>
      )}

      {result && (
        <div className="mb-4 rounded-lg border border-indigo-400 bg-[var(--panel)] p-4">
          <h2 className="mb-2 text-lg font-black">
            {result.traitorWin
              ? 'The city falls - the Compromised firms win!'
              : result.cause === 'villainmax'
                ? 'The Villain track maxed out!'
                : 'Final scoring'}
          </h2>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-[var(--ink-soft)]">
                <th className="py-1">Firm</th>
                <th>Trust</th>
                <th>Mandate</th>
                <th>Tokens</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              {Object.entries(result.scores).map(([seat, s]) => {
                const m = result.mandates.find((x) => x.seat === +seat);
                return (
                  <tr key={seat} className={result.winners.includes(+seat) ? 'font-black' : ''}>
                    <td className="py-1">
                      {names[seat]}
                      {result.winners.includes(+seat) ? ' - WINNER' : ''}
                    </td>
                    <td>{s.trust}</td>
                    <td>
                      +{s.mandateBonus} ({m.name}
                      {m.compromised ? `, traitor, ${m.tipOffs} tips` : ''})
                    </td>
                    <td>
                      +{s.tokenBonus} ({s.tokens})
                    </td>
                    <td>{s.total}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {st.fallen.length > 0 && (
        <section className="mb-4">
          <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-rose-300">Fallen Heroes</h2>
          <div className="flex gap-2 overflow-x-auto pb-2">
            {st.fallen.map((id) => (
              <FallenCard
                key={id}
                card={cards.fallen[id]}
                cost={costFrom(cards.fallen[id], 'redeem_')}
                occupied={occ(id).length > 0}
                affordable={canAfford(me.tokens, costFrom(cards.fallen[id], 'redeem_'))}
                clickable={placementTurn}
                onPick={pickSpot}
              />
            ))}
          </div>
        </section>
      )}

      <section className="mb-4">
        <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-[var(--ink-soft)]">
          Crisis row (oldest first)
        </h2>
        <div className="flex gap-2 overflow-x-auto pb-2">
          {st.crisisRow.map((slot) => (
            <CrisisCard
              key={slot.cardId}
              card={cards.crises[slot.cardId]}
              slot={slot}
              cost={crisisCostOf(cards.crises[slot.cardId])}
              affordable={canAfford(me.tokens, crisisCostOf(cards.crises[slot.cardId]))}
              clickable={placementTurn}
              onPick={pickSpot}
            />
          ))}
        </div>
      </section>

      <div className="grid gap-4 lg:grid-cols-3">
        <section className="lg:col-span-2 space-y-4">
          <div>
            <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-[var(--ink-soft)]">The city</h2>
            <div className="grid gap-2 sm:grid-cols-2">
              {Object.values(cards.locations).map((loc) => (
                <LocationCard
                  key={loc.id}
                  id={loc.id}
                  name={loc.name}
                  actionText={loc.action_text}
                  spaces={+loc.spaces}
                  occupants={occ(loc.id)}
                  closed={closedNow.includes(loc.id)}
                  note={loc.id === 'L03' && gridlockRg ? 'rebuild +1 RG' : isPress && loc.id === 'L09' ? 'not a reassign target' : null}
                  names={names}
                  clickable={placementTurn && !(isPress && loc.id === 'L09')}
                  onPick={pickSpot}
                />
              ))}
            </div>
          </div>
          {st.board.rebuiltLocations.length > 0 && (
            <div>
              <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-[var(--ink-soft)]">
                Rebuilt locations
              </h2>
              <div className="grid gap-2 sm:grid-cols-2">
                {st.board.rebuiltLocations.map((rl) => {
                  const c = cards.crises[rl.cardId];
                  return (
                    <LocationCard
                      key={rl.cardId}
                      id={'B' + rl.cardId}
                      name={c.back_name}
                      actionText={`${c.action_text}. Owner (${names[rl.owner]}): ${c.owner_text}`}
                      spaces={1}
                      occupants={occ('B' + rl.cardId)}
                      closed={false}
                      note={rl.owner === mySeat ? 'yours' : null}
                      names={names}
                      clickable={placementTurn}
                      onPick={pickSpot}
                    />
                  );
                })}
              </div>
            </div>
          )}
        </section>

        <section className="space-y-2">
          <HandPane me={me} cards={cards} st={st} />
          <h2 className="pt-2 text-sm font-bold uppercase tracking-wide text-[var(--ink-soft)]">Firms</h2>
          {st.players.map((p) => (
            <PlayerPanel
              key={p.seat}
              p={p}
              name={names[p.seat]}
              isYou={p.seat === mySeat}
              isActive={st.phase !== 'finished' && st.activeSeat === p.seat}
              cards={cards}
            />
          ))}
          <h2 className="pt-2 text-sm font-bold uppercase tracking-wide text-[var(--ink-soft)]">Log</h2>
          <GameLog log={st.log} names={names} />
        </section>
      </div>

      {/* ------------------------------------------------------- dialogs */}
      {dlg?.type === 'crisis' && (
        <Dialog title={cards.crises[dlg.cardId].name} onClose={() => setDlg(null)}>
          <p className="mb-2 text-sm">
            Pay <CostChips cost={crisisCostOf(cards.crises[dlg.cardId])} /> and score{' '}
            <b>{cards.crises[dlg.cardId].trust} Trust</b>.
          </p>
          <p className="mb-4 text-sm text-[var(--ink-soft)]">
            Decide now (R3): devote it for +1 {cards.crises[dlg.cardId].devote_currency} every round, or file it to
            rebuild later at Permits.
          </p>
          <div className="flex gap-2">
            <Button
              onClick={() => {
                sendAction({ spot: dlg.cardId, devote: true });
                sfx.resolve();
              }}
            >
              Devote
            </Button>
            <Button
              kind="quiet"
              onClick={() => {
                sendAction({ spot: dlg.cardId, devote: false });
                sfx.resolve();
              }}
            >
              Case Files
            </Button>
            <Button kind="quiet" onClick={() => setDlg(null)}>
              Cancel
            </Button>
          </div>
        </Dialog>
      )}

      {dlg?.type === 'redeem' && (
        <Dialog title={`Redeem ${cards.fallen[dlg.cardId].name}`} onClose={() => setDlg(null)}>
          <p className="mb-2 text-sm">
            Pay <CostChips cost={costFrom(cards.fallen[dlg.cardId], 'redeem_')} /> and score{' '}
            <b>{cards.fallen[dlg.cardId].trust} Trust</b>. The hero departs for good and their disruption ends.
          </p>
          <div className="flex gap-2">
            <Button
              onClick={() => {
                sendAction({ spot: dlg.cardId });
                sfx.resolve();
              }}
            >
              Redeem
            </Button>
            <Button kind="quiet" onClick={() => setDlg(null)}>
              Cancel
            </Button>
          </div>
        </Dialog>
      )}

      {dlg?.type === 'trade' && <TradeDialog me={me} onClose={() => setDlg(null)} onPlace={sendAction} />}

      {dlg?.type === 'rebuild' && (
        <RebuildDialog me={me} cards={cards} gridlockRg={gridlockRg} onClose={() => setDlg(null)} onPlace={sendAction} />
      )}

      {dlg?.type === 'spin' && (
        <SpinDialog me={me} cards={cards} st={st} names={names} mySeat={mySeat} onClose={() => setDlg(null)} onPlace={sendAction} />
      )}

      {scoutPending && (
        <ScoutDialog cardIds={st.pending.cards} cards={cards} onSubmit={(order) => act({ type: 'SCOUT_ORDER', order })} />
      )}
    </div>
  );
}

function TradeDialog({ me, onClose, onPlace }) {
  const [give, setGive] = useState('');
  const [get_, setGet] = useState('');
  const canTrade = CURRENCY_ORDER.some((c) => me.tokens[c] >= 2);
  const valid = give && get_ && give !== get_ && me.tokens[give] >= 2;
  return (
    <Dialog title="Coalition Summit" onClose={onClose}>
      <p className="mb-3 text-sm">Take 1 CO. Then you may trade 2 tokens of one type for 1 of another, once.</p>
      {canTrade && (
        <div className="mb-4 flex items-center gap-2 text-sm">
          <span>Give 2</span>
          <select className="rounded bg-[var(--panel-raised)] p-1" value={give} onChange={(e) => setGive(e.target.value)}>
            <option value="">--</option>
            {CURRENCY_ORDER.filter((c) => me.tokens[c] >= 2).map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
          <span>for 1</span>
          <select className="rounded bg-[var(--panel-raised)] p-1" value={get_} onChange={(e) => setGet(e.target.value)}>
            <option value="">--</option>
            {CURRENCY_ORDER.filter((c) => c !== give).map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </div>
      )}
      <div className="flex gap-2">
        <Button disabled={!valid} onClick={() => onPlace({ spot: 'L07', trade: { give, get: get_ } })}>
          Place with trade
        </Button>
        <Button kind="quiet" onClick={() => onPlace({ spot: 'L07' })}>
          Place, no trade
        </Button>
        <Button kind="quiet" onClick={onClose}>
          Cancel
        </Button>
      </div>
    </Dialog>
  );
}

function RebuildDialog({ me, cards, gridlockRg, onClose, onPlace }) {
  return (
    <Dialog title="City Hall: Permits - rebuild" onClose={onClose}>
      <p className="mb-3 text-sm text-[var(--ink-soft)]">
        Pay the rebuild cost{gridlockRg ? ' (+1 RG, The Gridlock)' : ''}, score the printed Trust, and the location
        becomes yours on the board.
      </p>
      <ul className="mb-3 space-y-2">
        {me.caseFiles.map((id) => {
          const c = cards.crises[id];
          const cost = addCost(costFrom(c, 'rebuild_'), 'RG', gridlockRg);
          const ok = canAfford(me.tokens, cost);
          return (
            <li key={id} className="flex items-center justify-between gap-2 rounded bg-[var(--panel-raised)] p-2 text-sm">
              <span>
                <b>{c.name}</b> {'->'} {c.back_name} <CostChips cost={cost} />{' '}
                <span className="rounded bg-indigo-300 px-1 font-black text-indigo-950">+{c.rebuild_trust}</span>
              </span>
              <Button disabled={!ok} onClick={() => onPlace({ spot: 'L03', rebuild: { cardId: id } })}>
                Rebuild
              </Button>
            </li>
          );
        })}
      </ul>
      <Button kind="quiet" onClick={onClose}>
        Cancel
      </Button>
    </Dialog>
  );
}

function SpinDialog({ me, cards, st, names, mySeat, onClose, onPlace }) {
  const [cardId, setCardId] = useState(me.spinHand[0] || '');
  const [target, setTarget] = useState('');
  const [shutterLoc, setShutterLoc] = useState('');
  const [recallSpot, setRecallSpot] = useState('');
  const card = cardId ? cards.spin[cardId] : null;
  const code = card?.effect_code || '';
  const needsTarget = code.includes('STEAL');
  const needsShutter = code.includes('SHUTTER');
  const needsRecall = code.includes('RECALL');
  const opponents = st.players.filter((p) => p.seat !== mySeat);
  const mySpots = Object.entries(st.board.occupancy || {})
    .filter(([spot, seats]) => !spot.startsWith('CR') && !spot.startsWith('FH') && seats.includes(mySeat))
    .map(([spot]) => spot);
  const spotName = (spot) =>
    spot.startsWith('B') ? cards.crises[spot.slice(1)].back_name : cards.locations[spot]?.name || spot;
  const valid =
    card && (!needsTarget || target !== '') && (!needsShutter || shutterLoc) && (!needsRecall || recallSpot);
  return (
    <Dialog title="Press Room - play a Spin card" onClose={onClose}>
      <div className="mb-3 space-y-2 text-sm">
        <select className="w-full rounded bg-[var(--panel-raised)] p-2" value={cardId} onChange={(e) => { setCardId(e.target.value); setTarget(''); setShutterLoc(''); setRecallSpot(''); }}>
          {me.spinHand.map((id, i) => (
            <option key={`${id}-${i}`} value={id}>
              [{cards.spin[id].kind}] {cards.spin[id].name}
            </option>
          ))}
        </select>
        {card && <p className="text-[var(--ink-soft)]">{card.effect_text}</p>}
        {needsTarget && (
          <label className="block">
            Target firm{' '}
            <select className="ml-1 rounded bg-[var(--panel-raised)] p-1" value={target} onChange={(e) => setTarget(e.target.value)}>
              <option value="">--</option>
              {opponents.map((p) => (
                <option key={p.seat} value={p.seat}>
                  {names[p.seat]}
                </option>
              ))}
            </select>
          </label>
        )}
        {needsShutter && (
          <label className="block">
            Close{' '}
            <select className="ml-1 rounded bg-[var(--panel-raised)] p-1" value={shutterLoc} onChange={(e) => setShutterLoc(e.target.value)}>
              <option value="">--</option>
              {['L04', 'L05', 'L06'].map((l) => (
                <option key={l} value={l}>
                  {cards.locations[l].name}
                </option>
              ))}
            </select>
          </label>
        )}
        {needsRecall && (
          <label className="block">
            Recall your exec from{' '}
            <select className="ml-1 rounded bg-[var(--panel-raised)] p-1" value={recallSpot} onChange={(e) => setRecallSpot(e.target.value)}>
              <option value="">--</option>
              {mySpots.map((s) => (
                <option key={s} value={s}>
                  {spotName(s)}
                </option>
              ))}
            </select>
          </label>
        )}
      </div>
      <div className="flex gap-2">
        <Button
          disabled={!valid}
          onClick={() =>
            onPlace({
              spot: 'L09',
              spin: {
                cardId,
                ...(needsTarget ? { target: +target } : {}),
                ...(needsShutter ? { shutterLoc } : {}),
                ...(needsRecall ? { recallSpot } : {}),
              },
            })
          }
        >
          Play it
        </Button>
        <Button kind="quiet" onClick={onClose}>
          Cancel
        </Button>
      </div>
    </Dialog>
  );
}

function ScoutDialog({ cardIds, cards, onSubmit }) {
  const [order, setOrder] = useState(cardIds.map((_, i) => i));
  const move = (i, dir) => {
    const j = i + dir;
    if (j < 0 || j >= order.length) return;
    const next = [...order];
    [next[i], next[j]] = [next[j], next[i]];
    setOrder(next);
  };
  return (
    <Dialog title="Reorder the top of the crisis deck">
      <p className="mb-3 text-sm text-[var(--ink-soft)]">Top of deck first. Only you see this.</p>
      <ul className="mb-4 space-y-2">
        {order.map((idx, i) => {
          const c = cards.crises[cardIds[idx]];
          return (
            <li key={idx} className="flex items-center justify-between gap-2 rounded bg-[var(--panel-raised)] p-2 text-sm">
              <span>
                <b>{c.name}</b>{' '}
                <span className="text-[var(--ink-soft)]">
                  ({c.type}, {c.trust} Trust, Threat {c.threat})
                </span>
              </span>
              <span className="flex gap-1">
                <Button kind="quiet" onClick={() => move(i, -1)} aria-label="move up">
                  up
                </Button>
                <Button kind="quiet" onClick={() => move(i, 1)} aria-label="move down">
                  down
                </Button>
              </span>
            </li>
          );
        })}
      </ul>
      <Button onClick={() => onSubmit(order)}>Confirm order</Button>
    </Dialog>
  );
}
