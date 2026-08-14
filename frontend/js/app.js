const API_BASE = "../backend/api";

const STEPS = [
  { key: "STYLE", label: "Style", backendStep: "style" },
  { key: "CHARACTERS", label: "Characters", backendStep: "characters" },
  { key: "PORTRAITS", label: "Portraits", backendStep: "portraits" },
  { key: "CHAPTERS", label: "Chapters", backendStep: "chapters" },
  { key: "ILLUSTRATIONS", label: "Illustrations", backendStep: "illustrations" }
];

const STATUS_ORDER = [
  "CREATED",
  "STYLE_SET",
  "CHARACTERS_GENERATED",
  "PORTRAITS_GENERATED",
  "CHAPTERS_GENERATED",
  "DONE"
];

const state = {
  user: null,
  projects: [],
  currentProjectId: null,
  pendingBookText: "",
  initializing: true
};

const projectPollTimers = new Map();

const app = document.getElementById("app");
const toast = document.getElementById("toast");
const modal = document.getElementById("book-modal");
const modalBody = document.getElementById("book-modal-body");
const modalClose = document.getElementById("book-modal-close");


/* =========================================================
   API
   ========================================================= */

async function api(url, options = {}) {
  const config = {
    credentials: "include",
    ...options,
    headers: {
      ...(options.body
        ? { "Content-Type": "application/json" }
        : {}),
      ...(options.headers || {})
    }
  };

  let response;

  try {
    response = await fetch(`${API_BASE}/${url}`, config);
  } catch (error) {
    throw new Error(
      "Cannot connect to the backend. Make sure XAMPP Apache is running."
    );
  }

  let data = null;

  try {
    data = await response.json();
  } catch {
    data = null;
  }

  if (!response.ok) {
    const requestError = new Error(
      data?.error ||
      `Request failed with status ${response.status}.`
    );

    requestError.status = response.status;
    requestError.payload = data;
    throw requestError;
  }

  return data;
}


/* =========================================================
   STATE / MAPPING
   ========================================================= */

function getCompletedCountFromSteps(steps = []) {
  return steps.filter(
    step =>
      String(step.state || "").toLowerCase() === "completed"
  ).length;
}


/*
 * IMPORTANT:
 * Project list API returns `completed_steps`.
 * We use that value after login / refresh because
 * projects.php does not return the full step array.
 */
function getCompletedSteps(project) {
  const completedFromApi = Number(project?.completedSteps);

  if (Number.isFinite(completedFromApi)) {
    return Math.max(
      0,
      Math.min(5, completedFromApi)
    );
  }

  if (Array.isArray(project?.steps)) {
    return Math.max(
      0,
      Math.min(
        5,
        getCompletedCountFromSteps(project.steps)
      )
    );
  }

  return 0;
}


function getFrontendStatusFromCompletedCount(completed) {
  completed = Math.max(
    0,
    Math.min(5, Number(completed) || 0)
  );

  if (completed >= 5) {
    return "DONE";
  }

  if (completed === 0) {
    return "CREATED";
  }

  if (completed === 1) {
    return "STYLE_SET";
  }

  if (completed === 2) {
    return "CHARACTERS_GENERATED";
  }

  if (completed === 3) {
    return "PORTRAITS_GENERATED";
  }

  return "CHAPTERS_GENERATED";
}


/*
 * Kept for compatibility with the rest of the app.
 */
function getFrontendStatus(steps = []) {
  return getFrontendStatusFromCompletedCount(
    getCompletedCountFromSteps(steps)
  );
}


function getStepState(project, stepKey) {
  const step = (project.steps || []).find(
    item => item.step === stepKey
  );

  return step?.state || "pending";
}


function getCurrentBackendStep(project) {
  const step = (project.steps || []).find(
    item =>
      String(item.state || "").toLowerCase() !== "completed"
  );

  return step?.step || null;
}


function mapProject(projectData, steps = []) {
  if (!projectData || projectData.id == null) {
    throw new Error("Project response is missing project data.");
  }

  const completedStepsFromApi =
    Number(projectData?.completed_steps);

  const hasCompletedStepsFromApi =
    Number.isFinite(completedStepsFromApi);

  const completedSteps =
    hasCompletedStepsFromApi
      ? Math.max(
          0,
          Math.min(5, completedStepsFromApi)
        )
      : getCompletedCountFromSteps(steps);

  return {
    id: String(projectData.id),

    title:
      projectData.title || "",

    bookText:
      projectData.book_text ||
      projectData.bookText ||
      "",

    bookFilePath:
      projectData.book_file_path ||
      null,

    createdAt:
      projectData.created_at
        ? new Date(
            projectData.created_at
          ).getTime()
        : Date.now(),

    updatedAt:
      projectData.updated_at
        ? new Date(
            projectData.updated_at
          ).getTime()
        : Date.now(),

    /*
     * IMPORTANT:
     * Persist the real completed count from API.
     */
    completedSteps,

    /*
     * Frontend status is derived from completed steps,
     * NOT blindly from projects.status.
     */
    status:
      getFrontendStatusFromCompletedCount(
        completedSteps
      ),

    backendStatus:
      projectData.status ||
      "draft",

    steps:
      Array.isArray(steps)
        ? steps
        : [],

    style: null,

    characters: [],

    chapters: []
  };
}


