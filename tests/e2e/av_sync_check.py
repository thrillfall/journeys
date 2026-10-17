"""A/V sync check for user-video clips inside a rendered journey film.

    python3 -I av_sync_check.py <film.mp4> <film_w> <film_h> <source videos...>

For each source video the clip's sound is located in the film by
cross-correlation (sample-exact). The picture is then compared against the
source only within +-10 frames of that position, so a clip that opens on
near-identical frames cannot pull the estimate off. The reported A-V offset is
how far the picture sits from the sound; |A-V| below one frame (33 ms) is in
sync.

Sources and film must be decoded the same way (gap-filling resample), the way
a player schedules them; plain decoding drops gaps in phone audio and shifts
the reference by tens of ms.
"""
import json
import subprocess
import sys

import numpy as np

FPS = 30
SR = 16000
THUMB = 24
SEARCH_FRAMES = 10


def probe(path):
    out = subprocess.run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries',
                          'stream=width,height:stream_side_data=rotation', '-of', 'json', path],
                         capture_output=True, text=True, check=True).stdout
    s = json.loads(out)['streams'][0]
    w, h = s['width'], s['height']
    if any(abs(int(sd.get('rotation', 0))) == 90 for sd in s.get('side_data_list', [])):
        w, h = h, w
    return w, h


def thumbs(path, side, ox, oy):
    vf = f'fps={FPS},crop={side}:{side}:{ox}:{oy},scale={THUMB}:{THUMB}:flags=area,format=gray'
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', path, '-vf', vf, '-f', 'rawvideo', '-'],
                         capture_output=True, check=True).stdout
    a = np.frombuffer(raw, np.uint8).astype(np.float32).reshape(-1, THUMB * THUMB)
    a -= a.mean(axis=1, keepdims=True)
    a /= a.std(axis=1, keepdims=True) + 1e-6
    return a


def audio(path):
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', path,
                          '-af', 'aresample=async=1:min_hard_comp=0.01:first_pts=0',
                          '-ac', '1', '-ar', str(SR), '-f', 'f32le', '-'],
                         capture_output=True, check=True).stdout
    return np.frombuffer(raw, np.float32).astype(np.float64)


def locate_sound(src, film):
    n = 1 << int(np.ceil(np.log2(len(src) + len(film))))
    c = np.fft.irfft(np.fft.rfft(film, n) * np.conj(np.fft.rfft(src, n)), n)
    c = np.concatenate([c[-(len(src) - 1):], c[:len(film)]])
    i = int(np.argmax(c))
    strength = c[i] / (np.median(np.abs(c)) + 1e-9)
    return (i - (len(src) - 1)) / SR, strength


def picture_offset(src_thumbs, film_thumbs, sound_lag):
    """Frames by which the picture trails the sound (positive = picture late)."""
    base = int(round(sound_lag * FPS))
    idx = np.arange(len(src_thumbs))
    scores = []
    for d in range(-SEARCH_FRAMES, SEARCH_FRAMES + 1):
        k = idx + base + d
        ok = (k >= 0) & (k < len(film_thumbs))
        if ok.sum() < FPS:
            scores.append(-1.0)
            continue
        sims = (src_thumbs[idx[ok]] * film_thumbs[k[ok]]).sum(axis=1) / (THUMB * THUMB)
        # the film holds only a ~4 s excerpt of the source: score its best 2 s
        scores.append(float(np.mean(np.sort(sims)[-2 * FPS:])))
    b = int(np.argmax(scores))
    frac = 0.0
    if 0 < b < len(scores) - 1:
        y0, y1, y2 = scores[b - 1], scores[b], scores[b + 1]
        den = y0 - 2 * y1 + y2
        frac = 0.5 * (y0 - y2) / den if den else 0.0
    return (b - SEARCH_FRAMES + frac) + (base - sound_lag * FPS), scores[b]


def main(film, canvas_w, canvas_h, sources):
    film_audio = audio(film)
    canvas_ratio = canvas_w / canvas_h
    film_cache = {}
    worst = 0.0
    print(f"{'source':40} {'lag (s)':>8} {'A-V ms':>8} {'sound':>7} {'picture':>8}")
    for src in sources:
        name = src.rsplit('/', 1)[-1]
        lag, strength = locate_sound(audio(src), film_audio)
        if strength < 12:
            print(f'{name:40} sound not identified (quiet or silent clip, xcorr {strength:.1f})')
            continue
        w, h = probe(src)
        fill = abs(w / h - canvas_ratio) / canvas_ratio < 0.15
        scale = max(canvas_w / w, canvas_h / h) if fill else min(canvas_w / w, canvas_h / h)
        src_side = int(min(w, h) * 0.6) // 2 * 2
        film_side = min(int(src_side * scale) // 2 * 2, canvas_w, canvas_h)
        if film_side not in film_cache:
            film_cache[film_side] = thumbs(film, film_side, (canvas_w - film_side) // 2, (canvas_h - film_side) // 2)
        frames, similarity = picture_offset(thumbs(src, src_side, (w - src_side) // 2, (h - src_side) // 2),
                                            film_cache[film_side], lag)
        if similarity < 0.8:
            print(f'{name:40} picture not identified near its sound (similarity {similarity:.2f})')
            continue
        av = -frames * 1000 / FPS
        worst = max(worst, abs(av))
        print(f'{name:40} {lag:8.3f} {av:8.1f} {strength:7.1f} {similarity:8.3f}')
    print(f'worst |A-V| = {worst:.1f} ms')


if __name__ == '__main__':
    main(sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4:])
