#!/usr/bin/env python3
"""Read the handwritten STUDENT'S ID NO. from an answer sheet's cover page.

Usage:
    read_student_id.py <answer-sheet.pdf> <crop-output.png>

Prints one JSON object to stdout:
    {"status": "ok", "digits": "241002203005", "confidences": [...],
     "min_confidence": 0.93, "cells": 12}
or  {"status": "no_strip" | "error", "message": "..."}

How it works (the cover sheet is a fixed printed template, but these are
phone photos — framing, scale and slight rotation vary, so nothing relies
on absolute coordinates):
  1. Render page 1 at the scan's native resolution.
  2. In the top part of the page, isolate ruled lines (morphology) and find
     "box strips": wide rectangles split by evenly spaced vertical lines.
     The topmost strip with enough boxes is STUDENT'S ID NO. (the
     Registration No. strip sits below it).
  3. Split the strip into its boxes, clean each box, centre the ink
     MNIST-style, and classify it with a small handwritten-digit model
     (ocr/mnist-12.onnx, ONNX model zoo).
The cropped strip is saved as a PNG so a person can verify it visually.
"""
import json
import os
import sys

import cv2
import pymupdf as fitz
import numpy as np
import onnxruntime as ort

HERE = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(HERE, "mnist-12.onnx")
RENDER_DPI = 150
SEARCH_TOP_FRACTION = 0.5  # the ID strip is always in the top half
MIN_CELLS = 6


def render_first_page(pdf_path):
    doc = fitz.open(pdf_path)
    page = doc.load_page(0)
    zoom = RENDER_DPI / 72.0
    pix = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), colorspace=fitz.csGRAY)
    img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width)
    return img.copy()


def binarize(gray):
    # Ink/lines -> 255, paper -> 0. Adaptive to cope with uneven lighting;
    # a light median blur first so camera grain isn't read as ink.
    return cv2.adaptiveThreshold(
        cv2.medianBlur(gray, 3), 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY_INV, 31, 12
    )


