import DOMPurify from "dompurify";

export const STORAGE_KEY = "structured-fields.settings.prototype.v1";
export const FORMAT = "structured-fields-prototype";
export const TYPES = {
  text: "テキスト",
  textarea: "文章",
  image: "画像",
  link: "リンク",
};
export const uid = () => crypto.randomUUID();
const machineName = /^[a-z][a-z0-9_]*$/;
const tags = new Set(
  "section article aside header footer main div span h1 h2 h3 h4 h5 h6 p br strong em b i u s ul ol li a figure img figcaption blockquote table thead tbody tfoot tr td th caption dl dt dd hr pre code time address".split(
    " ",
  ),
);
const attributes = new Set(
  "class id data-field href title src alt width height colspan rowspan scope datetime".split(
    " ",
  ),
);

export const defaultSettings = {
  postTypes: ["post"],
  editor: "structured",
  htmlOutput: true,
  jsonOutput: false,
  aiRead: true,
  aiDraft: false,
  humanPublish: true,
  roles: {
    administrator: { read: true, write: true },
    editor: { read: true, write: true },
    author: { read: true, write: false },
  },
};

const featureHTML = `<section class="feature">
  <div class="feature__inner">
    <div class="feature__body">
      <h2 data-field="heading">日常に、少しの余白を。</h2>
      <p data-field="body">陽の光が差し込む、静かなシェアアトリエ。
仕事に、趣味に、気持ちのよい時間を過ごせる場所です。</p>
    </div>
    <figure class="feature__image">
      <img data-field="image" src="/assets/atelier.png" alt="自然光が差し込むアトリエの内観">
    </figure>
  </div>
</section>`;

export const exampleCSS = `.feature { padding: 24px 0; }
.feature__inner {
  display: grid;
  grid-template-columns: 1fr 1fr;
  align-items: center;
  gap: 24px;
}
.feature h2 { font-size: 20px; line-height: 1.55; }
.feature p { font-size: 15px; line-height: 1.9; color: #4b555d; }
.feature__image { margin: 0; }
.feature__image img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; }
.feature--image-left .feature__image { grid-column: 1; grid-row: 1; }
.feature--image-left .feature__body { grid-column: 2; }
.feature--stacked .feature__inner { grid-template-columns: 1fr; }
@media (max-width: 430px) {
  .feature__inner { grid-template-columns: 1fr; }
  .feature--image-left .feature__body { grid-column: auto; }
  .feature--image-left .feature__image { grid-column: auto; grid-row: auto; }
}`;

function fieldFromElement(element, old = {}) {
  const key = element.getAttribute("data-field");
  const tag = element.tagName.toLowerCase();
  const type =
    tag === "img"
      ? "image"
      : tag === "a"
        ? "link"
        : tag === "p"
          ? "textarea"
          : "text";
  return {
    id: old.id || uid(),
    key,
    label:
      old.label ||
      { heading: "見出し", body: "本文", image: "画像", link: "リンク" }[key] ||
      key,
    type:
      ["text", "textarea"].includes(type) &&
      ["text", "textarea"].includes(old.type)
        ? old.type
        : type,
    required: old.required ?? type !== "image",
    value:
      type === "image"
        ? element.getAttribute("src") || ""
        : type === "link"
          ? element.getAttribute("href") || ""
          : element.textContent,
    alt:
      type === "image"
        ? element.getAttribute("alt") || ""
        : type === "link"
          ? element.textContent
          : "",
  };
}

export function parseHTML(html) {
  return new DOMParser().parseFromString(html, "text/html");
}

export function extractFields(html, previous = []) {
  const doc = parseHTML(html);
  const existing = new Map(previous.map((field) => [field.key, field]));
  const used = new Set();
  return [...doc.body.querySelectorAll("[data-field]")].map((element) => {
    const key = element.getAttribute("data-field");
    const old = used.has(key) ? undefined : existing.get(key);
    used.add(key);
    return fieldFromElement(element, old);
  });
}

export function updateHTML(profile, html) {
  const extracted = new Map(
    extractFields(html, profile.fields).map((field) => [field.key, field]),
  );
  return {
    ...profile,
    html,
    fields: profile.fields.map((field) =>
      extracted.has(field.key) ? extracted.get(field.key) : field,
    ),
  };
}

