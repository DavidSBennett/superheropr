// AUTH SHIM mirroring The Historians' AuthContext/RequireAuth names so the
// swap is mechanical once I have that source.
import { createContext, useContext, useEffect, useState } from 'react';
import { get, post } from './api.js';

const AuthContext = createContext(null);
export const useAuth = () => useContext(AuthContext);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    get('auth_me.php')
      .then((r) => setUser(r.user))
      .finally(() => setReady(true));
  }, []);

  const login = async (username, displayName) => {
    const r = await post('auth_login.php', { username, displayName });
    setUser(r.user);
  };
  const logout = async () => {
    await post('auth_logout.php');
    setUser(null);
  };

  return (
    <AuthContext.Provider value={{ user, ready, login, logout }}>{children}</AuthContext.Provider>
  );
}

export function RequireAuth({ children, fallback }) {
  const { user, ready } = useAuth();
  if (!ready) return <div className="p-8 text-center">Loading...</div>;
  if (!user) return fallback;
  return children;
}