def deskew(gray):
    """Rotate the page level, measured from its long printed lines.

    Phone photos are rarely straight, and even a 1 degree tilt breaks box
    detection. The form's long horizontal rules (and the ruled examiner
    table) give the tilt; the median angle of those segments is robust to
    the odd stray line.
    """
    h, w = gray.shape
    # The angle is measured on a half-size copy — same angle, ~4x faster.
    small = cv2.resize(gray, (w // 2, h // 2), interpolation=cv2.INTER_AREA)
    edges = cv2.Canny(cv2.GaussianBlur(small, (3, 3), 0), 50, 150)
    lines = cv2.HoughLinesP(edges, 1, np.pi / 720, threshold=75, minLineLength=int(w * 0.1), maxLineGap=8)
    if lines is None:
        return gray, 0.0
    angles = []
    for x1, y1, x2, y2 in lines.reshape(-1, 4):
        angle = np.degrees(np.arctan2(y2 - y1, x2 - x1))
        if abs(angle) <= 12:
            angles.append(angle)
    if len(angles) < 3:
        return gray, 0.0
    angle = float(np.median(angles))
    if abs(angle) < 0.15:
        return gray, 0.0
    matrix = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1.0)
    rotated = cv2.warpAffine(gray, matrix, (w, h), flags=cv2.INTER_LINEAR, borderValue=255)
    return rotated, angle


def find_strips(binary):
    h, w = binary.shape
    horiz = cv2.morphologyEx(binary, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (max(40, w // 30), 1)))
    vert = cv2.morphologyEx(binary, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(12, h // 120))))
    grid = cv2.dilate(cv2.bitwise_or(horiz, vert), np.ones((3, 3), np.uint8))

    contours, _ = cv2.findContours(grid, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    strips = []
    for c in contours:
        x, y, cw, ch = cv2.boundingRect(c)
        if ch < 15 or ch > h * 0.12 or cw < ch * 3:
            continue
        # Handwriting touching the strip (e.g. from the line above) merges
        # into the same blob and inflates its height — re-find the strip's
        # true top/bottom from its long horizontal ruled lines.
        rows = np.nonzero((horiz[y:y + ch, x:x + cw] > 0).mean(axis=1) >= 0.6)[0]
        if rows.size < 2:
            continue
        y, ch = y + int(rows[0]), int(rows[-1] - rows[0]) + 1
        if ch < 15 or ch > h * 0.06 or cw < ch * 4:
            continue
        cells = split_cells(vert[y:y + ch, x:x + cw], ch)
        if len(cells) < MIN_CELLS:
            continue
        widths = np.array([b - a for a, b in cells], dtype=float)
        # Real answer boxes are roughly square and evenly spaced — rules out
        # underlined headings, whose letter strokes also look like "cells".
        if not (0.55 * ch <= np.median(widths) <= 1.6 * ch):
            continue
        if widths.std() / widths.mean() > 0.25:
            continue
        # ...and are ruled along both the top and the bottom edge.
        band = max(2, ch // 6)
        top_cov = (horiz[y:y + band, x:x + cw] > 0).any(axis=0).mean()
        bot_cov = (horiz[y + ch - band:y + ch, x:x + cw] > 0).any(axis=0).mean()
        if top_cov < 0.7 or bot_cov < 0.7:
            continue
        strips.append({"x": x, "y": y, "w": cw, "h": ch, "cells": [(x + a, x + b) for a, b in cells]})
    strips.sort(key=lambda s: s["y"])
    return strips


def split_cells(vert_roi, strip_h):
    # Column projection of vertical lines -> candidate separator positions.
    ch = vert_roi.shape[0]
    col = (vert_roi > 0).sum(axis=0) >= ch * 0.5
    seps, i, n = [], 0, len(col)
    while i < n:
        if col[i]:
            j = i
            while j < n and col[j]:
                j += 1
            seps.append((i + j) / 2.0)
            i = j
        else:
            i += 1
    if len(seps) < 2:
        return []
    # The strip's own outer edges are box lines too — include them so a
    # first/last line too faint to detect doesn't silently drop that box.
    if seps[0] > 3:
        seps.insert(0, 0.0)
    if seps[-1] < n - 4:
        seps.append(float(n - 1))

    # Box lines sit on a regular grid; a tall handwritten stroke (the stem of
    # a 4, 1 or 7) also shows up as a "vertical line" but falls off-grid.
    # Estimate the pitch from the plausible gaps, then walk the grid taking
    # the candidate nearest each expected position — skipping off-grid
    # strokes and filling in a line too faint to have been detected.
    diffs = np.diff(seps)
    plausible = diffs[diffs >= 0.4 * strip_h]
    if plausible.size == 0:
        return []
    pitch = float(np.median(plausible))
    tolerance = 0.25 * pitch

    grid = [seps[0]]
    while grid[-1] + pitch * 0.75 < n:
        target = grid[-1] + pitch
        near = [s for s in seps if abs(s - target) <= tolerance]
        if near:
            grid.append(min(near, key=lambda s: abs(s - target)))
        elif target < n - 1:
            grid.append(target)
        else:
            break
    return [(int(round(a)), int(round(b))) for a, b in zip(grid, grid[1:]) if b - a >= 8]


def prepare_digit(cell_bin):
    """Return a 28x28 float MNIST-style image, or None for an empty box."""
    h, w = cell_bin.shape
    my, mx = max(2, int(h * 0.14)), max(2, int(w * 0.14))
    inner = cell_bin[my:h - my, mx:w - mx]
    if inner.size == 0:
        return None
    inner = cv2.morphologyEx(inner, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
    n, labels, stats, _ = cv2.connectedComponentsWithStats(inner, 8)
    keep = np.zeros_like(inner)
    min_area = max(6, inner.size * 0.01)
    for k in range(1, n):
        x, y, cw, ch, area = stats[k]
        touches_edge = x == 0 or y == 0 or x + cw >= inner.shape[1] or y + ch >= inner.shape[0]
        # Drop specks and leftover box-border fragments hugging the edge.
        if area < min_area or (touches_edge and (cw <= 2 or ch <= 2)):
            continue
        keep[labels == k] = 255
    if cv2.countNonZero(keep) < min_area:
        return None
    ys, xs = np.nonzero(keep)
    digit = keep[ys.min():ys.max() + 1, xs.min():xs.max() + 1]
    dh, dw = digit.shape
    scale = 20.0 / max(dh, dw)
    digit = cv2.resize(digit, (max(1, int(round(dw * scale))), max(1, int(round(dh * scale)))), interpolation=cv2.INTER_AREA)
    canvas = np.zeros((28, 28), np.uint8)
    y0 = (28 - digit.shape[0]) // 2
    x0 = (28 - digit.shape[1]) // 2
    canvas[y0:y0 + digit.shape[0], x0:x0 + digit.shape[1]] = digit
    # Centre of mass to the middle, as MNIST digits are.
    m = cv2.moments(canvas)
    if m["m00"] > 0:
        shift = np.float32([[1, 0, 14 - m["m10"] / m["m00"]], [0, 1, 14 - m["m01"] / m["m00"]]])
        canvas = cv2.warpAffine(canvas, shift, (28, 28))
    return canvas.astype(np.float32)


def softmax(v):
    e = np.exp(v - v.max())
    return e / e.sum()


def read_one(session, input_name, pdf_path, crop_path):
    """Read one sheet; always returns a result dict (never raises)."""
    try:
        gray, _ = deskew(render_first_page(pdf_path))
        top = gray[: int(gray.shape[0] * SEARCH_TOP_FRACTION)]
        binary = binarize(top)
        strips = find_strips(binary)
        if not strips:
            return {"status": "no_strip", "message": "Student ID box strip not found on page 1."}
        strip = strips[0]

        pad = max(4, strip["h"] // 4)
        y0, y1 = max(0, strip["y"] - pad), min(top.shape[0], strip["y"] + strip["h"] + pad)
        x0, x1 = max(0, strip["x"] - pad), min(top.shape[1], strip["x"] + strip["w"] + pad)
        os.makedirs(os.path.dirname(crop_path) or ".", exist_ok=True)
        cv2.imwrite(crop_path, gray[y0:y1, x0:x1])

        digits, confidences = [], []
        for a, b in strip["cells"]:
            img = prepare_digit(binary[strip["y"]:strip["y"] + strip["h"], a:b])
            if img is None:
                continue  # empty box (roll number shorter than the strip)
            probs = softmax(session.run(None, {input_name: img.reshape(1, 1, 28, 28)})[0][0])
            d = int(probs.argmax())
            digits.append(str(d))
            confidences.append(round(float(probs[d]), 4))

        return {
            "status": "ok" if digits else "no_strip",
            "digits": "".join(digits),
            "confidences": confidences,
            "min_confidence": min(confidences) if confidences else 0,
            "cells": len(strip["cells"]),
        }
    except Exception as exc:  # noqa: BLE001 — one bad sheet must not sink a batch
        return {"status": "error", "message": str(exc)}


def main():
    """Single:  read_student_id.py <pdf> <crop.png>          -> one JSON object
    Batch:   read_student_id.py --batch  (stdin: [{"pdf","crop"}, ...]) -> JSON array
    Batch mode loads the model once for the whole list, so it's much faster
    per sheet than one process per sheet."""
    session = ort.InferenceSession(MODEL_PATH, providers=["CPUExecutionProvider"])
    input_name = session.get_inputs()[0].name

    if len(sys.argv) == 2 and sys.argv[1] == "--batch":
        items = json.load(sys.stdin)
        print(json.dumps([read_one(session, input_name, it["pdf"], it["crop"]) for it in items]))
        return 0
    if len(sys.argv) == 3:
        print(json.dumps(read_one(session, input_name, sys.argv[1], sys.argv[2])))
        return 0
    print(json.dumps({"status": "error", "message": "usage: read_student_id.py <pdf> <crop.png> | --batch"}))
    return 2


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # noqa: BLE001 — always answer with JSON
        print(json.dumps({"status": "error", "message": str(exc)}))
        sys.exit(1)
