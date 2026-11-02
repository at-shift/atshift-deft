import { useDeferredValue, useEffect, useMemo, useRef, useState } from "react";
import aiKit from "../ai-kit/manifest.json";
import { version } from "../package.json";
import { IconButton, Dialog } from "./ui.jsx";
import ArticleWorkspace from "./ArticleWorkspace.jsx";
import {
  BookOpen,
  Settings2,
  ShieldCheck,
  Wrench,
  ChevronRight,
  Plus,
  Search,
  Pencil,
  Copy,
  Trash2,
  Download,
  Upload,
  Save,
  Check,
  X,
  Monitor,
  Smartphone,
  ListTree,
  ArrowLeft,
  Menu,
  CircleAlert,
  CircleCheck,
  FileCode2,
  RefreshCw,
  Image,
  Layers,
  FlaskConical,
} from "lucide-react";
import {
  STORAGE_KEY,
  TYPES,
  uid,
  createProfile,
  initialState,
  extractFields,
  updateHTML,
  validateProfile,
  structureTree,
  previewDocument,
  addBinding,
  updateBinding,
  removeBinding,
  activateProfile,
  importProfiles,
  exportData,
  loadState,
  downloadJSON,
} from "./model.js";

const navigation = [
  ["library", "構造ライブラリ", BookOpen],
  ["settings", "編集・出力設定", Settings2],
  ["ai", "AI連携・権限", ShieldCheck],
  ["tools", "ツール", Wrench],
];
const copy = (value) => structuredClone(value);
const fingerprint = (p) =>
  JSON.stringify([
    p.name,
    p.key,
    p.purpose,
    p.html,
    p.css,
    p.fields,
    p.variants,
  ]);
const hasDraft = (p) =>
  p.activeVersion > 0 && fingerprint(p) !== fingerprint(p.versions.at(-1));
const dateText = (value) =>
  new Date(value).toLocaleString("ja-JP", {
    month: "numeric",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });

