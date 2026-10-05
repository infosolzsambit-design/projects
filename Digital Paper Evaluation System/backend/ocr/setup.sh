#!/usr/bin/env bash
# Builds the Python environment for the student-ID reader, inside this
# ocr/ folder of whichever copy of the project it's run from — never copy
# ocr/.venv between machines (it contains machine-specific paths and
# compiled packages). Safe to re-run: it rebuilds from scratch.
#
#   bash ocr/setup.sh          (from the backend folder)
set -euo pipefail

OCR_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_BIN="${PYTHON_BIN:-python3}"

echo "Setting up the student ID reader in: $OCR_DIR"
rm -rf "$OCR_DIR/.venv"
"$PYTHON_BIN" -m venv "$OCR_DIR/.venv"
"$OCR_DIR/.venv/bin/python" -m pip install --quiet --upgrade pip
"$OCR_DIR/.venv/bin/python" -m pip install --quiet -r "$OCR_DIR/requirements.txt"

"$OCR_DIR/.venv/bin/python" -c "import numpy, cv2, pymupdf, onnxruntime" \
  && echo "OK — packages installed. Python: $OCR_DIR/.venv/bin/python"
