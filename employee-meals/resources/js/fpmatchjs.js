/*
 * Compare minutiae templates, in the browser.
 *
 * Extraction moved to the till PC so the server needs nothing installed. The
 * comparison has to follow it: the same algorithm in PHP measured 20 ms per
 * comparison, which is 16 seconds to search fifty staff - unusable at a till.
 * The same work in JavaScript is a fraction of that, and the till PC is idle
 * while the cashier types anyway.
 *
 * Same algorithm as the Python and PHP versions: a Hough vote over the rigid
 * transform, then a count of pairs that genuinely agree under the winning
 * transform.
 *
 * ⚠️ The tolerances are not taste - see the note in fpextract.js. The Hough
 * stage maximises over a large search space, and a maximum over a large space
 * flatters itself, so loose tolerances let strangers score.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.fpMatch = factory();
    }
}(typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : this), function () {
    'use strict';

    var DIST_TOL = 8.0;
    var ANGLE_TOL = 15 * Math.PI / 180;
    var HOUGH_BIN = 6.0;
    var ROT_FROM = -30, ROT_TO = 30, ROT_STEP = 2;
    var PI = Math.PI;

    /**
     * Pack a template into flat typed arrays once, so the inner loops touch
     * numbers instead of objects. This is most of the speed.
     */
    function pack(minutiae) {
        var n = minutiae.length;
        var out = {
            n: n,
            x: new Float64Array(n),
            y: new Float64Array(n),
            a: new Float64Array(n),
            t: new Uint8Array(n),        // 0 = ending, 1 = bifurcation
        };
        for (var i = 0; i < n; i++) {
            out.x[i] = minutiae[i].x;
            out.y[i] = minutiae[i].y;
            out.a[i] = minutiae[i].a;
            out.t[i] = minutiae[i].t === 'bif' ? 1 : 0;
        }
        return out;
    }

    /*
     * Hough accumulator as a flat typed array rather than a Map.
     *
     * Translations are bounded by the sensor, so every bin fits in a fixed
     * grid. A Map cost about 7 ms a comparison, which is five and a half
     * seconds to search fifty staff - too slow for a till. Only the bins
     * actually touched are cleared between rotations, so the grid is never
     * walked in full.
     */
    var ACC_SIZE = 256;                       // bins per axis, 6 px each
    var ACC_HALF = ACC_SIZE >> 1;
    var acc = new Int32Array(ACC_SIZE * ACC_SIZE);
    var touched = new Int32Array(ACC_SIZE * ACC_SIZE);

    function houghPeak(p, c, degFrom, degTo, degStep, out) {
        var bestVotes = 0, bestRad = 0, bestTx = 0, bestTy = 0;

        for (var deg = degFrom; deg <= degTo; deg += degStep) {
            var rad = deg * PI / 180;
            var cos = Math.cos(rad), sin = Math.sin(rad);
            var nTouched = 0;

            for (var i = 0; i < p.n; i++) {
                var rx = p.x[i] * cos - p.y[i] * sin;
                var ry = p.x[i] * sin + p.y[i] * cos;
                var ti = p.t[i];

                for (var j = 0; j < c.n; j++) {
                    if (c.t[j] !== ti) { continue; }
                    // ⚠️ `| 0` truncates TOWARDS ZERO, which is not rounding:
                    // -2.3 becomes -1 where Math.round gives -2. Half the bins
                    // are negative here, so the shortcut silently changed the
                    // scores - genuine pairs fell from 25 to 1. Bias by a
                    // constant first so the truncation always acts on a
                    // positive number, then take it back off.
                    var dx = (((c.x[j] - rx) / HOUGH_BIN + 0.5 + 4096) | 0) - 4096;
                    var dy = (((c.y[j] - ry) / HOUGH_BIN + 0.5 + 4096) | 0) - 4096;
                    var bx = dx + ACC_HALF, by = dy + ACC_HALF;
                    if (bx < 0 || bx >= ACC_SIZE || by < 0 || by >= ACC_SIZE) { continue; }

                    var idx = by * ACC_SIZE + bx;
                    var v = acc[idx];
                    if (v === 0) { touched[nTouched++] = idx; }
                    v++;
                    acc[idx] = v;

                    if (v > bestVotes) {
                        bestVotes = v; bestRad = rad;
                        bestTx = dx * HOUGH_BIN; bestTy = dy * HOUGH_BIN;
                    }
                }
            }

            for (var k = 0; k < nTouched; k++) { acc[touched[k]] = 0; }
        }

        out.votes = bestVotes; out.rad = bestRad; out.tx = bestTx; out.ty = bestTy;
        return bestVotes;
    }

    var peak = { votes: 0, rad: 0, tx: 0, ty: 0 };

    function score(probeIn, candidateIn) {
        var p = probeIn.n === undefined ? pack(probeIn) : probeIn;
        var c = candidateIn.n === undefined ? pack(candidateIn) : candidateIn;
        if (!p.n || !c.n) { return 0; }

        /*
         * ⚠️ Sweep EVERY rotation. A coarse pass followed by a refinement
         * around its winner looked like an easy 2x, and it changed the
         * answers: a genuine pair dropped from 27 to 4 and an impostor rose
         * from 0 to 4. The vote count is not smooth in rotation, so the
         * coarse winner is often not near the true peak. The typed-array
         * accumulator is where the speed actually came from - nine times
         * faster with identical scores - and that is kept.
         */
        houghPeak(p, c, ROT_FROM, ROT_TO, ROT_STEP, peak);

        var bestVotes = peak.votes, bestRad = peak.rad,
            bestTx = peak.tx, bestTy = peak.ty;

        if (!bestVotes) { return 0; }

        var ccos = Math.cos(bestRad), csin = Math.sin(bestRad);
        var used = new Uint8Array(c.n);
        var paired = 0;

        for (var k = 0; k < p.n; k++) {
            var ax = p.x[k] * ccos - p.y[k] * csin + bestTx;
            var ay = p.x[k] * csin + p.y[k] * ccos + bestTy;
            var aa = (p.a[k] + bestRad) % PI;
            var tk = p.t[k];

            // nearest qualifying candidate, not the first found - otherwise a
            // far-but-tolerable point consumes the slot its true partner needed
            var nearest = -1, nearestD = DIST_TOL;

            for (var m = 0; m < c.n; m++) {
                if (used[m] || c.t[m] !== tk) { continue; }
                var ddx = ax - c.x[m], ddy = ay - c.y[m];
                var d = Math.sqrt(ddx * ddx + ddy * ddy);
                if (d > nearestD) { continue; }
                var da = Math.abs(aa - c.a[m]) % PI;
                if (Math.min(da, PI - da) > ANGLE_TOL) { continue; }
                nearest = m; nearestD = d;
            }

            if (nearest >= 0) { used[nearest] = 1; paired++; }
        }

        return paired;
    }

    /**
     * Search a population. Candidates are [{id, samples: [minutiae, ...]}].
     * The BEST stored touch counts - averaging punishes one crooked press.
     */
    function identify(probe, candidates, acceptThreshold) {
        var packed = pack(probe.minutiae || probe);
        var results = [];

        for (var i = 0; i < candidates.length; i++) {
            var best = 0;
            var samples = candidates[i].samples || [];
            for (var j = 0; j < samples.length; j++) {
                var s = score(packed, pack(samples[j].minutiae || samples[j]));
                if (s > best) { best = s; }
            }
            results.push({ id: candidates[i].id, score: best });
        }

        results.sort(function (a, b) { return b.score - a.score; });
        var top = results[0] || { id: null, score: 0 };
        var runnerUp = results.length > 1 ? results[1].score : 0;

        return {
            id: top.score >= acceptThreshold ? top.id : null,
            score: top.score,
            // the runner-up says whether the reader is really telling two
            // people apart; a high best means little if second is right behind
            runnerUp: runnerUp,
            margin: top.score - runnerUp,
            accepted: top.score >= acceptThreshold,
        };
    }

    return { score: score, identify: identify, pack: pack };
}));