export function createProfile(kind = "empty") {
  const feature = kind === "feature";
  const notice = kind === "notice";
  const html = feature
    ? featureHTML
    : notice
      ? `<aside class="notice">
  <h2 data-field="heading">利用前にご確認ください</h2>
  <p data-field="body">ご利用には事前の予約が必要です。</p>
</aside>`
      : `<section class="content-section">
  <h2 data-field="heading">セクションの見出し</h2>
  <p data-field="body">ここに本文が入ります。</p>
</section>`;
  return {
    id: uid(),
    name: feature ? "画像付き説明" : notice ? "お知らせ" : "本文セクション",
    key: feature ? "image_feature" : notice ? "notice" : "content_section",
    purpose: feature
      ? "写真と説明文を並べて紹介する"
      : notice
        ? "注意事項や大切な情報を伝える"
        : "見出しと本文で話題をまとめる",
    html,
    fields: extractFields(html),
    css: feature
      ? exampleCSS
      : notice
        ? ".notice { padding: 24px; border-left: 4px solid #087f79; background: #f0f7f5; }\n.notice--warning { border-color: #b67b20; background: #fff7e9; }\n.notice h2 { font-size: 22px; }"
        : ".content-section { max-width: 720px; margin: 0 auto; padding: 24px 0; }\n.content-section h2 { font-size: 26px; }\n.content-section p { line-height: 1.9; }",
    variants: feature
      ? [
          { id: uid(), name: "画像を右", classes: "" },
          { id: uid(), name: "画像を左", classes: "feature--image-left" },
          { id: uid(), name: "縦に並べる", classes: "feature--stacked" },
        ]
      : notice
        ? [
            { id: uid(), name: "情報", classes: "" },
            { id: uid(), name: "注意", classes: "notice--warning" },
          ]
        : [{ id: uid(), name: "標準", classes: "" }],
    activeVersion: 0,
    versions: [],
    updatedAt: new Date().toISOString(),
  };
}

export function initialState() {
  const profiles = [
    createProfile("feature"),
    createProfile("notice"),
    createProfile("empty"),
  ];
  return {
    format: FORMAT,
    schemaVersion: 1,
    profiles,
    settings: structuredClone(defaultSettings),
  };
}