function applyProjectDetail(project, detail) {
  if (!detail) {
    return project;
  }

  const backendProject =
    detail.project || {};

  const steps =
    Array.isArray(detail.steps)
      ? detail.steps.map(step => ({
          ...step,
          state: String(step.state || "pending").toLowerCase(),
          is_stale:
            step.is_stale === true ||
            step.is_stale === 1 ||
            step.is_stale === "1"
        }))
      : [];

  project.id =
    String(
      backendProject.id ??
      project.id
    );

  project.title =
    backendProject.title ??
    project.title;

  project.bookText =
    backendProject.book_text ??
    backendProject.bookText ??
    project.bookText ??
    "";

  project.bookFilePath =
    backendProject.book_file_path ??
    project.bookFilePath ??
    null;

  project.createdAt =
    backendProject.created_at
      ? new Date(
          backendProject.created_at
        ).getTime()
      : project.createdAt;

  project.updatedAt =
    backendProject.updated_at
      ? new Date(
          backendProject.updated_at
        ).getTime()
      : project.updatedAt;

  project.backendStatus =
    backendProject.status ||
    project.backendStatus;

  /*
   * Detail API gives the real project_steps.
   */
  project.steps = steps;

  /*
   * IMPORTANT:
   * Recalculate completedSteps from actual step states.
   */
  project.completedSteps =
    getCompletedCountFromSteps(
      steps
    );

  project.status =
    getFrontendStatusFromCompletedCount(
      project.completedSteps
    );

  project.style =
    detail.style?.style_text ||
    detail.styles?.[0]?.style_text ||
    null;

  project.characters =
    (detail.characters || []).map(
      character => ({
        id: character.id,

        orderIndex:
          character.order_index,

        name:
          character.name,

        prompt:
          character.prompt,

        portraitPath:
          character.portrait_path ||
          null,

        portraitStatus:
          character.portrait_status ||
          "pending",

        portraitError:
          character.portrait_error ||
          null,

        portraitUrl:
          character.portrait_url ||
          null
      })
    );

  project.chapters =
    (detail.chapters || []).map(
      chapter => ({
        id: chapter.id,

        orderIndex:
          chapter.order_index,

        name:
          chapter.name,

        prompt:
          chapter.prompt,

        illustrationPath:
          chapter.illustration_path ||
          null,

        illustrationStatus:
          chapter.illustration_status ||
          "pending",

        illustrationError:
          chapter.illustration_error ||
          null,

        illustrationUrl:
          chapter.illustration_url ||
          null
      })
    );

  return project;
}


/* =========================================================
   NAVIGATION
   ========================================================= */

function navigate(hash) {
  window.location.hash = hash;
}


function route() {
  const hash =
    window.location.hash.replace(
      /^#\/?/,
      ""
    );

  if (!state.user) {
    return {
      name: "auth"
    };
  }

  if (
    hash === "" ||
    hash === "projects"
  ) {
    return {
      name: "list"
    };
  }

  if (hash === "projects/new") {
    return {
      name: "new"
    };
  }

  const match =
    hash.match(
      /^projects\/([a-zA-Z0-9-]+)$/
    );

  if (match) {
    return {
      name: "detail",
      id: match[1]
    };
  }

  return {
    name: "list"
  };
}


/* =========================================================
   HELPERS
   ========================================================= */

function escapeHtml(value) {
  return String(value ?? "")
    .replace(
      /[&<>"']/g,
      char => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
      }[char])
    );
}


function snippet(text, maxLength) {
  const normalized =
    String(text || "")
      .replace(/\s+/g, " ")
      .trim();

  return normalized.length > maxLength
    ? normalized.slice(0, maxLength) + "…"
    : normalized;
}


function showToast(message) {
  if (!toast) {
    return;
  }

  toast.textContent = message;
  toast.classList.remove("hidden");

  window.clearTimeout(
    showToast.timer
  );

  showToast.timer =
    window.setTimeout(() => {
      toast.classList.add("hidden");
    }, 2200);
}


function setButtonLoading(
  button,
  loading,
  loadingText = "Working..."
) {
  if (!button) {
    return;
  }

  if (loading) {
    button.dataset.originalText =
      button.innerHTML;

    button.disabled = true;
    button.innerHTML = loadingText;
  } else {
    button.disabled = false;

    if (button.dataset.originalText) {
      button.innerHTML =
        button.dataset.originalText;
    }
  }
}


/* =========================================================
   DATA LOADING
   ========================================================= */

async function loadProjects() {
  const data =
    await api("projects.php");

  const projects =
    data?.projects;

  if (!Array.isArray(projects)) {
    throw new Error("Projects response is missing the projects list.");
  }

  state.projects =
    projects.map(project => {

      const existing =
        state.projects.find(
          item =>
            String(item.id) ===
            String(project.id)
        );

      const mapped =
        mapProject(
          project,
          []
        );

      /*
       * IMPORTANT:
       * Do not overwrite an already-loaded detail object
       * with empty steps/style/characters/chapters.
       *
       * But ALWAYS update completedSteps/status from
       * the fresh projects.php response.
       */
      if (existing) {
        return {
          ...existing,

          id: mapped.id,
          title: mapped.title,

          bookText:
            mapped.bookText ||
            existing.bookText ||
            "",

          bookFilePath:
            mapped.bookFilePath ||
            existing.bookFilePath ||
            null,

          createdAt:
            mapped.createdAt,

          updatedAt:
            mapped.updatedAt,

          completedSteps:
            mapped.completedSteps,

          status:
            mapped.status,

          backendStatus:
            mapped.backendStatus,

          /*
           * Preserve detailed step/entity data if already loaded.
           */
          steps:
            Array.isArray(existing.steps)
              ? existing.steps
              : [],

          style:
            existing.style || null,

          characters:
            Array.isArray(existing.characters)
              ? existing.characters
              : [],

          chapters:
            Array.isArray(existing.chapters)
              ? existing.chapters
              : []
        };
      }

      return mapped;
    });

  return state.projects;
}


