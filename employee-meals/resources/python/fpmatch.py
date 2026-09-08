#!/usr/bin/env python3
"""
Fingerprint minutiae extraction and matching.

Why this exists: the DigitalPersona JavaScript SDK only CAPTURES. Comparing two
prints is FingerJet, a Windows native library that ships with the paid SDK, so
on a Linux web host the comparison has to be ours.

Pipeline, the textbook one:

    segment -> orientation field -> Gabor bank -> binarise -> thin
            -> crossing-number minutiae -> Hough vote on the transform
            -> count agreeing pairs

Two commands:

    fpmatch.py extract <image>                    -> {"minutiae": [...], ...}
    fpmatch.py match <probe.json> <candidates.json>

`extract` runs once per captured sample and its output is stored, so a
comparison at the till is only the cheap half.

⚠️ cv2.ximgproc.thinning is contrib-only and absent from the plain OpenCV
build on shared hosting, so Zhang-Suen is written out by hand below.
"""

from __future__ import annotations

import json
import math
import sys

import cv2
import numpy as np

# ── tunables ───────────────────────────────────────────────────────────────
BLOCK = 16              # orientation / segmentation block size
GABOR_KSIZE = 15
RIDGE_PERIOD = 9.0      # ~500 dpi prints run 8-10 px per ridge
MIN_MINUTIAE = 8        # a sample below this is too faint to store
BORDER_MARGIN = 12      # ignore minutiae this close to the mask edge

# Matching
#
# ⚠️ These tolerances are not taste. The Hough stage maximises over ~20
# rotations and many translations, and any maximum over a large search space
# flatters itself: loose tolerances let an impostor find *some* transform that
# lines a lot of points up by chance. Measured on synthetic prints, 12 px and
# 25 degrees gave impostor scores up to 40 against genuine scores from 30 -
# overlapping, so no threshold was safe. Tightening to 8 px and 15 degrees
# separated them.
DIST_TOL = 8.0          # px, how far apart two paired points may sit
ANGLE_TOL = math.radians(15)
ROT_STEPS = np.arange(-30, 31, 2)   # degrees searched by the Hough vote
HOUGH_BIN = 6.0         # px per translation bin

# ── decision thresholds ────────────────────────────────────────────────────
#
# MEASURED, 8 Sep 2026, on four real FVC prints (two impressions each of two
# fingers) plus synthetic re-presses of each - shifted, rotated, partly cropped
# and noised:
#
#     different fingers (impostor) : 0, 0, 2, 3        -> never above 3
#     same finger, good overlap    : 16, 20, 21, 47, 51, 57, 63, 74
#     same finger, poor overlap    : 2, 10             <- two captures that
#                                                         barely share an area
#
# So ACCEPT sits at 15: five times the worst impostor seen, and below all but
# the poor-overlap genuine pairs. The band from 8 to 15 is reported as
# uncertain rather than accepted.
#
# The bias is deliberate. A false REJECT costs one more press of the finger.
# A false ACCEPT gives one employee another employee's subsidy and corrupts the
# billing - so the threshold sits far above the impostor band, not midway.
#
# ⚠️ Two fingers is a small sample. This is a safe starting point, not a final
# calibration: re-run the measurement on the real DigitalPersona reader with
# real staff before trusting it in production, and expect to move it.
ACCEPT = 15
UNCERTAIN = 8


def _segment(img: np.ndarray) -> np.ndarray:
    """Foreground mask: fingerprint ridges have local variance, paper does not."""
    h, w = img.shape
    mask = np.zeros((h, w), np.uint8)
    for y in range(0, h, BLOCK):
        for x in range(0, w, BLOCK):
            blk = img[y:y + BLOCK, x:x + BLOCK]
            if blk.size and blk.std() > 12:
                mask[y:y + BLOCK, x:x + BLOCK] = 255
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, np.ones((BLOCK, BLOCK), np.uint8))
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((BLOCK, BLOCK), np.uint8))
    return mask


