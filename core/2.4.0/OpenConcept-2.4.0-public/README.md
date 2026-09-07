# OpenConcept 2.4.0 公開配布版

このフォルダーは、不特定多数への配布を目的としたOpenConceptの実行パッケージです。個別環境のホスト名、Database接続情報、APIキー、メールアカウント、SSH設定、利用者データ、ログ、添付ファイルは含まれていません。

V2.4.0は、共通・個別APIキーの選択と保持、AI設定の入力保持、通常AI検索とベクトル検索の説明、横並びの設定タブを改善した公開版です。公式5言語の案内を同梱しています。

Version 2.4.0 improves common and individual API key selection and retention, preserves pending AI settings, explains regular AI Search separately from vector search, and presents settings in horizontal tabs. Guidance is included in all five supported interface languages.

新規設置にはこの公開パッケージを使用します。既存2.3.3（修正ZIP適用済みを含む）の更新には、同時配布の`OpenConcept-2.4.0-upgrade-from-2.3.3.zip`と、同梱の更新手順を使用してください。更新パッケージの`files/`を既存アプリルートへ結合し、データや秘密設定を残します。

Use this public package for a new installation. To update an existing 2.3.3 installation, including installations with earlier fix ZIPs, use `OpenConcept-2.4.0-upgrade-from-2.3.3.zip` provided alongside this application package and its upgrade instructions. Merge the upgrade's `files/` contents into the existing application root while preserving data and private configuration.

HTTP/HTTPSサーバーへの配置ツリー、必要環境、PostgreSQL・ベクトル検索の条件は、[日英併記の設置説明書](HTTP-SERVER-SETUP.ja-en.md)を参照してください。

See the [bilingual HTTP server setup guide](HTTP-SERVER-SETUP.ja-en.md) for directory trees, server requirements, and PostgreSQL/vector search prerequisites.

## ライセンス

OpenConceptは、SHINNA Service and Trading Company Limitedの独自ライセンス「OpenConcept 利用許諾条件 第1.1版」に基づき、ソースコードを公開して提供します。本体の無料利用・複製・改変・無料再配布を認めます。本体および改変版本体の有料販売は禁止されますが、プラグインや設置・保守などの役務は、ライセンスの条件に従って有料提供できます。データの取得・引渡しに関する条件、ロゴの取扱いなどを含む全文を確認してください。

- 日本語原文：[TXT版](LICENSE.ja.txt) ／ [Markdown版](LICENSE.ja.md)
- English translation: [TXT](LICENSE.en.txt) / [Markdown](LICENSE.en.md)

再配布時はライセンス全文と必要な権利表示を同梱してください。改変版である旨を明示し、公式版と誤認させないことも必要です。図面管理は別配布です。PDF.js等の第三者資産のLICENSEとNOTICEは図面管理プラグインのパッケージ内に保持されています。

OpenConcept is provided with source code under the custom OpenConcept License Terms, Version 1.1. Use, copying, modification, and redistribution of the Core Software free of charge are permitted under those terms. Paid sales of the Core Software itself are prohibited. Plugins and services may be offered for a fee subject to the License. Please read the full [English translation](LICENSE.en.md), including the data protection and logo conditions.

## 配布ファイルの確認

`DISTRIBUTION-MANIFEST.json`には、パッケージ生成時の全ファイル名、サイズ、SHA-256が記録されています。公開・転送前にmanifestと実ファイルが一致することを確認してください。

## 必要環境

- PHP 8.2以上
- PDOおよびPDO SQLite
- OpenSSLとPHP session
- AI・外部接続を使う場合はcURL
- 日本語処理にはmbstringとintlを推奨
- Officeファイル読取りにはZIP、出力機能にはDOMを推奨
- `storage/`と`published/`へPHP実行ユーザーが書き込めること

新規インストールの標準DatabaseはSQLiteです。Database用の環境変数や外部Databaseは必須ではありません。

## 設置

1. このフォルダーの内容を、空の専用アプリケーションフォルダーへ展開します。
2. WebサーバーのDocument Rootを`public/`へ設定します。
3. `storage/`と`published/`だけに、PHP実行ユーザーの書込み権限を設定します。コード全体へ書込み権限を付けないでください。
4. ブラウザーでサイトを開き、最初のシステム管理者を登録します。
5. 初回登録後、親ページ「OpenConcept 詳細操作説明書」と15章が自動登録されます。

