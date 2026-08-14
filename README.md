# Book Illustration Studio

Book Illustration Studio is a full-stack application that turns a book's text into character portraits and one chapter illustration with the Gemini API.

The user runs five steps explicitly and in order:

`Style → Characters → Portraits → Chapters → Illustrations`

The application supports a mock provider for normal development and a real Gemini REST provider for the final billed check. Both providers use the same backend pipeline and persistence path; generated results are never faked in the frontend.

## What the application covers

- Name-and-email identity without passwords or OAuth.
- Multiple projects per user.
- Project creation from pasted text or a `.txt` upload.
- Five persistent, user-triggered pipeline steps.
- At most two adult characters and one chapter, enforced by the backend.
- Per-item progress while portraits and illustrations are generated.
- Project recovery after refresh, sign-out, or server restart.
- Duplicate-request protection across double-clicks and browser tabs.
- Failed-step retry without rerunning completed work.
- Stale-running recovery without manual database changes.
- Local storage for book text and generated images.

Animation, music, narration, media mixing, and public deployment are intentionally outside the submission scope.

## Requirements

- Windows with [XAMPP](https://www.apachefriends.org/) installed.
- PHP 8.0 or newer through XAMPP.
- XAMPP Apache using the normal local HTTP port.
- XAMPP MySQL/MariaDB available at `127.0.0.1:3306`.
- Node.js 18 or newer for the frontend tests.
- A modern browser.
- A Gemini API key with Cloud Billing only when testing the real image provider.
- Docker Compose is not required because XAMPP already provides Apache, PHP, and MySQL for this local Windows setup.

The project has been checked locally with PHP `8.0.30` and Node.js `24.19.0`.

## Quick start with the mock provider

The mock provider is the recommended way to review the application without a Gemini key or paid quota.

### 1. Place the repository inside XAMPP `htdocs`

For example:

```text
D:\Program Files\Xampp\htdocs\book-illustration-studio
```

`start.ps1` finds Apache and MySQL relative to this location. It will stop with a clear error if the repository is outside `htdocs`.

### 2. Create the local environment file

From the repository root:

```powershell
Copy-Item .env.example .env
```

The checked-in example already defaults to:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=book_illustration_studio
DB_USER=root
DB_PASSWORD=
GEMINI_PROVIDER=mock
```

Keep `.env` local. It is ignored by Git and must never be committed.
The repository-level Apache rules also deny HTTP access to `.env`, `.git`,
local cookie files, and directory listings.

### 3. Start Apache and MySQL

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\start.ps1
```

The script starts or reuses the XAMPP Apache and MySQL processes, then prints the application URL. Stop the services later from the XAMPP Control Panel.

### 4. Import the database schema once

Open [phpMyAdmin](http://localhost/phpmyadmin/), choose **Import**, and select:

```text
database/schema.sql
```

The schema creates the `book_illustration_studio` database and all required tables. It is intended as a one-time setup; do not re-import it into a populated database unless that data has been backed up.

### 5. Open the application

For the default folder name:

```text
http://localhost/book-illustration-studio/frontend/
```

Use the URL printed by `start.ps1` if the repository folder has a different name.

Enter a name and email, create a project, and run the five buttons in order. Mock generation still goes through the backend services, database state, retry rules, and local media storage.

## Run the tests

One command runs the frontend tests, real-provider contract tests, and backend smoke tests:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\test.ps1
```

The test command:

- Creates a uniquely named temporary database.
- Uses a temporary storage directory.
- Forces `GEMINI_PROVIDER=mock`.
- Starts an isolated PHP server.
- Runs all test groups.
- Drops the test database and removes temporary storage afterward.

It does not modify development projects, read the local Gemini key, call the Gemini network, or consume quota.

The supplied script expects the default XAMPP test connection: MySQL on `127.0.0.1:3306`, user `root`, and an empty password. The latest recorded run is **50 passed, 0 failed**. The complete output and testing rationale are in [TESTING.md](TESTING.md).

## Environment configuration

Start by copying `.env.example`; only change values that differ on the local machine.

### Database

- `DB_HOST` — MySQL host; default `127.0.0.1`.
- `DB_PORT` — MySQL port; default `3306`.
- `DB_NAME` — application database; default `book_illustration_studio`.
- `DB_USER` — database user; default `root` for XAMPP.
- `DB_PASSWORD` — database password; empty in a default XAMPP installation.

### Provider and models

- `GEMINI_PROVIDER` — `mock` or `gemini`; development defaults to `mock`.
- `GEMINI_API_KEY` — required only when `GEMINI_PROVIDER=gemini`.
- `GEMINI_TEXT_MODEL` — defaults to `gemini-3.6-flash`.
- `GEMINI_IMAGE_MODEL` — defaults to `gemini-3.1-flash-image`.
- `GEMINI_IMAGE_SIZE` — defaults to `1K`.
- `MOCK_LATENCY_MS` — optional mock delay used to make running UI visible.

### Timeouts, recovery, and storage

- `GEMINI_CONNECT_TIMEOUT_SECONDS` — timeout while establishing a Gemini connection; default `15`.
- `GEMINI_REQUEST_TIMEOUT_SECONDS` — timeout for one Gemini request; default `180`.
- `PIPELINE_EXECUTION_TIMEOUT_SECONDS` — runtime budget for `run-step.php`; default `600` so sequential portrait requests can finish without changing global `php.ini`.
- `STEP_STALE_SECONDS` — age after which an abandoned running step becomes retryable; default `420`. Keep it above two sequential Gemini request timeouts. Portrait generation refreshes a heartbeat after each completed item.
- `STORAGE_ROOT` — optional replacement for `backend/storage`.

## Run with real Gemini

The Nano Banana image model requires Cloud Billing. Billing must be active on the same Google Cloud project that owns the API key; a key from a different project can still show zero image quota.

Keep the mock provider while developing. When ready for one real end-to-end check, update the local `.env` file:

```dotenv
GEMINI_PROVIDER=gemini
GEMINI_API_KEY=your_local_key
GEMINI_TEXT_MODEL=gemini-3.6-flash
GEMINI_IMAGE_MODEL=gemini-3.1-flash-image
GEMINI_IMAGE_SIZE=1K
```

Then:

1. Confirm billing and image quota for the API key's Google Cloud project.
2. Create a new project; completed mock projects are intentionally not regenerated.
3. Run Style, Characters, Portraits, Chapters, and Illustrations manually.
4. Leave the page open to watch per-item progress, or reopen it to read persisted progress.
5. If a call fails, use the retry action for that step. The backend never retries a paid call automatically.

The book is uploaded to Gemini once. Later text calls reuse its stored interaction context, the second portrait continues the image chain from the first, and the final scene reuses the portrait context for character consistency.

The real provider has also been verified manually with a billed five-step run: two adult portraits and one chapter illustration were saved locally, and the project reopened as Done with `5/5`. Automated tests remain quota-free.

> Never commit `.env`, paste an API key into frontend code.

## Architecture

The application stays deliberately small:

```text
Browser
  → PHP JSON endpoints
    → ProjectService / PipelineService
      → MySQL state + local filesystem
      → MockGeminiProvider or RealGeminiProvider
```

- `frontend/` contains the single-page interface and Gradion styling.
- `backend/api/` contains small identity, project, pipeline, and media endpoints.
- `ProjectService` reads and formats persisted project data.
- `PipelineService` enforces ordering, locking, retry, stale recovery, caps, and status transitions.
- `project_steps` is the source of truth for five-step progress.
- `projects.status` is a reconciled summary used by the project list.
- `MockGeminiProvider` and `RealGeminiProvider` implement the same interface.
- `backend/storage/` holds uploaded books and generated media and denies direct HTTP access.
- `media.php` requires a signed-in user, validates the relative image path, and verifies project ownership before serving generated media.

The real provider uses Gemini's REST APIs because the Interactions workflow is fully available over HTTP and does not require another SDK layer in the PHP stack. Its behavior follows steps 1–5 of Google's [Book illustration notebook](https://colab.research.google.com/github/google-gemini/cookbook/blob/main/examples/Book_illustration.ipynb), including structured character/chapter output and context chaining.

More detail is available in [docs/architecture.md](docs/architecture.md).

## Pipeline state and recovery

Every project starts with five persistent `project_steps` rows.

- `pending → running → completed`
- `pending → running → failed`
- `failed → running → completed|failed` after an explicit retry

Every run request must name the exact step selected by the user. Before provider execution, the backend locks that step and moves it to `running`. Requests for a running step are rejected, completed duplicates return existing data, and out-of-order steps cannot run. A stranded running step becomes retryable after the configured stale timeout.

Project-list status is derived from completed database steps:

- `0/5` — Draft
- `1/5` through `4/5` — In progress
- `5/5` — Done

The frontend displays this persisted state; it does not use `localStorage` or browser memory as the pipeline source of truth.

## Project structure

```text
.htaccess                   Apache protection for local secrets and indexes
backend/
  api/                     PHP JSON endpoints
  services/                project and pipeline rules
  services/providers/      mock and real Gemini providers
  storage/                 local books and generated images, blocked from direct HTTP access
database/
  schema.sql               MySQL schema
docs/
  architecture.md          detailed system design
  plan.md                  implementation milestones
frontend/
  assets/                   Gradion logo
  css/style.css             application styling
  js/app.js                 frontend state and API calls
tests/
  backend/                  API smoke and provider contract tests
  frontend/                 important UI state tests
```