def _orientation(img: np.ndarray) -> np.ndarray:
    """Ridge direction per block, by the gradient-covariance method."""
    gx = cv2.Sobel(img, cv2.CV_64F, 1, 0, ksize=3)
    gy = cv2.Sobel(img, cv2.CV_64F, 0, 1, ksize=3)

    gxx = cv2.boxFilter(gx * gx, -1, (BLOCK, BLOCK))
    gyy = cv2.boxFilter(gy * gy, -1, (BLOCK, BLOCK))
    gxy = cv2.boxFilter(gx * gy, -1, (BLOCK, BLOCK))

    # ridge angle is perpendicular to the dominant gradient
    return 0.5 * np.arctan2(2 * gxy, gxx - gyy) + math.pi / 2


def _enhance(img: np.ndarray, orient: np.ndarray, mask: np.ndarray) -> np.ndarray:
    """
    Gabor filter tuned to the local ridge direction. Filtering with a bank of
    fixed orientations and picking per-pixel is far quicker than building a
    kernel per pixel, and the difference is not visible in the result.
    """
    angles = np.arange(0, math.pi, math.pi / 12)
    responses = np.stack([
        cv2.filter2D(
            img, cv2.CV_32F,
            cv2.getGaborKernel(
                (GABOR_KSIZE, GABOR_KSIZE), 4.0, float(a), RIDGE_PERIOD, 0.6, 0, ktype=cv2.CV_32F
            ),
        )
        for a in angles
    ])

    idx = np.round((orient % math.pi) / (math.pi / 12)).astype(int) % len(angles)
    enhanced = np.take_along_axis(responses, idx[None, ...], axis=0)[0]

    enhanced = cv2.normalize(enhanced, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)
    binary = cv2.adaptiveThreshold(
        enhanced, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY, 25, 5
    )
    # ridges black -> ridges white(1)
    binary = cv2.bitwise_not(binary)
    binary[mask == 0] = 0
    return (binary > 0).astype(np.uint8)


def _thin(binary: np.ndarray) -> np.ndarray:
    """
    Zhang-Suen thinning, written out because cv2.ximgproc is contrib-only and
    the plain build on shared hosting does not have it.
    """
    img = binary.copy().astype(np.uint8)

    def neighbours(padded):
        return [
            padded[0:-2, 1:-1],  # P2 N
            padded[0:-2, 2:],    # P3 NE
            padded[1:-1, 2:],    # P4 E
            padded[2:, 2:],      # P5 SE
            padded[2:, 1:-1],    # P6 S
            padded[2:, 0:-2],    # P7 SW
            padded[1:-1, 0:-2],  # P8 W
            padded[0:-2, 0:-2],  # P9 NW
        ]

    for _ in range(60):                      # converges long before this
        changed = False
        for step in (0, 1):
            padded = np.pad(img, 1, mode="constant")
            p = neighbours(padded)
            b = sum(p)                       # non-zero neighbours

            seq = p + [p[0]]
            a = sum(((seq[i] == 0) & (seq[i + 1] == 1)).astype(np.uint8) for i in range(8))

            if step == 0:
                c1 = p[0] * p[2] * p[4]
                c2 = p[2] * p[4] * p[6]
            else:
                c1 = p[0] * p[2] * p[6]
                c2 = p[0] * p[4] * p[6]

            kill = (img == 1) & (b >= 2) & (b <= 6) & (a == 1) & (c1 == 0) & (c2 == 0)
            if kill.any():
                img[kill] = 0
                changed = True
        if not changed:
            break

    return img


def _crossing_number(thin: np.ndarray) -> np.ndarray:
    padded = np.pad(thin, 1, mode="constant")
    p = [
        padded[0:-2, 1:-1], padded[0:-2, 2:], padded[1:-1, 2:], padded[2:, 2:],
        padded[2:, 1:-1], padded[2:, 0:-2], padded[1:-1, 0:-2], padded[0:-2, 0:-2],
    ]
    seq = p + [p[0]]
    return sum(np.abs(seq[i].astype(int) - seq[i + 1].astype(int)) for i in range(8)) // 2


