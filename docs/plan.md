# Implementation Plan

The assessment and Google's book-illustration notebook are the source of truth for this plan. Detailed trade-offs belong in `DECISIONS.md`, architecture details in `docs/architecture.md`, and test results in `TESTING.md`.

## Product boundaries

- Five explicit user-driven steps: Style → Characters → Portraits → Chapters → Illustrations.
- At most two adult characters and one chapter, enforced by the backend.
- MySQL is the source of truth for pipeline progress.
- Refreshing or signing in again must resume persisted work.
- Gemini calls are never retried automatically.
- Book text and generated media remain on the local filesystem.
- Animation, music, narration, and public deployment are out of scope.

## Milestones

### 1. Understand the Gemini reference pipeline — complete

- Run the required notebook section before implementing the real provider.
- Record the input, output, and context needed by each of the five steps.
- Confirm File API upload, structured output, interaction chaining, and image generation mechanics.

### 2. Establish the local development harness — complete

- Keep the existing PHP, MySQL, HTML, CSS, and JavaScript stack.
- Support mock development without a Gemini key.
- Provide one start command and one test command.
- Isolate test database and storage from development data.

### 3. Build the persistent vertical slice — complete

- Implement identity, project creation, project list, and project detail.
- Create five `project_steps` rows for every project.
- Run all five steps through `PipelineService` with the mock provider.
- Reconstruct status, prompts, and media from the backend after reopening.

### 4. Add failure and concurrency behavior — complete

- Enforce step ordering on the server.
- Lock the current step before provider execution.
- Block running and completed duplicates.
- Persist errors and retry only the failed step.
- Recover a stranded running step without database surgery.
- Persist each completed portrait before generating the next one.

### 5. Match the required frontend behavior — complete

- Show Draft, In progress, Done, and five-step progress on the project list.
- Show done, current, and pending states in the project stepper.
- Render named running, error, retry, and stale recovery states.
- Poll persisted detail while a step is running so images appear per item.
- Keep the existing Gradion visual language and responsive layout.

### 6. Implement and verify the real provider — complete

- Upload the book once and persist its Gemini file URI.
- Maintain separate stored interaction chains for text and images.
- Request structured adult-character and chapter data with server-side caps.
- Reuse the portrait image chain for the final chapter illustration.
- Save generated files locally and serve them through relative media URLs.
- Contract-test request and response handling with a fake HTTP transport.
- Complete one billing-enabled five-step run with real Gemini models.

### 7. Prepare the submission — complete

- Run the complete automated test command.
- Verify two real portraits and one real chapter illustration.
- Confirm the completed project reopens as Done with its results intact.
- Check that `.env` and the API key are not tracked.
- Review README, decisions, architecture, testing notes, and Git history.
- Record the short real end-to-end screen demonstration requested by the recruiter.

## Completion criteria

The repository is ready to submit when: 
    + The automated checks pass
    + One billed project completes all five steps
    + Generated results survive reopening
    + No secret is tracked
    + The screen recording has been prepared.