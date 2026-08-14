# Testing

## Strategy

Backend smoke tests exercise the behavior where regressions are expensive: identity/session restoration, creation of all five persisted step rows, ordered execution, the 2-character/1-chapter caps, project status reconciliation, duplicate-running protection, delayed duplicate protection, failed-step retry, stale-step recovery, media persistence, and a complete mock pipeline. The suite uses a temporary database and storage root so it cannot reset development data.

Frontend state tests load the real `app.js` in a small DOM harness. They verify Draft/In progress/Done mapping from `completed_steps`, detail response mapping, media URLs, running/loading markup, failed-step retry markup, and that the three previously duplicated helpers each have exactly one declaration. Manual browser checks cover the interactions and visual state that the state harness does not render realistically.

I deliberately do not test every CSS rule, simple text helper, or browser event. There is no coverage target. Real Gemini calls are also excluded because no billed API key is available; Nano Banana image models have no free-tier quota. The final submission still needs one real billed end-to-end run and the requested screen recording.

## Real run report — 14 August 2026

Command: `.\test.ps1`

Frontend result: 6 passed, 0 failed.

Backend result: 23 passed, 0 failed.

The backend run included all five mock steps, 5/5 Done persistence, an independent 0/5 Draft project, an independent 2/5 In progress project after sign-out/sign-in, retry, stale recovery, invalid order, and both concurrent/delayed duplicate guards.

Manual in-app browser verification also passed:

- Style switched to running immediately with spinner, explanatory copy, and disabled Generating button.
- Completion changed the stepper to a checked Style and made Characters current.
- Project list showed one orange segment, `1 of 5`, and In progress.
- Signing out and signing back in with the same email preserved `1 of 5` and In progress.
- Characters showed its named running state; portrait generation retained the overall running panel and item cards while the backend call was active.
