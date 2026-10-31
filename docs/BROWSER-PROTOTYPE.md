# ブラウザー試作の開発

この文書は独立したVite/Reactの実験環境について説明します。
WordPress版DEFTの接続・下書き・公開機能とは別で、WordPressへの自動同期はありません。

## 起動

Node.js 24以上を使用します。ローカルレビュー保存に `node:sqlite` が必要です。

```sh
npm ci
npm run dev -- --port 5178 --strictPort
```

`http://127.0.0.1:5178/` を開きます。静的ビルドは `npm run build` です。
レビュー保存APIはdevサーバー専用で、`dist` や `npm run preview` では動きません。

## 試せること

- 記事プレビュー、部分修正、部品の版固定、履歴の復元、JSONによるAI提案の取り込み。
- 構造ライブラリ、HTMLの `data-field` と型付き入力項目の対応付け、CSS・複数クラスの指定。
- PC・スマホ幅、CSSなし表示、構造ツリー、JSONの書き出しと検証付き取り込み。
- 任意で有効にするWebMCPの読み取り・提案。選択中の記事と版に限定し、切り替え・再読み込みで解除。
- 選択中の記事をローカルレビューサーバーへ明示保存し、固定した保存版をプレビュー・確認。

WebMCPには下書き保存・確認済み記録・公開・他の記事一覧を許可しません。
対応ブラウザーとAI側の接続が必要です。未対応時はJSON経由で試せます。

## 保存の境界

編集データはブラウザーのlocalStorageへ保存します。必要なデータはJSONで書き出してください。
サーバー側は `.local/review-store/reviews.sqlite` に保存し、ブラウザーの下書きや履歴とは自動同期しません。
APIは同一オリジンの127.0.0.1、ローカルセッション、CSRF照合に限定します。
保存HTMLはオリジンを含むため、ポート5178を使います。

上限は20記事、各100保存版、1リクエスト4MB、保存版合計64MB。
確認要求は5分・1回限りで再起動時に失効します。古い版・別セッションの確認要求は拒否します。
確認記録は `verifiedHuman: false` / `publishAuthorized: false` であり、本人認証や公開許可ではありません。
画像バイト列・画面ピクセル・人が実際に閲覧した事実を証明しません。

## AI構築キット

構造画面から、デザイン画像・既存CSS・依頼文をAIへ渡すためのキットを取得できます。
原本は [ai-kit](../ai-kit/)、記事提案の契約は [article-kit](../article-kit/) にあります。
`npm run build:ai-kit` で `public/ai-kit/` のJSON・ZIPを再生成します。生成物はGit対象外です。
dev/buildの前処理でも生成します。新しい原本を編集した後は再生成してください。

編集型はテキスト・複数行テキスト・画像・リンク。`data-field` は構造内で一意とし、クラス名と意味を混同しません。
プレビューはDOMPurifyの許可リスト、sandbox付きiframe、CSPで隔離します。
WordPressのv2/v3スナップショットは、この試作のv1形式へ取り込めません。

## 検証

```sh
npm run test:kit
npm run test:transport
npm run test:server
npm run build
```

devサーバーで `/tests/model.test.html`、`/tests/articles.test.html`、`/tests/article-tools.test.html` を開くとモデル回帰テストを実行できます。
HTTPテストにはループバック待ち受けが必要です。実AIクライアントとの接続は別途検証します。

## 画像資料

`docs/design-reference.png` は生成AIによる画面構成の参考画像、`public/assets/atelier.png` は生成AIによる見本記事用の写真です。
実際のWordPress画面や施設の写真ではありません。
