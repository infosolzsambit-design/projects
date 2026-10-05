# Student ID reader (roll number check)

`read_student_id.py` reads the handwritten STUDENT'S ID NO. boxes on an
answer sheet's cover page. There is **no queue and no background worker**:
the Generate Marksheet page runs it when you click Search, in batches of 25
sheets (`App\Services\StudentIdCheckService`, `--batch` mode so the model
loads once per batch), with a live progress bar. Each sheet is read once;
results are stored on `answer_sheets`. About 0.2 s per sheet (5,000 sheets
≈ 15 minutes, keep the page open). The Recheck button re-reads a course
(sheets confirmed manually are never changed).

## One-time server setup

**Never upload `ocr/.venv`** — it's built per machine (it contains that
machine's absolute paths and compiled packages). Upload the rest of `ocr/`
and build the environment on the server itself:

```bash
cd backend
sudo apt install python3-venv          # if venv isn't available yet
bash ocr/setup.sh                      # builds ocr/.venv right here, wherever "here" is
php artisan migrate
php artisan config:clear
```

Re-run `bash ocr/setup.sh` any time (e.g. after the project folder moves or
the server's Python is upgraded) — it rebuilds from scratch.

The app finds Python without any hardcoded path: `ROLL_CHECK_PYTHON` in
`.env` if set, otherwise `ocr/.venv/bin/python` relative to the project,
otherwise the system `python3`.

`mnist-12.onnx` (handwritten-digit model, ONNX model zoo) must sit next to
the script — it is committed with it.

## Optional: check from the terminal

Same reading as the page, with a progress bar — handy for a large backlog:

```bash
php artisan answer-sheets:check-student-ids              # every unchecked sheet
php artisan answer-sheets:check-student-ids --mapping=12 # one packet
php artisan answer-sheets:check-student-ids --recheck    # re-read everything
```

New uploads are simply "not checked" until the next search. Replacing a
PDF for a printing issue clears that sheet's reading so it's read again.

## Settings (.env)

| Key | Default |
|---|---|
| `ROLL_CHECK_PYTHON` | auto: `ocr/.venv/bin/python` if built, else `python3` |
| `ROLL_CHECK_TIMEOUT` | `20` (seconds per sheet) |