async function loadProjectDetail(
  projectId
) {
  const data =
    await api(
      `project.php?id=${encodeURIComponent(
        projectId
      )}`
    );

  if (!data?.project) {
    throw new Error("Project detail response is missing project data.");
  }

  const detailProject =
    data.project || {};

  let project =
    state.projects.find(
      item =>
        String(item.id) ===
        String(projectId)
    );

  if (!project) {
    project =
      mapProject(
        detailProject,
        data.steps || []
      );

    state.projects.push(
      project
    );
  }

  applyProjectDetail(
    project,
    data
  );

  state.currentProjectId =
    String(project.id);

  if (project.steps.some(step => step.state === "running")) {
    scheduleProjectPolling(project.id);
  } else {
    stopProjectPolling(project.id);
  }

  return project;
}


function setOptimisticRunningState(stepRecord) {
  if (!stepRecord) {
    return;
  }

  stepRecord.state = "running";
  stepRecord.is_stale = false;
  stepRecord.error_message = null;
}


function stopProjectPolling(projectId) {
  const key = String(projectId);
  const timer = projectPollTimers.get(key);

  if (timer) {
    window.clearTimeout(timer);
    projectPollTimers.delete(key);
  }
}


function scheduleProjectPolling(projectId, delay = 700) {
  const key = String(projectId);

  if (projectPollTimers.has(key)) {
    return;
  }

  const timer = window.setTimeout(async () => {
    projectPollTimers.delete(key);

    const current = route();
    if (current.name !== "detail" || String(current.id) !== key) {
      return;
    }

    try {
      const project = await loadProjectDetail(key);
      render();

      if (project.steps.some(step => step.state === "running")) {
        scheduleProjectPolling(key, 1000);
      }
    } catch (error) {
      showToast(error.message);
      scheduleProjectPolling(key, 2000);
    }
  }, delay);

  projectPollTimers.set(key, timer);
}


/* =========================================================
   RENDER
   ========================================================= */

function render() {
  if (state.initializing) {
    app.innerHTML = renderProjectLoading();
    return;
  }

  const current =
    route();

  if (!state.user) {
    app.innerHTML =
      renderAuth();

    return;
  }

  let body = "";

  if (current.name === "list") {
    body =
      renderProjectList();
  }

  else if (current.name === "new") {
    body =
      renderNewProject();
  }

  else if (current.name === "detail") {

    const project =
      state.projects.find(
        item =>
          String(item.id) ===
          String(current.id)
      );

    body =
      project
        ? renderProjectDetail(project)
        : renderProjectLoading();
  }

  app.innerHTML =
    renderNav() +
    body +
    renderFooter();
}


function renderProjectLoading() {
  return `
    <div class="app-body">
      <div class="step-panel">
        <p class="help">
          Loading project...
        </p>
      </div>
    </div>
  `;
}


function renderLogo() {
  return `
    <img
      src="./assets/gradion-logo.png"
      alt="Gradion"
    >
  `;
}


function renderNav() {
  const initials =
    (state.user?.name || "?")
      .split(/\s+/)
      .map(
        word => word[0]
      )
      .join("")
      .slice(0, 2)
      .toUpperCase();

  return `
    <nav
      class="gd-nav"
      aria-label="Main navigation"
    >

      <div class="gd-nav-inner">

        <button
          class="gd-nav-logo"
          type="button"
          onclick="navigate('#/projects')"
          aria-label="Go to projects"
        >
          ${renderLogo()}
        </button>

        <div class="gd-nav-links">
          <a
            onclick="navigate('#/projects')"
          >
            Projects
          </a>
        </div>

        <div class="gd-nav-user">

          <div
            class="gd-nav-avatar"
            aria-hidden="true"
          >
            ${escapeHtml(initials)}
          </div>

          <span>
            ${escapeHtml(
              state.user.name
            )}
          </span>

          <a onclick="signOut()">
            Sign out
          </a>

        </div>

      </div>

    </nav>
  `;
}


function renderFooter() {
  return `
    <footer class="app-footer">
      <span class="gd-signature">
        GRADION <b>|</b> Scaling Business
      </span>
    </footer>
  `;
}


/* =========================================================
   AUTH
   ========================================================= */

function renderAuth() {
  return `
    <div class="center-page">

      <section
        class="auth-card"
        aria-labelledby="auth-title"
      >

        <div class="logo-row">
          ${renderLogo()}
        </div>

        <h3
          id="auth-title"
          style="text-align:center;font-size:20px;"
        >
          Book Illustration Studio
        </h3>

        <p class="lede">
          Enter your details to start or resume
          an illustration project.
        </p>

        <form
          id="identity-form"
          novalidate
        >

          <div class="gd-field">

            <label for="f-name">
              Full name
              <span class="req">*</span>
            </label>

            <input
              id="f-name"
              name="name"
              autocomplete="name"
              placeholder="Mira Hassan"
              required
            >

          </div>

          <div class="gd-field">

            <label for="f-email">
              Email
              <span class="req">*</span>
            </label>

            <input
              id="f-email"
              name="email"
              type="email"
              autocomplete="email"
              placeholder="mira@gmail.com"
              required
            >

          </div>

          <div
            class="gd-field err"
            id="auth-err"
            role="alert"
          ></div>

          <button
            class="gd-btn gd-btn-primary"
            type="submit"
          >
            Continue
            <span class="gd-arrow">
              →
            </span>
          </button>

        </form>

        <p
          class="meta"
          style="text-align:center;margin-top:14px;"
        >
          No password — this is a lightweight
          identity check. Using an email that already
          has projects resumes them exactly where
          you left off.
        </p>

      </section>

    </div>
  `;
}


