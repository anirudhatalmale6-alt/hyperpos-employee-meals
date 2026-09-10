/*
 * Fingerprint minutiae extraction, in the browser.
 *
 * Why this exists: turning a fingerprint picture into a set of points is the
 * expensive half of the work, and doing it on the server means the server
 * needs Python with OpenCV. Shared hosting frequently does not have that, and
 * the canteen must not depend on any other machine. The till is a real Windows
 * PC that already holds the captured image, so it does the maths and sends a
 * small set of coordinates instead of a 232 KB picture.
 *
 * Same pipeline as resources/python/fpmatch.py:
 *
 *   segment -> orientation field -> Gabor -> binarise -> thin -> prune spurs
 *           -> crossing-number minutiae -> dedupe
 *
 * ⚠️ ONE DELIBERATE DIFFERENCE from the Python: the Gabor filter is applied
 * BLOCK-WISE - one convolution per 16x16 block using that block's own ridge
 * angle - instead of filtering the whole image once per orientation and
 * choosing per pixel. It is the same idea and the standard way to do it, but
 * roughly twelve times less arithmetic, which is what makes it viable at a
 * till. Because it is not bit-identical to the Python, the thresholds were
 * re-measured on the same real prints rather than assumed to carry over.
 *
 * No build step and no dependencies: it is loaded with a plain script tag,
 * exactly like the DigitalPersona SDK files.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.fpExtract = factory();
    }
}(typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : this), function () {
    'use strict';

    /*
     * Defaults. Every one of these was chosen by sweeping it against real
     * prints and keeping what separated genuine from impostor best - see
     * scratchpad/js_extract_sweep.py. They are overridable so that sweep can
     * run without editing this file.
     */
    var DEFAULTS = {
        BLOCK: 16,
        GABOR_KSIZE: 15,
        RIDGE_PERIOD: 9.0,
        GABOR_SIGMA: 4.0,
        GABOR_GAMMA: 0.6,
        MIN_MINUTIAE: 8,
        BORDER_MARGIN: 12,
        MIN_GAP: 10.0,        // two points closer than this are one broken ridge
        COHERENCE_MIN: 0.35,
        SPUR_PASSES: 8,
        THRESH_OFFSET: 5,     // local-mean binarisation offset
    };

    var BLOCK, GABOR_KSIZE, RIDGE_PERIOD, GABOR_SIGMA, GABOR_GAMMA,
        MIN_MINUTIAE, BORDER_MARGIN, MIN_GAP, COHERENCE_MIN, SPUR_PASSES,
        THRESH_OFFSET;

    function applyOptions(options) {
        var o = {}, k;
        for (k in DEFAULTS) { o[k] = DEFAULTS[k]; }
        if (options) { for (k in options) { if (k in o) { o[k] = options[k]; } } }
        BLOCK = o.BLOCK; GABOR_KSIZE = o.GABOR_KSIZE; RIDGE_PERIOD = o.RIDGE_PERIOD;
        GABOR_SIGMA = o.GABOR_SIGMA; GABOR_GAMMA = o.GABOR_GAMMA;
        MIN_MINUTIAE = o.MIN_MINUTIAE; BORDER_MARGIN = o.BORDER_MARGIN;
        MIN_GAP = o.MIN_GAP; COHERENCE_MIN = o.COHERENCE_MIN;
        SPUR_PASSES = o.SPUR_PASSES; THRESH_OFFSET = o.THRESH_OFFSET;
    }
    applyOptions(null);

    /* ── small helpers ──────────────────────────────────────────────── */

    function boxFilter(src, w, h, radius) {
        var tmp = new Float64Array(w * h);
        var out = new Float64Array(w * h);
        var x, y, i, acc, n;

        for (y = 0; y < h; y++) {
            acc = 0; n = 0;
            for (x = 0; x < w; x++) {
                acc += src[y * w + x]; n++;
                if (x - radius - 1 >= 0) { acc -= src[y * w + x - radius - 1]; n--; }
                if (x >= radius) { tmp[y * w + x - radius] = acc / n; }
            }
            for (x = Math.max(0, w - radius); x < w; x++) { tmp[y * w + x] = acc / Math.max(n, 1); }
        }
        for (x = 0; x < w; x++) {
            acc = 0; n = 0;
            for (y = 0; y < h; y++) {
                acc += tmp[y * w + x]; n++;
                if (y - radius - 1 >= 0) { acc -= tmp[(y - radius - 1) * w + x]; n--; }
                if (y >= radius) { out[(y - radius) * w + x] = acc / n; }
            }
            for (y = Math.max(0, h - radius); y < h; y++) { out[y * w + x] = acc / Math.max(n, 1); }
        }
        return out;
    }

    function sobel(src, w, h, dx) {
        var out = new Float64Array(w * h);
        var kx = dx ? [-1, 0, 1, -2, 0, 2, -1, 0, 1] : [-1, -2, -1, 0, 0, 0, 1, 2, 1];
        for (var y = 1; y < h - 1; y++) {
            for (var x = 1; x < w - 1; x++) {
                var s = 0, k = 0;
                for (var j = -1; j <= 1; j++) {
                    for (var i = -1; i <= 1; i++) {
                        s += src[(y + j) * w + (x + i)] * kx[k++];
                    }
                }
                out[y * w + x] = s;
            }
        }
        return out;
    }

    /* ── stages ─────────────────────────────────────────────────────── */

    /** Foreground: ridges have local variance, blank sensor does not. */
    function segment(gray, w, h) {
        var mask = new Uint8Array(w * h);
        for (var by = 0; by < h; by += BLOCK) {
            for (var bx = 0; bx < w; bx += BLOCK) {
                var sum = 0, sum2 = 0, n = 0;
                for (var y = by; y < Math.min(by + BLOCK, h); y++) {
                    for (var x = bx; x < Math.min(bx + BLOCK, w); x++) {
                        var v = gray[y * w + x]; sum += v; sum2 += v * v; n++;
                    }
                }
                if (!n) { continue; }
                var std = Math.sqrt(Math.max(0, sum2 / n - (sum / n) * (sum / n)));
                if (std > 12) {
                    for (var yy = by; yy < Math.min(by + BLOCK, h); yy++) {
                        for (var xx = bx; xx < Math.min(bx + BLOCK, w); xx++) {
                            mask[yy * w + xx] = 1;
                        }
                    }
                }
            }
        }
        return mask;
    }

    /** Ridge direction and how consistent it is, per pixel. */
    function orientation(gray, w, h) {
        var gx = sobel(gray, w, h, true);
        var gy = sobel(gray, w, h, false);
        var gxx = new Float64Array(w * h);
        var gyy = new Float64Array(w * h);
        var gxy = new Float64Array(w * h);
        for (var i = 0; i < w * h; i++) {
            gxx[i] = gx[i] * gx[i];
            gyy[i] = gy[i] * gy[i];
            gxy[i] = gx[i] * gy[i];
        }
        var r = BLOCK >> 1;
        gxx = boxFilter(gxx, w, h, r);
        gyy = boxFilter(gyy, w, h, r);
        gxy = boxFilter(gxy, w, h, r);

        var angle = new Float64Array(w * h);
        var coherence = new Float64Array(w * h);
        for (var j = 0; j < w * h; j++) {
            angle[j] = 0.5 * Math.atan2(2 * gxy[j], gxx[j] - gyy[j]) + Math.PI / 2;
            var num = Math.sqrt((gxx[j] - gyy[j]) * (gxx[j] - gyy[j]) + 4 * gxy[j] * gxy[j]);
            coherence[j] = num / (gxx[j] + gyy[j] + 1e-6);
        }
        return { angle: angle, coherence: coherence };
    }

    function gaborKernel(theta) {
        var half = GABOR_KSIZE >> 1;
        var k = new Float64Array(GABOR_KSIZE * GABOR_KSIZE);
        var ct = Math.cos(theta), st = Math.sin(theta);
        var idx = 0;
        for (var y = -half; y <= half; y++) {
            for (var x = -half; x <= half; x++) {
                var xr = x * ct + y * st;
                var yr = -x * st + y * ct;
                k[idx++] = Math.exp(-(xr * xr + GABOR_GAMMA * GABOR_GAMMA * yr * yr) / (2 * GABOR_SIGMA * GABOR_SIGMA))
                    * Math.cos(2 * Math.PI * xr / RIDGE_PERIOD);
            }
        }
        return k;
    }

    /**
     * Gabor per block, using that block's ridge angle, then a local mean
     * threshold. One convolution per block rather than one per orientation
     * over the whole image.
     */
    function enhance(gray, w, h, ori, mask) {
        var out = new Float64Array(w * h);
        var half = GABOR_KSIZE >> 1;
        var kernels = {};

        for (var by = 0; by < h; by += BLOCK) {
            for (var bx = 0; bx < w; bx += BLOCK) {
                var cy = Math.min(by + (BLOCK >> 1), h - 1);
                var cx = Math.min(bx + (BLOCK >> 1), w - 1);
                if (!mask[cy * w + cx]) { continue; }

                // quantise the angle so kernels are built once and reused
                var q = Math.round((ori.angle[cy * w + cx] % Math.PI) / (Math.PI / 24));
                var key = String(q);
                var kern = kernels[key] || (kernels[key] = gaborKernel(q * (Math.PI / 24)));

                for (var y = by; y < Math.min(by + BLOCK, h); y++) {
                    for (var x = bx; x < Math.min(bx + BLOCK, w); x++) {
                        if (!mask[y * w + x]) { continue; }
                        var s = 0, ki = 0;
                        for (var j = -half; j <= half; j++) {
                            var yy = y + j;
                            if (yy < 0 || yy >= h) { ki += GABOR_KSIZE; continue; }
                            for (var i = -half; i <= half; i++) {
                                var xx = x + i;
                                if (xx >= 0 && xx < w) { s += gray[yy * w + xx] * kern[ki]; }
                                ki++;
                            }
                        }
                        out[y * w + x] = s;
                    }
                }
            }
        }

        // local mean threshold, 25x25, offset 5 - the adaptive threshold the
        // Python does with cv2.ADAPTIVE_THRESH_MEAN_C
        var mean = boxFilter(out, w, h, 12);
        var bin = new Uint8Array(w * h);
        for (var p = 0; p < w * h; p++) {
            bin[p] = (mask[p] && out[p] > mean[p] + THRESH_OFFSET) ? 1 : 0;
        }
        return bin;
    }

    function crossingNumber(img, w, h, x, y) {
        var p = [
            img[(y - 1) * w + x], img[(y - 1) * w + x + 1], img[y * w + x + 1], img[(y + 1) * w + x + 1],
            img[(y + 1) * w + x], img[(y + 1) * w + x - 1], img[y * w + x - 1], img[(y - 1) * w + x - 1],
        ];
        var s = 0;
        for (var i = 0; i < 8; i++) { s += Math.abs(p[i] - p[(i + 1) % 8]); }
        return s >> 1;
    }

    /** Zhang-Suen, written out - there is no library here to call. */
    function thin(bin, w, h) {
        var img = Uint8Array.from(bin);
        var changed = true;
        var pass = 0;

        while (changed && pass < 60) {
            changed = false;
            for (var step = 0; step < 2; step++) {
                var kill = [];
                for (var y = 1; y < h - 1; y++) {
                    for (var x = 1; x < w - 1; x++) {
                        if (!img[y * w + x]) { continue; }
                        var p2 = img[(y - 1) * w + x], p3 = img[(y - 1) * w + x + 1],
                            p4 = img[y * w + x + 1], p5 = img[(y + 1) * w + x + 1],
                            p6 = img[(y + 1) * w + x], p7 = img[(y + 1) * w + x - 1],
                            p8 = img[y * w + x - 1], p9 = img[(y - 1) * w + x - 1];
                        var b = p2 + p3 + p4 + p5 + p6 + p7 + p8 + p9;
                        if (b < 2 || b > 6) { continue; }
                        var seq = [p2, p3, p4, p5, p6, p7, p8, p9, p2];
                        var a = 0;
                        for (var i = 0; i < 8; i++) { if (seq[i] === 0 && seq[i + 1] === 1) { a++; } }
                        if (a !== 1) { continue; }
                        if (step === 0) {
                            if (p2 * p4 * p6 !== 0 || p4 * p6 * p8 !== 0) { continue; }
                        } else {
                            if (p2 * p4 * p8 !== 0 || p2 * p6 * p8 !== 0) { continue; }
                        }
                        kill.push(y * w + x);
                    }
                }
                if (kill.length) {
                    for (var k = 0; k < kill.length; k++) { img[kill[k]] = 0; }
                    changed = true;
                }
            }
            pass++;
        }
        return img;
    }

    /**
     * Shave hairs off the skeleton. Thinning a noisy binarisation leaves short
     * spurs and every spur tip reads as a ridge ending; left alone they swamp
     * the real minutiae.
     */
    function pruneSpurs(img, w, h) {
        for (var pass = 0; pass < SPUR_PASSES; pass++) {
            var kill = [];
            for (var y = 1; y < h - 1; y++) {
                for (var x = 1; x < w - 1; x++) {
                    if (img[y * w + x] && crossingNumber(img, w, h, x, y) === 1) {
                        kill.push(y * w + x);
                    }
                }
            }
            if (!kill.length) { break; }
            for (var i = 0; i < kill.length; i++) { img[kill[i]] = 0; }
        }
        return img;
    }

    function erode(mask, w, h, radius) {
        var out = new Uint8Array(w * h);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var keep = 1;
                for (var j = -radius; j <= radius && keep; j++) {
                    for (var i = -radius; i <= radius; i++) {
                        var yy = y + j, xx = x + i;
                        if (yy < 0 || yy >= h || xx < 0 || xx >= w || !mask[yy * w + xx]) { keep = 0; break; }
                    }
                }
                out[y * w + x] = keep;
            }
        }
        return out;
    }

    function minutiae(skel, w, h, mask, ori) {
        var eroded = erode(mask, w, h, BORDER_MARGIN);
        var points = [];
        for (var y = 1; y < h - 1; y++) {
            for (var x = 1; x < w - 1; x++) {
                if (!skel[y * w + x] || !eroded[y * w + x]) { continue; }
                var cn = crossingNumber(skel, w, h, x, y);
                if (cn !== 1 && cn !== 3) { continue; }
                points.push({
                    x: x, y: y,
                    t: cn === 1 ? 'end' : 'bif',
                    a: Math.round(ori.angle[y * w + x] * 10000) / 10000,
                });
            }
        }

        // two points closer than a ridge period apart are one broken ridge
        var kept = [];
        for (var p = 0; p < points.length; p++) {
            var ok = true;
            for (var q = 0; q < kept.length; q++) {
                var dx = points[p].x - kept[q].x, dy = points[p].y - kept[q].y;
                if (dx * dx + dy * dy < MIN_GAP * MIN_GAP) { ok = false; break; }
            }
            if (ok) { kept.push(points[p]); }
        }
        return kept;
    }

    /* ── entry point ────────────────────────────────────────────────── */

    /**
     * @param {Uint8ClampedArray|Uint8Array|Array} gray  one byte per pixel
     * @returns {{w:number,h:number,count:number,usable:boolean,minutiae:Array}}
     */
    function extract(gray, w, h, options) {
        applyOptions(options);

        var g = new Float64Array(w * h);
        var min = 255, max = 0, i;
        for (i = 0; i < w * h; i++) {
            if (gray[i] < min) { min = gray[i]; }
            if (gray[i] > max) { max = gray[i]; }
        }
        var span = Math.max(1, max - min);
        for (i = 0; i < w * h; i++) { g[i] = (gray[i] - min) * 255 / span; }

        var mask = segment(g, w, h);
        var ori = orientation(g, w, h);

        // blocks with an inconsistent ridge direction are smudge, not ridge
        for (i = 0; i < w * h; i++) {
            if (ori.coherence[i] < COHERENCE_MIN) { mask[i] = 0; }
        }

        var bin = enhance(g, w, h, ori, mask);
        var skel = pruneSpurs(thin(bin, w, h), w, h);
        var pts = minutiae(skel, w, h, mask, ori);

        return {
            w: w, h: h,
            count: pts.length,
            usable: pts.length >= MIN_MINUTIAE,
            minutiae: pts,
        };
    }

    /** Pull grey bytes out of a canvas ImageData and extract. */
    function extractFromImageData(imageData) {
        var w = imageData.width, h = imageData.height, d = imageData.data;
        var gray = new Uint8Array(w * h);
        for (var i = 0, p = 0; i < d.length; i += 4, p++) {
            gray[p] = (d[i] * 299 + d[i + 1] * 587 + d[i + 2] * 114) / 1000;
        }
        return extract(gray, w, h);
    }

    return {
        extract: extract,
        extractFromImageData: extractFromImageData,
        MIN_MINUTIAE: MIN_MINUTIAE,
    };
}));
