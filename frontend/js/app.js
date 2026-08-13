const STEPS = [
  { key: "STYLE", label: "Style", status: "STYLE_SET" },
  { key: "CHARACTERS", label: "Characters", status: "CHARACTERS_GENERATED" },
  { key: "PORTRAITS", label: "Portraits", status: "PORTRAITS_GENERATED" },
  { key: "CHAPTERS", label: "Chapters", status: "CHAPTERS_GENERATED" },
  { key: "ILLUSTRATIONS", label: "Illustrations", status: "DONE" }
];

const STATUS_ORDER = ["CREATED", ...STEPS.map(step => step.status)];

const state = {
  user: null,
  projects: [],
  currentProjectId: null,
  pendingBookText: ""
};

const app = document.getElementById("app");
const toast = document.getElementById("toast");
const modal = document.getElementById("book-modal");
const modalBody = document.getElementById("book-modal-body");
const modalClose = document.getElementById("book-modal-close");

function statusIndex(status) {
  return STATUS_ORDER.indexOf(status);
}

function navigate(hash) {
  window.location.hash = hash;
}

function route() {
  const hash = window.location.hash.replace(/^#\/?/, "");

  if (!state.user) return { name: "auth" };
  if (hash === "" || hash === "projects") return { name: "list" };
  if (hash === "projects/new") return { name: "new" };

  const match = hash.match(/^projects\/([a-z0-9-]+)$/);
  if (match) return { name: "detail", id: match[1] };

  return { name: "list" };
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, char => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;"
  }[char]));
}

function snippet(text, maxLength) {
  const normalized = String(text || "").replace(/\s+/g, " ").trim();
  return normalized.length > maxLength
    ? normalized.slice(0, maxLength) + "…"
    : normalized;
}

function showToast(message) {
  toast.textContent = message;
  toast.classList.remove("hidden");
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => {
    toast.classList.add("hidden");
  }, 2200);
}

function render() {
  const current = route();

  if (current.name === "auth") {
    app.innerHTML = renderAuth();
    return;
  }

  let body = "";

  if (current.name === "list") {
    body = renderProjectList();
  } else if (current.name === "new") {
    body = renderNewProject();
  } else if (current.name === "detail") {
    const project = state.projects.find(item => item.id === current.id);
    body = project ? renderProjectDetail(project) : renderProjectList();
  }

  app.innerHTML = renderNav() + body + renderFooter();
}

function renderLogo() {
  return `<img src="./assets/gradion-logo.png" alt="Gradion">`;
}

