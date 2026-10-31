import { useEffect, useMemo, useRef, useState } from "react";
import { flushSync } from "react-dom";
import {
  Check,
  CircleAlert,
  CloudUpload,
  Download,
  History,
  Layers,
  Monitor,
  Pencil,
  Plus,
  Settings2,
  Smartphone,
  Upload,
  X,
  Undo2,
  FileOutput,
} from "lucide-react";
import { Dialog, IconButton } from "./ui.jsx";
import { downloadJSON } from "./model.js";
import {
  ARTICLE_STORAGE_KEY,
  buildCatalog,
  sampleArticle,
  createArticleRecord,
  decodeArticleStore,
  encodeArticleStore,
  persistArticleStore,
  handoffBundle,
  prepareProposal,
  applyPrepared,
  editArticle,
  restoreRevision,
  markReviewed,
  renderArticle,
  articleChanges,
} from "./article-model.js";
import "./articles.css";
import { createArticleTools } from "./article-tools.js";
import { useArticleTools } from "./use-article-tools.js";
import ServerReview from "./ServerReview.jsx";
import { snapshotFor } from "./review-snapshot.js";

const clone = (v) => structuredClone(v);
const SELECTION_KEY = `${ARTICLE_STORAGE_KEY}.selection`;

function ErrorNotice({ error }) {
  if (!error) return null;
  return (
    <div className="notice error article-error" role="alert">
      <CircleAlert size={18} />
      <div>
        <strong>{error.code || "ERROR"}</strong>
        <p>{error.message}</p>
        {error.path && <code>{error.path}</code>}
      </div>
      <IconButton
        icon={Download}
        label="エラーをJSONで書き出す"
        onClick={() =>
          downloadJSON("article-error.json", JSON.stringify(error, null, 2))
        }
      />
    </div>
  );
}

function ValueEditor({ record, onSave, onClose, onDirty }) {
  const [article, setArticle] = useState(() => clone(record.article));
  const [selection, setSelection] = useState("title");
  const [error, setError] = useState("");
  const dirty = articleChanges(record.article, article).length > 0;
  useEffect(() => {
    onDirty(dirty);
    return () => onDirty(false);
  }, [dirty, onDirty]);
  const entries = record.article.blocks.flatMap((block, index) => {
    const c = record.components.find(
      (v) =>
        v.id === block.componentId && v.revision === block.componentRevision,
    );
    return c.fields.map((field) => ({
      block,
      field,
      label: `${index + 1}. ${c.name} / ${field.label}`,
      key: `${block.id}:${field.key}`,
    }));
  });
  const entry = entries.find((v) => v.key === selection);
  const value =
    entry &&
    article.blocks.find((b) => b.id === entry.block.id).values[entry.field.key];
  function setValue(key, next) {
    setArticle((current) => ({
      ...current,
      blocks: current.blocks.map((block) =>
        block.id === entry.block.id
          ? {
              ...block,
              values: {
                ...block.values,
                [entry.field.key]: {
                  ...block.values[entry.field.key],
                  [key]: next,
                },
              },
            }
          : block,
      ),
    }));
  }
  function close() {
    if (!dirty || window.confirm("未保存の修正を破棄しますか？")) onClose();
  }
  return (
    <Dialog title="部分修正" onClose={close}>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          try {
            onSave(article);
          } catch (e) {
            setError(e.message);
          }
        }}
      >
        <div className="modal-body field-form">
          {error && (
            <p role="alert" className="notice error">
              {error}
            </p>
          )}
          <label>
            修正する箇所
            <select
              data-autofocus
              value={selection}
              onChange={(e) => setSelection(e.target.value)}
            >
              <option value="title">記事タイトル</option>
              {entries.map((v) => (
                <option key={v.key} value={v.key}>
                  {v.label}
                </option>
              ))}
            </select>
          </label>
          {!entry ? (
            <label>
              記事タイトル
              <input
                required
                maxLength={200}
                value={article.title}
                onChange={(e) =>
                  setArticle({ ...article, title: e.target.value })
                }
              />
            </label>
          ) : (
            <>
              <label>
                {entry.field.label}
                {entry.field.type === "textarea" ? (
                  <textarea
                    rows={7}
                    required={entry.field.required}
                    value={value.value}
                    onChange={(e) => setValue("value", e.target.value)}
                  />
                ) : (
                  <input
                    required={entry.field.required}
                    value={value.value}
                    onChange={(e) => setValue("value", e.target.value)}
                  />
                )}
              </label>
              {["image", "link"].includes(entry.field.type) && (
                <label>
                  {entry.field.type === "image"
                    ? "代替テキスト"
                    : "リンクの表示文"}
                  <input
                    value={value.alt}
                    onChange={(e) => setValue("alt", e.target.value)}
                  />
                </label>
              )}
            </>
          )}
        </div>
        <footer>
          <button type="button" className="button" onClick={close}>
            キャンセル
          </button>
          <button className="button primary" disabled={!dirty}>
            <Check size={16} />
            下書きに保存
          </button>
        </footer>
      </form>
    </Dialog>
  );
}

