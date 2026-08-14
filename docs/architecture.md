# Architecture

## Request flow

The browser calls small PHP JSON endpoints. `ProjectService` reads and formats project data, while `PipelineService` is the only component allowed to advance the illustration pipeline. 

MySQL stores users, projects, step state, prompts, media paths, and Gemini interaction references.

```text
Browser
  → PHP API endpoints
  → ProjectService / PipelineService
  → MockGeminiProvider or RealGeminiProvider
  → MySQL + local file storage
```

## Persistent pipeline state

Every project receives five `project_steps` rows:

- Style
- Characters
- Portraits
- Chapters
- Illustrations

These rows are the source of truth for ordering, progress, running state, failures, retries, and stale-step recovery.
`projects.status` is only a summary derived from the number of completed steps.

Before starting a step, the API requires the exact step selected by the user and `PipelineService` locks its database row.

- A running step cannot start again, and a completed step is returned without another Gemini call.
- Failed steps can be retried without changing completed work.
- A stale running step becomes retryable after its recovery timeout.

## Gemini providers

`MockGeminiProvider` and `RealGeminiProvider` implement the same interface and use the same persistence path.

- Mock mode supports normal development and automated testing without a key or image-generation cost.

- Real mode follows the Google book-illustration pipeline:

  1. Upload the book once with the Gemini File API.
  2. Store a text interaction chain for style, adult characters, and the chapter prompt.
  3. Store a separate image interaction chain for character portraits.
  4. Continue the image chain when generating the chapter illustration so character identities remain consistent.
  5. Persist each successful image before starting the next item, allowing a retry to resume partial progress.

## Frontend state

The frontend may optimistically display `running` immediately after a click, but it never marks a step completed. While a step is running, the project detail page polls the backend and renders the persisted state. Refreshing, signing out, or signing back in reconstructs progress, prompts, media, and errors from MySQL.

## File storage

Book text and generated images are stored under `backend/storage`, or under the directory configured by `STORAGE_ROOT`. Apache denies direct HTTP access to the default storage directory. The database stores relative paths, and `media.php` serves only generated images after checking the signed-in user and project ownership, without hard-coded domain URLs.

## Verification

Automated tests use the mock provider and a fake HTTP transport, so the normal test command consumes no Gemini quota. The real production path was also verified manually on 14 August 2026 with Cloud Billing enabled. One project completed all five steps through `RealGeminiProvider` and persisted two real portraits, one real chapter illustration, and its text and image interaction chains.