/* =========================================================
   PROJECT LIST
   ========================================================= */

function renderProjectList() {
  if (!state.projects.length) {

    return `
      <div class="app-body">

        <div class="list-head">
          <h2>Your projects</h2>
        </div>

        <div class="empty-state">

          <p style="margin:0;">
            No projects yet.
          </p>

          <button
            class="gd-btn gd-btn-primary"
            type="button"
            onclick="navigate('#/projects/new')"
          >
            + New project
          </button>

        </div>

      </div>
    `;
  }

  const rows =
    state.projects
      .map(
        (project, index) => `
          <div
            class="project-row"
            style="--stagger:${index * 45}ms"
            tabindex="0"
            role="button"
            aria-label="Open ${escapeHtml(
              project.title
            )}"
            onclick="openProject('${project.id}')"
            onkeydown="
              if(
                event.key==='Enter' ||
                event.key===' '
              )
              openProject('${project.id}')
            "
          >

            <div class="title">

              <h4>
                ${escapeHtml(
                  project.title
                )}
              </h4>

              <span class="meta">
                Created
                ${
                  new Date(
                    project.createdAt
                  ).toLocaleDateString()
                }
                ·
                ${projectSubtitle(project)}
              </span>

            </div>

            ${progressMiniHtml(project)}

            ${projectPillHtml(project)}

          </div>
        `
      )
      .join("");

  return `
    <div class="app-body">

      <div class="list-head">

        <h2>Your projects</h2>

        <button
          class="gd-btn gd-btn-primary"
          type="button"
          onclick="navigate('#/projects/new')"
        >
          + New project
        </button>

      </div>

      <div class="project-list">
        ${rows}
      </div>

    </div>
  `;
}


/*
 * SINGLE projectSubtitle() function.
 */
function projectSubtitle(project) {
  const completed =
    getCompletedSteps(project);

  if (completed === 0) {
    return "Book text saved · style not yet generated";
  }

  if (completed >= 5) {
    return "All 5 steps complete";
  }

  return (
    STEPS
      .slice(0, completed)
      .map(
        step => step.label
      )
      .join(" + ") +
    " done"
  );
}


/*
 * SINGLE projectPillHtml() function.
 */
function projectPillHtml(project) {
  const completed =
    getCompletedSteps(project);

  if (completed >= 5) {
    return `
      <span class="gd-pill ink">
        Done
      </span>
    `;
  }

  if (completed === 0) {
    return `
      <span class="gd-pill gray">
        Draft
      </span>
    `;
  }

  return `
    <span class="gd-pill">
      <span class="dot"></span>
      In progress
    </span>
  `;
}


/*
 * Used by progress bar and detail page.
 */
function getCompletedStepsCount(project) {
  return getCompletedSteps(project);
}


/*
 * SINGLE progressMiniHtml() function.
 */
function progressMiniHtml(project) {
  const completed =
    getCompletedSteps(project);

  return `
    <div
      class="progress-mini"
      aria-label="${completed} of 5 steps complete"
    >
      ${STEPS.map(
        (_, index) =>
          `<span class="seg ${
            index < completed
              ? "on"
              : ""
          }"></span>`
      ).join("")}
    </div>
  `;
}


async function openProject(
  projectId
) {
  state.currentProjectId =
    String(projectId);

  navigate(
    `#/projects/${projectId}`
  );

  try {

    await loadProjectDetail(
      projectId
    );

    render();

  } catch (error) {

    showToast(
      error.message
    );
  }
}


/* =========================================================
   NEW PROJECT
   ========================================================= */

function renderNewProject() {
  return `
    <div class="app-body narrow">

      <a
        class="back-link"
        onclick="navigate('#/projects')"
      >
        ← Back to projects
      </a>

      <h3 style="font-size:20px;">
        Start a new illustration project
      </h3>

      <p
        class="meta"
        style="margin-bottom:20px;"
      >
        Give it a title, then paste the book's
        text or upload a .txt file.
      </p>

      <form
        id="new-project-form"
        novalidate
      >

        <div class="gd-field">

          <label for="f-title">
            Project title
            <span class="req">*</span>
          </label>

          <input
            id="f-title"
            name="title"
            placeholder="e.g. The Wind in the Willows — cottage-core"
            required
          >

        </div>

        <div
          class="gd-field"
          style="margin-top:16px;"
        >

          <label for="book-textarea">
            Book text
            <span class="req">*</span>
          </label>

          <label
            class="dropzone"
            id="dropzone"
            for="file-input"
            tabindex="0"
          >

            <div
              id="dropzone-label"
              style="
                font-size:13px;
                font-weight:600;
                color:var(--grad-ink);
              "
            >
              Click to choose a .txt file
            </div>

            <div class="hint">
              Plain text only · used once as context
              for every step below
            </div>

          </label>

          <input
            type="file"
            id="file-input"
            accept=".txt,text/plain"
            style="display:none;"
          >

          <div class="divider-or">
            or paste text
          </div>

          <textarea
            id="book-textarea"
            name="bookText"
            rows="7"
            placeholder="Once upon a time, in a small burrow by the river..."
            required
          ></textarea>

        </div>

        <div
          class="gd-field err"
          id="new-err"
          role="alert"
        ></div>

        <button
          class="gd-btn gd-btn-primary"
          type="submit"
          style="
            width:100%;
            justify-content:center;
            margin-top:20px;
          "
        >
          Create project
          <span class="gd-arrow">
            →
          </span>
        </button>

      </form>

    </div>
  `;
}


