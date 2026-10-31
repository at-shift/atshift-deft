import {
  createProfile,
  uid,
  extractFields,
  updateHTML,
  validateProfile,
  cleanedHTML,
  previewDocument,
  addBinding,
  updateBinding,
  removeBinding,
  activateProfile,
  importProfiles,
  exportData,
  defaultSettings,
  initialState,
  loadState,
  STORAGE_KEY,
} from "../src/model.js";

let failures = 0;
const checks = [];
function check(name, operation) {
  try {
    if (!operation()) throw new Error("Assertion failed");
    checks.push({ name, pass: true });
  } catch (error) {
    failures++;
    checks.push({ name, pass: false, error: error.message });
  }
}
const valid = (profile) =>
  !validateProfile(profile).some((issue) => issue.level === "error");
const rejects = (operation) => {
  try {
    operation();
    return false;
  } catch {
    return true;
  }
};

check("Seed profiles validate", () =>
  ["feature", "notice", "empty"].every((kind) => valid(createProfile(kind))),
);
check("Extraction preserves stable field IDs", () => {
  const p = createProfile("feature");
  return extractFields(p.html, p.fields).every(
    (field, index) => field.id === p.fields[index].id,
  );
});
check("Field edits update the HTML source", () => {
  const p = createProfile("feature");
  const next = updateBinding(p, { ...p.fields[0], value: "Updated heading" });
  return next.html.includes("Updated heading") && valid(next);
});
check("HTML edits update existing field values", () => {
  const p = createProfile("feature");
  const next = updateHTML(p, p.html.replace(p.fields[0].value, "From HTML"));
  return next.fields[0].value === "From HTML" && valid(next);
});
check("Whitespace round-trip remains exact", () => {
  const p = createProfile();
  return valid(updateBinding(p, { ...p.fields[0], value: "  Exact text  " }));
});
check("Binding rename keeps HTML and schema in agreement", () => {
  const p = createProfile();
  const next = updateBinding(p, { ...p.fields[0], key: "new_heading" });
  return next.html.includes('data-field="new_heading"') && valid(next);
});
check("Unbinding retains static content", () => {
  const p = createProfile();
  const next = removeBinding(p, p.fields[0].id);
  return (
    next.html.includes(p.fields[0].value) &&
    !next.html.includes('data-field="heading"') &&
    valid(next)
  );
});
check("New binding appends to the existing root", () => {
  const p = createProfile();
  return valid(
    addBinding(p, {
      id: uid(),
      key: "extra",
      label: "Extra",
      type: "text",
      required: false,
      value: "Extra text",
      alt: "",
    }),
  );
});
check("Duplicate bindings are rejected", () => {
  const p = createProfile();
  p.html = p.html.replace(
    "</section>",
    '<p data-field="heading">duplicate</p></section>',
  );
  const extracted = extractFields(p.html, p.fields);
  return (
    !valid(p) &&
    new Set(extracted.map((field) => field.id)).size === extracted.length
  );
});
check("Nested editable containers are rejected", () => {
  const p = createProfile();
  p.html = p.html.replace(
    'class="content-section"',
    'class="content-section" data-field="container"',
  );
  p.fields = extractFields(p.html);
  return !valid(p);
});
check("Script elements cannot be activated", () => {
  const p = createProfile();
  p.html += "<script>window.bad=true</script>";
  return !valid(p) && !cleanedHTML(p.html).includes("<script");
});
check("Event attributes cannot be activated", () => {
  const p = createProfile();
  p.html = p.html.replace("<section ", '<section onclick="alert(1)" ');
  return !valid(p) && !cleanedHTML(p.html).includes("onclick");
});
check("JavaScript URLs are rejected", () => {
  const p = createProfile();
  p.html = p.html.replace(
    "</section>",
    '<a href="javascript:alert(1)">bad</a></section>',
  );
  return !valid(p);
});
check("Image alternate text is required", () => {
  const p = createProfile("feature");
  return !valid(updateBinding(p, { ...p.fields[2], alt: "" }));
});
check("CSS imports are rejected", () => {
  const p = createProfile();
  p.css = "@import url(https://example.test/style.css);";
  return !valid(p);
});
check("Root variant accepts multiple classes", () => {
  const p = createProfile();
  p.variants[0].classes = "custom-a custom-b";
  return previewDocument(p, p.variants[0].id, true).includes(
    "content-section custom-a custom-b",
  );
});
check("No-design preview retains baseline media protection", () => {
  const p = createProfile();
  const html = previewDocument(p, p.variants[0].id, false);
  return (
    html.includes("max-width:100%") && !html.includes(".content-section {")
  );
});
check("CSS cannot close the sandbox style element", () => {
  const p = createProfile();
  p.css = "</style><script>alert(1)</script>";
  return !previewDocument(p, p.variants[0].id, true).includes("<script>");
});
check("Source and value mismatch is rejected", () => {
  const p = createProfile();
  p.fields[0].value = "Different";
  return !valid(p);
});
check("Activated versions are immutable snapshots", () => {
  let p = activateProfile(createProfile());
  const first = p.fields[0].value;
  p = activateProfile(
    updateBinding(p, { ...p.fields[0], value: "Second version" }),
  );
  return (
    p.activeVersion === 2 &&
    p.versions[0].fields[0].value === first &&
    p.versions[1].fields[0].value === "Second version"
  );
});
check("Export/import keeps content and resets activation", () => {
  const p = activateProfile(createProfile("feature"));
  const imported = importProfiles(exportData([p], defaultSettings))[0];
  return (
    imported.html === p.html &&
    imported.activeVersion === 0 &&
    imported.versions.length === 0 &&
    valid(imported)
  );
});
check("Malformed imported types are rejected", () => {
  const p = createProfile();
  p.fields[0].type = "__proto__";
  return rejects(() => importProfiles(exportData([p], defaultSettings)));
});
check("Unsupported import formats are rejected", () =>
  rejects(() => importProfiles('{"format":"other","profiles":[]}')),
);
check("Oversized import is rejected", () =>
  rejects(() => importProfiles(" ".repeat(2000001))),
);