def _prune_spurs(thin: np.ndarray, length: int = 8) -> np.ndarray:
    """
    Shave hairs off the skeleton.

    Thinning a noisy binarisation leaves short spurs, and every spur ends in
    what the crossing number happily calls a ridge ending. Left alone they
    swamp the real minutiae: measured on synthetic prints, 195-394 "minutiae"
    per sample where a real finger carries 30-80, and genuine and impostor
    scores overlapped so badly that no threshold was safe.

    Removing the tip of every endpoint `length` times deletes any branch
    shorter than that and leaves true ridges, which are far longer, untouched.
    """
    img = thin.copy()
    for _ in range(length):
        ends = (img == 1) & (_crossing_number(img) == 1)
        if not ends.any():
            break
        img[ends] = 0
    return img


def _dedupe(points: list[dict], min_gap: float = 10.0) -> list[dict]:
    """
    Two minutiae closer than a ridge period apart are one broken ridge seen
    twice, not two features. Keep the first and drop the rest.
    """
    kept: list[dict] = []
    for p in points:
        if all(math.hypot(p["x"] - q["x"], p["y"] - q["y"]) >= min_gap for q in kept):
            kept.append(p)
    return kept


def _coherence(img: np.ndarray) -> np.ndarray:
    """
    How consistently the gradients in a block point one way. Low coherence
    means smudge or blank sensor, where any "minutia" found is an artefact.
    """
    gx = cv2.Sobel(img, cv2.CV_64F, 1, 0, ksize=3)
    gy = cv2.Sobel(img, cv2.CV_64F, 0, 1, ksize=3)
    gxx = cv2.boxFilter(gx * gx, -1, (BLOCK, BLOCK))
    gyy = cv2.boxFilter(gy * gy, -1, (BLOCK, BLOCK))
    gxy = cv2.boxFilter(gx * gy, -1, (BLOCK, BLOCK))

    num = np.sqrt((gxx - gyy) ** 2 + 4 * gxy ** 2)
    den = gxx + gyy + 1e-6
    return num / den


def _minutiae(thin: np.ndarray, mask: np.ndarray, orient: np.ndarray) -> list[dict]:
    """Crossing number: 1 neighbour = ridge ending, 3 = bifurcation."""
    cn = _crossing_number(thin)

    # keep away from the edge of the captured area: a ridge cut off by the
    # sensor border looks exactly like an ending and is not one
    eroded = cv2.erode(mask, np.ones((BORDER_MARGIN * 2, BORDER_MARGIN * 2), np.uint8))

    out = []
    ys, xs = np.where((thin == 1) & (eroded > 0) & ((cn == 1) | (cn == 3)))
    for y, x in zip(ys, xs):
        out.append({
            "x": int(x),
            "y": int(y),
            "t": "end" if cn[y, x] == 1 else "bif",
            "a": round(float(orient[y, x]), 4),
        })
    return _dedupe(out)


def extract(path: str) -> dict:
    img = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if img is None:
        raise SystemExit(json.dumps({"error": "could not read the image"}))

    img = cv2.normalize(img, None, 0, 255, cv2.NORM_MINMAX)
    img = cv2.GaussianBlur(img, (3, 3), 0)

    mask = _segment(img)
    orient = _orientation(img.astype(np.float32))

    # blocks where the ridge direction is not consistent are smudge, not ridge;
    # anything found there is an artefact
    mask[_coherence(img.astype(np.float32)) < 0.35] = 0

    binary = _enhance(img.astype(np.float32), orient, mask)
    thin = _prune_spurs(_thin(binary))
    pts = _minutiae(thin, mask, orient)

    return {
        "w": int(img.shape[1]),
        "h": int(img.shape[0]),
        "count": len(pts),
        "usable": len(pts) >= MIN_MINUTIAE,
        "minutiae": pts,
    }