/* =========================================================
   STEPPER
   ========================================================= */

function stepperHtml(project) {
  const completed =
    getCompletedSteps(project);

  return `
    <div
      class="stepper"
      aria-label="Project progress"
    >

      ${STEPS.map(
        (step, index) => {

          const stepState =
            getStepState(
              project,
              step.backendStep
            );

          const done =
            stepState === "completed";

          const running =
            stepState === "running";

          const failed =
            stepState === "failed";

          const current =
            !done &&
            index === completed;

          const cls =
            done
              ? "done"
              : current
                ? "current"
                : "pending";

          const marker =
            done
              ? `
                <span
                  class="gd-num-square done"
                >
                  ✓
                </span>
              `
              : `
                <span
                  class="gd-num-square ${
                    current
                      ? ""
                      : "gray"
                  }"
                >
                  ${index + 1}
                </span>
              `;

          return `
            <div class="step ${cls}">
              ${marker}

              <span class="lbl">
                ${step.label}
              </span>

              ${
                running
                  ? `
                    <span
                      class="step-running-dot"
                      aria-label="Running"
                    ></span>
                  `
                  : ""
              }

              ${
                failed
                  ? `
                    <span
                      class="step-failed-mark"
                      aria-label="Failed"
                    >!</span>
                  `
                  : ""
              }

            </div>

            ${
              index <
              STEPS.length - 1
                ? `
                  <div
                    class="connector ${
                      done
                        ? "done"
                        : ""
                    }"
                  ></div>
                `
                : ""
            }
          `;
        }
      ).join("")}

    </div>
  `;
}


/* =========================================================
   LOADING MESSAGE
   ========================================================= */

function getLoadingMessage(
  stepKey
) {

  if (stepKey === "STYLE") {
    return `
      Reading your book text and defining an art style
      — usually a couple of seconds in this demo,
      longer for real Gemini calls...
    `;
  }

  if (stepKey === "CHARACTERS") {
    return `
      Generating the character list from your book's text
      — usually a couple of seconds in this demo,
      longer for real Gemini calls...
    `;
  }

  if (stepKey === "PORTRAITS") {
    return `
      Generating character portraits
      — usually a couple of seconds in this demo,
      longer for real Gemini calls...
    `;
  }

  if (stepKey === "CHAPTERS") {
    return `
      Reading your book text and defining the key chapter
      — usually a couple of seconds in this demo,
      longer for real Gemini calls...
    `;
  }

  return `
    Creating the final chapter illustration
    — usually a couple of seconds in this demo,
    longer for real Gemini calls...
  `;
}


/* =========================================================
   PROJECT DETAIL
   ========================================================= */

