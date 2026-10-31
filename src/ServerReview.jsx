import { useEffect, useMemo, useRef, useState } from "react";
import {
  Check,
  CloudUpload,
  Monitor,
  RefreshCw,
  Smartphone,
} from "lucide-react";
import { Dialog, IconButton } from "./ui.jsx";
import { snapshotFor } from "./review-snapshot.js";
import { createReviewClient } from "./server-review-client.js";

export default function ServerReview({ record, locked, onClose }) {
  const client = useMemo(() => createReviewClient(), []);
  const snapshot = useMemo(() => snapshotFor(record), [record]);
  const [entry, setEntry] = useState(null);
  const [preview, setPreview] = useState(null);
  const [loadedKey, setLoaded] = useState(null);
  const [busy, setBusy] = useState(true);
  const [ready, setReady] = useState(false);
  const [error, setError] = useState("");
  const [mobile, setMobile] = useState(false);
  const active = useRef(true);
  const equal =
    entry && JSON.stringify(entry.snapshot) === JSON.stringify(snapshot);

  async function read() {
    await client.connect();
    const saved = await client.read(record.article.id);
    if (active.current) {
      setEntry(saved);
      setReady(true);
    }
  }
  useEffect(() => {
    active.current = true;
    let cancelled = false;
    // The effect's own flag handles StrictMode remounts independently of event handlers.
    (async () => {
      try {
        await client.connect();
        const saved = await client.read(record.article.id);
        if (!cancelled) {
          setEntry(saved);
          setReady(true);
        }
      } catch (e) {
        if (!cancelled) setError(e.message);
      } finally {
        if (!cancelled) setBusy(false);
      }
    })();
    return () => {
      cancelled = true;
      active.current = false;
    };
  }, [client, record.article.id]);

  async function run(action) {
    setBusy(true);
    setError("");
    try {
      await action();
    } catch (e) {
      if (active.current) {
        setError(`${e.code || "ERROR"}: ${e.message}`);
        setPreview(null);
        setLoaded(false);
        // Never advance the expected version after a conflict without a fresh read.
        if (["SERVER_CONFLICT", "SESSION_REQUIRED"].includes(e.code))
          setReady(false);
      }
    } finally {
      if (active.current) setBusy(false);
    }
  }
  function close() {
    if (!busy) onClose();
  }
  const previewKey = preview ? `${preview.ticket}:${mobile}` : "";
  const loaded = Boolean(preview) && loadedKey === previewKey;
  return (
    <Dialog title="サーバー保存版" wide onClose={close}>
      <div className="modal-body server-review">
        {error && (
          <p className="notice error" role="alert">
            {error}
          </p>
        )}
        <div className="server-review-status" role="status">
          <span>ブラウザー下書き r{record.article.revision}</span>
          <span>
            {!ready
              ? "未接続"
              : !entry
                ? "サーバー未保存"
                : `サーバー v${entry.version} / r${entry.snapshot.article.revision} · ${entry.receipt ? "確認済み" : "未確認"}`}
          </span>
          {entry && <span>{equal ? "下書きと一致" : "下書きと相違あり"}</span>}
          <span>ローカル確認のみ・公開承認ではありません</span>
        </div>
        <div className="server-review-actions">
          <IconButton
            icon={RefreshCw}
            label="サーバー状態を再取得"
            disabled={busy}
            onClick={() =>
              run(async () => {
                setPreview(null);
                setLoaded(false);
                await read();
              })
            }
          />
          <button
            className="button"
            disabled={busy || locked || !ready || equal}
            onClick={() =>
              run(async () => {
                const saved = await client.save(snapshot, entry?.version || 0);
                if (active.current) {
                  setEntry(saved);
                  setPreview(null);
                  setLoaded(false);
                }
              })
            }
          >
            <CloudUpload size={16} />
            サーバーに保存
          </button>
          <button
            className="button"
            disabled={busy || locked || !ready || !entry}
            onClick={() =>
              run(async () => {
                const next = await client.preview(
                  record.article.id,
                  entry.version,
                );
                if (active.current) {
                  setEntry(next);
                  setLoaded(false);
                  setPreview(next);
                }
              })
            }
          >
            <Monitor size={16} />
            保存版をプレビュー
          </button>
        </div>
        {preview && (
          <>
            <div className="server-review-actions">
              <IconButton
                icon={Monitor}
                label="保存版をPC表示"
                aria-pressed={!mobile}
                onClick={() => {
                  if (mobile) setLoaded(false);
                  setMobile(false);
                }}
              />
              <IconButton
                icon={Smartphone}
                label="保存版をスマホ表示"
                aria-pressed={mobile}
                onClick={() => {
                  if (!mobile) setLoaded(false);
                  setMobile(true);
                }}
              />
              <code title={preview.digest}>
                v{preview.version} · {preview.digest.slice(0, 12)}
              </code>
            </div>
            <div className={`server-preview ${mobile ? "is-mobile" : ""}`}>
              <iframe
                key={previewKey}
                title="サーバー保存版プレビュー"
                sandbox=""
                referrerPolicy="no-referrer"
                srcDoc={preview.html}
                onLoad={() => setLoaded(previewKey)}
              />
            </div>
          </>
        )}
      </div>
      <footer>
        <button className="button" onClick={close} disabled={busy}>
          閉じる
        </button>
        <button
          className="button primary"
          disabled={
            !preview || !loaded || busy || locked || Boolean(entry?.receipt)
          }
          onClick={() =>
            run(async () => {
              const receipt = await client.confirm(preview);
              if (active.current) {
                setEntry((current) => ({ ...current, receipt }));
              }
            })
          }
        >
          <Check size={16} />
          この保存版を確認済みにする
        </button>
      </footer>
    </Dialog>
  );
}