function FieldDialog({ profile, field, onSave, onClose }) {
  const isNew = !field;
  const [value, setValue] = useState(() =>
    field
      ? copy(field)
      : {
          id: uid(),
          label: "",
          key: "",
          type: "text",
          required: false,
          value: "",
          alt: "",
        },
  );
  const [error, setError] = useState("");
  const set = (key, next) => setValue((v) => ({ ...v, [key]: next }));
  function submit(event) {
    event.preventDefault();
    if (!/^[a-z][a-z0-9_]*$/.test(value.key))
      return setError(
        "項目名は英小文字で始め、英小文字・数字・アンダースコアを使ってください。",
      );
    if (profile.fields.some((f) => f.key === value.key && f.id !== value.id))
      return setError("この項目名はすでに使われています。");
    onSave(value);
  }
  return (
    <Dialog
      title={isNew ? "編集項目を追加" : "編集項目を編集"}
      onClose={onClose}
    >
      <form onSubmit={submit}>
        <div className="modal-body field-form">
          {error && (
            <div role="alert" className="notice error">
              <CircleAlert size={16} />
              {error}
            </div>
          )}
          <label>
            ラベル
            <input
              data-autofocus
              required
              value={value.label}
              onChange={(e) => set("label", e.target.value)}
            />
          </label>
          <label>
            項目名
            <input
              required
              className="mono"
              value={value.key}
              onChange={(e) => set("key", e.target.value)}
            />
          </label>
          <label>
            入力タイプ
            <select
              value={value.type}
              disabled={!isNew && ["image", "link"].includes(field.type)}
              onChange={(e) => set("type", e.target.value)}
            >
              {Object.entries(TYPES)
                .filter(([key]) =>
                  isNew || ["image", "link"].includes(field.type)
                    ? isNew || key === field.type
                    : ["text", "textarea"].includes(key),
                )
                .map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
            </select>
          </label>
          <label>
            {["image", "link"].includes(value.type) ? "見本のURL" : "見本の値"}
            {value.type === "textarea" ? (
              <textarea
                rows={4}
                value={value.value}
                onChange={(e) => set("value", e.target.value)}
              />
            ) : (
              <input
                value={value.value}
                onChange={(e) => set("value", e.target.value)}
              />
            )}
          </label>
          {["image", "link"].includes(value.type) && (
            <label>
              {value.type === "image" ? "代替テキスト" : "リンクの表示名"}
              <input
                value={value.alt}
                onChange={(e) => set("alt", e.target.value)}
              />
            </label>
          )}
          <label className="check-label">
            <input
              type="checkbox"
              checked={value.required}
              onChange={(e) => set("required", e.target.checked)}
            />
            必須項目
          </label>
        </div>
        <footer>
          <button type="button" className="button" onClick={onClose}>
            キャンセル
          </button>
          <button className="button primary" type="submit">
            <Check size={15} />
            {isNew ? "追加" : "変更を適用"}
          </button>
        </footer>
      </form>
    </Dialog>
  );
}

function CodeEditor({ value, onChange, label, language = "html", rows = 15 }) {
  const lines = useRef(null);
  return (
    <div className={`code-editor code-${language}`}>
      <div ref={lines} className="line-numbers" aria-hidden="true">
        {value.split("\n").map((_, i) => (
          <div key={i}>{i + 1}</div>
        ))}
      </div>
      <textarea
        aria-label={label}
        value={value}
        maxLength={50000}
        onChange={(e) => onChange(e.target.value)}
        onScroll={(e) => {
          lines.current.scrollTop = e.target.scrollTop;
        }}
        rows={rows}
        wrap="off"
        spellCheck="false"
        onKeyDown={(e) => {
          if (e.key !== "Tab") return;
          e.preventDefault();
          const { selectionStart: start, selectionEnd: end } = e.currentTarget;
          const target = e.currentTarget;
          onChange(value.slice(0, start) + "  " + value.slice(end));
          requestAnimationFrame(() => {
            target.selectionStart = target.selectionEnd = start + 2;
          });
        }}
      />
    </div>
  );
}

function TreeNode({ node }) {
  const label = (
    <>
      <code>{node.tag}</code>
      <span className="tree-class">{node.classes}</span>
      {node.field && <span className="tree-binding">{node.field}</span>}
    </>
  );
  return (
    <li>
      {node.children.length ? (
        <details open>
          <summary>{label}</summary>
          <ul>
            {node.children.map((child, index) => (
              <TreeNode key={index} node={child} />
            ))}
          </ul>
        </details>
      ) : (
        <div className="tree-leaf">{label}</div>
      )}
    </li>
  );
}

function Preview({ profile, variantId, setVariantId, expanded = false }) {
  const [viewport, setViewport] = useState("desktop");
  const [withCSS, setWithCSS] = useState(true);
  const html = useMemo(
    () => previewDocument(profile, variantId, withCSS),
    [profile, variantId, withCSS],
  );
  const tree = useMemo(() => structureTree(profile.html), [profile.html]);
  return (
    <div className={`preview-column ${expanded ? "expanded-preview" : ""}`}>
      <div className="panel-heading">
        <h2>表示プレビュー</h2>
        <div className="preview-controls">
          <div className="segments compact" aria-label="表示幅">
            <IconButton
              icon={Monitor}
              label="PC表示"
              aria-pressed={viewport === "desktop"}
              onClick={() => setViewport("desktop")}
            />
            <IconButton
              icon={Smartphone}
              label="スマホ表示"
              aria-pressed={viewport === "mobile"}
              onClick={() => setViewport("mobile")}
            />
          </div>
          <select
            aria-label="プレビューの見た目"
            value={
              profile.variants.some((v) => v.id === variantId)
                ? variantId
                : profile.variants[0]?.id || ""
            }
            onChange={(e) => setVariantId(e.target.value)}
          >
            {profile.variants.map((v) => (
              <option key={v.id} value={v.id}>
                {v.name || "名称未設定"}
              </option>
            ))}
          </select>
        </div>
      </div>
      <div className={`preview-stage viewport-${viewport}`}>
        <iframe
          title="構造の表示プレビュー"
          sandbox=""
          referrerPolicy="no-referrer"
          srcDoc={html}
        />
      </div>
      <div className="preview-caption">
        <label className="check-label">
          <input
            type="checkbox"
            checked={withCSS}
            onChange={(e) => setWithCSS(e.target.checked)}
          />
          プレビュー用CSS
        </label>
        <span>
          {viewport === "mobile" ? "最大375px" : "表示領域に合わせる"}
        </span>
      </div>
      <div className="panel-heading tree-heading">
        <h2>構造ツリー</h2>
        <ListTree size={16} />
      </div>
      <div className="structure-tree">
        <ul>
          {tree.map((node, index) => (
            <TreeNode key={index} node={node} />
          ))}
        </ul>
        {!tree.length && <span className="muted">構造がありません</span>}
      </div>
    </div>
  );
}

function Editor({
  profile,
  setProfile,
  dirty,
  onBack,
  onSave,
  onActivate,
  onDownload,
  toast,
}) {
  const [tab, setTab] = useState("html");
  const [variantId, setVariantId] = useState(profile.variants[0]?.id || "");
  const [fieldDialog, setFieldDialog] = useState(null);
  const [validated, setValidated] = useState(false);
  const [activation, setActivation] = useState(false);
  const deferred = useDeferredValue(profile);
  const issues = useMemo(() => validateProfile(deferred), [deferred]);
  const errors = issues.filter((i) => i.level === "error");
  const patch = (changes) => setProfile((p) => ({ ...p, ...changes }));
  function activate() {
    const currentIssues = validateProfile(profile);
    setValidated(true);
    if (currentIssues.some((i) => i.level === "error"))
      return toast("検証エラーを確認してください。", true);
    setActivation(true);
  }
  return (
    <>
      <div className="page-heading editor-heading">
        <div className="title-wrap">
          <IconButton
            icon={ArrowLeft}
            label="構造ライブラリに戻る"
            onClick={onBack}
          />
          <h1>{profile.name || "名称未設定"}</h1>
          <span className={`status ${profile.activeVersion ? "enabled" : ""}`}>
            {profile.activeVersion
              ? hasDraft(profile) || dirty
                ? "変更あり"
                : "有効"
              : "下書き"}
          </span>
          <span className="version">v{profile.activeVersion || 1}</span>
        </div>
        <div className="actions">
          <button className="button" onClick={onSave} disabled={!dirty}>
            <Save size={15} />
            下書き保存
          </button>
          <button
            className="button"
            onClick={() => {
              setValidated(true);
              toast(
                errors.length
                  ? "検証エラーがあります。"
                  : "検証が完了しました。",
                errors.length > 0,
              );
            }}
          >
            <ShieldCheck size={15} />
            検証
          </button>
          <button
            className="button primary"
            onClick={activate}
            disabled={profile.activeVersion > 0 && !hasDraft(profile) && !dirty}
          >
            <Check size={15} />
            有効化
          </button>
        </div>
      </div>
      <div className="tabs" role="tablist" aria-label="構造の設定">
        {[
          ["overview", "概要"],
          ["html", "HTML・編集項目"],
          ["appearance", "見た目"],
          ["preview", "プレビュー"],
        ].map(([key, label]) => (
          <button
            role="tab"
            id={`tab-${key}`}
            aria-controls={`panel-${key}`}
            aria-selected={tab === key}
            key={key}
            onClick={() => setTab(key)}
          >
            {label}
          </button>
        ))}
      </div>
      {validated && (
        <div
          className={`validation-result ${errors.length ? "has-errors" : ""}`}
          role="status"
        >
          <div className="validation-title">
            {errors.length ? (
              <CircleAlert size={18} />
            ) : (
              <CircleCheck size={18} />
            )}
            <strong>
              {errors.length ? `${errors.length}件のエラー` : "検証済み"}
            </strong>
            <button className="text-button" onClick={() => setValidated(false)}>
              閉じる
            </button>
          </div>
          {issues.length > 0 && (
            <ul>
              {issues.map((issue, index) => (
                <li key={index} className={issue.level}>
                  {issue.message}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
      <div
        role="tabpanel"
        id={`panel-${tab}`}
        aria-labelledby={`tab-${tab}`}
        className={`editor-grid ${tab === "preview" ? "preview-only" : ""}`}
      >
        {tab !== "preview" && (
          <div className="configuration-column">
            {tab === "overview" && (
              <>
                <section className="settings-section">
                  <h2>基本情報</h2>
                  <div className="field-form">
                    <label>
                      構造の名前
                      <input
                        value={profile.name}
                        onChange={(e) => patch({ name: e.target.value })}
                      />
                    </label>
                    <label>
                      識別名
                      <input
                        className="mono"
                        value={profile.key}
                        readOnly={profile.activeVersion > 0}
                        onChange={(e) => patch({ key: e.target.value })}
                      />
                    </label>
                    <label>
                      用途
                      <textarea
                        rows={3}
                        value={profile.purpose}
                        onChange={(e) => patch({ purpose: e.target.value })}
                      />
                    </label>
                  </div>
                </section>
                <section className="settings-section">
                  <h2>利用状況</h2>
                  <dl className="properties">
                    <div>
                      <dt>有効な版</dt>
                      <dd>
                        {profile.activeVersion
                          ? `v${profile.activeVersion}`
                          : "未有効化"}
                      </dd>
                    </div>
                    <div>
                      <dt>編集中の版</dt>
                      <dd>
                        v
                        {profile.activeVersion
                          ? profile.activeVersion +
                            (hasDraft(profile) || dirty ? 1 : 0)
                          : 1}
                      </dd>
                    </div>
                    <div>
                      <dt>最終保存</dt>
                      <dd>{dateText(profile.updatedAt)}</dd>
                    </div>
                    <div>
                      <dt>WordPress</dt>
                      <dd>未接続</dd>
                    </div>
                  </dl>
                </section>
                <section className="settings-section">
                  <h2>版の履歴</h2>
                  {profile.versions.length ? (
                    <ul className="version-list">
                      {profile.versions.map((version) => (
                        <li key={version.activeVersion}>
                          <span className="version">
                            v{version.activeVersion}
                          </span>
                          <span>{version.name}</span>
                          <span className="muted">
                            {dateText(version.updatedAt)}
                          </span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <div className="empty-line">有効化された版はありません</div>
                  )}
                  <button className="button" onClick={onDownload}>
                    <Download size={15} />
                    構造を書き出す
                  </button>
                </section>
              </>
            )}
            {tab === "html" && (
              <>
                <section className="html-section">
                  <div className="panel-heading">
                    <h2>HTMLの見本</h2>
                    <span className="code-language">HTML</span>
                  </div>
                  <CodeEditor
                    label="HTMLの見本"
                    value={profile.html}
                    onChange={(html) => setProfile((p) => updateHTML(p, html))}
                  />
                  <div className="editor-command">
                    <button
                      className="text-button"
                      onClick={() => {
                        patch({
                          fields: extractFields(profile.html, profile.fields),
                        });
                        toast("HTMLから編集項目を読み取りました。");
                      }}
                    >
                      <RefreshCw size={14} />
                      HTMLから編集項目を読み取る
                    </button>
                  </div>
                </section>
                <section className="bindings-section">
                  <div className="panel-heading">
                    <h2>編集項目</h2>
                    <span className="count">{profile.fields.length}</span>
                  </div>
                  <div className="table-scroll">
                    <table className="fields-table">
                      <thead>
                        <tr>
                          <th>ラベル</th>
                          <th>項目名</th>
                          <th>入力タイプ</th>
                          <th>必須</th>
                          <th>
                            <span className="sr-only">操作</span>
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {profile.fields.map((field) => (
                          <tr key={field.id}>
                            <td>
                              <button
                                className="row-link"
                                onClick={() => setFieldDialog({ field })}
                              >
                                {field.label}
                              </button>
                            </td>
                            <td>
                              <code>{field.key}</code>
                            </td>
                            <td>
                              <select
                                aria-label={`${field.label}の入力タイプ`}
                                value={field.type}
                                onChange={(e) =>
                                  patch({
                                    fields: profile.fields.map((f) =>
                                      f.id === field.id
                                        ? { ...f, type: e.target.value }
                                        : f,
                                    ),
                                  })
                                }
                              >
                                {Object.entries(TYPES)
                                  .filter(([type]) =>
                                    ["image", "link"].includes(field.type)
                                      ? type === field.type
                                      : ["text", "textarea"].includes(type),
                                  )
                                  .map(([type, label]) => (
                                    <option key={type} value={type}>
                                      {label}
                                    </option>
                                  ))}
                              </select>
                            </td>
                            <td>
                              <input
                                aria-label={`${field.label}を必須にする`}
                                type="checkbox"
                                checked={field.required}
                                onChange={(e) =>
                                  patch({
                                    fields: profile.fields.map((f) =>
                                      f.id === field.id
                                        ? { ...f, required: e.target.checked }
                                        : f,
                                    ),
                                  })
                                }
                              />
                            </td>
                            <td>
                              <div className="row-actions">
                                <IconButton
                                  icon={Pencil}
                                  label={`${field.label}を編集`}
                                  onClick={() => setFieldDialog({ field })}
                                />
                                <IconButton
                                  icon={Trash2}
                                  label={`${field.label}の編集項目を解除`}
                                  onClick={() => {
                                    setProfile((p) =>
                                      removeBinding(p, field.id),
                                    );
                                    toast(
                                      "編集項目を解除しました。HTMLの要素は残っています。",
                                    );
                                  }}
                                />
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                    {!profile.fields.length && (
                      <div className="empty-line">編集項目がありません</div>
                    )}
                  </div>
                  <button
                    className="text-button add-command"
                    onClick={() => setFieldDialog({ field: null })}
                  >
                    <Plus size={16} />
                    編集項目を追加
                  </button>
                </section>
              </>
            )}
            {tab === "appearance" && (
              <>
                <section className="settings-section variants-section">
                  <div className="panel-heading">
                    <h2>見た目の種類</h2>
                    <button
                      className="text-button"
                      onClick={() =>
                        patch({
                          variants: [
                            ...profile.variants,
                            { id: uid(), name: "新しい見た目", classes: "" },
                          ],
                        })
                      }
                    >
                      <Plus size={15} />
                      追加
                    </button>
                  </div>
                  {profile.variants.map((variant) => (
                    <div className="variant-row" key={variant.id}>
                      <label className="variant-name">
                        <input
                          type="radio"
                          name="preview-variant"
                          aria-label={`${variant.name}をプレビュー`}
                          checked={variantId === variant.id}
                          onChange={() => setVariantId(variant.id)}
                        />
                        <input
                          aria-label="見た目の名前"
                          value={variant.name}
                          onChange={(e) =>
                            patch({
                              variants: profile.variants.map((v) =>
                                v.id === variant.id
                                  ? { ...v, name: e.target.value }
                                  : v,
                              ),
                            })
                          }
                        />
                      </label>
                      <details className="class-settings">
                        <summary>クラス設定</summary>
                        <input
                          className="mono"
                          aria-label={`${variant.name}のクラス`}
                          value={variant.classes}
                          placeholder="追加するクラス名"
                          onChange={(e) =>
                            patch({
                              variants: profile.variants.map((v) =>
                                v.id === variant.id
                                  ? { ...v, classes: e.target.value }
                                  : v,
                              ),
                            })
                          }
                        />
                      </details>
                      <IconButton
                        icon={Trash2}
                        label={`${variant.name}を削除`}
                        disabled={profile.variants.length === 1}
                        onClick={() =>
                          patch({
                            variants: profile.variants.filter(
                              (v) => v.id !== variant.id,
                            ),
                          })
                        }
                      />
                    </div>
                  ))}
                </section>
                <section className="settings-section css-section">
                  <div className="panel-heading">
                    <h2>プレビュー用CSS</h2>
                    <span className="code-language">CSS</span>
                  </div>
                  <CodeEditor
                    label="プレビュー用CSS"
                    language="css"
                    rows={18}
                    value={profile.css}
                    onChange={(css) => patch({ css })}
                  />
                </section>
              </>
            )}
          </div>
        )}
        <Preview
          profile={deferred}
          variantId={variantId}
          setVariantId={setVariantId}
          expanded={tab === "preview"}
        />
      </div>
      <div className="editor-status">
        <span>
          <span className={`status-dot ${dirty ? "unsaved" : ""}`} />
          {dirty ? "未保存の変更" : "保存済み"}
        </span>
        <span>保存先：このブラウザー</span>
      </div>
      {fieldDialog && (
        <FieldDialog
          profile={profile}
          field={fieldDialog.field}
          onClose={() => setFieldDialog(null)}
          onSave={(field) => {
            setProfile((p) =>
              fieldDialog.field
                ? updateBinding(p, field)
                : addBinding(p, field),
            );
            setFieldDialog(null);
          }}
        />
      )}
      {activation && (
        <Dialog
          title={`v${profile.activeVersion + 1} を有効化`}
          onClose={() => setActivation(false)}
        >
          <div className="modal-body">
            <p>「{profile.name}」を新しい版として保存します。</p>
            <p className="muted">
              有効化済みの版は履歴に残ります。WordPressへの反映は行いません。
            </p>
            <dl className="properties">
              <div>
                <dt>編集項目</dt>
                <dd>{profile.fields.length}項目</dd>
              </div>
              <div>
                <dt>見た目</dt>
                <dd>{profile.variants.length}種類</dd>
              </div>
            </dl>
          </div>
          <footer>
            <button className="button" onClick={() => setActivation(false)}>
              キャンセル
            </button>
            <button
              className="button primary"
              onClick={() => {
                const success = onActivate();
                if (success) setActivation(false);
              }}
            >
              <Check size={15} />
              有効化する
            </button>
          </footer>
        </Dialog>
      )}
    </>
  );
}

function AIKitDownload() {
  return (
    <a
      className="button"
      href={`/ai-kit/${aiKit.archive}`}
      download={aiKit.archive}
    >
      <Download size={16} aria-hidden="true" />
      AI構築キット
    </a>
  );
}

function Library({
  profiles,
  onOpen,
  onCreate,
  onDuplicate,
  onDelete,
  onDownload,
}) {
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState("all");
  const [sort, setSort] = useState("updated");
  const filtered = profiles
    .filter(
      (p) =>
        `${p.name} ${p.purpose} ${p.key}`
          .toLowerCase()
          .includes(query.toLowerCase()) &&
        (filter === "all" ||
          (filter === "active"
            ? p.activeVersion > 0
            : !p.activeVersion || hasDraft(p))),
    )
    .sort((a, b) =>
      sort === "name"
        ? a.name.localeCompare(b.name, "ja")
        : b.updatedAt.localeCompare(a.updatedAt),
    );
  return (
    <>
      <div className="page-heading library-heading">
        <h1>構造ライブラリ</h1>
        <div className="actions">
          <AIKitDownload />
          <button className="button primary" onClick={onCreate}>
            <Plus size={16} />
            新しい構造
          </button>
        </div>
      </div>
      <div className="library-toolbar">
        <div className="filter-tabs" role="group" aria-label="構造の状態">
          {[
            ["all", "すべて", profiles.length],
            ["active", "有効", profiles.filter((p) => p.activeVersion).length],
            [
              "draft",
              "下書き",
              profiles.filter((p) => !p.activeVersion || hasDraft(p)).length,
            ],
          ].map(([key, label, count]) => (
            <button
              key={key}
              aria-pressed={filter === key}
              onClick={() => setFilter(key)}
            >
              {label}
              <span>{count}</span>
            </button>
          ))}
        </div>
        <div className="library-search">
          <div className="search-input">
            <Search size={16} />
            <input
              aria-label="構造を検索"
              placeholder="名前・用途・識別名で検索"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
            {query && (
              <IconButton
                icon={X}
                label="検索をクリア"
                onClick={() => setQuery("")}
              />
            )}
          </div>
          <select
            aria-label="並び順"
            value={sort}
            onChange={(e) => setSort(e.target.value)}
          >
            <option value="updated">更新順</option>
            <option value="name">名前順</option>
          </select>
        </div>
      </div>
      <div className="table-scroll library-table-wrap">
        <table className="library-table">
          <thead>
            <tr>
              <th>構造の名前</th>
              <th>編集項目</th>
              <th>見た目</th>
              <th>状態</th>
              <th>最終保存</th>
              <th>
                <span className="sr-only">操作</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((profile) => (
              <tr key={profile.id}>
                <td>
                  <div className="library-name">
                    <span className="structure-icon">
                      <Layers size={19} />
                    </span>
                    <div>
                      <button
                        className="row-link"
                        onClick={() => onOpen(profile)}
                      >
                        {profile.name || "名称未設定"}
                      </button>
                      <span className="purpose">{profile.purpose}</span>
                      <code className="profile-key">{profile.key}</code>
                    </div>
                  </div>
                </td>
                <td>
                  {profile.fields.length}
                  <span className="unit">項目</span>
                </td>
                <td>
                  {profile.variants.length}
                  <span className="unit">種類</span>
                </td>
                <td>
                  <span
                    className={`status ${profile.activeVersion ? "enabled" : ""}`}
                  >
                    {profile.activeVersion
                      ? `有効 · v${profile.activeVersion}`
                      : "下書き"}
                  </span>
                  {hasDraft(profile) && (
                    <small className="draft-indicator">下書きあり</small>
                  )}
                </td>
                <td className="muted nowrap">{dateText(profile.updatedAt)}</td>
                <td>
                  <div className="row-actions">
                    <IconButton
                      icon={Pencil}
                      label={`${profile.name}を編集`}
                      onClick={() => onOpen(profile)}
                    />
                    <IconButton
                      icon={Copy}
                      label={`${profile.name}を複製`}
                      onClick={() => onDuplicate(profile)}
                    />
                    <IconButton
                      icon={Download}
                      label={`${profile.name}を書き出す`}
                      onClick={() => onDownload(profile)}
                    />
                    <IconButton
                      icon={Trash2}
                      label={`${profile.name}を削除`}
                      onClick={() => onDelete(profile)}
                    />
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!filtered.length && (
          <div className="empty-state">
            <Search size={28} />
            <h2>
              {profiles.length
                ? "条件に合う構造がありません"
                : "構造がありません"}
            </h2>
            <button
              className="button"
              onClick={
                profiles.length
                  ? () => {
                      setQuery("");
                      setFilter("all");
                    }
                  : onCreate
              }
            >
              {profiles.length ? "検索条件をクリア" : "新しい構造"}
            </button>
          </div>
        )}
      </div>
      <div className="list-footer">
        <span>{filtered.length}件の構造</span>
        <span>
          <FlaskConical size={14} />
          保存先：このブラウザー
        </span>
      </div>
    </>
  );
}

function Settings({ settings, onSave, onDirtyChange, ai = false }) {
  const [value, setValue] = useState(() => copy(settings));
  const patch = (next) => setValue((v) => ({ ...v, ...next }));
  const dirty = JSON.stringify(value) !== JSON.stringify(settings);
  useEffect(() => {
    onDirtyChange(dirty);
  }, [dirty, onDirtyChange]);
  return (
    <>
      <div className="page-heading">
        <h1>{ai ? "AI連携・権限" : "編集・出力設定"}</h1>
        <button
          className="button primary"
          disabled={!dirty}
          onClick={() => onSave(value)}
        >
          <Save size={15} />
          設定を保存
        </button>
      </div>
      <div className="settings-layout">
        {ai ? (
          <>
            <section className="settings-band">
              <div className="section-label">
                <h2>接続状態</h2>
              </div>
              <div>
                <dl className="properties">
                  <div>
                    <dt>WordPress</dt>
                    <dd>
                      <span className="connection-dot" />
                      未接続
                    </dd>
                  </div>
                  <div>
                    <dt>MCP</dt>
                    <dd>
                      <span className="connection-dot" />
                      未接続
                    </dd>
                  </div>
                  <div>
                    <dt>設定の保存先</dt>
                    <dd>このブラウザー</dd>
                  </div>
                </dl>
              </div>
            </section>
            <section className="settings-band">
              <div className="section-label">
                <h2>操作の許可</h2>
              </div>
              <div className="settings-options">
                <Toggle
                  label="構造と値の読み取り"
                  checked={value.aiRead}
                  onChange={(aiRead) => patch({ aiRead })}
                />
                <Toggle
                  label="下書きの変更"
                  checked={value.aiDraft}
                  onChange={(aiDraft) => patch({ aiDraft })}
                />
                <Toggle label="公開前に人が確認" checked={true} disabled />
              </div>
            </section>
            <section className="settings-band">
              <div className="section-label">
                <h2>権限ごとの上限</h2>
              </div>
              <div className="table-scroll">
                <table className="permission-table">
                  <thead>
                    <tr>
                      <th>権限</th>
                      <th>読み取り</th>
                      <th>下書き変更</th>
                    </tr>
                  </thead>
                  <tbody>
                    {[
                      ["administrator", "管理者"],
                      ["editor", "編集者"],
                      ["author", "投稿者"],
                    ].map(([role, label]) => (
                      <tr key={role}>
                        <th>{label}</th>
                        {["read", "write"].map((action) => (
                          <td key={action}>
                            <input
                              type="checkbox"
                              aria-label={`${label}の${action === "read" ? "読み取り" : "下書き変更"}`}
                              checked={value.roles[role][action]}
                              onChange={(e) =>
                                patch({
                                  roles: {
                                    ...value.roles,
                                    [role]: {
                                      ...value.roles[role],
                                      [action]: e.target.checked,
                                    },
                                  },
                                })
                              }
                            />
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          </>
        ) : (
          <>
            <section className="settings-band">
              <div className="section-label">
                <h2>対象</h2>
              </div>
              <fieldset>
                <legend>投稿タイプ</legend>
                {[
                  ["post", "投稿"],
                  ["page", "固定ページ"],
                ].map(([key, label]) => (
                  <label key={key} className="check-label">
                    <input
                      type="checkbox"
                      checked={value.postTypes.includes(key)}
                      onChange={(e) =>
                        patch({
                          postTypes: e.target.checked
                            ? [...value.postTypes, key]
                            : value.postTypes.filter((type) => type !== key),
                        })
                      }
                    />
                    {label}
                  </label>
                ))}
              </fieldset>
            </section>
            <section className="settings-band">
              <div className="section-label">
                <h2>編集画面</h2>
              </div>
              <fieldset>
                <legend>新規投稿の編集画面</legend>
                <label className="check-label">
                  <input
                    type="radio"
                    name="editor"
                    checked={value.editor === "structured"}
                    onChange={() => patch({ editor: "structured" })}
                  />
                  専用の構造エディタ
                </label>
                <label className="check-label">
                  <input
                    type="radio"
                    name="editor"
                    checked={value.editor === "existing"}
                    onChange={() => patch({ editor: "existing" })}
                  />
                  既存の編集画面
                </label>
              </fieldset>
            </section>
            <section className="settings-band">
              <div className="section-label">
                <h2>出力</h2>
              </div>
              <div className="settings-options">
                <Toggle
                  label="HTMLを出力"
                  checked={value.htmlOutput}
                  onChange={(htmlOutput) => patch({ htmlOutput })}
                />
                <Toggle
                  label="公開用の構造JSONを出力"
                  checked={value.jsonOutput}
                  onChange={(jsonOutput) => patch({ jsonOutput })}
                />
              </div>
            </section>
            <section className="settings-band">
              <div className="section-label">
                <h2>スタイル</h2>
              </div>
              <dl className="properties">
                <div>
                  <dt>本番のCSS</dt>
                  <dd>サイト・テーマ側で管理</dd>
                </div>
                <div>
                  <dt>試作のプレビュー</dt>
                  <dd>構造ごとのプレビュー用CSS</dd>
                </div>
                <div>
                  <dt>既存テーマへの反映</dt>
                  <dd>未接続</dd>
                </div>
              </dl>
            </section>
          </>
        )}
      </div>
    </>
  );
}

function Toggle({ label, checked, onChange, disabled }) {
  return (
    <label className="toggle-row">
      <span>{label}</span>
      <span className="toggle">
        <input
          type="checkbox"
          role="switch"
          checked={checked}
          disabled={disabled}
          onChange={(e) => onChange?.(e.target.checked)}
        />
        <span aria-hidden="true" />
      </span>
    </label>
  );
}

function Tools({ state, onImport, onReset }) {
  const input = useRef(null);
  const [error, setError] = useState("");
  const [pending, setPending] = useState(null);
  async function read(event) {
    setError("");
    const file = event.target.files?.[0];
    if (!file) return;
    try {
      if (file.size > 2000000)
        throw new Error("ファイルは2MB以内にしてください。");
      const profiles = importProfiles(await file.text());
      if (profiles.length + state.profiles.length > 50)
        throw new Error("構造は合計50件まで取り込めます。");
      setPending(profiles);
    } catch (err) {
      setError(
        err instanceof SyntaxError
          ? "JSONファイルの形式が正しくありません。"
          : err.message,
      );
    }
    event.target.value = "";
  }
  return (
    <>
      <div className="page-heading">
        <h1>ツール</h1>
      </div>
      <div className="settings-layout">
        <section className="settings-band" aria-labelledby="ai-kit-title">
          <div className="section-label">
            <h2 id="ai-kit-title">AI構築キット</h2>
          </div>
          <div>
            <dl className="properties">
              <div>
                <dt>バージョン</dt>
                <dd>
                  キット v{aiKit.version} · スキーマ v{aiKit.schemaVersion}
                </dd>
              </div>
              <div>
                <dt>内容</dt>
                <dd>{aiKit.files.length}ファイル · ZIP</dd>
              </div>
              <div>
                <dt>取り込み方法</dt>
                <dd>新規の下書き</dd>
              </div>
            </dl>
            <AIKitDownload />
            <ul className="kit-files" aria-label="キットの個別ファイル">
              {aiKit.files.map((file) => (
                <li key={file.name}>
                  <a
                    href={`/ai-kit/${file.name}`}
                    download={file.name}
                    title={`${file.label} (${file.name})`}
                  >
                    <Download size={14} aria-hidden="true" />
                    {file.label}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        </section>
        <section className="settings-band">
          <div className="section-label">
            <h2>書き出し</h2>
          </div>
          <div>
            <dl className="properties">
              <div>
                <dt>形式</dt>
                <dd>JSON · スキーマ v1</dd>
              </div>
              <div>
                <dt>構造</dt>
                <dd>{state.profiles.length}件</dd>
              </div>
            </dl>
            <button
              className="button"
              onClick={() =>
                downloadJSON(
                  "atshift-deft-library.json",
                  exportData(state.profiles, state.settings),
                )
              }
            >
              <Download size={16} />
              ライブラリを書き出す
            </button>
          </div>
        </section>
        <section className="settings-band">
          <div className="section-label">
            <h2>取り込み</h2>
          </div>
          <div>
            <input
              ref={input}
              hidden
              type="file"
              accept=".json,application/json"
              onChange={read}
            />
            <button className="button" onClick={() => input.current.click()}>
              <Upload size={16} />
              JSONファイルを選択
            </button>
            <dl className="properties">
              <div>
                <dt>取り込み後の状態</dt>
                <dd>下書き</dd>
              </div>
              <div>
                <dt>既存の構造・権限設定</dt>
                <dd>変更しない</dd>
              </div>
            </dl>
            {error && (
              <div className="notice error" role="alert">
                <CircleAlert size={16} />
                {error}
              </div>
            )}
          </div>
        </section>
        <section className="settings-band">
          <div className="section-label">
            <h2>接続</h2>
          </div>
          <dl className="properties">
            <div>
              <dt>過去投稿の構造化</dt>
              <dd>未実装</dd>
            </div>
            <div>
              <dt>WordPress</dt>
              <dd>未接続</dd>
            </div>
          </dl>
        </section>
        <section className="settings-band">
          <div className="section-label">
            <h2>ローカルデータ</h2>
          </div>
          <div>
            <button className="button danger" onClick={onReset}>
              <RefreshCw size={16} />
              サンプル状態に戻す
            </button>
          </div>
        </section>
      </div>
      {pending && (
        <Dialog
          title="構造を下書きとして取り込む"
          onClose={() => setPending(null)}
        >
          <div className="modal-body">
            <ul className="import-list">
              {pending.map((profile) => (
                <li key={profile.id}>
                  <Layers size={16} />
                  <span>{profile.name}</span>
                  <span className="muted">{profile.fields.length}項目</span>
                </li>
              ))}
            </ul>
          </div>
          <footer>
            <button className="button" onClick={() => setPending(null)}>
              キャンセル
            </button>
            <button
              className="button primary"
              onClick={() => {
                onImport(pending);
                setPending(null);
              }}
            >
              <Upload size={15} />
              {pending.length}件を取り込む
            </button>
          </footer>
        </Dialog>
      )}
    </>
  );
}

export default function App() {
  const [state, setState] = useState(loadState);
  const [mode, setMode] = useState("articles");
  const [page, setPage] = useState("library");
  const [draft, setDraft] = useState(null);
  const [mobileMenu, setMobileMenu] = useState(false);
  const [createDialog, setCreateDialog] = useState(false);
  const [confirm, setConfirm] = useState(null);
  const [notification, setNotification] = useState(null);
  const [storageError, setStorageError] = useState(false);
  const [settingsDirty, setSettingsDirty] = useState(false);
  const saved = draft && state.profiles.find((p) => p.id === draft.id);
  const dirty = Boolean(
    draft && JSON.stringify(draft) !== JSON.stringify(saved),
  );
  function toast(message, error = false) {
    setNotification({ message, error, id: uid() });
  }
  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
      setStorageError(false);
    } catch {
      setStorageError(true);
    }
  }, [state]);
  useEffect(() => {
    if (!notification) return;
    const timer = setTimeout(() => setNotification(null), 4000);
    return () => clearTimeout(timer);
  }, [notification]);
  useEffect(() => {
    const handler = (event) => {
      if (dirty || settingsDirty) {
        event.preventDefault();
        event.returnValue = "";
      }
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [dirty, settingsDirty]);
  useEffect(() => {
    document.title = `${mode === "articles" ? "Articles" : draft?.name || navigation.find((n) => n[0] === page)?.[1]} | atshift DeFt`;
  }, [page, draft?.name, mode]);

  function leave(action) {
    if (dirty || settingsDirty)
      setConfirm({
        title: "未保存の変更があります",
        text: "変更を破棄して移動しますか？",
        action,
        label: "変更を破棄",
        danger: true,
      });
    else action();
  }
  function navigate(next) {
    leave(() => {
      setDraft(null);
      setSettingsDirty(false);
      setPage(next);
      setMobileMenu(false);
    });
  }
  function saveProfile(profile) {
    const next = { ...profile, updatedAt: new Date().toISOString() };
    setState((s) => ({
      ...s,
      profiles: s.profiles.map((p) => (p.id === next.id ? next : p)),
    }));
    setDraft(copy(next));
    return next;
  }
  function uniqueKey(key, profiles = state.profiles) {
    let candidate = key,
      index = 2;
    while (profiles.some((p) => p.key === candidate))
      candidate = `${key}_${index++}`;
    return candidate;
  }
  function activate() {
    if (state.profiles.some((p) => p.id !== draft.id && p.key === draft.key)) {
      toast("同じ識別名の構造があるため有効化できません。", true);
      return false;
    }
    saveProfile(activateProfile(draft));
    toast("新しい版を有効化しました。");
    return true;
  }
  function create(kind) {
    if (state.profiles.length >= 50)
      return toast("この試作で保存できる構造は50件までです。", true);
    const profile = createProfile(kind);
    profile.key = uniqueKey(profile.key);
    if (state.profiles.some((p) => p.name === profile.name))
      profile.name += "（新規）";
    setState((s) => ({ ...s, profiles: [profile, ...s.profiles] }));
    setDraft(copy(profile));
    setCreateDialog(false);
  }

  if (mode === "articles")
    return (
      <ArticleWorkspace
        profiles={state.profiles}
        onSetup={() => setMode("setup")}
      />
    );

  return (
    <div className="app-shell">
      <aside
        id="settings-sidebar"
        className={`sidebar ${mobileMenu ? "is-open" : ""}`}
      >
        <div className="brand">
          <Layers size={23} />
          <span>
            atshift DeFt<small>設定プロトタイプ</small>
          </span>
        </div>
        <nav aria-label="設定メニュー">
          <button
            onClick={() =>
              leave(() => {
                setDraft(null);
                setSettingsDirty(false);
                setMode("articles");
              })
            }
          >
            <ArrowLeft size={18} />
            <span>記事プレビューへ</span>
          </button>
          {navigation.map(([key, label, Icon]) => (
            <button
              key={key}
              aria-current={page === key ? "page" : undefined}
              onClick={() => navigate(key)}
            >
              <Icon size={18} />
              <span>{label}</span>
              {page === key && <ChevronRight size={15} />}
            </button>
          ))}
        </nav>
        <div className="sidebar-bottom">
          <span className="local-indicator" />
          ローカル試作<span className="prototype-version">{version}</span>
        </div>
      </aside>
      {mobileMenu && (
        <button
          className="nav-scrim"
          aria-label="メニューを閉じる"
          onClick={() => setMobileMenu(false)}
        />
      )}
      <main className="main-shell">
        <header className="topbar">
          <div className="breadcrumbs">
            <span className="mobile-nav">
              <IconButton
                icon={Menu}
                label="設定メニューを開く"
                aria-expanded={mobileMenu}
                aria-controls="settings-sidebar"
                onClick={() => setMobileMenu(!mobileMenu)}
              />
            </span>
            <button className="breadcrumb-root" onClick={() => navigate(page)}>
              {navigation.find((n) => n[0] === page)?.[1]}
            </button>
            {draft && (
              <>
                <ChevronRight size={14} />
                <span>{draft.name || "名称未設定"}</span>
              </>
            )}
          </div>
          <div className="environment">
            <span className="connection-dot" />
            WordPress 未接続
          </div>
        </header>
        <div className={`workspace ${draft ? "editor-workspace" : ""}`}>
          {storageError && (
            <div className="notice error" role="alert">
              <CircleAlert size={16} />
              ブラウザーに保存できません。JSONを書き出して保管してください。
            </div>
          )}
          {page === "library" &&
            (draft ? (
              <Editor
                key={draft.id}
                profile={draft}
                setProfile={setDraft}
                dirty={dirty}
                onBack={() => leave(() => setDraft(null))}
                onSave={() => {
                  saveProfile(draft);
                  toast("下書きを保存しました。");
                }}
                onActivate={activate}
                onDownload={() =>
                  downloadJSON(
                    `${draft.key}.json`,
                    exportData([draft], state.settings),
                  )
                }
                toast={toast}
              />
            ) : (
              <Library
                profiles={state.profiles}
                onOpen={(profile) => setDraft(copy(profile))}
                onCreate={() => setCreateDialog(true)}
                onDuplicate={(profile) => {
                  if (state.profiles.length >= 50)
                    return toast(
                      "この試作で保存できる構造は50件までです。",
                      true,
                    );
                  const next = {
                    ...copy(profile),
                    id: uid(),
                    name: `${profile.name}（複製）`,
                    key: uniqueKey(profile.key),
                    fields: profile.fields.map((field) => ({
                      ...copy(field),
                      id: uid(),
                    })),
                    variants: profile.variants.map((variant) => ({
                      ...copy(variant),
                      id: uid(),
                    })),
                    activeVersion: 0,
                    versions: [],
                    updatedAt: new Date().toISOString(),
                  };
                  setState((s) => ({ ...s, profiles: [next, ...s.profiles] }));
                  toast("構造を複製しました。");
                }}
                onDelete={(profile) =>
                  setConfirm({
                    title: "構造を削除",
                    text: `「${profile.name}」と保存済みの版を、このブラウザーから削除します。`,
                    label: "削除",
                    danger: true,
                    action: () => {
                      setState((s) => ({
                        ...s,
                        profiles: s.profiles.filter((p) => p.id !== profile.id),
                      }));
                      toast("構造を削除しました。");
                    },
                  })
                }
                onDownload={(profile) =>
                  downloadJSON(
                    `${profile.key}.json`,
                    exportData([profile], state.settings),
                  )
                }
              />
            ))}
          {["settings", "ai"].includes(page) && (
            <Settings
              key={page}
              settings={state.settings}
              ai={page === "ai"}
              onDirtyChange={setSettingsDirty}
              onSave={(settings) => {
                setState((s) => ({ ...s, settings }));
                toast("設定をこのブラウザーに保存しました。");
              }}
            />
          )}
          {page === "tools" && (
            <Tools
              state={state}
              onImport={(profiles) => {
                setState((s) => {
                  const next = [...s.profiles];
                  for (const profile of profiles)
                    next.push({
                      ...profile,
                      key: uniqueKey(profile.key, next),
                    });
                  return { ...s, profiles: next };
                });
                toast(`${profiles.length}件の構造を取り込みました。`);
              }}
              onReset={() =>
                setConfirm({
                  title: "サンプル状態に戻す",
                  text: "このブラウザーに保存した構造と設定を削除し、サンプルを読み込み直します。",
                  label: "サンプル状態に戻す",
                  danger: true,
                  action: () => {
                    setState(initialState());
                    toast("サンプル状態に戻しました。");
                  },
                })
              }
            />
          )}
        </div>
      </main>
      {notification && (
        <div
          className={`toast ${notification.error ? "toast-error" : ""}`}
          role="status"
        >
          {notification.error ? (
            <CircleAlert size={18} />
          ) : (
            <CircleCheck size={18} />
          )}
          <span>{notification.message}</span>
          <IconButton
            icon={X}
            label="通知を閉じる"
            onClick={() => setNotification(null)}
          />
        </div>
      )}
      {createDialog && (
        <Dialog title="新しい構造" onClose={() => setCreateDialog(false)}>
          <div className="modal-body template-list">
            {[
              ["empty", "本文セクション", "見出し・本文", FileCode2],
              ["feature", "画像付き説明", "見出し・本文・画像", Image],
              ["notice", "お知らせ", "見出し・本文", CircleAlert],
            ].map(([key, name, detail, Icon]) => (
              <button key={key} onClick={() => create(key)}>
                <Icon size={22} />
                <span>
                  <strong>{name}</strong>
                  <small>{detail}</small>
                </span>
                <ChevronRight size={18} />
              </button>
            ))}
          </div>
        </Dialog>
      )}
      {confirm && (
        <Dialog title={confirm.title} onClose={() => setConfirm(null)}>
          <div className="modal-body">
            <p>{confirm.text}</p>
          </div>
          <footer>
            <button className="button" onClick={() => setConfirm(null)}>
              キャンセル
            </button>
            <button
              className={`button ${confirm.danger ? "danger solid" : "primary"}`}
              onClick={() => {
                confirm.action();
                setConfirm(null);
              }}
            >
              {confirm.label || "続ける"}
            </button>
          </footer>
        </Dialog>
      )}
    </div>
  );
}