function renderProjectDetail(
  project
) {

  const completed =
    getCompletedSteps(project);

  const currentStep =
    STEPS[completed];

  let mainPanel;


  /* -------------------------------------------------------
     COMPLETE
     ------------------------------------------------------- */

  if (!currentStep) {

    mainPanel = `
      <div class="step-panel">

        <div
          class="status-line"
          style="color:var(--grad-ink);"
        >

          <span
            class="gd-num-square done"
            style="
              width:20px;
              height:20px;
              font-size:11px;
            "
          >
            ✓
          </span>

          All 5 steps complete.

        </div>

        <p class="help">
          This project has completed the
          full illustration pipeline.
        </p>

      </div>
    `;

  }

  /* -------------------------------------------------------
     CURRENT STEP
     ------------------------------------------------------- */

  else {

    const stepState =
      getStepState(
        project,
        currentStep.backendStep
      );

    const currentBackendStep =
      project.steps?.find(
        step =>
          step.step ===
          currentStep.backendStep
      );

    const isRunning =
      stepState === "running";

    const isFailed =
      stepState === "failed";

    const isStale =
      isRunning &&
      Boolean(currentBackendStep?.is_stale);


    /* -----------------------------------------------------
       STYLE INPUT
       ----------------------------------------------------- */

    const styleField =
      currentStep.key === "STYLE" &&
      !isRunning
        ? `
          <div
            class="gd-field"
            style="margin-bottom:14px;"
          >

            <label for="style-input">
              Art style (optional)
            </label>

            <input
              id="style-input"
              placeholder="Leave blank to let Gemini choose a style"
            >

          </div>
        `
        : "";


    /* -----------------------------------------------------
       STATUS CONTENT
       ----------------------------------------------------- */

    let statusContent = "";


    if (isStale) {

      statusContent = `
        <div class="status-line" style="color:var(--grad-ink);">
          <b>${currentStep.label}</b> appears to be stuck.
        </div>

        <div class="gd-field err" role="alert" style="margin-top:14px;">
          The previous request exceeded the recovery timeout. Retry this same
          step; completed steps will not run again.
        </div>
      `;

    }

    else if (isRunning) {

      statusContent = `
        <div class="pipeline-loading">

          <span
            class="pipeline-spinner"
            aria-hidden="true"
          ></span>

          <span class="pipeline-loading-text">
            ${getLoadingMessage(
              currentStep.key
            )}
          </span>

        </div>

        <p class="help pipeline-loading-help">
          Reopening this page mid-step won't fire
          a second request — it just shows the same
          in-flight state until it lands.
        </p>
      `;

    }

    else if (isFailed) {

      const errorMessage =
        currentBackendStep?.error_message ||
        "Step failed.";

      statusContent = `
        <div
          class="status-line"
          style="color:var(--grad-ink);"
        >
          <b>
            ${currentStep.label}
          </b>
          failed.
        </div>

        <div
          class="gd-field err"
          role="alert"
          style="margin-top:14px;"
        >
          ${escapeHtml(
            errorMessage
          )}
        </div>
      `;

    }

    else {

      statusContent = `
        <div
          class="status-line"
          style="color:var(--grad-ink);"
        >
          Ready for the next step:
          <b>
            ${currentStep.label}
          </b>.
        </div>

        <p class="help">
          Reopening this page mid-step won't fire
          a second request — it just shows the same
          in-flight state until it lands.
        </p>
      `;
    }


    /* -----------------------------------------------------
       BUTTON
       ----------------------------------------------------- */

    const buttonText =
      isStale
        ? `Retry ${currentStep.label}`
        : isRunning
        ? "Generating..."
        : isFailed
          ? `Retry ${currentStep.label}`
          : `Generate ${currentStep.label}`;


    mainPanel = `
      <div class="step-panel">

        ${styleField}

        ${statusContent}

        <button
          class="gd-btn gd-btn-primary"
          type="button"
          ${isRunning && !isStale ? "disabled" : ""}
          onclick="runCurrentStep('${project.id}')"
        >
          ${isRunning && !isStale
            ? `<span class="button-spinner" aria-hidden="true"></span>`
            : ""}
          ${buttonText}

          ${
            isRunning && !isStale
              ? ""
              : `
                <span class="gd-arrow">
                  →
                </span>
              `
          }
        </button>

      </div>
    `;
  }


  /* =======================================================
     ENTITIES
     ======================================================= */

  let entities = "";


  if (project.chapters.length) {

    entities += `
      <div class="panel-title">

        <h3>
          Chapters (${project.chapters.length})
        </h3>

      </div>

      <div
        class="entity-grid"
        style="
          grid-template-columns:1fr;
          margin-bottom:28px;
        "
      >

        ${project.chapters.map(
          (chapter, index) =>
            entityCardHtml(
              chapter,
              "chapter",
              index
            )
        ).join("")}

      </div>
    `;
  }


  if (project.characters.length) {

    entities += `
      <div class="panel-title">

        <h3>
          Characters (${project.characters.length})
        </h3>

      </div>

      <div class="entity-grid">

        ${project.characters.map(
          (character, index) =>
            entityCardHtml(
              character,
              "character",
              index
            )
        ).join("")}

      </div>
    `;
  }


  /* =======================================================
     SIDE NOTE
     ======================================================= */

  const textIsLong =
    project.bookText
      .replace(/\s+/g, " ")
      .trim()
      .length > 220;


  const sideNote = `
    <div class="side-note">

      ${
        project.style
          ? `
            <h5>
              Style
            </h5>

            <p>
              ${escapeHtml(
                project.style
              )}
            </p>
          `
          : ""
      }

      <h5
        style="${
          project.style
            ? "margin-top:16px;"
            : ""
        }"
      >
        Book text
      </h5>

      <p style="font-style:italic;">
        ${escapeHtml(
          snippet(
            project.bookText,
            220
          )
        )}
      </p>

      ${
        textIsLong
          ? `
            <button
              type="button"
              class="gd-btn gd-btn-ghost gd-btn-sm"
              style="
                padding-left:0;
                margin-top:8px;
              "
              onclick="openBookModal('${project.id}')"
            >
              Read full text →
            </button>
          `
          : ""
      }

    </div>
  `;


  return `
    <div class="app-body">

      <a
        class="back-link"
        onclick="navigate('#/projects')"
      >
        ← Back to projects
      </a>

      <h2
        style="
          font-size:22px;
          margin-bottom:4px;
        "
      >
        ${escapeHtml(
          project.title
        )}
      </h2>

      <p
        class="meta"
        style="margin-bottom:24px;"
      >
        Created
        ${
          new Date(
            project.createdAt
          ).toLocaleDateString()
        }
        by
        ${escapeHtml(
          state.user.name
        )}
      </p>

      ${stepperHtml(project)}

      <div class="detail-grid">

        <div>

          ${mainPanel}

          ${
            entities
              ? `
                <div
                  style="margin-top:28px;"
                >
                  ${entities}
                </div>
              `
              : ""
          }

        </div>

        <div>
          ${sideNote}
        </div>

      </div>

    </div>
  `;
}


/* =========================================================
   ENTITY CARDS
   ========================================================= */

function entityCardHtml(
  item,
  kind,
  index
) {

  const isCharacter =
    kind === "character";

  const artClass =
    isCharacter
      ? "art"
      : "art chapter";

  const imageUrl =
    isCharacter
      ? item.portraitUrl
      : item.illustrationUrl;

  const imageStatus =
    isCharacter
      ? item.portraitStatus
      : item.illustrationStatus;

  let visual;


  if (
    imageUrl &&
    imageStatus === "completed"
  ) {

    visual = `
      <img
        src="${escapeHtml(imageUrl)}"
        alt="${escapeHtml(item.name)}"
        style="
          width:100%;
          height:100%;
          object-fit:cover;
          display:block;
        "
      >
    `;

  }

  else if (imageStatus === "generating") {

    visual = `
      <span class="entity-generating">
        <span class="pipeline-spinner" aria-hidden="true"></span>
        Generating...
      </span>
    `;

  }

  else if (imageStatus === "failed") {

    visual = `
      <span class="placeholder-label muted">
        Generation failed — retry the step
      </span>
    `;

  }

  else {

    visual = `
      <span class="placeholder-label muted">
        ${
          isCharacter
            ? "Portrait preview"
            : "Illustration preview"
        }
      </span>
    `;
  }


  return `
    <div
      class="entity-card"
      style="--stagger:${index * 60}ms"
    >

      <div class="${artClass}">
        ${visual}
      </div>

      <div class="body">

        <h5>
          ${escapeHtml(
            item.name
          )}
        </h5>

        <p>
          ${escapeHtml(
            item.prompt
          )}
        </p>

      </div>

    </div>
  `;
}