function withStoredValue(value, operation) {
  const previous = localStorage.getItem(STORAGE_KEY);
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    return operation();
  } finally {
    if (previous === null) localStorage.removeItem(STORAGE_KEY);
    else localStorage.setItem(STORAGE_KEY, previous);
  }
}
check("Malformed local fields recover to sample state", () => {
  const state = initialState();
  state.profiles[0].fields[0].value = null;
  return withStoredValue(
    state,
    () => loadState().profiles[0].fields[0].value !== null,
  );
});
check("Stored permission values are normalized", () => {
  const state = initialState();
  state.settings.roles.editor = { read: "invalid", write: false };
  return withStoredValue(state, () => {
    const loaded = loadState();
    return (
      loaded.settings.roles.editor.read === true &&
      loaded.settings.roles.editor.write === false
    );
  });
});

const kitResponse = await fetch("/ai-kit/example.json");
const kitText = await kitResponse.text();
check("AI kit example imports as a new valid draft", () => {
  const [profile] = importProfiles(kitText);
  return (
    kitResponse.ok &&
    valid(profile) &&
    profile.activeVersion === 0 &&
    profile.versions.length === 0 &&
    profile.fields.length === 4
  );
});
check("AI kit image and link retain their HTML values and labels", () => {
  const [profile] = importProfiles(kitText);
  const source = extractFields(profile.html);
  return ["image", "link"].every((type) => {
    const field = profile.fields.find((f) => f.type === type);
    const htmlField = source.find((f) => f.key === field.key);
    return field.value === htmlField.value && field.alt === htmlField.alt;
  });
});
check(
  "Repeated AI kit imports receive fresh IDs without source changes",
  () => {
    const [a] = importProfiles(kitText);
    const [b] = importProfiles(kitText);
    return (
      a.id !== b.id &&
      a.html === b.html &&
      a.fields.every((field, index) => field.id !== b.fields[index].id)
    );
  },
);
check("AI kit preview adds the selected root variant", () => {
  const [profile] = importProfiles(kitText);
  return previewDocument(profile, profile.variants[1].id, true).includes(
    'class="sf-atelier sf-atelier--image-left"',
  );
});
check("AI kit HTML and field mismatch is rejected on import", () => {
  const payload = JSON.parse(kitText);
  payload.profiles[0].fields[0].value = "Unmatched AI output";
  return rejects(() => importProfiles(JSON.stringify(payload)));
});
check("AI kit unsupported nested fields are rejected on import", () => {
  const payload = JSON.parse(kitText);
  payload.profiles[0].html = payload.profiles[0].html.replace(
    '<h2 data-field="heading">',
    '<h2 data-field="heading"><strong>Nested</strong>',
  );
  return rejects(() => importProfiles(JSON.stringify(payload)));
});

const list = document.getElementById("checks");
for (const check of checks) {
  const item = document.createElement("li");
  item.textContent = `${check.pass ? "PASS" : "FAIL"}: ${check.name}${check.error ? ` (${check.error})` : ""}`;
  item.style.color = check.pass ? "#24694b" : "#b02020";
  list.append(item);
}
document.getElementById("result").textContent =
  `${checks.length - failures}/${checks.length} passed; ${failures} failed`;
document.title = `${failures ? "FAIL" : "PASS"}: atshift DEFT model tests`;
