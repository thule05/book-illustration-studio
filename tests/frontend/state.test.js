const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(
  path.join(__dirname, "..", "..", "frontend", "js", "app.js"),
  "utf8"
);

const appClasses = new Set();

const elements = {
  app: {
    innerHTML: "",
    classList: {
      toggle(name, force) {
        if (force) {
          appClasses.add(name);
        } else {
          appClasses.delete(name);
        }
      },
      contains(name) {
        return appClasses.has(name);
      },
    },
  },
  toast: { textContent: "", classList: { add() {}, remove() {} } },
  "book-modal": { hidden: true, addEventListener() {} },
  "book-modal-body": { textContent: "" },
  "book-modal-close": { addEventListener() {}, focus() {} },
};

const context = vm.createContext({
  console,
  URLSearchParams,
  document: {
    getElementById(id) {
      return elements[id] || null;
    },
    addEventListener() {},
  },
  window: {
    location: { hash: "" },
    addEventListener() {},
    setTimeout,
    clearTimeout,
  },
  fetch: async () => {
    throw new Error("fetch should not run in state tests");
  },
  FileReader: function FileReader() {},
});

vm.runInContext(source, context, { filename: "frontend/js/app.js" });

let passed = 0;
function test(name, callback) {
  callback();
  passed += 1;
  console.log(`PASS  ${name}`);
}

function evaluate(expression) {
  return vm.runInContext(expression, context);
}

test("project list status derives from completed_steps", () => {
  const statuses = evaluate(`[
    mapProject({id: 1, title: "Draft", completed_steps: 0}).status,
    mapProject({id: 2, title: "Partial", completed_steps: 2}).status,
    mapProject({id: 3, title: "Done", completed_steps: 5}).status
  ]`);

  assert.deepEqual(Array.from(statuses), [
    "CREATED",
    "CHARACTERS_GENERATED",
    "DONE",
  ]);
});

test("progress helper clamps backend count to five", () => {
  assert.equal(
    evaluate(`getCompletedSteps(mapProject({id: 1, completed_steps: 99}))`),
    5
  );
});

test("detail mapping uses singular style and media URLs", () => {
  const result = evaluate(`(() => {
    const project = mapProject({id: 8, title: "Book", completed_steps: 0});
    applyProjectDetail(project, {
      project: {id: 8, title: "Book", book_text: "Text", status: "in_progress"},
      steps: [{step: "style", state: "completed"}],
      style: {style_text: "Watercolor"},
      characters: [{name: "Mira", prompt: "Prompt", portrait_status: "completed", portrait_url: "/media/1"}],
      chapters: []
    });
    return {style: project.style, url: project.characters[0].portraitUrl, completed: project.completedSteps};
  })()`);

  assert.equal(result.style, "Watercolor");
  assert.equal(result.url, "/media/1");
  assert.equal(result.completed, 1);
});

test("running panel renders spinner, explanation and disabled button", () => {
  const html = evaluate(`(() => {
    state.user = {name: "Tester"};
    const project = mapProject({id: 9, title: "Book", completed_steps: 0}, [
      {step: "style", state: "running"},
      {step: "characters", state: "pending"},
      {step: "portraits", state: "pending"},
      {step: "chapters", state: "pending"},
      {step: "illustrations", state: "pending"}
    ]);
    return renderProjectDetail(project);
  })()`);

  assert.match(html, /pipeline-spinner/);
  assert.match(html, /Reopening this page mid-step/);
  assert.match(html, /Generating\.\.\./);
  assert.match(html, /disabled/);
});

test("navigation uses keyboard-focusable controls", () => {
  const html = evaluate(`(() => {
    state.user = {name: "Tester"};
    return renderNav() + renderNewProject();
  })()`);

  assert.match(html, /href="#\/projects"/);
  assert.match(html, /<button[\s\S]*class="nav-link-button"[\s\S]*Sign out/);
  assert.doesNotMatch(html, /<a[^>]*onclick=/);
});

test("loading messages describe real calls without demo timing", () => {
  const messages = evaluate(`[
    getLoadingMessage("STYLE"),
    getLoadingMessage("CHARACTERS"),
    getLoadingMessage("PORTRAITS"),
    getLoadingMessage("CHAPTERS"),
    getLoadingMessage("ILLUSTRATIONS")
  ].join(" ")`);

  assert.doesNotMatch(messages, /couple of seconds in this demo/i);
  assert.match(messages, /Gemini calls|image generation/i);
});

test("polling signature ignores heartbeat-only changes", () => {
  const result = evaluate(`(() => {
    const project = mapProject({id: 11, title: "Book", completed_steps: 2}, [
      {step: "style", state: "completed"},
      {step: "characters", state: "completed"},
      {step: "portraits", state: "running", updated_at: "first"}
    ]);
    project.characters = [{
      id: 1,
      name: "Mira",
      prompt: "Prompt",
      portraitStatus: "generating",
      portraitUrl: null
    }];

    const before = projectRenderSignature(project);
    project.updatedAt += 1000;
    project.steps[2].updated_at = "heartbeat";
    const afterHeartbeat = projectRenderSignature(project);
    project.characters[0].portraitStatus = "completed";
    project.characters[0].portraitUrl = "/media/portrait";
    const afterPortrait = projectRenderSignature(project);

    return {before, afterHeartbeat, afterPortrait};
  })()`);

  assert.equal(result.before, result.afterHeartbeat);
  assert.notEqual(result.before, result.afterPortrait);
});

test("in-place render suppresses entrance motion", () => {
  evaluate(`(() => {
    state.initializing = false;
    state.user = {name: "Tester"};
    window.location.hash = "#/projects";
    render({suppressMotion: true});
  })()`);
  assert.equal(elements.app.classList.contains("suppress-render-motion"), true);

  evaluate(`render()`);
  assert.equal(elements.app.classList.contains("suppress-render-motion"), false);
});

test("failed step renders the same-step retry action", () => {
  const html = evaluate(`(() => {
    state.user = {name: "Tester"};
    const project = mapProject({id: 10, title: "Book", completed_steps: 0}, [
      {step: "style", state: "failed", error_message: "Mock failure"},
      {step: "characters", state: "pending"},
      {step: "portraits", state: "pending"},
      {step: "chapters", state: "pending"},
      {step: "illustrations", state: "pending"}
    ]);
    return renderProjectDetail(project);
  })()`);

  assert.match(html, /Mock failure/);
  assert.match(html, /Retry Style/);
});

test("retry clears stale UI while the new request is running", () => {
  const result = evaluate(`(() => {
    const step = {
      state: "running",
      is_stale: true,
      error_message: "Old timeout"
    };
    setOptimisticRunningState(step);
    return step;
  })()`);

  assert.equal(result.state, "running");
  assert.equal(result.is_stale, false);
  assert.equal(result.error_message, null);
});

test("critical project helpers have one declaration each", () => {
  for (const functionName of [
    "projectSubtitle",
    "progressMiniHtml",
    "getCompletedSteps",
  ]) {
    const matches = source.match(new RegExp(`function\\s+${functionName}\\s*\\(`, "g"));
    assert.equal(matches?.length, 1, functionName);
  }
});

console.log(`\nSummary: ${passed} passed, 0 failed`);