/* =========================================================
   FILE HANDLING
   ========================================================= */

function handleFile(file) {

  if (!file) {
    return;
  }

  if (
    file.type &&
    file.type !== "text/plain" &&
    !file.name
      .toLowerCase()
      .endsWith(".txt")
  ) {

    const error =
      document.getElementById(
        "new-err"
      );

    if (error) {
      error.textContent =
        "Please choose a .txt file.";
    }

    return;
  }


  const reader =
    new FileReader();


  reader.onload = event => {

    state.pendingBookText =
      String(
        event.target.result || ""
      );

    const textarea =
      document.getElementById(
        "book-textarea"
      );

    const dropzone =
      document.getElementById(
        "dropzone"
      );

    const label =
      document.getElementById(
        "dropzone-label"
      );


    if (textarea) {
      textarea.value =
        state.pendingBookText;
    }

    if (dropzone) {
      dropzone.classList.add(
        "has-file"
      );
    }

    if (label) {
      label.textContent =
        `✓ ${file.name} loaded`;
    }
  };


  reader.onerror = () => {

    const error =
      document.getElementById(
        "new-err"
      );

    if (error) {
      error.textContent =
        "Could not read the selected file.";
    }
  };


  reader.readAsText(file);
}


/* =========================================================
   CREATE PROJECT
   ========================================================= */

async function createProjectFromForm(
  form
) {

  const title =
    document
      .getElementById("f-title")
      .value
      .trim();

  const bookText =
    document
      .getElementById("book-textarea")
      .value
      .trim();

  const error =
    document.getElementById(
      "new-err"
    );

  const button =
    form.querySelector(
      'button[type="submit"]'
    );


  if (!title || !bookText) {

    error.textContent =
      "Give the project a title and provide the book text.";

    return;
  }

  error.textContent = "";


  setButtonLoading(
    button,
    true,
    "Creating project..."
  );


  try {

    const data =
      await api(
        "projects.php",
        {
          method: "POST",
          body: JSON.stringify({
            title,
            book_text: bookText
          })
        }
      );


    const backendProject =
      data?.project;

    if (!backendProject?.id) {
      throw new Error("Create-project response is missing project data.");
    }

    const project =
      mapProject(
        backendProject,
        data.steps || []
      );

    /*
     * New project always starts at 0/5.
     */
    project.completedSteps = 0;
    project.status = "CREATED";


    state.projects.unshift(
      project
    );

    state.currentProjectId =
      String(project.id);

    state.pendingBookText =
      "";

    showToast(
      "Project created."
    );

    navigate(
      `#/projects/${project.id}`
    );

    await loadProjectDetail(
      project.id
    );

    render();

  }

  catch (requestError) {

    error.textContent =
      requestError.message;

  }

  finally {

    setButtonLoading(
      button,
      false
    );
  }
}


/* =========================================================
   IDENTITY / LOGIN
   ========================================================= */

async function signInFromForm() {

  const nameInput =
    document.getElementById(
      "f-name"
    );

  const emailInput =
    document.getElementById(
      "f-email"
    );

  const error =
    document.getElementById(
      "auth-err"
    );

  const form =
    document.getElementById(
      "identity-form"
    );

  const button =
    form?.querySelector(
      'button[type="submit"]'
    );


  const name =
    nameInput.value.trim();

  const email =
    emailInput.value
      .trim()
      .toLowerCase();


  if (!name) {

    error.textContent =
      "Please enter your name.";

    nameInput.focus();

    return;
  }


  if (
    !email ||
    !emailInput.checkValidity()
  ) {

    error.textContent =
      "Please enter a valid email.";

    emailInput.focus();

    return;
  }


  error.textContent = "";


  setButtonLoading(
    button,
    true,
    "Connecting..."
  );


  try {

    const data =
      await api(
        "identity.php",
        {
          method: "POST",
          body: JSON.stringify({
            name,
            email
          })
        }
      );


    if (!data?.user?.id || !data.user.name || !data.user.email) {
      throw new Error("Identity response is missing user data.");
    }

    state.user = data.user;

    /*
     * IMPORTANT:
     * projects.php returns completed_steps.
     * loadProjects() now uses it to restore
     * Draft / In progress / Done correctly.
     */
    await loadProjects();

    showToast(
      "Identity accepted."
    );

    navigate(
      "#/projects"
    );

    render();

  }

  catch (requestError) {

    error.textContent =
      requestError.message;

  }

  finally {

    setButtonLoading(
      button,
      false
    );
  }
}


/* =========================================================
   SIGN OUT
   ========================================================= */

async function signOut() {
  try {
    await api("identity.php", { method: "DELETE" });
  } catch (error) {
    showToast(error.message);
  } finally {
    projectPollTimers.forEach(timer => window.clearTimeout(timer));
    projectPollTimers.clear();
    state.user = null;
    state.projects = [];
    state.currentProjectId = null;
    state.pendingBookText = "";
    navigate("#/");
    render();
  }
}


