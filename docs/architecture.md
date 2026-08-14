# Architecture

The browser uses small PHP JSON endpoints backed by MySQL. `ProjectService` reads and formats persisted project data. `PipelineService` is the only component allowed to advance a step; it locks the relevant `project_steps` row, runs the selected provider, persists outputs, and then reconciles `projects.status`.

Both `MockGeminiProvider` and `RealGeminiProvider` implement the same interface. Mock mode creates deterministic placeholder output for normal development. Real mode uses Gemini REST directly: the File API uploads the book once, one stored interaction chain carries text context, and a separate stored interaction chain carries character portraits into the final scene. Generated files remain under `backend/storage` (or `STORAGE_ROOT`) and `media.php` serves them through relative URLs.

The frontend never marks a step completed. It may optimistically render `running` after a click, then polls project detail while the synchronous backend request is active. Refreshing or signing back in reconstructs status, progress, media, and errors from MySQL.