export default function ArticleWorkspace({ profiles, onSetup }) {
  const [catalog, setCatalog] = useState(null);
  const [records, setRecords] = useState(null);
  const [selectedId, setSelectedId] = useState(() => {
    try {
      return sessionStorage.getItem(SELECTION_KEY) || "";
    } catch {
      return "";
    }
  });
  const [error, setError] = useState(null);
  const [locked, setLocked] = useState(false);
  const [message, setMessage] = useState("");
  const [panel, setPanel] = useState(null);
  const [pending, setPending] = useState(null);
  const [mobile, setMobile] = useState(false);
  const [withCSS, setWithCSS] = useState(true);
  const [editingDirty, setEditingDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [newTitle, setNewTitle] = useState("");
  const [connectedArticleId, setConnectedArticleId] = useState(null);
  const [loadedPreviewKey, setLoadedPreviewKey] = useState(null);
  const fileInput = useRef(null);
  const storageText = useRef(null);
  const initialized = useRef(false);
  const selected =
    records?.find((r) => r.article.id === selectedId) || records?.[0];
  const selectedRef = useRef(selected);
  selectedRef.current = selected;

  useEffect(() => {
    if (!selected) return;
    try {
      sessionStorage.setItem(SELECTION_KEY, selected.article.id);
    } catch {
      /* Selection is optional; article persistence is checked separately. */
    }
  }, [selected?.article.id]);

  function openPanel(name) {
    setError(null);
    setMessage("");
    setPanel(name);
  }

  function report(e) {
    setError({
      code: e.code || "ERROR",
      message: e.message,
      path: e.path || "",
    });
  }
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const nextCatalog = await buildCatalog(profiles);
        if (cancelled) return;
        setCatalog(nextCatalog);
        if (initialized.current) return;
        const text = localStorage.getItem(ARTICLE_STORAGE_KEY);
        const loaded =
          text === null
            ? [sampleArticle(nextCatalog)]
            : await decodeArticleStore(text);
        if (cancelled) return;
        storageText.current = text;
        // Persist the first draft before exposing it; failed/corrupt storage is never reset.
        if (text === null)
          storageText.current = persistArticleStore(loaded, null);
        initialized.current = true;
        setRecords(loaded);
      } catch (e) {
        if (!cancelled) {
          report(e);
          setLocked(true);
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [profiles]);
  useEffect(() => {
    const changed = (event) => {
      if (event.key === ARTICLE_STORAGE_KEY || event.key === null) {
        setLocked(true);
        report({
          code: "STORAGE_CONFLICT",
          message:
            "別のタブで記事が変更されました。再読み込みして最新の版を確認してください。",
        });
      }
    };
    window.addEventListener("storage", changed);
    return () => window.removeEventListener("storage", changed);
  }, []);
  useEffect(() => {
    const handler = (event) => {
      if (editingDirty || pending) {
        event.preventDefault();
        event.returnValue = "";
      }
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [editingDirty, pending]);

  const shown = pending?.article || selected?.article;
  const components = pending?.components || selected?.components;
  const preview = useMemo(() => {
    if (!shown) return { html: "", error: null };
    try {
      return { html: renderArticle(shown, components, withCSS), error: null };
    } catch (e) {
      return {
        html: "",
        error: {
          code: e.code || "PREVIEW_ERROR",
          message: e.message,
          path: e.path || "",
        },
      };
    }
  }, [shown, components, withCSS]);

  const previewKey = JSON.stringify([
    shown?.id,
    shown?.revision,
    pending?.proposalId,
    mobile,
    withCSS,
  ]);
  const view = useMemo(
    () => ({
      viewport: mobile ? "mobile" : "desktop",
      withCSS,
      loaded: loadedPreviewKey === previewKey && !preview.error,
      error: preview.error,
    }),
    [mobile, withCSS, loadedPreviewKey, previewKey, preview.error],
  );
  const connectionEnabled = Boolean(
    selected && connectedArticleId === selected.article.id,
  );
  const toolState = useRef(null);
  toolState.current = {
    enabled: connectionEnabled,
    record: selected,
    catalog,
    pending,
    locked,
    interacting: Boolean(panel) || busy,
    view,
  };
  const tools = useMemo(
    () =>
      createArticleTools({
        getState: () => ({
          ...toolState.current,
          storageCurrent:
            localStorage.getItem(ARTICLE_STORAGE_KEY) === storageText.current,
        }),
        stageProposal: (next) =>
          flushSync(() => {
            setPending(next);
            setError(null);
            setMessage(
              "WebMCPから提案を受け取りました。まだ下書きに反映していません。",
            );
          }),
        showPreview: (next) =>
          flushSync(() => {
            setMobile(next.viewport === "mobile");
            setWithCSS(next.withCSS);
          }),
      }),
    [],
  );
  const toolConnection = useArticleTools(
    tools,
    connectionEnabled && !locked && !panel && !busy && Boolean(catalog),
  );

  function commit(next, status) {
    if (locked) throw new Error("再読み込みして最新の状態を確認してください。");
    const updated = records.map((r) =>
      r.article.id === next.article.id ? next : r,
    );
    storageText.current = persistArticleStore(updated, storageText.current);
    setRecords(updated);
    setError(null);
    setMessage(status);
  }
  function downloadContext() {
    downloadJSON(
      "article-ai-handoff.json",
      JSON.stringify(handoffBundle(selected, catalog), null, 2),
    );
    setMessage("AI受け渡し用JSONを書き出しました。");
  }
  async function importProposal(event) {
    const file = event.target.files[0];
    event.target.value = "";
    if (!file) return;
    setError(null);
    setBusy(true);
    try {
      if (file.size > 2000000)
        throw new Error("ファイルは2MB以内にしてください。");
      const text = await file.text();
      setPending(prepareProposal(text, selectedRef.current, catalog));
      setMessage("");
    } catch (e) {
      report(e);
    } finally {
      setBusy(false);
    }
  }
  function newArticle(event) {
    event.preventDefault();
    try {
      if (!newTitle.trim()) return;
      if (records.length >= 20)
        throw new Error("この試作の記事は20件までです。");
      const next = createArticleRecord(newTitle.trim(), []);
      const updated = [...records, next];
      storageText.current = persistArticleStore(updated, storageText.current);
      setRecords(updated);
      setSelectedId(next.article.id);
      setPanel(null);
      setNewTitle("");
      setError(null);
    } catch (e) {
      report(e);
    }
  }

  return (
    <div className="article-app">
      <header className="article-topbar">
        <div className="article-brand">
          <Layers size={21} />
          <strong>atshift DEFT</strong>
        </div>
        <span className="article-environment">
          Local prototype · WordPress 未接続
        </span>
        <label
          className="article-connection"
          title={
            toolConnection.error?.message ||
            (toolConnection.supported
              ? "選択中の記事の読み取り・提案のみ。保存・承認・公開の権限は含みません。"
              : "このブラウザーはWebMCPに未対応です。JSONでの受け渡しは引き続き使えます。")
          }
        >
          <input
            type="checkbox"
            role="switch"
            aria-label="選択中の記事のWebMCP接続"
            checked={connectionEnabled}
            disabled={!selected || !toolConnection.supported || locked}
            onChange={(e) =>
              setConnectedArticleId(
                e.target.checked ? selected.article.id : null,
              )
            }
          />
          WebMCP
          <span role="status">
            {toolConnection.error
              ? "接続エラー"
              : !toolConnection.supported
                ? "未対応"
                : toolConnection.registered
                  ? "接続中"
                  : connectionEnabled
                    ? "待機中"
                    : "オフ"}
          </span>
        </label>
        <IconButton
          icon={Settings2}
          label="構造と設定"
          onClick={onSetup}
          disabled={Boolean(pending) || busy}
        />
      </header>
      <main className="article-main">
        <div className="article-toolbar">
          <div className="article-picker">
            <label className="sr-only" htmlFor="article-picker">
              記事
            </label>
            <select
              id="article-picker"
              disabled={!records || Boolean(pending) || busy}
              value={selected?.article.id || ""}
              onChange={(e) => {
                setSelectedId(e.target.value);
                setConnectedArticleId(null);
                setMessage("");
                setError(null);
              }}
            >
              {!selected && <option value="">記事なし</option>}
              {records?.map((r) => (
                <option key={r.article.id} value={r.article.id}>
                  {r.article.title}
                </option>
              ))}
            </select>
            <IconButton
              icon={Plus}
              label="新しい下書き"
              disabled={!records || locked || Boolean(pending) || busy}
              onClick={() => openPanel("new")}
            />
          </div>
          <div className="article-tools">
            <IconButton
              icon={FileOutput}
              label="WordPress用スナップショットを書き出す"
              disabled={!selected || Boolean(pending) || busy}
              onClick={() =>
                downloadJSON(
                  "wordpress-snapshot.json",
                  JSON.stringify(snapshotFor(selected), null, 2),
                )
              }
            />
            <IconButton
              icon={CloudUpload}
              label="サーバー保存版"
              disabled={!selected || locked || Boolean(pending) || busy}
              onClick={() => openPanel("server")}
            />
            <button
              className="button"
              onClick={downloadContext}
              disabled={!selected || !catalog || busy}
            >
              <Download size={16} />
              AIに渡す
            </button>
            <button
              className="button"
              disabled={!selected || locked || Boolean(pending) || busy}
              onClick={() => fileInput.current.click()}
            >
              <Upload size={16} />
              提案を読み込む
            </button>
            <input
              ref={fileInput}
              type="file"
              accept="application/json,.json"
              hidden
              onChange={importProposal}
              aria-label="AI提案JSON"
            />
            <IconButton
              icon={Download}
              label="記事のバックアップ"
              disabled={!records}
              onClick={() =>
                downloadJSON("article-backup.json", encodeArticleStore(records))
              }
            />
          </div>
        </div>
        {!panel && <ErrorNotice error={error || preview.error} />}
        {message && (
          <div role="status" className="article-status">
            {message}
          </div>
        )}
        {!records && !locked && <p role="status">記事を読み込んでいます。</p>}
        {selected && (
          <>
            <div className="article-meta">
              <span>
                下書き <code>r{selected.article.revision}</code>
              </span>
              <span>
                {selected.review
                  ? `r${selected.review.revision} ブラウザー確認済み・未公開`
                  : "未確認・未公開"}
              </span>
            </div>
            {pending && (
              <section className="proposal-review" aria-label="提案の確認">
                <div className="proposal-heading">
                  <h2>AI提案のプレビュー</h2>
                  <span>{pending.changes.length}件の変更</span>
                </div>
                {pending.questions.length > 0 && (
                  <div role="alert">
                    <strong>確認が必要です</strong>
                    <ul>
                      {pending.questions.map((q, i) => (
                        <li key={i}>{q}</li>
                      ))}
                    </ul>
                  </div>
                )}
                <details>
                  <summary>変更内容</summary>
                  <ul className="change-list">
                    {pending.changes.map((c, i) => (
                      <li key={i}>
                        <strong>{c.label}</strong>
                        {c.before !== undefined && (
                          <>
                            <del>
                              {c.before}
                              {c.beforeAlt ? ` (${c.beforeAlt})` : ""}
                            </del>
                            <ins>
                              {c.after}
                              {c.afterAlt ? ` (${c.afterAlt})` : ""}
                            </ins>
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                </details>
                <div className="proposal-actions">
                  <button className="button" onClick={() => setPending(null)}>
                    <X size={16} />
                    取り消す
                  </button>
                  <button
                    className="button primary"
                    disabled={
                      locked ||
                      pending.questions.length > 0 ||
                      !pending.changes.length
                    }
                    onClick={() => {
                      try {
                        commit(
                          applyPrepared(selected, pending),
                          "提案を下書きに反映しました。公開はしていません。",
                        );
                        setPending(null);
                      } catch (e) {
                        report(e);
                      }
                    }}
                  >
                    <Check size={16} />
                    下書きに反映
                  </button>
                </div>
              </section>
            )}
            <div className="article-preview-toolbar">
              <div
                className="article-preview-controls"
                role="group"
                aria-label="表示幅"
              >
                <IconButton
                  icon={Monitor}
                  label="PC表示"
                  aria-pressed={!mobile}
                  onClick={() => setMobile(false)}
                />
                <IconButton
                  icon={Smartphone}
                  label="スマホ表示"
                  aria-pressed={mobile}
                  onClick={() => setMobile(true)}
                />
                <label>
                  <input
                    type="checkbox"
                    checked={withCSS}
                    onChange={(e) => setWithCSS(e.target.checked)}
                  />
                  CSS
                </label>
              </div>
              <div className="article-preview-controls">
                <IconButton
                  icon={Pencil}
                  label="部分修正"
                  disabled={Boolean(pending) || locked || busy}
                  onClick={() => openPanel("edit")}
                />
                <IconButton
                  icon={History}
                  label="変更履歴"
                  disabled={Boolean(pending) || busy}
                  onClick={() => openPanel("history")}
                />
                <button
                  className="button"
                  disabled={
                    Boolean(pending) ||
                    locked ||
                    Boolean(selected.review) ||
                    Boolean(preview.error) ||
                    busy ||
                    !selected.article.blocks.length
                  }
                  onClick={async () => {
                    setBusy(true);
                    try {
                      commit(
                        await markReviewed(selected),
                        "この版のプレビューを確認済みにしました。公開はしていません。",
                      );
                    } catch (e) {
                      report(e);
                    } finally {
                      setBusy(false);
                    }
                  }}
                >
                  <Check size={16} />
                  ブラウザー版を確認
                </button>
              </div>
            </div>
            <div
              className={`article-preview-stage ${mobile ? "is-mobile" : ""}`}
            >
              <iframe
                key={previewKey}
                title="記事プレビュー"
                sandbox=""
                referrerPolicy="no-referrer"
                srcDoc={preview.html}
                onLoad={() => setLoadedPreviewKey(previewKey)}
              />
            </div>
          </>
        )}
      </main>
      {panel === "server" && selected && (
        <ServerReview
          record={selected}
          locked={locked}
          onClose={() => setPanel(null)}
        />
      )}
      {panel === "edit" && selected && (
        <ValueEditor
          record={selected}
          onDirty={setEditingDirty}
          onClose={() => setPanel(null)}
          onSave={(article) => {
            commit(
              editArticle(selected, article),
              "修正を保存しました。確認状態を解除しました。",
            );
            setPanel(null);
          }}
        />
      )}
      {panel === "history" && selected && (
        <Dialog title="変更履歴" onClose={() => setPanel(null)}>
          <div className="modal-body article-history">
            <ErrorNotice error={error} />
            <p>現在: r{selected.article.revision}</p>
            {!selected.history.length && (
              <p>保存済みの過去の版はありません。</p>
            )}
            {[...selected.history].reverse().map((h) => (
              <div key={h.article.revision}>
                <span>
                  <strong>r{h.article.revision}</strong> {h.article.title}
                  <small>{new Date(h.at).toLocaleString("ja-JP")}</small>
                </span>
                <button
                  className="button"
                  disabled={locked}
                  onClick={() => {
                    try {
                      commit(
                        restoreRevision(selected, h.article.revision),
                        `r${h.article.revision}の内容を新しい版として保存しました。`,
                      );
                      setPanel(null);
                    } catch (e) {
                      report(e);
                    }
                  }}
                >
                  <Undo2 size={16} />
                  この内容に戻す
                </button>
              </div>
            ))}
          </div>
        </Dialog>
      )}
      {panel === "new" && (
        <Dialog title="新しい下書き" onClose={() => setPanel(null)}>
          <form onSubmit={newArticle}>
            <div className="modal-body field-form">
              <ErrorNotice error={error} />
              <label>
                記事タイトル
                <input
                  data-autofocus
                  required
                  maxLength={200}
                  value={newTitle}
                  onChange={(e) => setNewTitle(e.target.value)}
                />
              </label>
            </div>
            <footer>
              <button
                type="button"
                className="button"
                onClick={() => setPanel(null)}
              >
                キャンセル
              </button>
              <button className="button primary" disabled={locked}>
                作成
              </button>
            </footer>
          </form>
        </Dialog>
      )}
    </div>
  );
}