Apacheでプロジェクトルートを公開する互換構成では、同梱の`.htaccess`が内部フォルダーへのアクセスを拒否します。ただし、可能な限り`public/`だけをDocument Rootにしてください。Nginxなど`.htaccess`を解釈しないWebサーバーでは、内部フォルダーとdotfileへの拒否規則をサーバー設定へ明示してください。

Nginxで`public/`をDocument Rootにする場合、静的サイト公開機能を利用するには、兄弟ディレクトリの`published/`をURL `/published/`へ明示的にマッピングし、そのディレクトリではPHPなどのスクリプトを実行させないでください。

## 環境設定

### 共通API KEYと個別キー / Common and individual API keys

設定画面上部の横並びタブで「AI・RAG」を選び、共通AI接続の詳細を開きます。太枠の「共通API KEY」へキーを入力して保存し、その後に「保存済み設定をテスト」を実行します。共通APIキーはすべてのAI処理で選択できますが、接続先のサービスが対応するキーである必要があります。

Use the horizontal **AI / RAG** settings tab and open the common AI connection details. Enter the key in the bold-bordered **Common API KEY** field, save, then test the saved settings. Each AI process can select the common API key, provided its service accepts that key.

翻訳、Embedding、Custom RAG、個別の回答生成接続では「共通APIキーを使用する」を選べます。選択中は個別キーの入力を無効にしますが、保存済み個別キーは保持します。個別へ戻し、対応する接続先と認証方式を使うと、以前のキーを再利用できます。共通AI接続全体を使う選択では接続先・モデルも共通となり、個別接続で共通キーだけを使う選択とは異なります。

Translation, embedding, Custom RAG, and individual answer connections offer **Use the common API key**. Selecting it disables individual key entry while retaining the saved individual key. Switch back to the matching connection and authentication settings to reuse it. Selecting the entire common AI connection also shares its endpoint and model; selecting only its key leaves the individual endpoint and model in use.

キーを削除する場合だけ削除チェックを入れて保存してください。空欄での保存、キー・接続の切り替え、認証なしの選択では保存済みキーを削除しません。共通キー使用中の個別削除チェックは、その個別キーだけを削除します。キーは設置先に暗号化して保存し、配布物には含めません。未保存の入力は画面の再描画や保存失敗後も保持し、保存成功後にクリアします。

Delete a saved key only by selecting its deletion checkbox and saving. Blank input, source or connection changes, and no-auth selection do not delete it. Deleting an individual key while using the common key deletes only that individual key. Keys are encrypted locally and excluded from distributions. Pending input survives re-rendering and failed saves, and clears after a successful save.

### 通常AI検索とベクトル検索 / Regular AI Search and vector search

RAGは検索した情報をAI回答に使う仕組みです。「通常のAI検索（LIKE検索など）」は現在のSQLite・MySQL・PostgreSQLで要約・キーワード・本文を探し、閲覧権限を確認した情報を共通AIへ渡します。MySQLでは全文検索も併用します。原文・権限情報は公開ページの保存時に同期し、要約・タグ・チャンクはAI処理成功後に生成します。中間データの生成にはPostgreSQLは不要です。

RAG uses retrieved information as evidence for AI answers. **Regular AI Search (LIKE and keyword search)** searches summaries, keywords, and content in the current SQLite, MySQL, or PostgreSQL database, checks access permissions, and passes evidence to the common AI connection. MySQL also uses full-text search. Published-page source and permission data are synchronized when saved; summaries, tags, and chunks are generated after successful AI processing. Intermediate data generation does not require PostgreSQL.

標準ベクトル検索を追加する場合は、PostgreSQL・pgvector・Embedding接続を用意し、インデックスを構築して有効化します。設定の保存成功と、接続・索引の準備完了は別です。待機中の案内が出た場合は接続先やキー、ジョブの状態を確認してください。

To add standard vector search, configure PostgreSQL, pgvector, and an embedding connection, then build and activate the index. Successfully saving settings does not mean the service or index is ready. If a waiting message appears, check the connection, credentials, and processing jobs.

### WAFの誤検知による保存エラー / Saving blocked by WAF false positives

