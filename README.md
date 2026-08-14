# Book Illustration Studio

A local PHP/MySQL application that turns book text into a five-step illustration pipeline: Style → Characters → Portraits → Chapters → Illustrations.

## Prerequisites

- Windows with XAMPP (PHP 8.1+ and MySQL/MariaDB)
- Node.js 18+ for frontend tests
- MySQL running on `127.0.0.1:3306`

Import `database/schema.sql`, copy `.env.example` to `.env`, and adjust the database values if needed. Development defaults to `GEMINI_PROVIDER=mock`; no Gemini key is required.

## Start

```powershell
.\start.ps1
```

Open `http://127.0.0.1:8000/frontend/`.

## Test

```powershell
.\test.ps1
```

The test command creates a temporary isolated database and storage directory, runs frontend and backend tests with the mock provider, then removes those test resources. It does not use the development database or Gemini quota.

## Environment

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
- `GEMINI_PROVIDER=mock|gemini`
- `GEMINI_API_KEY` for the real provider only
- `GEMINI_TEXT_MODEL`, `GEMINI_IMAGE_MODEL`
- `MOCK_LATENCY_MS` to make running UI visible during local development
- `STEP_STALE_SECONDS` for stuck-step recovery
- `STORAGE_ROOT` to override `backend/storage`

Nano Banana image models require Cloud Billing. Billing must be active on the same Google Cloud project that owns the API key, otherwise image quota can remain zero.

## Architecture

The browser calls small PHP JSON endpoints. `ProjectService` formats persisted project data, while `PipelineService` enforces ordered state transitions, retry, stale recovery, and duplicate execution protection. `project_steps` is the pipeline source of truth; `projects.status` is a reconciled summary. Providers implement the same interface, so mock and real Gemini execution share the backend persistence path. Book text and generated images are stored locally and served through `media.php`.

The current `RealGeminiProvider` is not implemented or verified yet. Use the mock provider until a billed Gemini key is available, then complete a real end-to-end verification before submission.
