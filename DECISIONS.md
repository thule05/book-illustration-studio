# Engineering Decisions

## Keep PHP, MySQL, and `project_steps` instead of rebuilding state

Keeping the existing stack was my decision. The repository already had PHP, MySQL, local media storage, and one `project_steps` row for every pipeline step. An early AI direction treated frontend status, `projects.status`, and step progress as separate state that the application would need to coordinate. I pushed back because the database already had enough information to represent the pipeline. Adding another state machine or table would create more synchronization problems without satisfying a new requirement.

We landed on `project_steps.state` as the source of truth. `projects.status` remains only a summary for project-list queries, while book text and generated images stay on the local filesystem as required. The trade-off is that media files and database rows are not updated in one transaction, and the summary status must be reconciled after a step succeeds or fails. I accepted that cost because it is smaller than maintaining a second state system.

## Reject the AI-authored frontend status after testing sign-in recovery

One AI-assisted frontend version derived project cards from an in-memory status value. It looked correct during a single browser session, but I challenged it with a specific case from the assessment: finish one or more steps, sign out, then sign back in with the same email. The UI returned the project as Draft with `0/5`, even though the database still contained completed `project_steps`. That proved the AI solution was using the wrong source of truth.

I removed frontend status ownership. `projects.php` now returns `completed_steps`, and the UI maps zero completed steps to Draft, one through four to In progress, and five to Done. `ProjectService` also reconciles a stale `projects.status` when project data is read. The cost is an aggregate count when projects are loaded, but refresh and login persistence are now based on stored data rather than browser memory.

## Accept the AI’s warning about delayed duplicate requests

My original preference was to let `run-step.php` look up and execute whatever step came next. Codex pushed back with a failure case I had missed: a second Style request could be delayed by the browser or another tab, arrive after Style completed, and accidentally start Characters. A normal “already running” check would not prevent that because the current runnable step had already changed.

I accepted the AI’s objection and changed the request contract so the frontend sends the exact step the user clicked. The backend compares that value with the current runnable step. A duplicate of a completed step returns existing project data, an out-of-order request is rejected, and the current `project_steps` row is locked with `SELECT ... FOR UPDATE` before provider execution begins. This adds one required API field and makes the endpoint stricter, but it prevents a delayed click from causing an unexpected paid Gemini request.

## Reject WebSockets, but accept the AI’s timeout and heartbeat corrections

When we discussed per-item portrait progress, Codex raised SSE or WebSockets as a richer real-time option. I rejected that direction because this is a local five-step assessment, not a real-time platform. Adding a connection server and another client state path would be disproportionate. Polling the existing project-detail endpoint while a step is running was enough because every completed portrait is persisted immediately.

The billed run then showed that my simpler design still contained two incorrect assumptions. I initially treated `started_at` as enough to detect a dead request, but Codex pointed out that a legitimate two-portrait step could run for several minutes. Codex also helped identify that XAMPP was ending `run-step.php` at PHP’s execution limit before the provider’s HTTP timeout had expired. I accepted those corrections: the step now refreshes `updated_at` after each portrait, stale recovery uses that heartbeat, and only the pipeline endpoint receives a longer execution budget.

The trade-off is a small polling request roughly once per second and a recovery delay before a genuinely abandoned step can be retried. I preferred that predictable cost over adding real-time infrastructure or letting an active paid request be incorrectly marked as stuck.

## Reject frontend fake data and use a mock provider through the real backend

Before billing was enabled, an AI shortcut was to represent generated images with frontend placeholder data or wait until a paid key was available before finishing the pipeline. I rejected both options. Frontend fake results would not test persistence, retry, project progress, or media URLs, while waiting for billing would block most of the implementation.

Instead, `GEMINI_PROVIDER=mock` runs all five steps through the same `PipelineService`, database writes, status transitions, and local media paths as the real provider. For the production provider, I also kept an injectable HTTP transport so automated tests can verify upload, structured output, interaction chaining, image handling, and the absence of automatic retries without consuming quota.

The limitation is that mocks cannot prove live quota behavior, real latency, or visual consistency. I accepted that limitation during development and used one final billed run only after the automated suite was stable. The real run used `gemini-3.6-flash` and `gemini-3.1-flash-image`, completed all five steps, generated two portraits and one chapter illustration, and reopened with its persisted `5/5` Done state.