2.3.3では「設定 > 一般」に、管理者用の「WAF誤検知を回避」を追加しました。既定値はOFFです。ONでは対象の保存要求をAES-256-GCMで暗号化し、PHPで復号します。HTTPSとWeb Crypto対応ブラウザー、AES-GCMを利用できるPHP OpenSSLが必要です。WAFの本文検査が働かなくなる場合があるため、保存の誤検知がある環境で選択してください。全サーバーでの通過を保証するものではなく、復号後の入力・権限・SQL対策は維持します。

Version 2.3.3 adds the administrator option **Avoid WAF false positives** under **Settings > General**, disabled by default. It encrypts applicable save requests with AES-256-GCM and decrypts them in PHP. HTTPS, a Web Crypto capable browser, and PHP OpenSSL with AES-GCM support are required. WAF inspection of the body may no longer work; use this option for environments affected by false positives. Server-side validation, authorization, and SQL protections remain active, and WAF passage is not guaranteed on every host.

保存失敗時は編集タブを保持し、別タブで設定を変更してから「保存を再試行」を押してください。詳細は同梱の操作説明書の「WAFの誤検知で保存できない場合」を参照してください。

After a save failure, keep the editor tab open, update the setting in another tab, then choose **Retry save**. Do not reload the editor while it contains unsaved changes.

### 図面管理の追加 / Add Drawing Manager

図面管理は本体に同梱しません。「設定 > プラグイン > 公式ダウンロード」からダウンロードして有効化してください。配布元は`BloomingBlooming/openconcept_official`に固定されています。導入時はPHP実行ユーザーに`plugins/`への書込み権限が必要です。既存の図面プラグイン・DB・添付は本体更新時に保持します。

Drawing Manager is distributed separately. Download it in Settings > Plugins > Official downloads, then enable it. The source is fixed to `BloomingBlooming/openconcept_official`. Installation requires PHP write access to `plugins/`. Preserve existing drawing plugins, databases, and uploads when updating the application.

通常のSQLite利用では`.env`は不要です。外部AI、SMTP、公開URLなどを設定する場合だけ、`.env.example`を`.env`へコピーして、配布先固有の値を設定します。

- `.env`をソース管理、配布物、問い合わせ添付へ入れないでください。
- APIキーやパスワードを画面写真、ログ、manifestへ記録しないでください。
- MySQLまたはPostgreSQLを使う場合は、空の専用Databaseを用意し、初期管理者登録後にDatabase Adapterの管理画面から接続します。
- このパッケージには、実在するDatabase名、ホスト、ユーザー名、パスワードは登録されていません。

## 任意のStandard RAG Docker構成

`compose.rag.yaml`は、OpenConcept、PostgreSQL、Embeddingサービス、workerを構成する汎用テンプレートです。利用前に`.env.example`を参考に、必須のパスワード、APIキー、公開URLを配布先で設定してください。値を設定しない状態では、Composeの必須変数検査により起動しません。

セットアップは次のいずれかを使用します。

```powershell
powershell -ExecutionPolicy Bypass -File scripts/setup-rag-stack.ps1 -Topology full
```

```sh
sh scripts/setup-rag-stack.sh full
```

同梱のPostgreSQL初期化SQLは、`vector`、`pgcrypto`、`pg_trgm`拡張を冪等に確認・有効化します。`pgvector`はStandard RAGの必須能力です。`pg_trgm`は任意の検索改善ですが、Docker配布構成では利用可能な状態を検証します。

## バックアップ

Databaseバックアップは、Web公開外の暗号化された保存先を指定して作成します。

```sh
php scripts/create-database-backup.php /private/backup/directory
```

Databaseだけでなく、添付、`storage/`内の資格情報鍵とAdapter状態、公開runtimeと署名鍵を同じ復旧単位として保全してください。バックアップやruntimeを別利用者向けの新規パッケージへコピーしてはいけません。

## この公開版に含まれないもの

- 開発用テスト、CI設定、サンプルサーバー、内部設計・監査資料
- MCP接続設定、特定ホスティング向けデプロイ・同期設定
- ローカル開発用Database構成とテスト用認証情報
- `.env`、Database実体、添付、ログ、lock、鍵、証明書、バックアップ
- 生成済みWeb公開サイトとDocker volume

詳しい画面操作は`docs/openconcept-operation-manual.ja.md`を参照してください。同じ内容が初回管理者登録時にOpenConcept内へ登録されます。
