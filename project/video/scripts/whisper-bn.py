"""Transcribe the Bangla voice clips with word timings → src/bn-whisper.json.

Each voice-bn/NN-id.mp3 is converted to 16 kHz mono WAV with macOS afconvert
(Remotion's bundled ffmpeg cannot emit raw PCM), then run through mlx-whisper
large-v3-turbo with word timestamps.

Usage: .venv/bin/python scripts/whisper-bn.py [scene-id …]
"""
import json, os, subprocess, sys, wave
import numpy as np
import mlx_whisper

MODEL = 'mlx-community/whisper-large-v3-turbo'
SRC, TMP, OUT = 'voice-bn', '.venv/wav16', 'src/bn-whisper.json'
os.makedirs(TMP, exist_ok=True)

def load(path):
    wav = os.path.join(TMP, os.path.basename(path).rsplit('.', 1)[0] + '.wav')
    subprocess.run(['afconvert', '-f', 'WAVE', '-d', 'LEI16@16000', '-c', '1', path, wav], check=True)
    with wave.open(wav) as w:
        pcm = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16)
        return pcm.astype(np.float32) / 32768.0, w.getnframes() / 16000

only = set(sys.argv[1:])
old = {o['id']: o for o in json.load(open(OUT))} if os.path.exists(OUT) else {}
res = []
for f in sorted(os.listdir(SRC)):
    if not f.endswith('.mp3'):
        continue
    sid = f[:-4]
    if only and sid not in only and sid in old:
        res.append(old[sid]); continue
    audio, dur = load(os.path.join(SRC, f))
    r = mlx_whisper.transcribe(audio, path_or_hf_repo=MODEL, language='bn', word_timestamps=True, condition_on_previous_text=False)
    words = [[w['word'].strip(), round(w['start'], 3), round(w['end'], 3)] for s in r['segments'] for w in s.get('words', [])]
    res.append({'id': sid, 'duration': round(dur, 3), 'text': r['text'].strip(), 'words': words})
    print(f"{sid:12s} {dur:5.1f}s {len(words):3d} words | {r['text'].strip()[:90]}")
json.dump(res, open(OUT, 'w'), ensure_ascii=False, indent=0)
