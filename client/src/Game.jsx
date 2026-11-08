import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { get, post } from './api.js';
import { useAuth } from './auth.jsx';
import { sfx, isMuted, setMuted } from './audio.js';
import { Button, Chip, CostChips, CURRENCY_ORDER, Dialog, useToast } from './ui.jsx';

const CUR_KEYS = { cost: 'cost_', rebuild: 'rebuild_' };
function crisisCost(card, prefix = 'cost_') {
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

// ------------------------------------------------------------ polling hook

function useGame(gameId) {
  const [data, setData] = useState(null); // envelope from state.php
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
        const r = await post('act.php', {
          gameId,
          expectedVersion: d.version,
          action,
        });
        const next = { ...d, version: r.version, state: r.state, status: r.state.phase === 'finished' ? 'finished' : d.status };
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
      <div className="h-3 overflow-hidden rounded bg-[var(--panel-raised)]" role="img"
        aria-label={`Villain track at ${villain.track} of ${villain.max}`}>
        <div
          className={`h-full ${hot ? 'bg-rose-500' : 'bg-orange-400'}`}
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}

function CrisisCard({ card, slot, mine, affordable, myTurn, onPick }) {
  const cost = crisisCost(card);
  const oldest = slot.pos <= 1;
  const clickable = myTurn && affordable;
  return (
    <button
      disabled={!clickable}
      onClick={() => onPick(card.id)}
      title={`Fallout if it falls: ${card.fallout_text}`}
      className={`flex w-52 shrink-0 flex-col gap-1 rounded-lg border p-2 text-left ${
        oldest ? 'border-orange-400' : 'border-[var(--line)]'
      } bg-[var(--panel)] ${clickable ? 'hover:bg-[var(--panel-raised)] cursor-pointer' : ''} ${
        !affordable ? 'opacity-100' : ''
      }`}
    >
      <div className="flex items-start justify-between gap-1">
        <span className="text-sm font-bold leading-tight">{card.name}</span>
        <span className="rounded bg-indigo-300 px-1.5 text-sm font-black text-indigo-950">
          {card.trust}
        </span>
      </div>
      <div className="text-xs text-[var(--ink-soft)]">
        {card.type} - Threat {card.threat}
        {oldest && <span className="ml-1 font-bold text-orange-300">falls next refresh</span>}
      </div>
      <CostChips cost={cost} />
      {card.reward_extra && (
        <div className="text-xs text-emerald-300">+ {card.reward_extra}</div>
      )}
      {!affordable && myTurn && (
        <div className="text-xs text-[var(--ink-soft)]">cannot afford</div>
      )}
    </button>
  );
}

function LocationCard({ loc, occupants, closed, disabled, names, myTurn, onPick }) {
  const spaces = +loc.spaces;
  const full = spaces > 0 && occupants.length >= spaces;
  const clickable = myTurn && !closed && !disabled && !full;
  return (
    <button
      disabled={!clickable}
      onClick={() => onPick(loc.id)}
      className={`flex flex-col gap-1 rounded-lg border border-[var(--line)] bg-[var(--panel)] p-2 text-left ${
        clickable ? 'hover:bg-[var(--panel-raised)] cursor-pointer' : ''
      }`}
    >
      <div className="flex items-center justify-between gap-1">
        <span className="text-sm font-bold">{loc.name}</span>
        <span className="text-xs text-[var(--ink-soft)]">
          {spaces === 0 ? 'unlimited' : `${occupants.length}/${spaces}`}
        </span>
      </div>
      <div className="text-xs text-[var(--ink-soft)]">{loc.action_text}</div>
      <div className="flex flex-wrap gap-1 text-xs">
        {disabled && <span className="rounded bg-slate-500 px-1.5 font-bold text-white">Phase 3</span>}
        {closed && <span className="rounded bg-rose-500 px-1.5 font-bold text-white">closed</span>}
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
      className={`rounded-lg border p-3 ${
        isActive ? 'border-emerald-400' : 'border-[var(--line)]'
      } bg-[var(--panel)]`}
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
        {'○'.repeat(Math.max(0, p.execsTotal - p.execsLeft))}
        {p.passed ? ' (passed)' : ''}
      </div>
      <div className="mb-2 flex flex-wrap gap-1">
        {CURRENCY_ORDER.map((c) => (
          <Chip key={c} cur={c} n={p.tokens[c] || 0} />
        ))}
      </div>
      {p.devoted.length > 0 && (
        <div className="text-xs">
          <span className="font-semibold">Devoted:</span>{' '}
          {p.devoted
            .map((id) => `${cards.crises[id].name} (+1 ${cards.crises[id].devote_currency})`)
            .join(', ')}
        </div>
      )}
      {p.caseFiles.length > 0 && (
        <div className="text-xs">
          <span className="font-semibold">Case Files:</span>{' '}
          {p.caseFiles.map((id) => cards.crises[id].name).join(', ')}
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

// ------------------------------------------------------------------ main

export default function Game({ gameId, onExit }) {
  const { user } = useAuth();
  const { data, cards, act, refresh } = useGame(gameId);
  const toast = useToast();
  const [dlg, setDlg] = useState(null);
  const [muted, setMutedState] = useState(isMuted());
  const prevRef = useRef({ version: 0, villain: 0, myTurn: false, finished: false });

  const st = data?.state;
  const mySeat = data?.you?.seat;
  const names = useMemo(() => {
    const m = {};
    (data?.names || []).forEach((n) => (m[n.seat] = n.name));
    return m;
  }, [data]);

  // Sound cues on state changes.
  useEffect(() => {
    if (!st) return;
    const prev = prevRef.current;
    const myTurn =
      st.phase !== 'finished' &&
      (st.pending ? st.pending.seat === mySeat : st.activeSeat === mySeat);
    if (data.version !== prev.version && prev.version !== 0) {
      if (st.phase === 'finished' && !prev.finished) sfx.gameOver();
      else if (st.villain.track > prev.villain) sfx.villain();
      else if (myTurn && !prev.myTurn) sfx.yourTurn();
    }
    prevRef.current = {
      version: data.version,
      villain: st.villain.track,
      myTurn,
      finished: st.phase === 'finished',
    };
  }, [data]);

  if (!data || !cards) return <div className="p-8 text-center">Loading game...</div>;

  // ------------------------------------------------- lobby waiting screen
  if (data.status === 'lobby') {
    const isCreator = data.createdBy === data.you.userId;
    return (
      <div className="mx-auto max-w-md p-8 text-center">
        <h1 className="mb-2 text-2xl font-black">Superhero P.R.</h1>
        <p className="mb-4">
          Join code: <span className="font-mono text-xl font-bold">{data.joinCode}</span>
        </p>
        <p className="mb-6 text-sm text-[var(--ink-soft)]">
          {data.names.length}/{data.playerCount} firms at the table:{' '}
          {data.names.map((n) => n.name).join(', ')}
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
  const myTurn =
    st.phase !== 'finished' &&
    (st.pending ? st.pending.seat === mySeat : st.activeSeat === mySeat) ;
  const placementTurn = myTurn && !st.pending;

  const hasLegalPlacement = () => {
    for (const loc of Object.values(cards.locations)) {
      if (['L03', 'L09'].includes(loc.id)) continue;
      if (st.board.closedThisRound.includes(loc.id)) continue;
      const occ = (st.board.occupancy[loc.id] || []).length;
      if (+loc.spaces === 0 || occ < +loc.spaces) return true;
    }
    return st.crisisRow.some((slot) => canAfford(me.tokens, crisisCost(cards.crises[slot.cardId])));
  };

  const doPlace = async (action) => {
    const r = await act(action);
    if (r) sfx.place();
    setDlg(null);
  };

  const pickSpot = (spot) => {
    if (spot.startsWith('CR')) setDlg({ type: 'crisis', cardId: spot });
    else if (spot === 'L07') setDlg({ type: 'trade' });
    else doPlace({ type: 'PLACE', spot });
  };

  const scoutPending = st.pending && st.pending.seat === mySeat && st.pending.cards;

  // ------------------------------------------------------------- finished
  const result = st.result;

  return (
    <div className="mx-auto max-w-6xl p-4">
      <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-baseline gap-3">
          <h1 className="text-xl font-black tracking-tight">Superhero P.R.</h1>
          <span className="text-sm text-[var(--ink-soft)]">
            Round {st.round}/8 - deck {st.crisisDeckCount}
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
          className={`mb-4 rounded-lg px-3 py-2 text-sm font-bold ${
            myTurn ? 'bg-emerald-600 text-white' : 'bg-[var(--panel)]'
          }`}
        >
          {myTurn
            ? scoutPending
              ? 'Your call: reorder the top of the crisis deck.'
              : 'Your turn: place an exec.'
            : `Waiting on ${names[st.pending ? st.pending.seat : st.activeSeat]}...`}
          {placementTurn && !hasLegalPlacement() && (
            <Button kind="danger" className="ml-3" onClick={() => doPlace({ type: 'PASS' })}>
              No legal placement - pass
            </Button>
          )}
        </div>
      )}

      {result && (
        <div className="mb-4 rounded-lg border border-indigo-400 bg-[var(--panel)] p-4">
          <h2 className="mb-2 text-lg font-black">
            {result.cause === 'villainmax' ? 'The Villain track maxed out!' : 'Final scoring'}
          </h2>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-[var(--ink-soft)]">
                <th className="py-1">Firm</th>
                <th>Trust</th>
                <th>Token bonus</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              {Object.entries(result.scores).map(([seat, s]) => (
                <tr key={seat} className={result.winners.includes(+seat) ? 'font-black' : ''}>
                  <td className="py-1">
                    {names[seat]}
                    {result.winners.includes(+seat) ? ' - WINNER' : ''}
                  </td>
                  <td>{s.trust}</td>
                  <td>
                    +{s.tokenBonus} ({s.tokens} tokens)
                  </td>
                  <td>{s.total}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
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
              myTurn={placementTurn}
              affordable={canAfford(me.tokens, crisisCost(cards.crises[slot.cardId]))}
              onPick={pickSpot}
            />
          ))}
        </div>
      </section>

      <div className="grid gap-4 lg:grid-cols-3">
        <section className="lg:col-span-2">
          <h2 className="mb-2 text-sm font-bold uppercase tracking-wide text-[var(--ink-soft)]">
            The city
          </h2>
          <div className="grid gap-2 sm:grid-cols-2">
            {Object.values(cards.locations).map((loc) => (
              <LocationCard
                key={loc.id}
                loc={loc}
                occupants={st.board.occupancy[loc.id] || []}
                closed={st.board.closedThisRound.includes(loc.id)}
                disabled={['L03', 'L09'].includes(loc.id)}
                names={names}
                myTurn={placementTurn}
                onPick={pickSpot}
              />
            ))}
          </div>
        </section>

        <section className="space-y-2">
          <h2 className="text-sm font-bold uppercase tracking-wide text-[var(--ink-soft)]">Firms</h2>
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
            Pay <CostChips cost={crisisCost(cards.crises[dlg.cardId])} /> and score{' '}
            <b>{cards.crises[dlg.cardId].trust} Trust</b>.
          </p>
          <p className="mb-4 text-sm text-[var(--ink-soft)]">
            Decide now (R3): devote it for +1 {cards.crises[dlg.cardId].devote_currency} every
            round, or file it in your Case Files to rebuild later.
          </p>
          <div className="flex gap-2">
            <Button onClick={() => { doPlace({ type: 'PLACE', spot: dlg.cardId, devote: true }); sfx.resolve(); }}>
              Devote
            </Button>
            <Button kind="quiet" onClick={() => { doPlace({ type: 'PLACE', spot: dlg.cardId, devote: false }); sfx.resolve(); }}>
              Case Files
            </Button>
            <Button kind="quiet" onClick={() => setDlg(null)}>
              Cancel
            </Button>
          </div>
        </Dialog>
      )}

      {dlg?.type === 'trade' && (
        <TradeDialog me={me} onClose={() => setDlg(null)} onPlace={doPlace} />
      )}

      {scoutPending && (
        <ScoutDialog
          cardIds={st.pending.cards}
          cards={cards}
          onSubmit={(order) => act({ type: 'SCOUT_ORDER', order })}
        />
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
      <p className="mb-3 text-sm">
        Take 1 CO. Then you may trade 2 tokens of one type for 1 of another, once.
      </p>
      {canTrade && (
        <div className="mb-4 flex items-center gap-2 text-sm">
          <span>Give 2</span>
          <select className="rounded bg-[var(--panel-raised)] p-1" value={give} onChange={(e) => setGive(e.target.value)}>
            <option value="">--</option>
            {CURRENCY_ORDER.filter((c) => me.tokens[c] >= 2).map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
          <span>for 1</span>
          <select className="rounded bg-[var(--panel-raised)] p-1" value={get_} onChange={(e) => setGet(e.target.value)}>
            <option value="">--</option>
            {CURRENCY_ORDER.filter((c) => c !== give).map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
        </div>
      )}
      <div className="flex gap-2">
        <Button
          disabled={!valid}
          onClick={() => onPlace({ type: 'PLACE', spot: 'L07', trade: { give, get: get_ } })}
        >
          Place with trade
        </Button>
        <Button kind="quiet" onClick={() => onPlace({ type: 'PLACE', spot: 'L07' })}>
          Place, no trade
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
    <Dialog title="Newswire: reorder the top of the crisis deck">
      <p className="mb-3 text-sm text-[var(--ink-soft)]">
        Top of deck first. Only you see this.
      </p>
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
                <Button kind="quiet" onClick={() => move(i, -1)} aria-label="move up">up</Button>
                <Button kind="quiet" onClick={() => move(i, 1)} aria-label="move down">down</Button>
              </span>
            </li>
          );
        })}
      </ul>
      <Button onClick={() => onSubmit(order)}>Confirm order</Button>
    </Dialog>
  );
}
