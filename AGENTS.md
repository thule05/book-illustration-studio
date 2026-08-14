# Book Illustration Studio — Agent Instructions

## Source of truth

Implement only steps 1–5 of the Gradion assessment and Google’s Book Illustration notebook:

`Style → Characters → Portraits → Chapters → Illustrations`

Use `app-demo.html` as the UI/UX baseline. Do not implement animation, music, narration, media mixing, or public deployment.

## Stack

- Frontend: HTML, CSS, JavaScript
- Backend: PHP 8 with JSON endpoints
- Database: MySQL through PDO
- Storage: local filesystem
- AI: mock provider or Gemini REST provider
- Local runtime: Windows and XAMPP

Do not replace this architecture unless a requirement cannot be met with the existing implementation.

## Fixed product boundaries

- Maximum two adult characters.
- Maximum one chapter.
- Every pipeline step requires an explicit user action.
- Steps must run in order.
- Completed work survives refresh, sign-out, and server restart.
- Failed work retries only the same step.
- Gemini calls are never retried automatically.
- Book text and generated media remain on the local filesystem.

## Pipeline state

`project_steps` is the source of truth.

Valid transitions are:

- `pending → running → completed`
- `pending → running → failed`
- `failed → running → completed|failed`
- stale `running → failed`, followed by a user-triggered retry

`projects.status` is only a reconciled summary:

- 0 completed steps: `draft`
- 1–4 completed steps: `in_progress`
- 5 completed steps: `done`

> Never infer completed progress from frontend memory. Never create a second pipeline-state system.
> The backend must lock the requested step before calling a provider.
> A running or completed duplicate must not execute another provider request.
> The API request includes the exact step selected by the user, so a delayed duplicate cannot advance the next step.

## Gemini provider rules

Development and automated tests use `GEMINI_PROVIDER=mock`.

The real provider must:

- Read its API key only from `.env`.
- Upload the book once and persist the returned file URI.
- Reuse persisted interaction IDs instead of resending the full book.
- Request structured adult-character output capped at two.
- Request structured chapter output capped at one.
- Persist each portrait before starting the next portrait.
- Reuse portrait image context for the chapter illustration.
- Save images locally and expose them through `media.php`.
- Return readable errors without leaking secrets.
- Never retry a paid request automatically.

## Frontend rules

- Keep the existing Gradion visual language.
- Render project status from backend `completed_steps`.
- Show done, current, and pending stepper states.
- Show the name of the running step.
- Show per-item portrait and illustration progress.
- Poll project detail only while work is running.
- Show retry and stuck-step recovery actions.
- Do not put pipeline state in `localStorage`.

## Security

- Never commit `.env`, API keys, sessions, generated books, or images.
- Commit `.env.example` with placeholders only.
- Use prepared SQL statements.
- Validate identity, project ownership, step names, caps, and file paths on the server.
- Deny direct HTTP access to `.env`, Git metadata, and `backend/storage`.
- Require authentication and project ownership before serving generated media.
- Do not expose provider secrets in responses or logs.

## Commands

Start:

`powershell -ExecutionPolicy Bypass -File .\start.ps1`

Test:

`powershell -ExecutionPolicy Bypass -File .\test.ps1`

Tests must use the isolated test database, temporary storage, and mock provider. 
They must not consume Gemini quota.

## Documentation ownership

- `README.md`: setup, commands, environment, and architecture overview.
- `DECISIONS.md`: real engineering trade-offs and AI overrides.
- `TESTING.md`: strategy, deliberate omissions, and actual test output.
- `docs/plan.md`: implementation milestones.
- `docs/architecture.md`: system structure and request flow.