function renderNav() {
  const initials = (state.user?.name || "?")
    .split(/\s+/)
    .map(word => word[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();

  return `
    <nav class="gd-nav" aria-label="Main navigation">
      <div class="gd-nav-inner">
        <button class="gd-nav-logo" type="button"
                onclick="navigate('#/projects')"
                aria-label="Go to projects">
          ${renderLogo()}
        </button>

        <div class="gd-nav-links">
          <a onclick="navigate('#/projects')">Projects</a>
        </div>

        <div class="gd-nav-user">
          <div class="gd-nav-avatar" aria-hidden="true">${escapeHtml(initials)}</div>
          <span>${escapeHtml(state.user.name)}</span>
          <a onclick="signOut()">Sign out</a>
        </div>
      </div>
    </nav>
  `;
}

function renderFooter() {
  return `
    <footer class="app-footer">
      <span class="gd-signature">GRADION <b>|</b> Scaling Business</span>
      
    </footer>
  `;
}

function renderAuth() {
  return `
    <div class="center-page">
      <section class="auth-card" aria-labelledby="auth-title">
        <div class="logo-row">${renderLogo()}</div>

        <h3 id="auth-title" style="text-align:center;font-size:20px;">
          Book Illustration Studio
        </h3>

        <p class="lede">
          Enter your details to start or resume an illustration project.
        </p>

        <form id="identity-form" novalidate>
          <div class="gd-field">
            <label for="f-name">Full name <span class="req">*</span></label>
            <input id="f-name" name="name" autocomplete="name"
                   placeholder="Mira Hassan" required>
          </div>

          <div class="gd-field">
            <label for="f-email">Email <span class="req">*</span></label>
            <input id="f-email" name="email" type="email"
                   autocomplete="email" placeholder="mira@gmail.com" required>
          </div>

          <div class="gd-field err" id="auth-err" role="alert"></div>

          <button class="gd-btn gd-btn-primary" type="submit">
            Continue <span class="gd-arrow">→</span>
          </button>
        </form>

        <p class="meta" style="text-align:center;margin-top:14px;">
          No password — this is a lightweight identity check. Using an email that already has projects resumes them exactly where you left off.
        </p>
      </section>
    </div>
  `;
}

function renderProjectList() {
  if (!state.projects.length) {
    return `
      <div class="app-body">
        <div class="list-head">
          <h2>Your projects</h2>
        </div>

        <div class="empty-state">
          <p style="margin:0;">No projects yet.</p>
          <button class="gd-btn gd-btn-primary"
                  type="button"
                  onclick="navigate('#/projects/new')">
            + New project
          </button>
        </div>
      </div>
    `;
  }

  const rows = state.projects.map((project, index) => `
    <div class="project-row"
         style="--stagger:${index * 45}ms"
         tabindex="0"
         role="button"
         aria-label="Open ${escapeHtml(project.title)}"
         onclick="navigate('#/projects/${project.id}')"
         onkeydown="if(event.key==='Enter' || event.key===' ') navigate('#/projects/${project.id}')">

      <div class="title">
        <h4>${escapeHtml(project.title)}</h4>
        <span class="meta">
          Created ${new Date(project.createdAt).toLocaleDateString()}
          · ${projectSubtitle(project)}
        </span>
      </div>

      ${progressMiniHtml(project)}
      ${projectPillHtml(project)}
    </div>
  `).join("");

  return `
    <div class="app-body">
      <div class="list-head">
        <h2>Your projects</h2>
        <button class="gd-btn gd-btn-primary"
                type="button"
                onclick="navigate('#/projects/new')">
          + New project
        </button>
      </div>

      <div class="project-list">${rows}</div>
    </div>
  `;
}

function projectSubtitle(project) {
  if (project.status === "CREATED") {
    return "Book text saved · style not yet generated";
  }

  if (project.status === "DONE") {
    return "All 5 steps complete";
  }

  const completed = statusIndex(project.status);
  return STEPS.slice(0, completed).map(step => step.label).join(" + ") + " done";
}

function projectPillHtml(project) {
  if (project.status === "DONE") {
    return `<span class="gd-pill ink">Done</span>`;
  }

  if (project.status === "CREATED") {
    return `<span class="gd-pill gray">Draft</span>`;
  }

  return `<span class="gd-pill"><span class="dot"></span>In progress</span>`;
}

function progressMiniHtml(project) {
  const completed = statusIndex(project.status);

  return `
    <div class="progress-mini" aria-label="${completed} of 5 steps complete">
      ${STEPS.map((_, index) =>
        `<span class="seg ${index < completed ? "on" : ""}"></span>`
      ).join("")}
    </div>
  `;
}

function renderNewProject() {
  return `
    <div class="app-body narrow">
      <a class="back-link" onclick="navigate('#/projects')">
        ← Back to projects
      </a>

      <h3 style="font-size:20px;">Start a new illustration project</h3>

      <p class="meta" style="margin-bottom:20px;">
        Give it a title, then paste the book's text or upload a .txt file.
      </p>

      <form id="new-project-form" novalidate>
        <div class="gd-field">
          <label for="f-title">
            Project title <span class="req">*</span>
          </label>
          <input id="f-title"
                 name="title"
                 placeholder="e.g. The Wind in the Willows — cottage-core"
                 required>
        </div>

        <div class="gd-field" style="margin-top:16px;">
          <label for="book-textarea">
            Book text <span class="req">*</span>
          </label>

          <label class="dropzone" id="dropzone" for="file-input" tabindex="0">
            <div id="dropzone-label"
                 style="font-size:13px;font-weight:600;color:var(--grad-ink);">
              Click to choose a .txt file
            </div>
            <div class="hint">
              Plain text only · used once as context for every step below
            </div>
          </label>

          <input type="file"
                 id="file-input"
                 accept=".txt,text/plain"
                 style="display:none;">

          <div class="divider-or">or paste text</div>

          <textarea id="book-textarea"
                    name="bookText"
                    rows="7"
                    placeholder="Once upon a time, in a small burrow by the river..."
                    required></textarea>
        </div>

        <div class="gd-field err" id="new-err" role="alert"></div>

        <button class="gd-btn gd-btn-primary"
                type="submit"
                style="width:100%;justify-content:center;margin-top:20px;">
          Create project <span class="gd-arrow">→</span>
        </button>
      </form>
    </div>
  `;
}

function stepperHtml(project) {
  const completed = statusIndex(project.status);

  return `
    <div class="stepper" aria-label="Project progress">
      ${STEPS.map((step, index) => {
        const done = index < completed;
        const current = index === completed;
        const cls = done ? "done" : current ? "current" : "pending";

        const marker = done
          ? `<span class="gd-num-square done">✓</span>`
          : `<span class="gd-num-square ${current ? "" : "gray"}">${index + 1}</span>`;

        return `
          <div class="step ${cls}">
            ${marker}
            <span class="lbl">${step.label}</span>
          </div>
          ${index < STEPS.length - 1
            ? `<div class="connector ${index < completed ? "done" : ""}"></div>`
            : ""}
        `;
      }).join("")}
    </div>
  `;
}

function renderProjectDetail(project) {
  const completed = statusIndex(project.status);
  const currentStep = STEPS[completed];

  let mainPanel;

  if (!currentStep) {
    mainPanel = `
      <div class="step-panel">
        <div class="status-line" style="color:var(--grad-ink);">
          <span class="gd-num-square done"
                style="width:20px;height:20px;font-size:11px;">✓</span>
          All 5 steps complete.
        </div>
        <p class="help">
          This is the completed-project UI state. Gemini execution will be
          connected in the backend phase.
        </p>
      </div>
    `;
  } else {
    const styleField = currentStep.key === "STYLE"
      ? `
        <div class="gd-field" style="margin-bottom:14px;">
          <label for="style-input">Art style (optional)</label>
          <input id="style-input"
                 placeholder="Leave blank to let Gemini choose a style">
        </div>
      `
      : "";

    mainPanel = `
      <div class="step-panel">
        <div class="status-line" style="color:var(--grad-ink);">
          Ready for the next step:
          <b>${currentStep.label}</b>.
        </div>

        ${styleField}

        <p class="help">
          Backend execution will be connected next. This button currently
          only shows the intended UI action.
        </p>

        <button class="gd-btn gd-btn-primary"
                type="button"
                onclick="previewStepAction('${currentStep.key}')">
          Generate ${currentStep.label}
          <span class="gd-arrow">→</span>
        </button>
      </div>
    `;
  }

  let entities = "";

  if (project.chapters.length) {
    entities += `
      <div class="panel-title">
        <h3>Chapters (${project.chapters.length})</h3>
      </div>

      <div class="entity-grid"
           style="grid-template-columns:1fr;margin-bottom:28px;">
        ${project.chapters.map((chapter, index) =>
          entityCardHtml(chapter, "chapter", index)
        ).join("")}
      </div>
    `;
  }

  if (project.characters.length) {
    entities += `
      <div class="panel-title">
        <h3>Characters (${project.characters.length})</h3>
      </div>

      <div class="entity-grid">
        ${project.characters.map((character, index) =>
          entityCardHtml(character, "character", index)
        ).join("")}
      </div>
    `;
  }

  const textIsLong = project.bookText.replace(/\s+/g, " ").trim().length > 220;

const sideNote = project.style
  ? `
    <div class="side-note">
      <h5>Style</h5>
      <p>${escapeHtml(project.style)}</p>

      <h5 style="margin-top:16px;">Book text</h5>
      <p style="font-style:italic;">
        ${escapeHtml(snippet(project.bookText, 220))}
      </p>

      ${textIsLong
        ? `<button type="button"
                   class="gd-btn gd-btn-ghost gd-btn-sm"
                   style="padding-left:0;margin-top:8px;"
                   onclick="openBookModal('${project.id}')">
              Read full text →
           </button>`
        : ""}
    </div>
  `
  : `
    <div class="side-note">
      <h5>Book text</h5>
      <p style="font-style:italic;">
        ${escapeHtml(snippet(project.bookText, 220))}
      </p>

      ${textIsLong
        ? `<button type="button"
                   class="gd-btn gd-btn-ghost gd-btn-sm"
                   style="padding-left:0;margin-top:8px;"
                   onclick="openBookModal('${project.id}')">
              Read full text →
           </button>`
        : ""}
    </div>
  `;

  return `
    <div class="app-body">
      <a class="back-link" onclick="navigate('#/projects')">
        ← Back to projects
      </a>

      <h2 style="font-size:22px;margin-bottom:4px;">
        ${escapeHtml(project.title)}
      </h2>

      <p class="meta" style="margin-bottom:24px;">
        Created ${new Date(project.createdAt).toLocaleDateString()}
        by ${escapeHtml(state.user.name)}
      </p>

      ${stepperHtml(project)}

      <div class="detail-grid">
        <div>
          ${mainPanel}
          ${entities ? `<div style="margin-top:28px;">${entities}</div>` : ""}
        </div>

        <div>${sideNote}</div>
      </div>
    </div>
  `;
}

function entityCardHtml(item, kind, index) {
  const artClass = kind === "character" ? "art" : "art chapter";

  return `
    <div class="entity-card" style="--stagger:${index * 60}ms">
      <div class="${artClass}">
        <span class="placeholder-label muted">
          ${kind === "character" ? "Portrait preview" : "Illustration preview"}
        </span>
      </div>

      <div class="body">
        <h5>${escapeHtml(item.name)}</h5>
        <p>${escapeHtml(item.prompt)}</p>
      </div>
    </div>
  `;
}

function handleFile(file) {
  if (!file) return;

  if (file.type && file.type !== "text/plain" && !file.name.toLowerCase().endsWith(".txt")) {
    const error = document.getElementById("new-err");
    if (error) error.textContent = "Please choose a .txt file.";
    return;
  }

  const reader = new FileReader();

  reader.onload = event => {
    state.pendingBookText = String(event.target.result || "");

    const textarea = document.getElementById("book-textarea");
    const dropzone = document.getElementById("dropzone");
    const label = document.getElementById("dropzone-label");

    if (textarea) textarea.value = state.pendingBookText;
    if (dropzone) dropzone.classList.add("has-file");
    if (label) label.textContent = `✓ ${file.name} loaded`;
  };

  reader.onerror = () => {
    const error = document.getElementById("new-err");
    if (error) error.textContent = "Could not read the selected file.";
  };

  reader.readAsText(file);
}

function createProjectFromForm(form) {
  const title = document.getElementById("f-title").value.trim();
  const bookText = document.getElementById("book-textarea").value.trim();
  const error = document.getElementById("new-err");

  if (!title || !bookText) {
    error.textContent = "Give the project a title and provide the book text.";
    return;
  }

  const project = {
    id: crypto.randomUUID ? crypto.randomUUID() : String(Date.now()),
    title,
    bookText,
    createdAt: Date.now(),
    status: "CREATED",
    style: null,
    characters: [],
    chapters: []
  };

  state.projects.unshift(project);
  state.currentProjectId = project.id;
  state.pendingBookText = "";

  showToast("Project created.");
  navigate(`#/projects/${project.id}`);
}

function signInFromForm() {
  const nameInput = document.getElementById("f-name");
  const emailInput = document.getElementById("f-email");
  const error = document.getElementById("auth-err");

  const name = nameInput.value.trim();
  const email = emailInput.value.trim().toLowerCase();

  if (!name) {
    error.textContent = "Please enter your name.";
    nameInput.focus();
    return;
  }

  if (!email || !emailInput.checkValidity()) {
    error.textContent = "Please enter a valid email.";
    emailInput.focus();
    return;
  }

  // Temporary in-memory identity only.
  // Replace this block with POST /backend/api/identity.php.
  state.user = { name, email };

  showToast("Identity accepted.");
  navigate("#/projects");
}

function signOut() {
  state.user = null;
  state.projects = [];
  state.currentProjectId = null;
  navigate("#/");
}

function previewStepAction(stepKey) {
  showToast(`${stepKey} UI is ready. Backend API comes next.`);
}

function openBookModal(projectId) {
  const project = state.projects.find(item => item.id === projectId);
  if (!project) return;

  modalBody.textContent = project.bookText;
  modal.hidden = false;
  modalClose.focus();
}

function closeBookModal() {
  modal.hidden = true;
}

document.addEventListener("submit", event => {
  if (event.target.id === "identity-form") {
    event.preventDefault();
    signInFromForm();
  }

  if (event.target.id === "new-project-form") {
    event.preventDefault();
    createProjectFromForm(event.target);
  }
});

document.addEventListener("change", event => {
  if (event.target.id === "file-input") {
    handleFile(event.target.files[0]);
  }
});

document.addEventListener("keydown", event => {
  if (event.target.id === "dropzone" &&
      (event.key === "Enter" || event.key === " ")) {
    event.preventDefault();
    document.getElementById("file-input")?.click();
  }

  if (event.key === "Escape" && !modal.hidden) {
    closeBookModal();
  }
});

modalClose.addEventListener("click", closeBookModal);

modal.addEventListener("click", event => {
  if (event.target === modal) closeBookModal();
});

window.addEventListener("hashchange", render);
window.addEventListener("DOMContentLoaded", render);

render();
