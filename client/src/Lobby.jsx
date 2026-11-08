import { useEffect, useState } from 'react';
import { get, post } from './api.js';
import { useAuth } from './auth.jsx';
import { Button, useToast } from './ui.jsx';

// "Your Games" list: stands in for The Historians widget until I have its source.
export default function Lobby({ onOpenGame }) {
  const { user, logout } = useAuth();
  const toast = useToast();
  const [games, setGames] = useState([]);
  const [playerCount, setPlayerCount] = useState(2);
  const [joinCode, setJoinCode] = useState('');
  const [busy, setBusy] = useState(false);

  const refresh = () => get('games_mine.php').then((r) => setGames(r.games)).catch(() => {});
  useEffect(() => {
    refresh();
    const t = setInterval(refresh, 10000);
    return () => clearInterval(t);
  }, []);

  const createGame = async () => {
    setBusy(true);
    try {
      const r = await post('games_create.php', { playerCount });
      onOpenGame(r.gameId);
    } catch (e) {
      toast(e.message);
    } finally {
      setBusy(false);
    }
  };

  const joinGame = async () => {
    setBusy(true);
    try {
      const r = await post('games_join.php', { joinCode: joinCode.trim() });
      onOpenGame(r.gameId);
    } catch (e) {
      toast(e.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-2xl p-6">
      <header className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-black tracking-tight">Superhero P.R.</h1>
        <div className="text-sm text-[var(--ink-soft)]">
          {user.display_name}{' '}
          <button className="underline" onClick={logout}>
            log out
          </button>
        </div>
      </header>

      <section className="mb-6 grid gap-4 sm:grid-cols-2">
        <div className="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4">
          <h2 className="mb-2 font-bold">New game</h2>
          <label className="mb-3 block text-sm">
            Players{' '}
            <select
              className="ml-2 rounded bg-[var(--panel-raised)] p-1"
              value={playerCount}
              onChange={(e) => setPlayerCount(+e.target.value)}
            >
              {[2, 3, 4, 5].map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
          <Button onClick={createGame} disabled={busy}>
            Create game
          </Button>
          <p className="mt-2 text-xs text-[var(--ink-soft)]">
            Phase 2 slice is tuned and tested for 2 players.
          </p>
        </div>
        <div className="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4">
          <h2 className="mb-2 font-bold">Join by code</h2>
          <input
            className="mb-3 w-full rounded bg-[var(--panel-raised)] p-2 font-mono uppercase"
            maxLength={6}
            value={joinCode}
            onChange={(e) => setJoinCode(e.target.value.toUpperCase())}
            placeholder="ABC123"
          />
          <Button onClick={joinGame} disabled={busy || joinCode.trim().length !== 6}>
            Join
          </Button>
        </div>
      </section>

      <section>
        <h2 className="mb-2 font-bold">Your games</h2>
        {games.length === 0 && <p className="text-sm text-[var(--ink-soft)]">None yet.</p>}
        <ul className="space-y-2">
          {games.map((g) => (
            <li key={g.gameId}>
              <button
                className="flex w-full items-center justify-between rounded-lg border border-[var(--line)] bg-[var(--panel)] p-3 text-left hover:bg-[var(--panel-raised)]"
                onClick={() => onOpenGame(g.gameId)}
              >
                <span>
                  <span className="font-mono font-bold">{g.joinCode}</span>{' '}
                  <span className="text-sm text-[var(--ink-soft)]">
                    {g.status === 'lobby'
                      ? `waiting ${g.playersJoined}/${g.playerCount}`
                      : g.status}
                  </span>
                </span>
                {g.yourTurn && (
                  <span className="rounded bg-emerald-500 px-2 py-0.5 text-xs font-bold text-emerald-950">
                    your turn
                  </span>
                )}
              </button>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
