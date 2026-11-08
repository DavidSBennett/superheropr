// Synthesized sound effects via the Web Audio API. No audio files.
let ctx = null;
let muted = localStorage.getItem('prgMuted') === '1';

export function isMuted() {
  return muted;
}
export function setMuted(m) {
  muted = m;
  localStorage.setItem('prgMuted', m ? '1' : '0');
}

function ac() {
  if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
  if (ctx.state === 'suspended') ctx.resume();
  return ctx;
}

function tone(freq, t0, dur, type = 'sine', gain = 0.08) {
  const c = ac();
  const o = c.createOscillator();
  const g = c.createGain();
  o.type = type;
  o.frequency.value = freq;
  g.gain.setValueAtTime(0, c.currentTime + t0);
  g.gain.linearRampToValueAtTime(gain, c.currentTime + t0 + 0.01);
  g.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + t0 + dur);
  o.connect(g).connect(c.destination);
  o.start(c.currentTime + t0);
  o.stop(c.currentTime + t0 + dur + 0.05);
}

function play(fn) {
  if (muted) return;
  try {
    fn();
  } catch {
    /* audio blocked until user gesture; fine */
  }
}

export const sfx = {
  place: () => play(() => tone(520, 0, 0.09, 'triangle')),
  gain: () => play(() => [392, 523, 659].forEach((f, i) => tone(f, i * 0.07, 0.12, 'triangle'))),
  resolve: () => play(() => [523, 659, 784, 1046].forEach((f, i) => tone(f, i * 0.08, 0.16, 'triangle', 0.09))),
  fallout: () => play(() => [196, 155].forEach((f, i) => tone(f, i * 0.12, 0.25, 'sawtooth', 0.05))),
  villain: () => play(() => tone(82, 0, 0.4, 'sawtooth', 0.09)),
  yourTurn: () => play(() => [659, 988].forEach((f, i) => tone(f, i * 0.12, 0.2, 'sine', 0.1))),
  gameOver: () => play(() => [262, 330, 392, 523].forEach((f) => tone(f, 0, 0.9, 'sine', 0.05))),
  error: () => play(() => tone(220, 0, 0.15, 'square', 0.04)),
};
