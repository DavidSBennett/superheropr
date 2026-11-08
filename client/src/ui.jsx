// Small shared UI pieces. Color never carries meaning alone: every currency
// chip pairs a color with its two-letter code (WCAG AA).
import { createContext, useCallback, useContext, useRef, useState } from 'react';

export const CURRENCIES = {
  AN: { name: 'Analysts', chip: 'bg-slate-200 text-slate-900' },
  IN: { name: 'Influencers', chip: 'bg-pink-200 text-pink-950' },
  GR: { name: 'Grassroots', chip: 'bg-green-200 text-green-950' },
  RG: { name: 'Regulatory Contacts', chip: 'bg-amber-200 text-amber-950' },
  CO: { name: 'Coalition Partners', chip: 'bg-sky-200 text-sky-950' },
  IV: { name: 'Investigators', chip: 'bg-violet-200 text-violet-950' },
};
export const CURRENCY_ORDER = ['AN', 'IN', 'GR', 'RG', 'CO', 'IV'];

export function Chip({ cur, n }) {
  const c = CURRENCIES[cur];
  return (
    <span
      title={c.name}
      className={`inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-xs font-bold ${c.chip}`}
    >
      {n}
      <span aria-hidden>{cur}</span>
      <span className="sr-only">{c.name}</span>
    </span>
  );
}

export function CostChips({ cost }) {
  const entries = CURRENCY_ORDER.filter((c) => (cost[c] || 0) > 0);
  if (!entries.length) return <span className="text-xs">free</span>;
  return (
    <span className="inline-flex flex-wrap gap-1">
      {entries.map((c) => (
        <Chip key={c} cur={c} n={cost[c]} />
      ))}
    </span>
  );
}

export function Button({ children, kind = 'primary', className = '', ...props }) {
  const kinds = {
    primary: 'bg-indigo-500 hover:bg-indigo-400 text-white',
    quiet: 'bg-[var(--panel-raised)] hover:bg-[var(--line)] text-[var(--ink)]',
    danger: 'bg-rose-600 hover:bg-rose-500 text-white',
  };
  return (
    <button
      className={`rounded px-3 py-1.5 text-sm font-semibold disabled:cursor-not-allowed disabled:bg-slate-600 disabled:text-slate-300 ${kinds[kind]} ${className}`}
      {...props}
    >
      {children}
    </button>
  );
}

export function Dialog({ title, children, onClose }) {
  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
      <div
        className="w-full max-w-md rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4 shadow-xl"
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-label={title}
      >
        <h2 className="mb-3 text-lg font-bold">{title}</h2>
        {children}
      </div>
    </div>
  );
}

const ToastContext = createContext(() => {});
export const useToast = () => useContext(ToastContext);

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const idRef = useRef(0);
  const push = useCallback((text, kind = 'error') => {
    const id = ++idRef.current;
    setToasts((t) => [...t, { id, text, kind }]);
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 4500);
  }, []);
  return (
    <ToastContext.Provider value={push}>
      {children}
      <div className="fixed bottom-4 left-1/2 z-50 flex w-full max-w-sm -translate-x-1/2 flex-col gap-2 px-2">
        {toasts.map((t) => (
          <div
            key={t.id}
            className={`rounded px-3 py-2 text-sm font-semibold shadow-lg ${
              t.kind === 'error' ? 'bg-rose-700 text-white' : 'bg-emerald-700 text-white'
            }`}
          >
            {t.text}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}
