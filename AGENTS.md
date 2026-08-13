# Book Illustration Studio — Agent Instructions

## Project Goal

Build a local full-stack web application that turns book text
into a sequence of AI-assisted illustration outputs.

The application follows five user-driven pipeline steps:

1. Style
2. Characters
3. Portraits
4. Chapters
5. Illustrations

## Current Stack

- Frontend: HTML, CSS, JavaScript
- Backend: PHP
- Database: MySQL
- Database access: PDO
- AI: Gemini API

## Product Constraints

- Maximum 2 adult characters.
- Maximum 1 chapter.
- The five steps must run in order.
- Each step requires an explicit user action.
- Completed work must persist.
- Refreshing the page must not restart completed work.
- A failed step must be retryable.
- Duplicate Gemini execution must be prevented.
- Gemini API keys must never be committed to Git.

## Frontend Guidelines

- Keep the existing Gradion visual language.
- Use the provided app-demo.html as the baseline, not as a reason to blindly copy the UI.
- Prefer clear spacing, readable typography, responsive layouts, and accessible controls.
- Provide meaningful empty, loading, and error states.
- Do not add unnecessary UI or features outside the assessment scope.

## Backend Guidelines

- Keep API responsibilities small and explicit.
- Validate input on the server.
- Use prepared SQL statements.
- Do not trust frontend state for pipeline progress.
- Persist pipeline state in the database.
- Do not execute Gemini calls when a step is already completed.
- Handle retryable failures without losing completed work.

## Security

- Never hard-code API keys.
- Never commit `.env`.
- Use `.env.example` for required environment variables.
- Do not expose secrets in API responses or logs.

## AI Workflow

Before implementing a Gemini pipeline step:

1. Verify the required behavior from the assessment and notebook.
2. Identify the input and output contract.
3. Keep the implementation consistent with previous pipeline steps.
4. Prefer the smallest implementation that satisfies the requirement.
5. Record important architectural decisions in DECISIONS.md.

## Testing

Backend tests should focus on:

- Step ordering
- Progress/state transitions
- Retry behavior
- Duplicate execution protection

Frontend tests should focus on important states such as:

- Empty
- Loading
- Error
- User actions that change pipeline state

Do not create unnecessary tests only for the sake of coverage.