export function validateProfile(profile) {
  const issues = [];
  const error = (message) => issues.push({ level: "error", message });
  const warning = (message) => issues.push({ level: "warning", message });
  if (!profile.name.trim()) error("構造の名前を入力してください。");
  if (!machineName.test(profile.key))
    error(
      "識別名は英小文字で始め、英小文字・数字・アンダースコアを使ってください。",
    );
  if (!profile.html.trim()) error("HTMLの見本が空です。");
  if (profile.html.length > 50000)
    error("HTMLは50,000文字以内にしてください。");
  const doc = parseHTML(profile.html);
  if (doc.body.children.length !== 1)
    error("HTML全体を一つの要素で囲んでください。");
  if (doc.head.children.length)
    error("head要素や外部スタイルシートはHTMLの見本に含められません。");
  const ids = new Set();
  const bindings = new Map();
  for (const el of doc.body.querySelectorAll("*")) {
    const tag = el.tagName.toLowerCase();
    if (!tags.has(tag)) error(`${tag} 要素は使用できません。`);
    for (const attr of el.attributes) {
      if (!attributes.has(attr.name))
        error(`${tag} の ${attr.name} 属性は使用できません。`);
      if (
        ["href", "src"].includes(attr.name) &&
        attr.value &&
        !safeURL(attr.value, attr.name === "href")
      )
        error(`${tag} のURLを確認してください。`);
    }
    if (el.id && ids.has(el.id))
      error(`HTMLのid「${el.id}」が重複しています。`);
    ids.add(el.id);
    if (el.hasAttribute("data-field")) {
      const key = el.getAttribute("data-field");
      if (!machineName.test(key))
        error(`項目名「${key}」の形式を確認してください。`);
      if (bindings.has(key)) error(`HTMLの項目名「${key}」が重複しています。`);
      bindings.set(key, tag);
      if (el.children.length)
        error(`編集項目「${key}」は子要素を持たない要素に指定してください。`);
    }
  }
  const keys = new Set();
  const sourceValues = new Map(
    extractFields(profile.html).map((field) => [field.key, field]),
  );
  for (const field of profile.fields) {
    if (keys.has(field.key))
      error(`編集項目「${field.key}」が重複しています。`);
    keys.add(field.key);
    if (!bindings.has(field.key))
      error(`編集項目「${field.key}」に対応する data-field がありません。`);
    if (!field.label.trim())
      error(`「${field.key}」のラベルを入力してください。`);
    const source = sourceValues.get(field.key);
    if (source && (source.value !== field.value || source.alt !== field.alt))
      error(
        `「${field.label}」のHTMLと見本の値が一致していません。HTMLから読み取り直してください。`,
      );
    if (field.required && !field.value.trim())
      error(`必須項目「${field.label}」の見本の値が空です。`);
    if (field.type === "image" && bindings.get(field.key) !== "img")
      error(`画像項目「${field.label}」は img 要素に指定してください。`);
    if (field.type === "link" && bindings.get(field.key) !== "a")
      error(`リンク項目「${field.label}」は a 要素に指定してください。`);
    if (field.type === "image" && field.value && !field.alt.trim())
      error(`画像「${field.label}」の代替テキストを入力してください。`);
    if (
      ["image", "link"].includes(field.type) &&
      field.value &&
      !safeURL(field.value, field.type === "link")
    )
      error(`「${field.label}」のURLを確認してください。`);
    if (
      field.type === "image" &&
      /^https?:/.test(field.value) &&
      !field.value.startsWith(location.origin + "/")
    )
      warning(`外部画像「${field.label}」はこのプレビューでは読み込みません。`);
  }
  for (const key of bindings.keys())
    if (!keys.has(key))
      error(`「${key}」をHTMLから読み取り、編集項目に追加してください。`);
  if (!profile.fields.length)
    warning("編集項目がありません。固定の構造として保存されます。");
  if (!profile.variants.length)
    error("見た目の種類を一つ以上登録してください。");
  for (const variant of profile.variants) {
    if (!variant.name.trim()) error("見た目の種類に名前を付けてください。");
    if (
      variant.classes
        .split(/\s+/)
        .filter(Boolean)
        .some((token) => /["'<>`=]/.test(token))
    )
      error(`「${variant.name}」のクラス名を確認してください。`);
  }
  if (profile.css.length > 50000) error("CSSは50,000文字以内にしてください。");
  if (/@import\b/i.test(profile.css))
    error("CSSの外部読み込みはこの試作では使用できません。");
  return issues.filter(
    (issue, index, list) =>
      list.findIndex((other) => other.message === issue.message) === index,
  );
}

export function safeURL(value, link = false) {
  try {
    const url = new URL(value, location.origin);
    return ["http:", "https:", ...(link ? ["mailto:", "tel:"] : [])].includes(
      url.protocol,
    );
  } catch {
    return false;
  }
}

export function cleanedHTML(html) {
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: [...tags],
    ALLOWED_ATTR: [...attributes],
    ALLOW_DATA_ATTR: false,
  });
}

export function structureTree(html) {
  const doc = parseHTML(cleanedHTML(html));
  let total = 0;
  function node(el, depth = 0) {
    if (++total > 200 || depth > 18) return null;
    return {
      tag: el.tagName.toLowerCase(),
      classes: el.className || "",
      field: el.getAttribute("data-field") || "",
      children: [...el.children]
        .map((child) => node(child, depth + 1))
        .filter(Boolean),
    };
  }
  return [...doc.body.children].map((el) => node(el)).filter(Boolean);
}

