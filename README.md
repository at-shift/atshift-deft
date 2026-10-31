# atshift DEFT

**伝えることに専念する。あとの作業は、いつものAIに。**

普段使うAIとの会話から、段組を含む記事の構成、下書き保存、プレビュー、WordPressへの公開・予約投稿までをつなぐプラグインです。
DEFT は **Delegate Execution. Focus on Thinking.** の略です。

現在は **0.5.0 の開発版**です。まずバックアップを取得したテストサイトでお試しください。

## はじめ方

### STEP 1. インストールする

[WordPress用ZIPをダウンロード](https://github.com/at-shift/atshift-deft/releases/download/v0.5.0/atshift-deft-0.5.0.zip)し、「プラグイン → 新規追加 → アップロード」からインストール・有効化します。
WordPress **6.9以上**、PHP **8.2以上**が必要です。[ReleaseとSHA-256](https://github.com/at-shift/atshift-deft/releases/tag/v0.5.0)も確認できます。GitHubの「Download ZIP」はソース一式で、そのままインストールする配布ZIPではありません。

### STEP 2. 手順書を、いつものAIへ渡す

WordPressの **atshift DEFT** を開き、最初の案内から **AI用手順書をダウンロード**。そのファイルを普段使うAIへ添付するか、チャットのプロジェクトに追加します。

> この手順書を読んで、接続から案内してください。

### STEP 3. AIの案内に沿って接続する

利用するAIアプリの設定を行い、自分のWordPressアカウントで必要な範囲だけ許可します。AIが実際に接続を確認したら準備完了です。
サイト管理者による公式 **WordPress MCP Adapter 0.7.1以上**の有効化が必要です。接続方式・利用条件はAIアプリにより異なります。パスワードや接続キーはチャットに貼りません。

### STEP 4. サンプルで試す、または記事を頼む

初めてなら、手順書に入っているサンプルで下書きとプレビューを試せます。スキップして自分の記事から始めても構いません。
伝えたい相手・内容を話し、資料や希望する段組の画像があればAIへ渡します。

> 初めて参加する人向けの案内を作って。資料の内容を整理して、日時と申込方法が分かる構成にしてください。

### STEP 5. 確認して、公開時刻を伝える

プレビューを確認し、会話で修正します。公開を任せる場合は、公開権限のある担当者が接続に公開権限を別途許可したうえで、**「今すぐ公開」** または **「11月5日午前9時、日本時間で公開」** のように伝えます。
公開を依頼しなければ下書きのままです。サンプル体験では公開・予約をしない手順になっています。

詳しい画面操作は [導入・利用手順](wordpress/atshift-deft/GETTING-STARTED.ja.md)、接続の違いは [AIサービス別の接続手順](wordpress/atshift-deft/docs/CONNECTIONS.md) を参照してください。

## できること

- **会話から記事を構成**: AIが利用可能な部品・入力項目・ルールを読み、見出し、本文、画像、リンクなどを組み立てます。
- **既存部品を優先して再利用**: 共有カタログから探し、足りない場合だけ許可された範囲で構造を追加します。Q&Aの一覧・開閉表示なども選べます。
- **下書きの保存と再開**: 担当者本人の記事をWordPressに保存し、版番号を照合して古い内容での上書きを防ぎます。
- **画像・PDFの取り込み**: 必要なファイルだけを非公開で預かり、記事の保存版と紐付けます。既存WordPressメディアの再利用にも対応します。
- **プレビュー中心の確認**: AI側へ持ち出すHTMLプレビューと、WordPressの実テーマによる本人用プレビューを使い分けられます。必要な部分だけ手動修正もできます。
- **即時公開・予約投稿**: 公開専用の権限を明示的に許可した接続で、AIが希望日時を設定します。
- **担当者ごとの分離**: 記事・素材・接続は本人単位。入力内容を含まない共有構造は再利用できます。

プラグイン内にAIモデルは搭載しません。AI事業者のAPIキーをWordPressへ登録する方式ではなく、利用者が選んだ外部AIからMCPで接続します。AI側のアカウント・契約・対応機能は別途必要です。

## 接続の仕組み

| 経路 | 設定 |
| --- | --- |
| OAuth対応のリモートMCPクライアント | 管理者が正確なcallback URLでアプリを事前登録し、本人がWordPressで許可。PKCE S256対応のpublic clientが必要です。 |
| 認証ヘッダー対応のMCPクライアント・CLI | 個人用の接続キーを安全な認証設定へ登録。秘密を含まないCLI設定テンプレートを利用できます。 |
| MCP接続を利用できないAI | 記事JSONを受け渡し、WordPress画面で取り込む手動経路を利用します。自動接続とは別です。 |

ChatGPT、Claude、Geminiおよび各CLI向けの案内を用意していますが、すべてのアプリ・契約・地域での接続を保証するものではありません。実アカウントでの接続、添付の読み出し、プレビュー表示はそれぞれ確認が必要です。
クラウド上のAIから接続するサイトには、外部から到達できるHTTPSが必要です。`localhost` はクラウドAIから到達できません。

## 動作環境

| 項目 | 条件 |
| --- | --- |
| WordPress | 6.9以上 |
| PHP | 8.2以上。DOM・mbstring・OpenSSL・Sodiumが必要 |
| データベース | InnoDB・名前付きロック対応のMySQL / MariaDB |
| AI接続 | 公式WordPress MCP Adapter 0.7.1以上と対応するMCPクライアント |
| 非公開素材 | 公開領域外の保存先。詳細は導入手順を参照 |
| 予約投稿 | WP-Cron。時刻重視の場合はサーバーの定期実行を設定 |

ホスティング先にNode.jsや専用AIサーバーを追加する必要はありません。
検証環境はWordPress 7.1、PHP 8.3.36、MySQL 8.4、Twenty Twenty-Fiveです。宣言した最低バージョン・マルチサイト・すべてのテーマでの動作確認は未完了です。

## 権限とデータ

- 接続は担当者ごとに作成し、必要最小限を許可します。個人の接続キーを組織で共有しません。
- 下書き権限と公開権限は別です。公開を許可した接続は、追加のWordPress確認画面なしで公開できます。会話の承認はAIへの運用指示であり、サーバーが人の発言を証明するものではありません。
- 接続の取り消しは未実行の予約も取り消します。トークン・キーの期限切れだけでは、すでに受け付けた予約は取り消しません。
- AIへ渡す資料やプレビューは、そのAIサービスにも渡ります。機密情報・個人情報の扱いは利用するサービスの条件を確認してください。
- HTML・値を検証し、任意JavaScriptの登録は許可しません。ただし、管理者・designerが登録するCSSはページ全体の表示へ影響し得るため、信頼する担当者に限って許可してください。

脆弱性の詳細や認証情報は公開Issueへ投稿せず、下記の非公開フォームから報告してください。

## 0.5.0の範囲

既存の **atshift Fields / CFSとは別製品**です。既存投稿の自動変換・移行は行いません。
チャットに添付したファイルが自動でWordPressへ送られるわけではなく、AI側にファイルの読み出し・HTTP転送能力が必要です。
持ち出しHTMLプレビューにはテーマ・JavaScriptを含みません。最終的な見た目は本人用の実テーマプレビューで確認してください。

公開済み投稿の更新、担当者間の所有権移管、素材の保管期限・削除UI、通常メディアへの自動登録は未対応です。
プラグインを無効化すると、予約処理とDEFTが配信する素材・スタイルも停止します。
対応範囲・既知の課題は [FOLLOW-UP.md](docs/FOLLOW-UP.md)、変更履歴は [CHANGELOG.md](CHANGELOG.md) に記録しています。

## フィードバック

**機能要望・改善提案を歓迎します。** 「何をしたいか」「今どこで困るか」「期待する結果」を教えてください。技術的な実装案は不要です。

- [GitHub Issues](https://github.com/at-shift/atshift-deft/issues): 不具合報告、機能要望、改善提案。
- [日本語のお問い合わせフォーム](https://plugins.at-shift.net/support/?product=deft&category=idea): GitHubアカウントがなくても送れます。
- [English support form](https://plugins.at-shift.net/en/support/?product=deft&category=idea): Feature requests and improvement suggestions are welcome.
- セキュリティの報告は [非公開フォーム](https://plugins.at-shift.net/support/?product=deft&category=security) / [private security report](https://plugins.at-shift.net/en/support/?product=deft&category=security) へお願いします。パスワード・接続キーは送らないでください。

## 翻訳

WordPressのgettextに対応し、POT、PO/MO、JavaScript用JSONを追加できます。
現在の日本語カタログは一部の既存案内のみで、全画面の翻訳はまだ行っていません。
[翻訳ファイルの追加手順](wordpress/atshift-deft/languages/README.md) を参照してください。

## 開発・ビルド

ローカルの開発環境にはNode.js 24以上、PHP 8.2以上を用意します。

```sh
npm ci
npm run build:php-deps
npm run pack:wordpress
```

依存関係はlockファイルで固定し、名前空間を分離して同梱します。ZIPとSHA-256は `.local/releases/` に出力されます。
ソース一式のZIPや、依存ライブラリを含まない `wordpress/atshift-deft/` を、そのまま配布ZIPとしてインストールしないでください。

| 資料 | 内容 |
| --- | --- |
| [WordPress開発ガイド](wordpress/README.md) | 保存・API・ローカル検証 |
| [構造カタログ](docs/WORDPRESS-CATALOG.md) | 部品・入力項目の契約 |
| [担当者別運用](docs/MULTI-USER.md) | 所有者・権限の境界 |
| [ブラウザー試作](docs/BROWSER-PROTOTYPE.md) | WordPress版とは独立したUI・構造設計の実験環境 |
| [0.5.0変更ノート](docs/V0.5.0.md) | 旧試作からの移行と検証記録 |

WordPressテストは専用のローカル環境で実行します。本番のデータベースを指定しないでください。

## ライセンス

GPL-2.0-or-later。依存ライブラリの著作権・ライセンスはそれぞれの配布物に従います。
