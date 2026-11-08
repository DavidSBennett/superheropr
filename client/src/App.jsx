import { useState } from 'react';
import { AuthProvider, RequireAuth, useAuth } from './auth.jsx';
import { ToastProvider, Button, useToast } from './ui.jsx';
import Lobby from './Lobby.jsx';
import Game from './Game.jsx';

function Login() {
  const { login } = useAuth();
  const toast = useToast();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [display, setDisplay] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      await login(username.trim().toLowerCase(), password, display.trim());
    } catch (err) {
      toast(err.message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="mx-auto max-w-sm p-10">
      <h1 className="mb-1 text-3xl font-black tracking-tight">Superhero P.R.</h1>
      <p className="mb-6 text-sm text-[var(--ink-soft)]">
        Rival firms clean up after the heroes. A new username is created on
        first sign-in with the password you type.
      </p>
      <form onSubmit={submit} className="space-y-3">
        <input
          className="w-full rounded bg-[var(--panel-raised)] p-2"
          placeholder="username (a-z, 0-9, _)"
          autoComplete="username"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
        />
        <input
          type="password"
          className="w-full rounded bg-[var(--panel-raised)] p-2"
          placeholder="password (6+ characters)"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <input
          className="w-full rounded bg-[var(--panel-raised)] p-2"
          placeholder="display name (optional)"
          value={display}
          onChange={(e) => setDisplay(e.target.value)}
        />
        <Button disabled={busy || username.trim().length < 2 || password.length < 6}>
          Enter the newsroom
        </Button>
      </form>
    </div>
  );
}

function Shell() {
  const [gameId, setGameId] = useState(null);
  return (
    <RequireAuth fallback={<Login />}>
      {gameId === null ? (
        <Lobby onOpenGame={setGameId} />
      ) : (
        <Game gameId={gameId} onExit={() => setGameId(null)} />
      )}
    </RequireAuth>
  );
}

export default function App() {
  return (
    <ToastProvider>
      <AuthProvider>
        <Shell />
      </AuthProvider>
    </ToastProvider>
  );
}
