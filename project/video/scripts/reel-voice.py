"""Reel voice: trim pauses, transcribe, time every script sentence.

voice-reel/main.mp3 (one full ElevenLabs take)
  → public/voice-reel/voice.wav   every pause longer than PAUSE shortened to PAUSE
  → src/reel-whisper.json         Whisper word timings on the trimmed audio
  → src/reel-timing.json          start/end of every sentence in reel-script.json

Sentence starts: each sentence's expected start (its share of the script's
characters, mapped onto the voiced span) is matched against Whisper's words
by consonant skeleton, then snapped to the nearest real pause; with no match
it snaps the estimate to the nearest pause.

Usage: .venv/bin/python scripts/reel-voice.py
"""
import json, os, re, subprocess, sys, unicodedata, wave
from difflib import SequenceMatcher
import numpy as np

SRC = 'voice-reel/main.mp3'
OUT_WAV = 'public/voice-reel/voice.wav'
TMP = '.venv/reel'
PAUSE = 0.45      # longest pause kept (s)
HEAD, TAIL = 0.15, 0.4
FRAME = 0.02
SR = 44100
os.makedirs(TMP, exist_ok=True)
os.makedirs(os.path.dirname(OUT_WAV), exist_ok=True)


def read(path):
    with wave.open(path) as w:
        return np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float32) / 32768


def write(path, a, sr):
    with wave.open(path, 'w') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(sr)
        w.writeframes((np.clip(a, -1, 1) * 32767).astype(np.int16).tobytes())


def silences(a, sr, min_len):
    hop = int(sr * FRAME)
    n = len(a) // hop
    rms = np.sqrt((a[: n * hop].reshape(n, hop) ** 2).mean(axis=1))
    voiced = rms > rms.max() * 0.06
    first = int(np.argmax(voiced)); last = n - int(np.argmax(voiced[::-1]))
    out, run = [], 0
    for i in range(first, last):
        if not voiced[i]:
            run += 1
        else:
            if run * FRAME >= min_len:
                out.append(((i - run) * FRAME, i * FRAME))
            run = 0
    return first * FRAME, last * FRAME, out


# 1. Trim pauses -------------------------------------------------------------
raw = f'{TMP}/raw.wav'
subprocess.run(['afconvert', '-f', 'WAVE', '-d', f'LEI16@{SR}', '-c', '1', SRC, raw], check=True)
a = read(raw)
first, last, gaps = silences(a, SR, PAUSE)
fade = int(SR * 0.012)
pieces, cur = [], max(0.0, first - HEAD)
for s, e in gaps:
    keep = PAUSE / 2
    pieces.append((cur, s + keep))
    cur = e - keep
pieces.append((cur, min(len(a) / SR, last + TAIL)))
out = []
for s, e in pieces:
    seg = a[int(s * SR): int(e * SR)].copy()
    seg[:fade] *= np.linspace(0, 1, fade); seg[-fade:] *= np.linspace(1, 0, fade)
    out.append(seg)
trimmed = np.concatenate(out)
write(OUT_WAV, trimmed, SR)
print(f'trimmed {len(a) / SR:.1f}s → {len(trimmed) / SR:.1f}s ({len(gaps)} pauses shortened)')

# 2. Whisper, in chunks cut at pauses (one long pass skips whole sentences) --
import mlx_whisper
w16 = f'{TMP}/voice16.wav'
subprocess.run(['afconvert', '-f', 'WAVE', '-d', 'LEI16@16000', '-c', '1', OUT_WAV, w16], check=True)
b = read(w16)
v_first, v_last, v_gaps = silences(b, 16000, 0.3)
cuts, start = [], 0.0
for s, e in v_gaps:
    if s - start > 12:
        cuts.append((start, (s + e) / 2)); start = (s + e) / 2
