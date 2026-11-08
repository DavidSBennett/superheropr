// Thin fetch wrapper. In production the client sits next to the api/ folder
// (public_html/superheropr/ + public_html/superheropr/api/), so relative
// paths work; in dev the vite proxy forwards /api to php -S.
const BASE = import.meta.env.DEV ? '/api/' : 'api/';

export class ApiError extends Error {
  constructor(status, payload) {
    super(payload?.detail || payload?.error || `HTTP ${status}`);
    this.status = status;
    this.payload = payload || {};
    this.code = payload?.error || 'HTTP_' + status;
  }
}

async function call(path, options = {}) {
  const res = await fetch(BASE + path, { credentials: 'same-origin', ...options });
  let payload = null;
  try {
    payload = await res.json();
  } catch {
    /* non-JSON error page */
  }
  if (!res.ok) throw new ApiError(res.status, payload);
  return payload;
}

export const get = (path) => call(path);
export const post = (path, body) =>
  call(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
