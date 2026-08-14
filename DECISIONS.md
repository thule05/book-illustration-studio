# Engineering Decisions

## Keep PHP, MySQL, and `project_steps` as the state model

The existing repository already had the right-sized stack and a persistent row for every pipeline step. During the repair, Codex could have introduced another frontend state machine or a new status table; I explicitly rejected that because it would create two sources of truth. We kept `project_steps.state` authoritative and treated `projects.status` as a cached summary. The cost is that the summary must be reconciled whenever a step finishes or fails, and old inconsistent rows must be repaired when projects are read.

## Derive list progress from completed database steps

An earlier AI-assisted frontend pass trusted an in-memory status enum, which made completed projects return as Draft after signing in again. Codex identified the mismatch between that enum and `project_steps`; I kept the useful display enum but overrode the idea that it could be authoritative. `projects.php` now returns `completed_steps`, the UI maps 0/1–4/5 to Draft/In progress/Done, and `ProjectService` repairs a stale cached `projects.status`. The trade-off is a small count query per project list response.

## Include the requested step in every run command

The original endpoint inferred “the next step” from the database. That is convenient, but Codex caught a subtle unsafe case: a delayed second Style request could arrive after Style completed and accidentally start Characters. I accepted the AI push-back and added the step the user actually clicked to the request contract. The backend still resolves order from the database, but a duplicate completed request returns the existing result instead of advancing. This adds one field to the API and closes a real cost/idempotency hole.

## Poll persisted state instead of adding SSE or WebSockets

Per-item portrait progress and refresh recovery need fresh server state while a long image call is running. A richer real-time channel would be attractive, but it is outside the assessment scope, so I rejected that extra infrastructure. The detail page polls the existing detail endpoint only while a step is running; PHP releases the session lock before long work so another request can read progress. The cost is periodic GET traffic and updates that can be up to roughly one second late.

## Develop with the mock provider and reserve paid images for final verification

The image-generation models in the Nano Banana family are not included in the Gemini API free tier. The quota remains zero until Cloud Billing is enabled, and billing must be enabled on the same GCP project that owns the API key. The user specifically had no billed key, so I overrode any suggestion to block development on real image calls or put fake results in the frontend. `GEMINI_PROVIDER=mock` runs the full backend pipeline, writes placeholder media, and supports adjustable latency; real calls stay opt-in. This saves quota during iteration, but the real provider still requires a billed key and a final recorded end-to-end verification.

## Keep one service-level response contract

One repair attempt duplicated SQL inside `projects.php` and `project.php`, moved the database config into the API folder, and returned `styles` while the UI expected `style`. I rejected that duplication and routed both endpoints back through `ProjectService`, which also adds relative media URLs. The cost is that the service formats both list and detail data, but it prevents the two endpoints from drifting again.

## If I had one more day

I would implement and verify `RealGeminiProvider` against the current Gemini REST endpoints, then record one complete billed run. That is the largest remaining gap between a fully tested local mock workflow and production-equivalent behavior.
