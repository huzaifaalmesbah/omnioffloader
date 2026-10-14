"""Synthesise subtle UI sound effects (original, no licence needed) → public/sfx/*.wav.

click  – soft mouse click (short filtered noise + body thump)
whoosh – scene transition (band-swept noise, fades in and out)
pop    – success / item appears (short rising sine blip)
"""
import wave
import numpy as np

SR = 44100
rng = np.random.default_rng(7)

def save(name, x, gain):
    x = x / (np.max(np.abs(x)) or 1) * gain
    pcm = (np.clip(x, -1, 1) * 32767).astype(np.int16)
    st = np.repeat(pcm[:, None], 2, axis=1).ravel()
    with wave.open(f'public/sfx/{name}.wav', 'wb') as w:
        w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR); w.writeframes(st.tobytes())

def lowpass(x, a):
    y = np.empty_like(x); acc = 0.0
    for i, v in enumerate(x):
        acc += a * (v - acc); y[i] = acc
    return y

# click: 45 ms
n = int(0.045 * SR); t = np.arange(n) / SR
noise = rng.standard_normal(n) * np.exp(-t * 260)
hp = noise - lowpass(noise, 0.25)
body = np.sin(2 * np.pi * 1800 * t) * np.exp(-t * 180) * 0.5 + np.sin(2 * np.pi * 220 * t) * np.exp(-t * 90) * 0.35
save('click', hp * 0.8 + body, 0.8)

# whoosh: 0.7 s, swept low-pass noise, smooth envelope
n = int(0.7 * SR); t = np.arange(n) / SR
noise = rng.standard_normal(n)
cut = 0.02 + 0.22 * np.sin(np.pi * t / t[-1]) ** 2
y = np.empty(n); acc = 0.0
for i in range(n):
    acc += cut[i] * (noise[i] - acc); y[i] = acc
env = np.sin(np.pi * t / t[-1]) ** 2
save('whoosh', y * env, 0.7)

# pop: 120 ms rising blip
n = int(0.12 * SR); t = np.arange(n) / SR
f = 520 + 520 * (t / t[-1])
ph = 2 * np.pi * np.cumsum(f) / SR
env = np.minimum(1, t / 0.004) * np.exp(-t * 32)
save('pop', (np.sin(ph) + 0.25 * np.sin(2 * ph)) * env, 0.6)
print('ok')
