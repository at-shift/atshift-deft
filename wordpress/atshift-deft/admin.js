(() => {
  "use strict";
  const { __, sprintf, _n } = window.wp.i18n;
  const config = window.sfWordPress;
  const $ = (id) => document.getElementById(`sf-${id}`);
  const welcome = $("ai-welcome");
  if (welcome) {
    $("ai-welcome-close").onclick = () => {
      if (typeof welcome.close === "function") welcome.close();
      else { welcome.removeAttribute("open"); $("ai-guide").focus(); }
    };
    welcome.addEventListener("close", () => $("ai-guide").focus());
    welcome.addEventListener("keydown", (event) => {
      if (event.key !== "Tab") return;
      const first = $("ai-welcome-close"), last = $("ai-welcome-download");
      if ((!event.shiftKey && document.activeElement === last) ||
          (event.shiftKey && document.activeElement === first)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      }
    });
    if (typeof welcome.showModal === "function") welcome.showModal();
    else welcome.setAttribute("open", "");
  }
  let candidate = null,
    saved = null,
    base = null,
    expected = 0,
    assets = null,
    busy = false,
    composeMode = false,
    behaviors = [],
    publication = null;
  let mediaItems = [],
    mediaPage = 0,
    mediaPages = 0,
    mediaSearch = "";
  const status = (text, error = false) => {
    $("status").textContent = text;
    $("status").classList.toggle("sf-error", error);
  };
  async function request(path, body, query = {}) {
    const url = new URL(config.root);
    if (url.searchParams.has("rest_route"))
      url.searchParams.set(
        "rest_route",
        url.searchParams.get("rest_route") + path,
      );
    else url.pathname += path;
    for (const [key, value] of Object.entries(query))
      url.searchParams.set(key, value);
    const response = await fetch(url, {
      method: body ? "POST" : "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        "X-WP-Nonce": config.nonce,
        ...(body instanceof FormData
          ? {}
          : { "Content-Type": "application/json" }),
      },
      ...(body
        ? { body: body instanceof FormData ? body : JSON.stringify(body) }
        : {}),
    });
    const data = await response.json();
    if (!response.ok)
      throw Object.assign(new Error(data.message || __("Request failed.", "atshift-deft")), {
        code: data.code,
      });
    return data;
  }
  function paint() {
    const currentPublication = publication?.articleId === base?.articleId ? publication : null;
    $("publication").hidden = !currentPublication;
    $("publication-status").textContent = currentPublication ? /* translators: 1: currentPublication.state; 2: currentPublication.publishAt ? ` · ${new Date(currentPublication.publishAt).toLocaleString(config.locale, {tim; 3: currentPublication.error ? ` · ${currentPublication.error}` : "". */ sprintf(__("Publication: %1$s%2$s%3$s", "atshift-deft"), currentPublication.state, currentPublication.publishAt ? ` · ${new Date(currentPublication.publishAt).toLocaleString(config.locale, {timeZone: /^[+-]/.test(currentPublication.timeZone) ? "UTC" : currentPublication.timeZone})} (${/^[+-]/.test(currentPublication.timeZone) ? "UTC" : currentPublication.timeZone})` : "", currentPublication.error ? ` · ${currentPublication.error}` : "") : "";
    $("publication-link").hidden = !currentPublication?.url;
    if (currentPublication?.url) $("publication-link").href = currentPublication.url;
    else $("publication-link").removeAttribute("href");
    if ($("publication-cancel")) {
      $("publication-cancel").hidden = !["scheduled", "overdue", "invalidated", "blocked"].includes(currentPublication?.state);
      $("publication-cancel").disabled = busy;
    }
    $("title").textContent = candidate?.article?.title || __("No article selected", "atshift-deft");
    $("version").textContent = candidate
      ? /* translators: 1: candidate.article.revision; 2: saved ? `WordPress v${saved.version}` : "Not saved". */ sprintf(__("Article r%1$s · %2$s", "atshift-deft"), candidate.article.revision, saved ? /* translators: 1: saved.version. */ sprintf(__("WordPress v%1$s", "atshift-deft"), saved.version) : __("Not saved", "atshift-deft"))
      : "";
    $("behavior-status").textContent = (saved?.behaviorWarnings || []).join(
      " ",
    );
    $("save").disabled = busy || !candidate || !!saved;
    $("review").disabled = busy || !saved;
    $("export").disabled = busy || !candidate;
    $("handoff").disabled = busy || (!!candidate && !saved);
    $("import-button").disabled =
      $("import").disabled =
      $("sample").disabled =
      $("media-sample").disabled =
      $("new").disabled =
      $("refresh").disabled =
        busy;
    $("preview").hidden = $("frame").hidden = !saved;
    $("editor").hidden = !candidate;
    for (const input of $("editor").querySelectorAll(
      "input,select,textarea,button",
    ))
      input.disabled = busy || input.dataset.unavailable === "true";
    $("component-add").disabled =
      busy ||
      !candidate ||
      !$("component-choice").value ||
      candidate.article.blocks.length >= 50;
    $("assets").hidden = !base;
    $("media-fields").hidden = !base || mediaFields().length === 0;
    $("media-find").disabled =
      $("media-search").disabled =
      $("media-more").disabled =
        busy || !base;
    $("media-more").hidden = mediaPage >= mediaPages;
    for (const input of $("media-bindings").querySelectorAll("input,select"))
      input.disabled = busy;
    $("upload-button").disabled =
      $("upload").disabled =
      $("asset-purpose").disabled =
        busy || !base || !assets?.storageReady;
    if (saved) {
      $("preview").href = saved.previewUrl;
      if ($("frame").src !== saved.previewUrl)
        $("frame").src = saved.previewUrl;
    } else {
      $("preview").removeAttribute("href");
      $("frame").removeAttribute("src");
    }
  }
  async function loadAssets() {
    publication = base ? await request(`publications/${encodeURIComponent(base.articleId)}`) : null;
    assets = null;
    $("asset-list").replaceChildren();
    if (!base) return;
    assets = await request(
      `articles/${encodeURIComponent(base.articleId)}/assets`,
    );
    $("assets-status").textContent = assets.storageReady
      ? /* translators: 1: file count, 2: per-file size limit in MiB. */ sprintf(_n("%1$d private file · %2$d MiB max/file", "%1$d private files · %2$d MiB max/file", assets.assets.length, "atshift-deft"), assets.assets.length, Math.floor(assets.maxFileBytes / 1024 / 1024))
      : __("Private storage unavailable", "atshift-deft");
    for (const item of assets.assets) {
      const li = document.createElement("li");
      if (item.mime.startsWith("image/")) {
        const img = document.createElement("img");
        img.src = item.contentUrl;
        img.alt = "";
        img.loading = "lazy";
        li.append(img);
      }
      const info = document.createElement("div");
      const link = document.createElement("a");
      link.href = item.contentUrl;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.textContent = item.filename;
      const detail = document.createElement("p");
      const purpose =
        item.purpose === "reference"
          ? __("Reference only", "atshift-deft")
          : __("Publication candidate", "atshift-deft");
      detail.textContent = /* translators: 1: purpose; 2: Math.ceil(item.bytes / 1024). */ sprintf(__("%1$s · Private · %2$s KiB", "atshift-deft"), purpose, Math.ceil(item.bytes / 1024));
      info.append(link, detail);
      li.append(info);
      $("asset-list").append(li);
    }
    renderMediaFields();
  }
  function mediaFields() {
    if (!candidate?.article?.blocks || !Array.isArray(candidate.components))
      return [];
    return candidate.article.blocks.flatMap((block) => {
      const component = candidate.components.find(
        (c) =>
          c.id === block.componentId && c.revision === block.componentRevision,
      );
      return (component?.fields || [])
        .filter(
          (f) => ["image", "link"].includes(f.type) && block.values?.[f.key],
        )
        .map((field) => ({ block, field, component }));
    });
  }
  const referenceKey = (ref) => `${ref.kind}:${ref.id}:${ref.sha256}`;
  function changed() {
    if (saved)
      candidate.article.revision =
        Math.max(candidate.article.revision, saved.revision) + 1;
    saved = null;
    candidate.schemaVersion = formatVersion(candidate);
    status(__("Article changes not saved.", "atshift-deft"));
    paint();
  }
  function renderMediaFields() {
    $("media-bindings").replaceChildren();
    const sources = [
      ...(assets?.assets || [])
        .filter((a) => a.purpose === "publication")
        .map((a) => ({
          ...a,
          label: /* translators: 1: a.filename. */ sprintf(__("Private: %1$s", "atshift-deft"), a.filename),
          assetRef: { kind: "staged", id: a.assetId, sha256: a.sha256 },
        })),
      ...mediaItems.map((a) => ({ ...a, label: /* translators: 1: a.filename. */ sprintf(__("WordPress: %1$s", "atshift-deft"), a.filename) })),
    ];
    mediaFields().forEach(({ block, field, component }, index) => {
      const value = block.values[field.key];
      const row = document.createElement("div");
      row.className = "sf-media-row";
      const label = document.createElement("label");
      label.htmlFor = `sf-media-source-${index}`;
      label.textContent = `${component.name} / ${field.label} (${block.id})`;
      const select = document.createElement("select");
      select.id = label.htmlFor;
      select.append(new Option(__("URL / no file", "atshift-deft"), "url"));
      const allowed = sources.filter((s) =>
        field.type === "image"
          ? s.mime.startsWith("image/")
          : s.mime === "application/pdf",
      );
      for (const source of allowed)
        select.append(new Option(source.label, referenceKey(source.assetRef)));
      if (
        value.assetRef &&
        !allowed.some(
          (s) => referenceKey(s.assetRef) === referenceKey(value.assetRef),
        )
      ) {
        select.append(
          new Option(
            /* translators: 1: value.assetRef.kind; 2: value.assetRef.id. */ sprintf(__("Saved source: %1$s %2$s", "atshift-deft"), value.assetRef.kind, value.assetRef.id),
            referenceKey(value.assetRef),
          ),
        );
      }
      select.value = value.assetRef ? referenceKey(value.assetRef) : "url";
      const url = document.createElement("input");
      url.type = "text";
      url.value = value.value;
      url.maxLength = 50000;
      url.setAttribute("aria-label", /* translators: 1: field.label. */ sprintf(__("%1$s URL", "atshift-deft"), field.label));
      url.hidden = !!value.assetRef;
      const textLabel = document.createElement("label");
      textLabel.htmlFor = `sf-media-text-${index}`;
      textLabel.textContent =
        field.type === "image" ? __("Alternative text", "atshift-deft") : __("Link text", "atshift-deft");
      const alt = document.createElement("input");
      alt.id = textLabel.htmlFor;
      alt.type = "text";
      alt.value = value.alt;
      alt.maxLength = 50000;
      select.onchange = () => {
        if (select.value === "url") {
          delete value.assetRef;
          value.value = url.value;
        } else {
          const source = allowed.find(
            (s) => referenceKey(s.assetRef) === select.value,
          );
          if (!source) return;
          value.assetRef = { ...source.assetRef };
          value.value = "";
        }
        url.hidden = !!value.assetRef;
        changed();
      };
      url.oninput = () => {
        value.value = url.value;
        changed();
      };
      alt.oninput = () => {
        value.alt = alt.value;
        changed();
      };
      row.append(label, select, url, textLabel, alt);
      $("media-bindings").append(row);
    });
  }
  async function searchMedia(append = false) {
    if (!append) {
      mediaPage = 0;
      mediaItems = [];
      mediaSearch = $("media-search").value;
    }
    const result = await request("media", null, {
      search: mediaSearch,
      page: mediaPage + 1,
    });
    mediaPage = result.page;
    mediaPages = result.totalPages;
    for (const item of result.items)
      if (
        !mediaItems.some(
          (i) => referenceKey(i.assetRef) === referenceKey(item.assetRef),
        )
      )
        mediaItems.push(item);
    renderMediaFields();
    /* translators: %d: number of existing media files. */
    status(sprintf(_n("%d existing WordPress file found.", "%d existing WordPress files found.", mediaItems.length, "atshift-deft"), mediaItems.length));
  }
  async function catalog() {
    behaviors = (await request("behaviors")).items;
    const choice = $("component-choice"),
      previous = choice.value;
    choice.replaceChildren(new Option(__("Select component", "atshift-deft"), ""));
    const used = document.createElement("optgroup"),
      available = document.createElement("optgroup");
    used.label = __("Used by me", "atshift-deft");
    available.label = __("All other available components", "atshift-deft");
    for (let page = 1; page <= 5; page++) {
      const result = await request("catalog", null, { page });
      for (const c of result.items)
        (c.usedByMe ? used : available).append(new Option(c.name, c.id));
      if (page >= result.totalPages) break;
    }
    if (used.children.length) choice.append(used);
    if (available.children.length) choice.append(available);
    choice.value = [...choice.options].some((o) => o.value === previous)
      ? previous
      : "";
  }
  function formatVersion(snapshot) {
    return snapshot.schemaVersion === 3 ||
      snapshot.components.some((c) => Object.hasOwn(c, "behaviors")) ||
      snapshot.article.blocks.some((b) => Object.hasOwn(b, "behaviorSettings"))
      ? 3
      : 2;
  }
  function renderBehaviors(section, component, block, index) {
    if (!component.behaviors?.length) return;
    const group = document.createElement("details"),
      summary = document.createElement("summary");
    summary.textContent = __("Behaviors", "atshift-deft");
    group.className = "sf-behavior-controls";
    group.append(summary);
    for (const ref of component.behaviors) {
      const entry = behaviors.find(
        (b) => b.id === ref.id && b.revision === ref.revision,
      );
      const heading = document.createElement("p");
      heading.textContent = `${entry?.name || ref.id} · ${ref.target}${entry?.enabled ? "" : __(" · Stopped", "atshift-deft")}`;
      group.append(heading);
      if (!entry) continue;
      const values = block.behaviorSettings?.[ref.key] || ref.settings;
      for (const [key, rule] of Object.entries(
        entry.settingsSchema.properties,
      )) {
        const label = document.createElement("label"),
          input = document.createElement(
            rule.type === "boolean" ? "input" : "select",
          );
        label.className = "sf-behavior-setting";
        label.textContent =
          {
            enabled: __("Enabled", "atshift-deft"),
            durationMs: __("Duration (ms)", "atshift-deft"),
            showCaption: __("Show caption", "atshift-deft"),
          }[key] || key;
        input.id = `sf-behavior-${index}-${ref.key}-${key}`;
        label.htmlFor = input.id;
        if (rule.type === "boolean") {
          input.type = "checkbox";
          input.checked = values[key];
        } else {
          for (const value of rule.enum)
            input.append(new Option(String(value), String(value)));
          input.value = String(values[key]);
        }
        input.dataset.unavailable = String(!entry.enabled);
        input.onchange = () => {
          block.behaviorSettings ||= {};
          block.behaviorSettings[ref.key] ||= structuredClone(ref.settings);
          block.behaviorSettings[ref.key][key] =
            rule.type === "boolean" ? input.checked : Number(input.value);
          changed();
        };
        label.append(input);
        group.append(label);
      }
    }
    section.append(group);
  }
  function renderEditor() {
    $("blocks").replaceChildren();
    $("article-title").value = candidate?.article.title || "";
    if (!candidate) return;
    candidate.article.blocks.forEach((block, index) => {
      const component = candidate.components.find(
        (c) =>
          c.id === block.componentId && c.revision === block.componentRevision,
      );
      if (!component) return;
      const section = document.createElement("fieldset");
      section.className = "sf-block-editor";
      const legend = document.createElement("legend");
      legend.textContent = `${index + 1}. ${component.name}`;
      const tools = document.createElement("div");
      tools.className = "sf-toolbar";
      const variants = document.createElement("select");
      variants.setAttribute("aria-label", /* translators: 1: block.id. */ sprintf(__("%1$s variant", "atshift-deft"), block.id));
      for (const v of component.variants)
        variants.append(new Option(v.name, v.id));
      variants.value = block.variantId;
      variants.onchange = () => {
        block.variantId = variants.value;
        changed();
      };
      tools.append(variants);
      for (const [name, icon, delta] of [
        [__("Move up", "atshift-deft"), "arrow-up-alt2", -1],
        [__("Move down", "atshift-deft"), "arrow-down-alt2", 1],
        [__("Remove component", "atshift-deft"), "trash", 0],
      ]) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "button";
        button.title = name;
        button.setAttribute("aria-label", `${name} ${block.id}`);
        button.dataset.unavailable = String(
          delta !== 0 &&
            (index + delta < 0 ||
              index + delta >= candidate.article.blocks.length),
        );
        const symbol = document.createElement("span");
        symbol.className = `dashicons dashicons-${icon}`;
        symbol.setAttribute("aria-hidden", "true");
        button.append(symbol);
        button.onclick = () => {
          const blocks = candidate.article.blocks;
          if (delta) {
            const target = index + delta;
            if (target < 0 || target >= blocks.length) return;
            [blocks[index], blocks[target]] = [blocks[target], blocks[index]];
          } else {
            blocks.splice(index, 1);
            candidate.components = candidate.components.filter((c) =>
              blocks.some(
                (b) =>
                  b.componentId === c.id && b.componentRevision === c.revision,
              ),
            );
          }
          changed();
          renderEditor();
          renderMediaFields();
        };
        tools.append(button);
      }
      section.append(legend, tools);
      for (const field of component.fields.filter((f) =>
        ["text", "textarea"].includes(f.type),
      )) {
        const label = document.createElement("label");
        label.textContent = `${field.label}${field.required ? " *" : ""}`;
        const input = document.createElement(
          field.type === "textarea" ? "textarea" : "input",
        );
        input.id = `sf-value-${index}-${field.key}`;
        label.htmlFor = input.id;
        input.value = block.values[field.key].value;
        input.maxLength = 50000;
        if (field.type === "textarea") input.rows = 4;
        else input.type = "text";
        input.required = field.required;
        input.oninput = () => {
          block.values[field.key].value = input.value;
          changed();
        };
        section.append(label, input);
      }
      renderBehaviors(section, component, block, index);
      $("blocks").append(section);
    });
    paint();
  }
  async function action(fn) {
    if (busy) return;
    busy = true;
    paint();
    try {
      await fn();
    } catch (e) {
      status(`${e.code || "ERROR"}: ${e.message}`, true);
    } finally {
      busy = false;
      paint();
    }
  }
  async function openArticle(id) {
    base = saved = await request(`articles/${encodeURIComponent(id)}`);
    candidate = saved.snapshot;
    composeMode = true;
    expected = saved.version;
    mediaItems = [];
    mediaPage = mediaPages = 0;
    $("media-search").value = "";
    $("asset-purpose").value = "reference";
    await loadAssets();
    renderEditor();
    $("editor").open = false;
    status(__("Saved draft loaded.", "atshift-deft"));
  }
  async function list() {
    const articles = await request("articles");
    $("articles").replaceChildren();
    for (const item of articles) {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = /* translators: 1: item.title; 2: item.version. */ sprintf(__("%1$s · v%2$s", "atshift-deft"), item.title, item.version);
      button.onclick = () =>
        action(async () => {
          if (candidate && !saved && !confirm(__("Discard the unsaved import?", "atshift-deft")))
            return;
          await openArticle(item.articleId);
        });
      $("articles").append(button);
    }
  }
  async function stage(value, fresh = false) {
    if (!value?.article?.id || !Array.isArray(value.components))
      throw new Error(__("Choose a WordPress snapshot JSON file.", "atshift-deft"));
    if (candidate && !saved && !confirm(__("Discard the unsaved import?", "atshift-deft")))
      return false;
    if (fresh) {
      value.article.id = `article-${crypto.randomUUID()}`;
      value.article.revision = 1;
    }
    let current;
    try {
      current = await request(
        `articles/${encodeURIComponent(value.article.id)}`,
      );
    } catch (e) {
      if (e.code !== "sf_missing") throw e;
    }
    expected = current?.version || 0;
    candidate = value;
    composeMode = false;
    saved = null;
    base = current || null;
    assets = null;
    mediaItems = [];
    mediaPage = mediaPages = 0;
    $("media-search").value = "";
    $("asset-purpose").value = "reference";
    status(__("Import ready. Not yet saved.", "atshift-deft"));
    await loadAssets();
    renderEditor();
  }
  $("new").onclick = () =>
    action(async () => {
      if (candidate && !saved && !confirm(__("Discard the unsaved article?", "atshift-deft")))
        return;
      candidate = {
        schemaVersion: 2,
        article: {
          id: `article-${crypto.randomUUID()}`,
          revision: 1,
          title: __("Untitled article", "atshift-deft"),
          blocks: [],
        },
        components: [],
      };
      composeMode = true;
      base = saved = assets = null;
      expected = 0;
      mediaItems = [];
      mediaPage = mediaPages = 0;
      await catalog();
      await loadAssets();
      renderEditor();
      $("editor").open = true;
      $("article-title").focus();
      status(__("New article. Not saved.", "atshift-deft"));
    });
  $("article-title").oninput = () => {
    candidate.article.title = $("article-title").value;
    changed();
  };
  $("component-choice").onchange = paint;
  $("catalog-refresh").onclick = () => action(catalog);
  $("component-add").onclick = () =>
    action(async () => {
      const row = await request(
        `catalog/${encodeURIComponent($("component-choice").value)}`,
      );
      if (!row.availableForNewArticles)
        throw new Error(__("Component is no longer active. Refresh the catalog.", "atshift-deft"));
      const c = row.component;
      if (
        !candidate.components.some(
          (item) => item.id === c.id && item.revision === c.revision,
        )
      )
        candidate.components.push(c);
      candidate.article.blocks.push({
        id: `block-${crypto.randomUUID()}`,
        componentId: c.id,
        componentRevision: c.revision,
        variantId: c.variants[0].id,
        values: Object.fromEntries(
          c.fields.map((f) => [f.key, { value: f.value, alt: f.alt }]),
        ),
      });
      changed();
      renderEditor();
      renderMediaFields();
    });
  $("import-button").onclick = () => $("import").click();
  $("handoff").onclick = () =>
    action(async () => {
      const value = await request(
        "catalog/context",
        null,
        saved ? { articleId: saved.articleId } : {},
      );
      const url = URL.createObjectURL(
        new Blob([JSON.stringify(value, null, 2)], {
          type: "application/json",
        }),
      );
      const link = document.createElement("a");
      link.href = url;
      link.download = "atshift-deft-ai-handoff.json";
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  $("import").onchange = (event) =>
    action(async () => {
      const file = event.target.files[0];
      event.target.value = "";
      if (!file) return;
      if (file.size > 4 * 1024 * 1024)
        throw new Error(__("Maximum file size is 4 MB.", "atshift-deft"));
      const input = JSON.parse(await file.text());
      if (
        Object.hasOwn(input, "expectedVersion") &&
        input.article &&
        !input.components
      ) {
        if (
          !Array.isArray(input.article.blocks) ||
          input.article.blocks.length > 50
        )
          throw new Error(__("Use at most 50 article blocks.", "atshift-deft"));
        if (
          Object.keys(input).some(
            (key) => !["article", "expectedVersion"].includes(key),
          )
        )
          throw new Error(__("Unknown composition property.", "atshift-deft"));
        let current = null;
        try {
          current = await request(
            `articles/${encodeURIComponent(input.article.id)}`,
          );
        } catch (error) {
          if (error.code !== "sf_missing") throw error;
        }
        if (input.expectedVersion !== (current?.version || 0))
          throw new Error(__("Article changed. Export a fresh AI handoff.", "atshift-deft"));
        const components = [];
        for (const block of input.article.blocks) {
          if (
            components.some(
              (c) =>
                c.id === block.componentId &&
                c.revision === block.componentRevision,
            )
          )
            continue;
          const pinned = current?.snapshot.components.find(
            (c) =>
              c.id === block.componentId &&
              c.revision === block.componentRevision,
          );
          const c =
            pinned ||
            (
              await request(
                `catalog/${encodeURIComponent(block.componentId)}`,
                null,
                { revision: block.componentRevision },
              )
            ).component;
          components.push(c);
        }
        const proposal = {
          schemaVersion: current?.snapshot.schemaVersion || 2,
          article: input.article,
          components,
        };
        proposal.schemaVersion = formatVersion(proposal);
        const staged = await stage(proposal);
        if (staged !== false) {
          composeMode = true;
          expected = input.expectedVersion;
        }
      } else await stage(input);
    });
  $("sample").onclick = () =>
    action(async () => {
      const response = await fetch(config.sample);
      if (!response.ok) throw new Error(__("Sample could not be loaded.", "atshift-deft"));
      await stage(await response.json(), true);
    });
  $("media-sample").onclick = () =>
    action(async () => {
      const response = await fetch(config.mediaSample);
      if (!response.ok) throw new Error(__("Media sample could not be loaded.", "atshift-deft"));
      await stage(await response.json(), true);
    });
  $("media-find").onclick = () => action(() => searchMedia());
  if ($("publication-cancel")) $("publication-cancel").onclick = () => action(async () => {
    if (!publication || !confirm(__("Cancel this publication reservation?", "atshift-deft"))) return;
    publication = await request("publications/cancel", {articleId: publication.articleId, expectedPublicationVersion: publication.version});
    status(__("Publication reservation cancelled.", "atshift-deft"));
  });
  $("review").onclick = () =>
    action(async () => {
      const result = await request(
        `articles/${encodeURIComponent(saved.articleId)}/reviews`,
        { expectedVersion: saved.version },
      );
      window.location.assign(result.reviewUrl);
    });
  $("media-more").onclick = () => action(() => searchMedia(true));
  $("media-search").onkeydown = (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      action(() => searchMedia());
    }
  };
  $("save").onclick = () =>
    action(async () => {
      base = saved = await request(
        composeMode ? "articles/compose" : "articles",
        {
          ...(composeMode
            ? { article: candidate.article }
            : { snapshot: candidate }),
          expectedVersion: expected,
        },
      );
      candidate = saved.snapshot;
      expected = saved.version;
      status(__("Draft saved. Not published.", "atshift-deft"));
      await list();
      await catalog();
      await loadAssets();
      renderEditor();
    });
  $("refresh").onclick = () =>
    action(async () => {
      await list();
      await loadAssets();
    });
  $("upload-button").onclick = () => $("upload").click();
  $("upload").onchange = (event) => {
    const files = [...event.target.files];
    event.target.value = "";
    action(async () => {
      if (!base || !files.length) return;
      const articleId = base.articleId;
      const purpose = $("asset-purpose").value;
      let received = 0;
      try {
        for (const file of files) {
          if (file.size > assets.maxFileBytes)
            throw new Error(/* translators: 1: file.name. */ sprintf(__("%1$s: file exceeds the upload limit.", "atshift-deft"), file.name));
          const body = new FormData();
          body.set("purpose", purpose);
          body.set("file", file);
          status(/* translators: 1: received + 1; 2: files.length; 3: file.name. */ sprintf(__("Receiving %1$s/%2$s: %3$s", "atshift-deft"), received + 1, files.length, file.name));
          await request(
            `articles/${encodeURIComponent(articleId)}/assets`,
            body,
          );
          received++;
        }
        /* translators: %d: number of received private files. */
        status(sprintf(_n("%d file received privately. Not published.", "%d files received privately. Not published.", received, "atshift-deft"), received));
      } catch (e) {
        throw new Error(/* translators: 1: received; 2: files.length; 3: e.message. */ sprintf(__("%1$s/%2$s received. %3$s", "atshift-deft"), received, files.length, e.message));
      } finally {
        await loadAssets();
      }
    });
  };
  $("export").onclick = () => {
    if (!candidate) return;
    const url = URL.createObjectURL(
      new Blob([JSON.stringify(candidate, null, 2)], {
        type: "application/json",
      }),
    );
    const a = document.createElement("a");
    a.href = url;
    a.download = "wordpress-snapshot.json";
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  };
  window.addEventListener("beforeunload", (event) => {
    if (candidate && !saved) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
  action(async () => {
    await list();
    await catalog();
    const id = new URLSearchParams(location.search).get("sf_article");
    if (id && /^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/.test(id)) await openArticle(id);
  });
})();
