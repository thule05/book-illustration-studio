# Engineering Decisions

## Keep PHP, MySQL, and `project_steps` as the state model

The existing repository already had the right-sized stack and a persistent row for every pipeline step. During the repair, Codex could have introduced another frontend state machine or a new status table; I explicitly rejected that because it would create two sources of truth. We kept `project_steps.state` authoritative and treated `projects.status` as a cached summary. The cost is that the summary must be reconciled whenever a step finishes or fails, and old inconsistent rows must be repaired when projects are read.

## Derive list progress from completed database steps

An earlier AI-assisted frontend pass trusted an in-memory status enum, which made completed projects return as Draft after signing in again. Codex identified the mismatch between that enum and `project_steps`; I kept the useful display enum but overrode the idea that it could be authoritative. `projects.php` now returns `completed_steps`, the UI maps 0/1–4/5 to Draft/In progress/Done, and `ProjectService` repairs a stale cached `projects.status`. The trade-off is a small count query per project list response.

## Include the requested step in every run command

The original endpoint inferred “the next step” from the database. That is convenient, but Codex caught a subtle unsafe case: a delayed second Style request could arrive after Style completed and accidentally start Characters. I accepted the AI push-back and added the step the user actually clicked to the request contract. The backend still resolves order from the database, but a duplicate completed request returns the existing result instead of advancing. This adds one field to the API and closes a real cost/idempotency hole.

## Poll persisted state instead of adding SSE or WebSockets

Per-item portrait progress and refresh recovery need fresh server state while a long image call is running. A richer real-time channel would be attractive, but it is outside the assessment scope, so I rejected that extra infrastructure. The detail page polls the existing detail endpoint only while a step is running; PHP releases the session lock before long work so another request can read progress. The cost is periodic GET traffic and updates that can be up to roughly one second late.

## Develop with mock responses, but keep the real REST path testable

The image-generation models in the Nano Banana family are not included in the Gemini API free tier. The quota remains zero until Cloud Billing is enabled, and billing must be enabled on the same GCP project that owns the API key. The user specifically had no billed key, so I overrode any suggestion to block development on paid calls or put fake results in the frontend. `GEMINI_PROVIDER=mock` runs the full backend pipeline through the same service and persistence code, while a fake HTTP transport tests the production REST request/response contract without quota. This saves money and still catches malformed upload, chaining, schema, and image handling; the accepted cost is that one billed end-to-end run remains a manual verification.

For the opt-in real path I chose the current documented `gemini-3.6-flash` text model and `gemini-3.1-flash-image` Nano Banana model, both overrideable in `.env` because model IDs change. Codex initially focused on generation calls, but I caught that a retry after a partial failure could upload the book again or lose the first portrait's image chain. We now persist the uploaded file URI immediately and persist each successful portrait interaction ID before starting the next item. The extra writes buy correct resume behavior and cost control.

## Keep one service-level response contract

One repair attempt duplicated SQL inside `projects.php` and `project.php`, moved the database config into the API folder, and returned `styles` while the UI expected `style`. I rejected that duplication and routed both endpoints back through `ProjectService`, which also adds relative media URLs. The cost is that the service formats both list and detail data, but it prevents the two endpoints from drifting again.

## If I had one more day

I would enable billing on the API key's own Google Cloud project, run the new project through all five real calls, inspect character consistency and error messages, and record that run for the submission. The contracts and persistence behavior are automated; the remaining uncertainty is the quality and live quota behavior of paid image generation.
