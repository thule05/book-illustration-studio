# Book Illustration Studio

A local PHP/MySQL application that turns book text into a five-step illustration pipeline: Style → Characters → Portraits → Chapters → Illustrations.

## Prerequisites

- Windows with XAMPP (PHP 8.0+ and MySQL/MariaDB)
- Node.js 18+ for frontend tests
- MySQL running on `127.0.0.1:3306`

Import `database/schema.sql`, copy `.env.example` to `.env`, and adjust the database values if needed. Development defaults to `GEMINI_PROVIDER=mock`; no Gemini key is required.

## Start

```powershell
.\start.ps1
```

The command starts (or reuses) XAMPP Apache and MySQL. Open
`http://localhost/book-illustration-studio/frontend/`. Apache is used instead
of PHP's single-process development server so project polling can show each
portrait as it lands during a long running step. Stop the services from the
XAMPP Control Panel when finished.

## Test

```powershell
.\test.ps1
```

The test command creates a temporary isolated database and storage directory, runs frontend and backend tests with the mock provider, then removes those test resources. It does not use the development database or Gemini quota.

## Environment

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
- `GEMINI_PROVIDER=mock|gemini`
- `GEMINI_API_KEY` for the real provider only
- `GEMINI_TEXT_MODEL` (default `gemini-3.6-flash`)
- `GEMINI_IMAGE_MODEL` (default `gemini-3.1-flash-image`)
- `GEMINI_IMAGE_SIZE` (`1K` by default)
- `GEMINI_CONNECT_TIMEOUT_SECONDS`, `GEMINI_REQUEST_TIMEOUT_SECONDS`
- `PIPELINE_EXECUTION_TIMEOUT_SECONDS` (default 600) keeps XAMPP's PHP request
  alive for sequential image calls without changing the global `php.ini`
- `MOCK_LATENCY_MS` to make running UI visible during local development
- `STEP_STALE_SECONDS` for stuck-step recovery (default 420; keep it above two
  sequential Gemini request timeouts; Portraits refreshes a per-item heartbeat)
- `STORAGE_ROOT` to override `backend/storage`

Nano Banana image models require Cloud Billing. Billing must be active on the same Google Cloud project that owns the API key, otherwise image quota can remain zero.

## Run once with billed Gemini

Keep the mock provider while developing. For the final real verification:

1. Enable Cloud Billing on the Google Cloud project that owns the Gemini API key, and confirm the image-model quota is no longer zero.
2. Put the following values in the local `.env` file (never commit that file):

   ```dotenv
   GEMINI_PROVIDER=gemini
   GEMINI_API_KEY=your_local_key
   GEMINI_TEXT_MODEL=gemini-3.6-flash
   GEMINI_IMAGE_MODEL=gemini-3.1-flash-image
   GEMINI_IMAGE_SIZE=1K
   ```

3. Restart the stack with `./start.ps1`.
4. Create a new project and run all five buttons in order while recording the screen. A project already completed with the mock provider is intentionally idempotent and will not be regenerated.
5. If a paid call fails, read the saved error in the UI and retry that same step manually. The backend never auto-retries a Gemini call.

The implementation follows the five-step [Google book illustration notebook](https://colab.research.google.com/github/google-gemini/cookbook/blob/main/examples/Book_illustration.ipynb): the book is uploaded once, text outputs use structured JSON where required, and persisted interaction IDs chain later text and image calls. The REST request shapes follow the official [File API](https://ai.google.dev/gemini-api/docs/file-input-methods) and [Interactions API](https://ai.google.dev/api/interactions-api).

## Architecture

The browser calls small PHP JSON endpoints. `ProjectService` formats persisted project data, while `PipelineService` enforces ordered state transitions, retry, stale recovery, and duplicate execution protection. `project_steps` is the pipeline source of truth; `projects.status` is a reconciled summary. Providers implement the same interface, so mock and real Gemini execution share the backend persistence path. Book text and generated images are stored locally and served through `media.php`.

`RealGeminiProvider` is implemented and covered by a fake-transport contract test, so the normal test command uses no network or quota. A billed end-to-end run is still intentionally left as a manual verification because no billed key is committed or available to the test suite.