export function previewDocument(profile, variantId, withCSS) {
  const doc = parseHTML(cleanedHTML(profile.html));
  for (const element of doc.body.querySelectorAll("img")) {
    const source = element.getAttribute("src");
    if (source && safeURL(source))
      element.setAttribute("src", new URL(source, location.origin).href);
    else element.removeAttribute("src");
  }
  const variant =
    profile.variants.find((item) => item.id === variantId) ||
    profile.variants[0];
  const root = doc.body.firstElementChild;
  for (const token of (variant?.classes || "").split(/\s+/).filter(Boolean)) {
    if (!/["'<>`=]/.test(token)) root?.classList.add(token);
  }
  for (const link of doc.body.querySelectorAll("a"))
    link.removeAttribute("href");
  const baseline = `*{box-sizing:border-box}html{background:white;color:#1f2930;font-family:-apple-system,BlinkMacSystemFont,"Noto Sans JP",sans-serif;font-size:15px}body{margin:20px;overflow-wrap:anywhere}h1,h2,h3{margin:0 0 16px;line-height:1.55}p{line-height:1.85;white-space:pre-line;margin:0 0 16px}img{max-width:100%;height:auto;display:block}figure{margin:20px 0}table{display:block;max-width:100%;overflow:auto}a{color:#087f79;text-decoration:underline;pointer-events:none}pre{white-space:pre-wrap}`;
  // A CSS escape keeps user text inside the style element even when it contains an HTML end tag.
  const css = withCSS ? profile.css.replace(/</g, "\\3c ") : "";
  const policy = `default-src 'none'; img-src ${location.origin} data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'`;
  return `<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="${policy}"><style>${baseline}\n${css}</style></head><body>${doc.body.innerHTML}</body></html>`;
}

export function addBinding(profile, field) {
  const doc = parseHTML(profile.html);
  const root = doc.body.firstElementChild;
  if (!root) return profile;
  const el = doc.createElement(
    field.type === "image"
      ? "img"
      : field.type === "link"
        ? "a"
        : field.type === "textarea"
          ? "p"
          : "h3",
  );
  el.setAttribute("data-field", field.key);
  if (field.type === "image") {
    el.setAttribute("src", field.value);
    el.setAttribute("alt", field.alt);
  } else if (field.type === "link") {
    el.setAttribute("href", field.value);
    el.textContent = field.alt;
  } else el.textContent = field.value;
  root.append(el);
  return {
    ...profile,
    html: doc.body.innerHTML,
    fields: [...profile.fields, field],
  };
}

export function updateBinding(profile, field) {
  const old = profile.fields.find((item) => item.id === field.id);
  const doc = parseHTML(profile.html);
  for (const element of doc.body.querySelectorAll("[data-field]")) {
    if (element.getAttribute("data-field") !== old.key) continue;
    element.setAttribute("data-field", field.key);
    if (field.type === "image") {
      element.setAttribute("src", field.value);
      element.setAttribute("alt", field.alt);
    } else if (field.type === "link") {
      element.setAttribute("href", field.value);
      element.textContent = field.alt;
    } else element.textContent = field.value;
  }
  return {
    ...profile,
    html: doc.body.innerHTML,
    fields: profile.fields.map((item) => (item.id === field.id ? field : item)),
  };
}

export function removeBinding(profile, id) {
  const field = profile.fields.find((item) => item.id === id);
  const doc = parseHTML(profile.html);
  for (const element of doc.body.querySelectorAll("[data-field]")) {
    if (element.getAttribute("data-field") === field.key)
      element.removeAttribute("data-field");
  }
  return {
    ...profile,
    html: doc.body.innerHTML,
    fields: profile.fields.filter((item) => item.id !== id),
  };
}

export function activateProfile(profile) {
  const version = profile.activeVersion + 1;
  const { versions, ...snapshot } = structuredClone(profile);
  const updatedAt = new Date().toISOString();
  return {
    ...profile,
    activeVersion: version,
    updatedAt,
    versions: [...versions, { ...snapshot, updatedAt, activeVersion: version }],
  };
}

function cleanImportedProfile(value) {
  if (!value || typeof value !== "object" || Array.isArray(value))
    throw new Error("構造データの形式が正しくありません。");
  for (const key of ["name", "key", "purpose", "html", "css"])
    if (typeof value[key] !== "string" || value[key].length > 50000)
      throw new Error(`${key} の形式を確認してください。`);
  if (
    !Array.isArray(value.fields) ||
    value.fields.length > 100 ||
    !Array.isArray(value.variants) ||
    value.variants.length > 30
  )
    throw new Error("編集項目または見た目の種類が多すぎます。");
  const fields = value.fields.map((field) => {
    if (
      !field ||
      !Object.hasOwn(TYPES, field.type) ||
      !["key", "label", "value", "alt"].every(
        (key) => typeof field[key] === "string" && field[key].length <= 50000,
      )
    )
      throw new Error("編集項目の形式が正しくありません。");
    return {
      id: uid(),
      key: field.key,
      label: field.label,
      value: field.value,
      alt: field.alt,
      type: field.type,
      required: field.required === true,
    };
  });
  const variants = value.variants.map((variant) => {
    if (
      !variant ||
      typeof variant.name !== "string" ||
      typeof variant.classes !== "string" ||
      variant.name.length > 200 ||
      variant.classes.length > 2000
    )
      throw new Error("見た目の種類の形式が正しくありません。");
    return { id: uid(), name: variant.name, classes: variant.classes };
  });
  const profile = {
    id: uid(),
    name: value.name,
    key: value.key,
    purpose: value.purpose,
    html: value.html,
    css: value.css,
    fields,
    variants,
    activeVersion: 0,
    versions: [],
    updatedAt: new Date().toISOString(),
  };
  const errors = validateProfile(profile).filter(
    (issue) => issue.level === "error",
  );
  if (errors.length) throw new Error(errors[0].message);
  return profile;
}

export function importProfiles(text) {
  if (text.length > 2000000)
    throw new Error("ファイルは2MB以内にしてください。");
  const data = JSON.parse(text);
  if (
    data?.format !== FORMAT ||
    data.schemaVersion !== 1 ||
    !Array.isArray(data.profiles) ||
    !data.profiles.length ||
    data.profiles.length > 50
  )
    throw new Error("対応する構造ライブラリのJSONではありません。");
  return data.profiles.map(cleanImportedProfile);
}

export function exportData(profiles, settings) {
  return JSON.stringify(
    {
      format: FORMAT,
      schemaVersion: 1,
      exportedAt: new Date().toISOString(),
      profiles,
      settings,
    },
    null,
    2,
  );
}

export function loadState() {
  try {
    const data = JSON.parse(localStorage.getItem(STORAGE_KEY));
    if (
      data?.format === FORMAT &&
      data.schemaVersion === 1 &&
      Array.isArray(data.profiles) &&
      data.profiles.length <= 50
    ) {
      for (const p of data.profiles) {
        if (
          !p ||
          !["id", "name", "key", "purpose", "html", "css", "updatedAt"].every(
            (key) => typeof p[key] === "string" && p[key].length <= 50000,
          ) ||
          !Array.isArray(p.fields) ||
          !Array.isArray(p.variants) ||
          !Array.isArray(p.versions) ||
          !Number.isInteger(p.activeVersion) ||
          p.activeVersion < 0 ||
          !p.fields.every(
            (field) =>
              field &&
              Object.hasOwn(TYPES, field.type) &&
              ["id", "key", "label", "value", "alt"].every(
                (key) => typeof field[key] === "string",
              ),
          ) ||
          !p.variants.every(
            (variant) =>
              variant &&
              ["id", "name", "classes"].every(
                (key) => typeof variant[key] === "string",
              ),
          ) ||
          !p.versions.every(
            (version) =>
              version &&
              typeof version.name === "string" &&
              typeof version.updatedAt === "string" &&
              Number.isInteger(version.activeVersion) &&
              ["html", "css", "key", "purpose"].every(
                (key) => typeof version[key] === "string",
              ) &&
              Array.isArray(version.fields) &&
              Array.isArray(version.variants),
          )
        )
          throw new Error("Invalid local data");
      }
      const settings = structuredClone(defaultSettings);
      const stored = data.settings || {};
      for (const key of ["htmlOutput", "jsonOutput", "aiRead", "aiDraft"]) {
        if (typeof stored[key] === "boolean") settings[key] = stored[key];
      }
      if (Array.isArray(stored.postTypes))
        settings.postTypes = stored.postTypes.filter((type) =>
          ["post", "page"].includes(type),
        );
      if (["structured", "existing"].includes(stored.editor))
        settings.editor = stored.editor;
      for (const role of Object.keys(settings.roles))
        for (const action of ["read", "write"]) {
          if (typeof stored.roles?.[role]?.[action] === "boolean")
            settings.roles[role][action] = stored.roles[role][action];
        }
      return {
        format: FORMAT,
        schemaVersion: 1,
        profiles: data.profiles,
        settings,
      };
    }
  } catch {
    /* A malformed or unavailable local store must not prevent opening the prototype. */
  }
  return initialState();
}

export function downloadJSON(filename, data) {
  const url = URL.createObjectURL(
    new Blob([data], { type: "application/json" }),
  );
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
