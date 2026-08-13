# Engineering Decisions

This document records important technical and product decisions made
during development, including cases where an AI suggestion was changed
after reviewing the assessment requirements or the actual application behavior.

---

## Decision 1 — Separate the frontend from app-demo.html

### Context

The provided `app-demo.html` was used as the reference for the product
UI and workflow.

### Initial approach

The initial approach was to adapt the demo directly into the application.

### Developer feedback / override

I decided not to keep the demo as the final application structure.
The frontend needed to be separated into independent files so that the
application logic could later be connected to the PHP backend without
mixing UI, styling, and application behavior in one file.

### Final decision

The application uses:

- `frontend/index.html` for the page structure
- `frontend/css/style.css` for styling
- `frontend/js/app.js` for application logic

The demo remains a reference for the intended UI and workflow rather
than the final implementation.

### Trade-off

This introduces more files and structure than directly using the demo,
but makes the frontend easier to maintain and integrate with the
backend.