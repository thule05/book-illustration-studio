Testing

I kept the test suite focused on the pipeline rules rather than coverage. The expensive bugs in this project are not small rendering differences; they are running steps out of order, losing completed work, or sending the same paid Gemini request twice.

## Automated tests

Run everything with:

```powershell
powershell -ExecutionPolicy Bypass -File .\test.ps1
```

The script creates a temporary MySQL database and storage directory, starts an isolated PHP server, runs the tests, and cleans up afterward. It forces `GEMINI_PROVIDER=mock`, so the command does not touch my development data, read my Gemini key, or consume paid quota.

There are three test groups:
    - The frontend tests load the real `frontend/js/app.js` in a small Node.js DOM harness. They cover project progress, loading, failure, retry, media mapping, and the helper functions that were previously duplicated.

    - The backend smoke test calls the PHP APIs and runs a complete five-step project with the mock provider. It also covers sign-in persistence, step ordering, the two-character and one-chapter caps, duplicate requests, failed-step retry, and stale-step recovery.

    - The real-provider contract test injects a fake HTTP transport into `RealGeminiProvider`. This checks book upload, interaction chaining, structured output, image saving, and the no-auto-retry rule without making a Gemini network request.

## What I left manual

I did not test individual CSS rules, every small text helper, or every browser event. Those tests would add maintenance without protecting the behavior that matters most here.

Live Gemini calls are also excluded from the normal test command. They cost money, take much longer, and can fail because of quota or model availability rather than an application regression. I used mock and contract tests while developing, then performed one billed end-to-end check after the automated suite was stable.

A full browser E2E suite, load testing, and public-deployment checks are outside this submission. The assessment does not require E2E or rate-limiting infrastructure, and the application is intended to run locally.

## Automated test report 

Command:

```powershell
powershell -ExecutionPolicy Bypass -File .\test.ps1
```

Actual output:

```text
PASS  project list status derives from completed_steps
PASS  progress helper clamps backend count to five
PASS  detail mapping uses singular style and media URLs
PASS  running panel renders spinner, explanation and disabled button
PASS  failed step renders the same-step retry action
PASS  retry clears stale UI while the new request is running
PASS  critical project helpers have one declaration each

Summary: 7 passed, 0 failed

PASS  resumable book upload contract
PASS  upload session does not forward API key
PASS  book context and style interaction are chained
PASS  adult character output is structured and capped at 2
PASS  first portrait initializes image chain and saves media
PASS  second portrait reuses the persisted image chain
PASS  chapter output is structured and capped at 1
PASS  chapter illustration reuses portraits and saves media
PASS  expected Gemini request count without auto-retry
PASS  API failure is retryable by user, not automatically retried or leaked
PASS  provider factory switches from mock to real

Summary: 11 passed, 0 failed

PASS  database connection
PASS  identity POST
PASS  identity session restore
PASS  create project
PASS  create project inserts 5 steps
PASS  book text saved to storage
PASS  project detail
PASS  pipeline step style
PASS  delayed duplicate cannot advance next step
PASS  pipeline step characters
PASS  pipeline step portraits
PASS  pipeline step chapters
PASS  pipeline step illustrations
PASS  pipeline completes project
PASS  max 2 characters
PASS  max 1 chapter
PASS  media URLs work from the XAMPP project subdirectory
PASS  sign out clears backend session
PASS  project status and progress persist after login
PASS  invalid step order blocked
PASS  duplicate running step blocked
PASS  retry failed step
PASS  stale running step recovered and retried
PASS  adult characters only

Summary: 24 passed, 0 failed
```

Total: 42 passed, 0 failed.

## Manual Gemini check 

After enabling billing, I created a new project and ran all five steps with `RealGeminiProvider`, using `gemini-3.6-flash` for text and `gemini-3.1-flash-image` for images.

The run produced: 
    + 2 adult characters
    + 2 real portraits
    + 1 chapter prompt
    + 1 real chapter illustration. 
The project finished as Done with `5/5`, and reopening it preserved the prompts and generated media. 
No paid request was retried automatically.

The first image run also exposed a real environment problem: PHP could end the request before both portraits finished. I extended only the pipeline endpoint's execution budget and added a heartbeat after each completed portrait, then repeated the pipeline successfully. This is why the normal test suite also runs mock generation under a deliberately short PHP execution limit.
