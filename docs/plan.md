# Implementation Plan

1. Preserve the existing PHP/MySQL architecture and audit the assessment against `project_steps`.
2. Repair persistent project progress, login restoration, duplicate-request protection, and running/error UI states.
3. Validate the complete five-step path with the mock provider and isolated frontend/backend tests.
4. Map the reference notebook to Gemini File and Interactions REST calls without adding an SDK.
5. Implement stored text/image conversation chains and persist partial progress before later paid calls.
6. Contract-test the real provider with recorded fake responses, then reserve quota for one billed manual run and screen recording.

Steps 1–5 are complete. Step 6 is automated up to the live billed verification, which requires a billing-enabled key owned by the reviewer/developer environment.
