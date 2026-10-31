(() => {
  "use strict";
  const { __, sprintf } = window.wp.i18n;
  const config = window.sfWordPress;
  const $ = (id) => document.getElementById("sf-" + id);
  let selected = null,
    mode = "shared",
    context = null,
    guidance = null,
    dirty = false,
    busy = false;
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
        "Content-Type": "application/json",
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response.json();
    if (!response.ok)
      throw Object.assign(new Error(data.message || __("Request failed.", "atshift-deft")), {
        code: data.code,
      });
    return data;
  }
  function paint() {
    for (const button of document.querySelectorAll("#sf-workspace button"))
      button.disabled = busy;
    const editable = selected
      ? selected.canEdit
      : mode === "personal" ||
        mode === "structure" ||
        config.canRegisterCatalog;
    $("catalog-json").disabled = busy;
    $("catalog-json").readOnly = !editable;
    $("catalog-export").disabled = busy || !$("catalog-json").value;
    $("catalog-save").disabled = busy || !dirty || !editable;
    $("catalog-enabled").disabled = busy || dirty || !selected?.canEdit;
    if (!busy) $("catalog-enabled").checked = !!selected?.enabled;
    $("catalog-reuse").hidden = mode === "shared";
    for (const id of [
      "catalog-considered",
      "catalog-reason",
      "catalog-scope",
      "catalog-query",
      "reply-language",
    ])
      $(id).disabled = busy;
    for (const input of $("behavior-list").querySelectorAll("input"))
      input.disabled = busy || input.dataset.readonly === "true";
    if ($("catalog-share"))
      $("catalog-share").disabled =
        busy || dirty || selected?.scope !== "personal";
  }
  async function action(fn) {
    if (busy) return;
    busy = true;
    paint();
    try {
      await fn();
    } catch (error) {
      status((error.code || "ERROR") + ": " + error.message, true);
    } finally {
      busy = false;
      paint();
    }
  }
  function show(component, row = null, changed = false) {
    selected = row;
    dirty = changed;
    mode =
      row?.definitionKind === "structure" ||
      (!row &&
        component.layout &&
        !component.fields.some((field) => "value" in field || "alt" in field))
        ? "structure"
        : row?.scope === "personal" || (!row && component.layout)
          ? "personal"
          : "shared";
    guidance = row?.guidance || null;
    const editable = structuredClone(
      mode !== "shared" ? row?.recipe || component : component,
    );
    delete editable.revision;
    $("catalog-json").value = JSON.stringify(editable, null, 2);
    $("title").textContent = component.name;
    $("catalog-state").textContent = row
      ? (row.enabled ? __("Active", "atshift-deft") : __("Inactive", "atshift-deft")) +
        " · v" +
        row.version +
        " · " +
        row.revision.slice(0, 12) +
        " · " +
        row.scope
      : __("Not registered", "atshift-deft");
    $("catalog-purpose").textContent = component.purpose;
    $("catalog-fields").replaceChildren();
    for (const field of component.fields || []) {
      const name = document.createElement("dt"),
        detail = document.createElement("dd");
      name.textContent = field.label + " (" + field.key + ")";
      detail.textContent = field.type + (field.required ? __(" · Required", "atshift-deft") : "");
      $("catalog-fields").append(name, detail);
    }
  }
  async function list() {
    await listBehaviors();
    const rows = [],
      scope = $("catalog-scope").value;
    for (let page = 1; page <= 5; page++) {
      const result = await request("catalog", null, {
        page,
        scope,
        ...(scope === "personal" || config.canManageCatalog
          ? { includeDisabled: "1" }
          : {}),
      });
      rows.push(...result.items);
      if (page >= result.totalPages) break;
    }
    $("articles").replaceChildren();
    for (const item of rows) {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent =
        item.name + " · v" + item.version + (item.enabled ? "" : __(" · Inactive", "atshift-deft"));
      button.onclick = () =>
        action(async () => {
          if (dirty && !confirm(__("Discard unsaved component changes?", "atshift-deft"))) return;
          const row = await request("catalog/" + encodeURIComponent(item.id));
          show(row.component, row);
          if (mode !== "shared") await lookup();
          status(__("Component loaded.", "atshift-deft"));
        });
      $("articles").append(button);
    }
    if (!rows.length) status(__("No components registered.", "atshift-deft"));
  }
  async function listBehaviors() {
    const registry = await request("behaviors");
    $("behavior-list").replaceChildren();
    for (const item of registry.items) {
      const row = document.createElement("div"),
        label = document.createElement("label"),
        input = document.createElement("input"),
        info = document.createElement("code");
      row.className = "sf-behavior-registry-row";
      input.type = "checkbox";
      input.checked = item.enabled;
      input.dataset.readonly = String(!registry.canManageAvailability);
      input.setAttribute("aria-label", /* translators: 1: item.name. */ sprintf(__("Enable %1$s", "atshift-deft"), item.name));
      label.append(
        input,
        document.createTextNode(`${item.name} · ${item.implementation}`),
      );
      info.textContent = `${item.id} @ ${item.revision.slice(0, 12)}`;
      input.onchange = () =>
        action(async () => {
          try {
            await request(`behaviors/${item.id}/availability`, {
              revision: item.revision,
              enabled: input.checked,
              expectedVersion: registry.version,
            });
            status(__("Behavior availability saved. Reload previews to apply.", "atshift-deft"));
          } finally {
            await listBehaviors();
          }
        });
      row.append(label, info);
      $("behavior-list").append(row);
    }
  }
  async function lookup() {
    context = await request("catalog/context", null, {
      query: $("catalog-query").value,
      replyLanguage: $("reply-language").value,
    });
    const refs = new Set(
      [...$("catalog-considered").selectedOptions].map((o) => o.value),
    );
    $("catalog-considered").replaceChildren();
    for (const item of context.components) {
      const option = new Option(
        item.name + " (" + item.scope + ")",
        item.id + "@" + item.revision,
      );
      option.selected = refs.has(option.value);
      $("catalog-considered").append(option);
    }
    /* translators: 1: number of matching components, 2: number available. */
    status(sprintf(__("Matching components: %1$d. Available: %2$d.", "atshift-deft"), context.matches.length, context.components.length));
    return context;
  }
  function download(value, name) {
    const url = URL.createObjectURL(
      new Blob([JSON.stringify(value, null, 2)], { type: "application/json" }),
    );
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  $("refresh").onclick = () => action(list);
  $("catalog-scope").onchange = () => action(list);
  $("catalog-lookup").onclick = () => action(lookup);
  $("catalog-handoff").onclick = () =>
    action(async () =>
      download(await lookup(), "atshift-deft-ai-handoff.json"),
    );
  $("catalog-schema").onclick = () =>
    action(async () =>
      download(
        await request("catalog/schema"),
        "wordpress-catalog-schemas.json",
      ),
    );
  $("catalog-export").onclick = () =>
    action(async () =>
      download(JSON.parse($("catalog-json").value), "wordpress-component.json"),
    );
  $("catalog-personal").onclick = () =>
    action(async () => {
      if (dirty && !confirm(__("Discard unsaved component changes?", "atshift-deft"))) return;
      const current = await lookup();
      show(current.personalExample, null, true);
      $("catalog-reason").value = "";
      status(__("Personal recipe ready. Not registered.", "atshift-deft"));
    });
  $("catalog-structure").onclick = () =>
    action(async () => {
      if (dirty && !confirm(__("Discard unsaved component changes?", "atshift-deft"))) return;
      const current = await lookup();
      show(current.structureExample, null, true);
      $("catalog-reason").value = "";
      status(__("Shared structure ready. Not registered.", "atshift-deft"));
    });
  $("catalog-json").oninput = () => {
    dirty = true;
    status(__("Component changes not registered.", "atshift-deft"));
    paint();
  };
  $("catalog-import").onclick = () => $("catalog-file").click();
  $("catalog-file").onchange = (event) => {
    const file = event.target.files[0];
    event.target.value = "";
    action(async () => {
      if (!file || (dirty && !confirm(__("Discard unsaved component changes?", "atshift-deft"))))
        return;
      if (file.size > 524288)
        throw new Error(__("Maximum component size is 512 KiB.", "atshift-deft"));
      const input = JSON.parse(await file.text());
      const component =
        input.recipe ||
        input.component ||
        (input.components?.length === 1 ? input.components[0] : input);
      if (!component.id || !Array.isArray(component.fields))
        throw new Error(__("Import one component or personal recipe.", "atshift-deft"));
      let row = null;
      try {
        row = await request("catalog/" + encodeURIComponent(component.id));
      } catch (error) {
        if (error.code !== "sf_missing") throw error;
      }
      if (
        "expectedVersion" in input &&
        input.expectedVersion !== (row?.version || 0)
      )
        throw new Error(
          __("Component changed. Download a new handoff before importing.", "atshift-deft"),
        );
      show(component, row, true);
      // Retain imported recipe edits, not the stored version.
      if (component.layout)
        $("catalog-json").value = JSON.stringify(component, null, 2);
      if (input.guidance) guidance = input.guidance;
      if (mode !== "shared") {
        await lookup();
        if (input.reuse) {
          context.catalogToken = input.reuse.catalogToken;
          const refs = new Set(
            input.reuse.considered.map((r) => r.id + "@" + r.revision),
          );
          for (const option of $("catalog-considered").options)
            option.selected = refs.has(option.value);
          $("catalog-reason").value = input.reuse.reason;
        }
      }
      status(__("Component ready. Not registered.", "atshift-deft"));
    });
  };
  if ($("catalog-example"))
    $("catalog-example").onclick = () =>
      action(async () => {
        if (dirty && !confirm(__("Discard unsaved component changes?", "atshift-deft"))) return;
        const response = await fetch(config.sample);
        if (!response.ok) throw new Error(__("Example unavailable.", "atshift-deft"));
        const c = (await response.json()).components[0],
          id = crypto.randomUUID();
        c.id = "component-" + id;
        c.key = "component_" + id.replaceAll("-", "_");
        c.name = __("New component", "atshift-deft");
        delete c.revision;
        show(c, null, true);
        status(__("Example ready. Not registered.", "atshift-deft"));
      });
  $("catalog-save").onclick = () =>
    action(async () => {
      const component = JSON.parse($("catalog-json").value);
      if (selected && component.id !== selected.id)
        throw new Error(
          __("Keep the selected component ID. Import a new component separately.", "atshift-deft"),
        );
      const row = await request(
        mode === "structure"
          ? "catalog/structures"
          : mode === "personal"
            ? "catalog/personal"
            : "catalog",
        {
          ...(mode !== "shared"
            ? {
                recipe: component,
                reuse: {
                  catalogToken: context?.catalogToken || "",
                  considered: [...$("catalog-considered").selectedOptions].map(
                    (o) => {
                      const [id, revision] = o.value.split("@");
                      return { id, revision };
                    },
                  ),
                  reason: $("catalog-reason").value,
                },
              }
            : { component, ...(guidance ? { guidance } : {}) }),
          expectedVersion: selected?.version || 0,
        },
      );
      show(row.component, row);
      if (mode !== "shared") await lookup();
      await list();
      status(
        row.reused
          ? __("Existing shared structure reused. No duplicate created.", "atshift-deft")
          : __("Component version registered.", "atshift-deft"),
      );
    });
  $("catalog-enabled").onchange = (event) => {
    const enabled = event.target.checked;
    action(async () => {
      const row = await request(
        "catalog/" + encodeURIComponent(selected.id) + "/availability",
        { expectedVersion: selected.version, enabled },
      );
      show(row.component, row);
      await list();
      status(
        enabled
          ? __("Component available.", "atshift-deft")
          : __("Component unavailable for new articles.", "atshift-deft"),
      );
    });
  };
  if ($("catalog-share"))
    $("catalog-share").onclick = () =>
      action(async () => {
        if (
          !confirm(
            __("Register this definition and its example content for all workspace users?", "atshift-deft"),
          )
        )
          return;
        const id = crypto.randomUUID();
        const row = await request(
          "catalog/" + encodeURIComponent(selected.id) + "/share",
          {
            expectedVersion: selected.version,
            id: "shared-" + id,
            key: "shared_" + id.replaceAll("-", "_"),
          },
        );
        show(row.component, row);
        await list();
        status(__("Shared copy registered. Personal definition retained.", "atshift-deft"));
      });
  window.addEventListener("beforeunload", (event) => {
    if (dirty) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
  action(list);
})();