def _score(probe: list[dict], candidate: list[dict]) -> int:
    """
    Hough vote on the rigid transform, then count agreeing pairs.

    Every (probe, candidate) pairing implies a rotation and a translation. The
    true alignment is voted for by many pairs; noise scatters. Take the winning
    bin, then count how many pairs genuinely agree under it - that count is the
    score, not the vote total, because the vote total rewards clutter.
    """
    if not probe or not candidate:
        return 0

    best = (0, None)
    for deg in ROT_STEPS:
        rad = math.radians(float(deg))
        cos, sin = math.cos(rad), math.sin(rad)
        votes: dict[tuple[int, int], int] = {}

        for a in probe:
            rx = a["x"] * cos - a["y"] * sin
            ry = a["x"] * sin + a["y"] * cos
            for b in candidate:
                if a["t"] != b["t"]:
                    continue
                dx = int(round((b["x"] - rx) / HOUGH_BIN))
                dy = int(round((b["y"] - ry) / HOUGH_BIN))
                key = (dx, dy)
                votes[key] = votes.get(key, 0) + 1

        if votes:
            key, n = max(votes.items(), key=lambda kv: kv[1])
            if n > best[0]:
                best = (n, (rad, key[0] * HOUGH_BIN, key[1] * HOUGH_BIN))

    if best[1] is None:
        return 0

    rad, tx, ty = best[1]
    cos, sin = math.cos(rad), math.sin(rad)

    used = set()
    paired = 0
    for a in probe:
        ax = a["x"] * cos - a["y"] * sin + tx
        ay = a["x"] * sin + a["y"] * cos + ty
        aa = (a["a"] + rad) % math.pi

        # ⚠️ Pair with the NEAREST candidate that qualifies, not the first one
        # found. First-match lets a far-but-inside-tolerance point consume a
        # slot that the true partner needed, which quietly inflates impostor
        # scores.
        nearest, nearest_d = None, DIST_TOL
        for i, b in enumerate(candidate):
            if i in used or a["t"] != b["t"]:
                continue
            d = math.hypot(ax - b["x"], ay - b["y"])
            if d > nearest_d:
                continue
            da = abs(aa - b["a"]) % math.pi
            if min(da, math.pi - da) > ANGLE_TOL:
                continue
            nearest, nearest_d = i, d

        if nearest is not None:
            used.add(nearest)
            paired += 1

    return paired


def match(probe_path: str, candidates_path: str) -> dict:
    with open(probe_path) as fh:
        probe = json.load(fh)
    with open(candidates_path) as fh:
        candidates = json.load(fh)

    probe_pts = probe.get("minutiae", probe if isinstance(probe, list) else [])

    results = []
    for c in candidates:
        # An enrolment is several touches. Match against EVERY stored sample and
        # keep the BEST - averaging punishes one crooked press.
        best = 0
        for sample in c.get("samples", []):
            best = max(best, _score(probe_pts, sample.get("minutiae", [])))
        results.append({"id": c.get("id"), "score": best})

    results.sort(key=lambda r: r["score"], reverse=True)
    top = results[0] if results else {"id": None, "score": 0}
    runner_up = results[1]["score"] if len(results) > 1 else 0

    score = int(top["score"])
    if score >= ACCEPT:
        decision = "accept"
    elif score >= UNCERTAIN:
        decision = "uncertain"
    else:
        decision = "reject"

    return {
        "best": top,
        "decision": decision,
        "accept_threshold": ACCEPT,
        # The runner-up is the number that says whether the reader is really
        # telling two people apart - a high best score means little if second
        # place is right behind it. Always reported, and logged with the test.
        "runner_up": runner_up,
        "margin": score - runner_up,
        "results": results[:5],
    }


if __name__ == "__main__":
    if len(sys.argv) < 2:
        raise SystemExit(json.dumps({"error": "usage: fpmatch.py extract|match ..."}))

    cmd = sys.argv[1]
    if cmd == "extract" and len(sys.argv) == 3:
        print(json.dumps(extract(sys.argv[2])))
    elif cmd == "match" and len(sys.argv) == 4:
        print(json.dumps(match(sys.argv[2], sys.argv[3])))
    else:
        raise SystemExit(json.dumps({"error": "bad arguments"}))