/* =========================================================
   RUN PIPELINE STEP
   ========================================================= */

async function runCurrentStep(
  projectId
) {

  const project =
    state.projects.find(
      item =>
        String(item.id) ===
        String(projectId)
    );


  if (!project) {
    return;
  }


  const currentBackendStep =
    getCurrentBackendStep(
      project
    );


  if (!currentBackendStep) {

    showToast(
      "This project is already complete."
    );

    return;
  }


  const styleInput =
    document.getElementById(
      "style-input"
    );

  const userStyle =
    styleInput
      ? styleInput.value.trim()
      : null;


  /*
   * Optimistically display running state.
   * DO NOT increase completedSteps here.
   */
  const stepRecord =
    project.steps.find(
      step =>
        step.step ===
        currentBackendStep
    );


  setOptimisticRunningState(stepRecord);

  if (currentBackendStep === "portraits") {
    const nextPortrait = project.characters.find(
      character => character.portraitStatus !== "completed"
    );

    if (nextPortrait) {
      nextPortrait.portraitStatus = "generating";
      nextPortrait.portraitError = null;
    }
  }

  if (currentBackendStep === "illustrations") {
    const nextIllustration = project.chapters.find(
      chapter => chapter.illustrationStatus !== "completed"
    );

    if (nextIllustration) {
      nextIllustration.illustrationStatus = "generating";
      nextIllustration.illustrationError = null;
    }
  }


  /*
   * Keep the real completed count.
   * Running != completed.
   */
  project.completedSteps =
    getCompletedCountFromSteps(
      project.steps
    );

  project.status =
    getFrontendStatusFromCompletedCount(
      project.completedSteps
    );


  render();

  scheduleProjectPolling(projectId);


  try {

    const data =
      await api(
        "run-step.php",
        {
          method: "POST",

          body: JSON.stringify({
            project_id:
              Number(projectId),

            step:
              currentBackendStep,

            ...(userStyle
              ? {
                  user_style:
                    userStyle
                }
              : {})
          })
        }
      );


    /*
     * Backend returns the authoritative detail.
     * This recalculates completedSteps from
     * the actual project_steps state.
     */
    applyProjectDetail(
      project,
      data.detail
    );

    stopProjectPolling(projectId);


    showToast(
      `${currentBackendStep} completed.`
    );


    render();

  }

  catch (requestError) {

    try {

      await loadProjectDetail(
        projectId
      );

    }

    catch {
      // Keep original error.
    }


    showToast(
      requestError.message
    );

    render();
  }
}


/* =========================================================
   BOOK MODAL
   ========================================================= */

function openBookModal(
  projectId
) {

  if (!modal || !modalBody) {
    return;
  }

  const project =
    state.projects.find(
      item =>
        String(item.id) ===
        String(projectId)
    );


  if (!project) {
    return;
  }


  modalBody.textContent =
    project.bookText;

  modal.hidden = false;

  modalClose?.focus();
}


function closeBookModal() {

  if (modal) {
    modal.hidden = true;
  }
}


/* =========================================================
   EVENT HANDLERS
   ========================================================= */

document.addEventListener(
  "submit",
  event => {

    if (
      event.target.id ===
      "identity-form"
    ) {

      event.preventDefault();

      signInFromForm();

      return;
    }


    if (
      event.target.id ===
      "new-project-form"
    ) {

      event.preventDefault();

      createProjectFromForm(
        event.target
      );
    }
  }
);


document.addEventListener(
  "change",
  event => {

    if (
      event.target.id ===
      "file-input"
    ) {

      handleFile(
        event.target.files[0]
      );
    }
  }
);


document.addEventListener(
  "keydown",
  event => {

    if (
      event.target.id ===
        "dropzone" &&
      (
        event.key === "Enter" ||
        event.key === " "
      )
    ) {

      event.preventDefault();

      document
        .getElementById(
          "file-input"
        )
        ?.click();
    }


    if (
      event.key === "Escape" &&
      modal &&
      !modal.hidden
    ) {

      closeBookModal();
    }
  }
);


if (modalClose) {

  modalClose.addEventListener(
    "click",
    closeBookModal
  );
}


if (modal) {

  modal.addEventListener(
    "click",
    event => {

      if (
        event.target === modal
      ) {
        closeBookModal();
      }
    }
  );
}


/* =========================================================
   ROUTING
   ========================================================= */

window.addEventListener(
  "hashchange",
  async () => {

    render();

    const current =
      route();


    if (
      current.name ===
      "detail"
    ) {

      try {

        await loadProjectDetail(
          current.id
        );

        render();

      }

      catch (error) {

        showToast(
          error.message
        );
      }
    }
  }
);


/* =========================================================
   INITIAL LOAD
   ========================================================= */

async function initializeApp() {
  try {
    const data = await api("identity.php");

    if (!data?.user?.id || !data.user.name || !data.user.email) {
      throw new Error("Identity response is missing user data.");
    }

    state.user = data.user;
    await loadProjects();

    const current = route();
    if (current.name === "detail") {
      await loadProjectDetail(current.id);
    }
  } catch (error) {
    state.user = null;
    state.projects = [];

    if (error.status && error.status !== 401) {
      showToast(error.message);
    }
  } finally {
    state.initializing = false;
    render();
  }
}

window.addEventListener("DOMContentLoaded", initializeApp);