cuts.append((start, len(b) / 16000))
words = []
for s, e in cuts:
    r = mlx_whisper.transcribe(b[int(s * 16000): int(e * 16000)], path_or_hf_repo='mlx-community/whisper-large-v3-turbo',
                               language='bn', word_timestamps=True, condition_on_previous_text=False)
    words += [[x['word'].strip(), round(s + x['start'], 3), round(s + x['end'], 3)] for seg in r['segments'] for x in seg.get('words', [])]
json.dump({'duration': round(len(b) / 16000, 3), 'words': words}, open('src/reel-whisper.json', 'w'), ensure_ascii=False, indent=0)

# 3. Sentence starts ---------------------------------------------------------
def split(t):
    return [x.strip() for x in re.split(r'(?<=[।?!])\s+', t) if x.strip()]

_DROP = re.compile('[ঁ-ঃ়া-্ৗ‌‍।,.?!:;\'"()-]')
_MAP = str.maketrans({'শ': 'স', 'ষ': 'স', 'ণ': 'ন', 'য': 'জ', 'ছ': 'চ', 'ঢ': 'ড', 'ঠ': 'ট', 'ঘ': 'গ', 'খ': 'ক', 'ধ': 'দ', 'থ': 'ত', 'ভ': 'ব', 'ফ': 'প', 'ঝ': 'জ', 'ঐ': 'অ', 'ঔ': 'অ', 'আ': 'অ', 'ই': 'অ', 'ঈ': 'অ', 'উ': 'অ', 'ঊ': 'অ', 'এ': 'অ', 'ও': 'অ'})
skel = lambda t: _DROP.sub('', unicodedata.normalize('NFD', t)).translate(_MAP).replace(' ', '')
nchars = lambda t: len(re.sub(r'\s+', '', t))

def anchor(sent, lo, hi):
    toks = sent.split()
    if not toks or re.search('[A-Za-z0-9]', toks[0]):
        return None
    want = skel(' '.join(toks[:2]))
    if len(want) < 3:
        return None
    best = None
    for i, w in enumerate(words):
        if lo <= w[1] <= hi:
            heard = skel(' '.join(x[0] for x in words[i:i + 2]))[: len(want) + 1]
            r = SequenceMatcher(None, want, heard).ratio()
            if r >= 0.7 and (best is None or r > best[0]):
                best = (r, w[1])
    return best[1] if best else None

script = json.load(open('reel-script.json'))
flat = [(sc['id'], k, t) for sc in script for k, t in enumerate(split(sc['text']))]
total = sum(nchars(t) for _, _, t in flat)
resume = [e for _, e in v_gaps]  # speech resumes after each pause
starts, how, cum = [v_first], ['start'], nchars(flat[0][2])
for j in range(1, len(flat)):
    expect = v_first + (v_last - v_first) * cum / total
    cum += nchars(flat[j][2])
    lo = starts[-1] + 0.4
    a_ = anchor(flat[j][2], max(lo, expect - 4), expect + 4)
    target, h = (a_, 'heard') if a_ is not None and a_ >= lo else (expect, 'share')
    near = [g for g in resume if g >= lo and abs(g - target) <= (0.6 if h == 'heard' else 2.0)]
    starts.append(min(near, key=lambda g: abs(g - target)) if near else max(target, lo))
    how.append(h)
ends = starts[1:] + [v_last]
timing = {'duration': round(len(b) / 16000, 3), 'sentences': []}
for (sid, k, t), s, e, h in zip(flat, starts, ends, how):
    timing['sentences'].append({'scene': sid, 'k': k, 'start': round(s, 2), 'end': round(e, 2), 'text': t})
    heard = ' '.join(w[0] for w in words if s - 0.05 <= w[1] < s + 1.2)
    print(f'{sid:12s}{k} {s:6.2f} {h[0]} | {t[:28]:28s} | heard: {heard[:40]}')
json.dump(timing, open('src/reel-timing.json', 'w'), ensure_ascii=False, indent=1)
