# OpenConcept 2.6.1 HTTPサーバー配置・必要環境 / HTTP Server Layout and Requirements

本書は、公開配布版をHTTP/HTTPSサーバーへ設置するための説明書です。アプリ一式を同じフォルダーへ展開し、通常のDocument Rootはその中の **`public/`** に設定します。パスとホスト名はすべて例です。

This guide explains how to deploy the public distribution on an HTTP/HTTPS server. Extract the complete application into one directory and set the normal document root to **`public/`** inside it. All paths and hostnames are examples.

v2.4.0では、太枠の「共通API KEY」と各接続の「共通APIキーを使用する」で利用するキーを選択します。共通キーへ切り替えても個別キーは保持され、削除チェックを入れて保存した場合だけ削除されます。個別へ戻すと、対応する接続先・認証方式で以前のキーを再利用できます。接続先サービスがそのキーに対応している必要があります。

In v2.4.0, use the prominent Common API KEY field and each connection's common-key checkbox to select its credential. Switching to the common key retains individual keys; deletion requires the deletion checkbox and saving. Returning to the matching individual connection and authentication mode reuses its retained key. The service must accept the selected key.

RAGはベクトル検索に限りません。PostgreSQLなしでも、公開原文・権限情報の保存と、AI処理成功後の要約・タグ・チャンク生成を継続します。通常のAI検索は現在のDBでLIKE検索などを行います。標準ベクトル検索を追加する場合に、PostgreSQL・pgvector・Embedding接続とインデックスの構築・有効化が必要です。

RAG is not limited to vector search. Without PostgreSQL, published source and permission data are still stored, and successful AI processing generates summaries, tags, and chunks. Regular AI Search uses LIKE and keyword queries in the current database. Adding standard vector search requires PostgreSQL, pgvector, an embedding connection, and a built and activated index.

## 1. 配布物からサーバーへの配置 / From distribution to server

```text
dist/core/
├── OpenConcept-2.6.1-public.zip          配布ZIP / Distribution archive
├── OpenConcept-2.6.1-public.zip.sha256   ZIP検証値 / Archive checksum
└── OpenConcept-2.6.1-public/             展開済み配布物 / Unpacked distribution
    ├── HTTP-SERVER-SETUP.ja-en.md        本書 / This guide
    ├── README.md
    ├── DISTRIBUTION-MANIFEST.json       ファイル一覧・ハッシュ / File inventory and hashes
    └── ...                             アプリ一式 / Complete application

                    展開・転送 / Extract and transfer
                                  |
                                  v
/srv/openconcept/                       アプリルート / Application root
└── public/                             Document Root
```

`OpenConcept-2.6.1-public/`の**内容全体**を、設置先の専用アプリルートへ転送します。`dist/`全体やZIPをDocument Rootにする必要はありません。`public/`だけを単独でコピーすると、親フォルダーのPHPコードや保存領域を参照できなくなります。隠しファイルも保持してください。

Transfer the **entire contents** of `OpenConcept-2.5.0-public/` into a dedicated application root. Neither `dist/` nor the ZIP is the document root. Copying only `public/` breaks access to PHP code and storage in its parent directory. Preserve hidden files as well.

## 2. HTTP公開範囲が分かる配置ツリー / Directory tree and HTTP exposure

```text
/srv/openconcept/                        アプリルート / Application root
├── public/                              通常のHTTP公開範囲 / Normal document root
│   ├── index.php                        画面 / Application UI
│   ├── api.php                          API
│   ├── app-css.php, app-js.php           本体CSS・JS配信 / Core asset endpoints
│   ├── plugin-asset.php                  Plugin資産配信 / Plugin asset endpoint
│   ├── share.php, export.php            共有・出力 / Sharing and export
│   ├── ...                              その他の同梱PHP / Other bundled PHP endpoints
│   ├── assets/                          公開用CSS・JS / Public CSS and JavaScript
│   └── .user.ini                        PHP設定、HTTPでは拒否 / PHP settings; deny HTTP
├── published/                           静的公開専用 / Generated static sites only
│   ├── .htaccess                        アクセス制御 / Access controls
│   └── <slug>/                          発行後に生成 / Created after publication
│       ├── index.html
│       └── ...                          公開ページの画像等 / Published images, etc.
├── app/                                 PHP本体、HTTP非公開 / Private PHP application code
├── assets/                              本体資産の原本 / Core asset source mirror
├── database/                            Schema・SQL、HTTP非公開 / Private schema and SQL
├── locales/                             本体言語Pack / Core language packs
├── plugins/                             Pluginの正式配置先 / Official plugin location
│   ├── database-postgresql-adapter/
│   ├── database-mysql-adapter/
│   ├── translation-openai/
│   ├── ai-file-reader/
│   ├── ai-search-voice/
│   └── voice-conversation/
├── storage/                             保存領域、HTTP非公開 / Private runtime storage
│   ├── .htaccess
│   └── ...                              DB・添付・鍵・状態 / Database, uploads, keys, state
├── scripts/                             管理用CLI / Administrative CLI scripts
├── docker/                              任意のDocker構成 / Optional Docker build files
├── docs/
│   ├── nginx.conf                       サイト別設定例 / Nginx virtual-host reference
│   └── openconcept-operation-manual.ja.md
├── compose.rag.yaml                     任意のRAG構成 / Optional RAG stack
├── .env.example                         設定例 / Configuration template
├── .env                                 設置先で任意に作成 / Optional; created on the server
├── .htaccess                            Apache互換構成用 / Apache compatibility rules
├── index.php, api.php, ...               互換エントリー / Compatibility entry points
├── HTTP-SERVER-SETUP.ja-en.md
├── README.md
├── DISTRIBUTION-MANIFEST.json
└── LICENSE, LICENSE.*                   ライセンス / License files
```

`.env`、DB実体、利用者の添付・鍵・ログ、発行済みサイトは配布物に含みません。ツリーの該当項目は設置後の生成物です。SQLiteのDB実体は`storage/`側で管理し、`database/`はSchema等の配布ファイルを保持します。

The distribution contains no `.env`, live databases, user uploads, keys, logs, or generated sites. Those entries illustrate files created after installation. SQLite database files are managed under `storage/`; `database/` holds distributed schema files.

| URL例 / Example URL | サーバー側の対応 / Server mapping |
| --- | --- |
| `https://wiki.example.com/` | `/srv/openconcept/public/index.php` |
| `https://wiki.example.com/api.php` | `/srv/openconcept/public/api.php` |
| `https://wiki.example.com/assets/app.js` | `/srv/openconcept/public/assets/app.js` |
| `https://wiki.example.com/published/<slug>/` | `/srv/openconcept/published/<slug>/index.html`：別途マッピング / Explicit mapping required |
| `/.env`、`/.user.ini`、`/storage/`、`/database/`、`/app/`、`/plugins/` | HTTPアクセスを拒否 / Deny HTTP access |

`published/`は`public/`の兄弟フォルダーです。静的サイト公開機能を使う場合だけURL `/published/`へ対応付け、HTML・画像・CSS等の静的ファイルだけを配信します。PHP等の実行、dotfile、署名・管理メタデータの取得は許可しません。

`published/` is a sibling of `public/`. Map it to `/published/` when using static site publication, serving only static content such as HTML, images, and CSS. Deny script execution and access to dotfiles, signatures, and management metadata.

## 3. Pluginの配置 / Plugin layout

```text
<application-root>/plugins/<plugin-id>/
├── plugin.json             ID・version・資産定義 / ID, version, asset declarations
├── plugin.php              PHPエントリー / PHP entry point
├── database-adapter.php    Database Adapterの場合 / Database adapters only
├── src/                    Plugin実装 / Plugin implementation, when used
├── assets/                 PluginのCSS・JS等 / Plugin assets, when used
└── locales/                UI付きPluginの言語Pack / Language packs for plugins with UI
    ├── en-US.json
    ├── ja-JP.json
    ├── vi-VN.json
    ├── ko-KR.json
    └── zh-CN.json
```

Plugin本体は`public/plugins/`へ移さず、必ず`plugins/<plugin-id>/`へ置きます。ブラウザー向け資産は`plugin-asset.php`経由で配信されます。プラグインの有効・無効は管理者の「設定 > プラグイン」で管理します。

Keep each plugin in `plugins/<plugin-id>/`. Browser assets are served through `plugin-asset.php`; do not relocate plugins into `public/plugins/`. Administrators manage enabled states in Settings > Plugins.

V2.5.0のAI File Readerは同梱・初期無効です。有効化後に対応するLLM接続を設定・テストし、自動読み取りを開始します。PDF・Word・Excel、承認待ちの処理、通常AI検索との連携は[リリースノート](docs/release-notes-2.5.0.md)を参照してください。

V2.5.0 bundles AI File Reader with the plugin disabled by default. Enable it, configure and test a compatible LLM connection, then start automatic reading. See the [release notes](docs/release-notes-2.5.0.md) for supported files, review decisions, and regular AI Search integration.

図面管理は2.3.1以降の本体配布から分離されています。「設定 > プラグイン > 公式ダウンロード」でダウンロードし、有効化すると`plugins/drawing-manager/`が利用されます。配布元は`BloomingBlooming/openconcept_official`に固定され、環境変数による変更はできません。既存環境の図面プラグイン、図面DB、添付は更新時も保持してください。

Drawing Manager is distributed separately from application version 2.3.1 onward. Download and enable it under Settings > Plugins > Official downloads; it is installed at `plugins/drawing-manager/`. The publisher repository is fixed to `BloomingBlooming/openconcept_official` and cannot be changed through environment variables. Preserve existing drawing plugins, databases, and uploads across application updates.

## 4. 必要環境 / Required environment

| 項目 / Item | 条件 / Requirement |
| --- | --- |
| Webサーバー / Web server | PHPを実行できるApacheまたはNginx等。NginxではPHP-FPM等のFastCGI接続先が必要。 / Apache, Nginx, or another server configured to execute PHP. Nginx needs a PHP FastCGI backend such as PHP-FPM. |
| PHP | アプリ仕様上は8.2以上。セキュリティ更新が提供されるPHP系統を使用。 / Application minimum: PHP 8.2. Use a PHP release receiving security updates. |
| 基本PHP機能 / Core PHP facilities | PDO、`pdo_sqlite`、OpenSSL、session、JSON。PHPのsession・一時保存先も書込み可能であること。 / PDO, `pdo_sqlite`, OpenSSL, sessions, and JSON; writable PHP session and temporary directories. |
| AI・外部HTTP接続 / AI and outbound HTTP | PHP cURL、信頼済みCA証明書、接続先への通信。利用するAIサービスの認証設定。 / PHP cURL, a trusted CA bundle, network access to configured endpoints, and AI service credentials where required. |
| 追加PHP機能 / Additional PHP facilities | `mbstring`・`intl`を推奨。Office読取りにZIP、出力にDOMを推奨。画像を含む静的サイト公開には`fileinfo`が必要。 / Recommend `mbstring` and `intl`; ZIP for Office reading and DOM for exports. Static publication containing images requires `fileinfo`. |
| 永続領域 / Persistent storage | PHP実行ユーザーが`storage/`と`published/`を読み書きできること。DB、添付、鍵、状態を再起動・更新後も保持。 / The PHP service identity must read and write `storage/` and `published/`, retaining databases, uploads, keys, and state across restarts and updates. |
| 公開URL / Public URL | インターネット公開ではHTTPSを設定し、`OPENCONCEPT_APP_URL`を実際のURLへ合わせる。 / Configure HTTPS for an internet deployment and set `OPENCONCEPT_APP_URL` to its actual public URL. |
| PHP CLI / Background execution | RAG保守workerやCLIバックアップを実行する場合に必要。Web PHPと同じDB拡張・設定・保存領域を使用。 / Required for RAG maintenance workers and CLI backups; use the same database extensions, configuration, and storage as web PHP. |

通常の新規インストールはSQLiteを使用します。MySQL、PostgreSQL、Docker、Pythonは通常の本体利用の必須条件ではありません。配布済みPHP・CSS・JavaScriptを使用するため、設置時のNode.jsビルドやComposerインストールは不要です。

A normal new installation uses SQLite. MySQL, PostgreSQL, Docker, and Python are not required for ordinary core use. The distribution includes its PHP, CSS, and JavaScript, so deployment does not require a Node.js build or Composer installation.

ファイルアップロードの上限は、アプリ、PHPの`upload_max_filesize`・`post_max_size`、Webサーバーの制限を揃えます。本体添付は既定15 MB、図面管理は保存上限が既定50 MBです。`post_max_size`とWebサーバーのリクエスト上限にはmultipartの余裕も必要です。AI処理のタイムアウトもPHP・FastCGI・リバースプロキシ間で調整します。

Align application upload limits with PHP's `upload_max_filesize` and `post_max_size` and the web server request limit. Core attachments default to 15 MB; Drawing Manager storage defaults to 50 MB. Allow multipart overhead in request limits. Align PHP, FastCGI, and reverse proxy timeouts with AI processing times.

## 5. PostgreSQL・ベクトルDBの必須条件 / PostgreSQL and vector database requirements

**OpenConcept組み込みの標準RAGでベクトル保存・検索を使う場合、正本DBとしてPostgreSQLを使用し、その同じDBでpgvectorの`vector`拡張が利用できることが必須です。PostgreSQL本体だけの導入では足りません。**

**For vector storage and search with OpenConcept's built-in Standard RAG, PostgreSQL must be the authoritative application database, and pgvector's `vector` extension must be usable in that same database. Installing PostgreSQL alone is insufficient.**

| 利用構成 / Configuration | PostgreSQL・pgvectorの要否 / PostgreSQL and pgvector |
| --- | --- |
| 通常の本体利用 / Ordinary core use | 不要。SQLiteが標準。 / Not required; SQLite is the default. |
| MySQLを正本にする / MySQL as the authoritative DB | 不要。MySQLサーバー・`pdo_mysql`・MySQL Adapterが必要。 / Not required; needs a MySQL server, `pdo_mysql`, and the MySQL Adapter. |
| PostgreSQLのみ、標準RAGは未使用 / PostgreSQL without Standard RAG | PostgreSQL・`pdo_pgsql`・PostgreSQL Adapterが必要。詳細設定で標準RAG準備を解除した場合はpgvectorなしで移行可能。 / Requires PostgreSQL, `pdo_pgsql`, and the PostgreSQL Adapter. Migration without pgvector is allowed when Standard RAG readiness is explicitly disabled in advanced settings. |
| 組み込み標準RAG / Built-in Standard RAG | **正本PostgreSQL＋有効な`vector`拡張＋Embedding接続先が必須。** / **Requires authoritative PostgreSQL, the enabled `vector` extension, and an embedding endpoint.** |
| 外部Custom RAG / External Custom RAG | OpenConcept側はSQLite・MySQL・PostgreSQLのいずれも可。外部サービスのベクトルDB要件はそのサービスに従う。 / OpenConcept may use SQLite, MySQL, or PostgreSQL; the external service defines its own vector database requirements. |

### 標準RAGの構成ツリー / Standard RAG service tree

```text
利用者 / Browser
└── HTTPS Web server
    └── OpenConcept PHP application
        ├── plugins/database-postgresql-adapter/
        │   └── 正本PostgreSQL / Authoritative PostgreSQL
        │       ├── ページ・権限等 / Pages, permissions, application data
        │       ├── RAG管理状態 / RAG control state
        │       └── vector拡張 / pgvector extension
        │           └── 派生Embedding・HNSW索引 / Derived embeddings and HNSW indexes
        ├── Embedding API / BGE-M3-compatible dense embedding endpoint
        │   └── 同梱例: Python BGE-M3 service / Bundled option: Python BGE-M3 service
        ├── PHP worker / scripts/run-rag-maintenance.php
        │   └── キュー処理・索引更新 / Queue processing and index updates
        └── 回答生成AI / Configured answer-generation AI provider
```

次の条件を揃えてください。PostgreSQLやEmbeddingは、同じサーバー、別サーバー、対応するマネージドサービスのいずれでも構いません。

Prepare the following. PostgreSQL and embedding services may run on the same host, separate hosts, or compatible managed services.

| 条件 / Condition | 必須内容 / Required preparation |
| --- | --- |
| DB接続 / Database connection | UTF8の専用DBとユーザー、到達可能なHost・Port、認証・TLS設定。Web PHPとworker双方に`pdo_pgsql`。 / A dedicated UTF8 database and user, reachable host/port, authentication and TLS settings; `pdo_pgsql` in both web PHP and workers. |
| DB権限 / Database privileges | 対象Schema内の接続、CREATE・ALTER・INDEX・CRUD・DROP等がAdapter診断を通ること。 / Connection and schema privileges sufficient for the adapter's CREATE, ALTER, INDEX, CRUD, and DROP diagnostics. |
| pgvectorのサーバー導入 / Server installation | PostgreSQLのメジャー版に対応したpgvector本体をDB管理者が導入すること。 / The DB administrator installs pgvector built for the PostgreSQL major version. |
| DB単位での有効化 / Per-database activation | OpenConceptが接続するDBで`vector`を有効化。サーバーにファイルがあるだけでは不十分。 / Enable `vector` in the database OpenConcept connects to; server files alone are insufficient. |
| 索引能力 / Index capability | `vector`型、距離演算子、HNSW索引が利用可能であること。現行実装のdense vector次元数は1〜2000。 / The vector type, distance operators, and HNSW indexes must work; the current implementation accepts 1–2000 dense dimensions. |
| Embedding | BGE-M3互換のOpenAI形式Dense Embedding APIへ接続でき、認証・モデル・次元数が一致すること。同梱BGE-M3例は1024次元。 / A reachable BGE-M3-compatible OpenAI-format dense embedding API with matching authentication, model, and dimensions; the bundled BGE-M3 example uses 1024 dimensions. |
| バックグラウンド処理 / Background processing | キューを処理するworkerの実行経路を確保すること。継続運用では下記CLIをサービスやスケジューラーから実行。 / Provide a worker execution path for queued jobs; run the CLI below through a service or scheduler for ongoing processing. |

同梱`compose.rag.yaml`の組合せは **PostgreSQL 17＋pgvector 0.8.1** です。これは同梱構成のバージョンであり、一般的な「最低バージョン」の保証ではありません。別構成ではAdapter診断と標準RAGの索引作成・検索を確認してください。

The bundled `compose.rag.yaml` specifies **PostgreSQL 17 with pgvector 0.8.1**. These are the bundled versions, not a blanket minimum-version guarantee. Validate alternative combinations through adapter diagnostics and Standard RAG index creation and retrieval.

pgvectorはDBサーバー側へ導入する拡張です。PHPの拡張ディレクトリや`plugins/`へコピーするものではありません。対象DBごとの有効化とHNSWについては[pgvector公式文書](https://github.com/pgvector/pgvector#readme)、拡張有効化の権限については[PostgreSQL公式文書](https://www.postgresql.org/docs/current/sql-createextension.html)を参照してください。

pgvector is installed on the database server, not in PHP's extension directory or OpenConcept's `plugins/`. See the [official pgvector documentation](https://github.com/pgvector/pgvector#readme) for per-database activation and HNSW, and the [PostgreSQL extension documentation](https://www.postgresql.org/docs/current/sql-createextension.html) for activation privileges.

### 導入・確認の順序 / Setup and verification order

1. SQLiteで初回管理者を作成し、「設定 > プラグイン」でPostgreSQL Database Adapterを有効にします。 / Create the initial administrator using SQLite, then enable the PostgreSQL Database Adapter in Settings > Plugins.
2. 管理者が用意したPostgreSQLの接続先をAdapter画面へ入力します。SQLiteから直接移行でき、MySQLを経由する必要はありません。 / Enter the prepared PostgreSQL connection in the adapter UI. Direct migration from SQLite is supported; MySQL is not an intermediate requirement.
3. 標準RAGを使う場合は既定の「標準RAGを使用できる状態にする」を有効のまま実行します。Adapterはpgvectorの存在を検査し、可能なら対象DBで`vector`を有効化します。 / For Standard RAG, keep the default readiness option enabled. The adapter checks for pgvector and enables `vector` in the target database when permitted.
4. 本体欠落・権限不足・有効化失敗の場合は移行が停止し、現在の正本DBが維持されます。サーバーファイルはOpenConceptのブラウザー画面から導入できません。 / Missing server files, insufficient privileges, or activation failures stop migration and preserve the current authoritative database. OpenConcept's browser UI cannot install server files.
5. DB移行と検証が完了した後、「設定 > AI・RAG」で標準RAGとEmbedding接続先を設定します。health check、索引生成、実際の検索まで確認します。 / After migration and validation, configure Standard RAG and its embedding endpoint in Settings > AI & RAG. Verify health, index generation, and an actual search.

管理者が手動で有効化する場合は、**OpenConceptの接続対象DB**へ拡張を作成できる権限で接続して実行します。通常のアプリユーザーへ恒常的なsuperuser権限を与える必要はありません。

For manual activation, connect to **the database used by OpenConcept** with an account authorized to create the extension. The normal application account does not need permanent superuser privileges.

```sql
CREATE EXTENSION IF NOT EXISTS vector;

SELECT current_database(), current_setting('server_encoding');
SELECT extname, extversion FROM pg_extension WHERE extname = 'vector';
SELECT '[1,0,0]'::vector <-> '[0,1,0]'::vector AS distance;
SELECT amname FROM pg_am WHERE amname = 'hnsw';
```

`vector`のversion行、距離計算結果、`hnsw`行が返ることを確認します。これらは能力確認であり、データ移行や索引生成の完了を証明するものではありません。`pg_trgm`は任意の検索改善で、`vector`の代わりにはなりません。同梱Docker初期化SQLは`vector`・`pgcrypto`・`pg_trgm`を有効化し、Docker検査は`pg_trgm`も確認します。

Expect a `vector` version row, a distance result, and an `hnsw` row. These checks confirm capabilities, not completed migration or indexing. `pg_trgm` is an optional search enhancement and cannot replace `vector`. Bundled Docker initialization enables `vector`, `pgcrypto`, and `pg_trgm`; Docker diagnostics also check `pg_trgm`.

アプリルートから実行するworkerコマンド / Worker command, run from the application root:

```sh
php scripts/run-rag-maintenance.php --limit=25
```

workerはWeb PHPと同じ`storage/`、Adapter状態、鍵、設定へアクセスさせます。モデルや次元数を変更した場合、または別のPostgreSQLへ切り替えた場合は、派生ベクトル索引を再構築して完了を確認します。

Workers must share the web application's `storage/`, adapter state, keys, and configuration. After changing the embedding model or dimensions, or switching PostgreSQL databases, rebuild the derived vector index and verify completion.

## 6. Webサーバーの設定手順 / Web server setup

1. 配布物を専用アプリルートへ展開し、Document Rootを`<application-root>/public`へ指定します。PHPから親のアプリルートも読み取れるよう、ホスティングの`open_basedir`等を設定します。 / Extract into a dedicated application root and set the document root to `<application-root>/public`. Hosting restrictions such as `open_basedir` must allow PHP to read the parent application directory.
2. PHP実行ユーザーに`storage/`と`published/`の必要な権限を与えます。通常のコードは読取り専用とします。管理画面からPluginをダウンロードする場合だけ、インストール処理が`plugins/`へ書き込める運用を別途用意します。 / Grant the PHP service identity the required access to `storage/` and `published/`. Keep ordinary code read-only. If downloading plugins through the UI, separately arrange write access to `plugins/` for installation.
3. 通常のSQLite利用だけなら`.env`は不要です。公開URLや外部接続を設定する場合は`.env.example`を同じアプリルートの`.env`へコピーし、使用する項目を設置先の値へ変更します。サンプル値のまま接続しないでください。 / SQLite alone does not require `.env`. To configure the public URL or external services, copy `.env.example` to `.env` in the same application root and replace the settings you use with deployment-specific values.
4. HTTPSとPHP処理を設定し、内部ディレクトリ・dotfileへのHTTPアクセス、未知のPHPファイルの実行を拒否します。 / Configure HTTPS and PHP execution, blocking HTTP access to internal directories and dotfiles and execution of unknown PHP files.
5. 静的公開を利用する場合、`/published/`を兄弟フォルダーへ対応付けます。`OPENCONCEPT_PUBLIC_SITE_DIR`と`OPENCONCEPT_PUBLIC_SITE_URL`を変更する場合は、実ファイルとURLの対応も同時に変更します。 / For static publication, map `/published/` to the sibling directory. If overriding `OPENCONCEPT_PUBLIC_SITE_DIR` or `OPENCONCEPT_PUBLIC_SITE_URL`, update the server mapping to match.
6. ブラウザーで初回管理者を登録します。初回登録URLは管理者だけが到達できる状態で準備してください。以後、必要なAI・SMTP・Plugin・DB設定を行います。 / Register the first administrator in the browser, keeping initial setup accessible only to the intended administrator. Then configure the required AI, SMTP, plugins, and database options.

### Nginx

同梱の[`docs/nginx.conf`](docs/nginx.conf)は`http` contextから読み込むサイト別`server`設定例です。global `nginx.conf`の置換用ではありません。アプリの絶対パス、`server_name`、`listen`とTLS、`fastcgi_pass`を変更し、アップロード上限とタイムアウトを調整してください。Nginxは`.htaccess`を解釈しません。

The bundled [`docs/nginx.conf`](docs/nginx.conf) is a virtual-host `server` example included from the `http` context, not a replacement for the global configuration. Adjust the application path, `server_name`, `listen` and TLS, `fastcgi_pass`, upload limits, and timeouts. Nginx does not interpret `.htaccess`.

Nginxには`public/`と配信対象`published/`の読取り権限、ならびに配備停止判定用`storage/.deployment-write-gate`の存在確認権限が必要です。SQLiteや鍵の読取り権限をNginxへ広げないでください。設定例のTLSヘッダー継承に関するコメントも確認します。

Nginx needs read access to `public/` and the served publications, plus permission to check the existence of `storage/.deployment-write-gate`. Do not grant it access to SQLite files or private keys. Review the reference comments about TLS header inheritance.

設定は管理者が設置・検証・反映します。OpenConceptが稼働中のWebサーバー設定を自動変更することはありません。構文検査成功後に反映してください。

The administrator installs, validates, and activates the configuration. OpenConcept does not automatically modify the live web server configuration. Apply it only after a successful syntax check.

```sh
nginx -t
nginx -s reload
```

### Apache・共有ホスティング / Apache and shared hosting

ApacheでもDocument Rootは`public/`を基本とし、PHP実行設定とdotfile拒否を適用します。静的公開を使う場合は、兄弟`published/`のAlias等と、そこでのスクリプト実行拒否を別途設定します。

For Apache, use `public/` as the document root, configure PHP, and deny dotfiles. Static publication requires a separate mapping such as an Alias to the sibling `published/`, with script execution disabled there.

Document Rootを変更できないApacheホスティングでは、アプリ全体を公開ルートへ置く互換構成もあります。その場合は同梱`.htaccess`が有効になる`AllowOverride`設定と`mod_rewrite`、内部フォルダーの拒否規則が必須です。拒否を実測できない環境では、その配置で公開しないでください。

Apache hosting with a fixed document root can use the compatibility layout with the full application at that root. This requires appropriate `AllowOverride` settings, `mod_rewrite`, and working internal-directory deny rules. Do not publish that layout unless the denied paths have been verified.

## 7. 任意のDocker構成 / Optional Docker deployment

Dockerは標準RAGやPostgreSQL Adapter自体の必須条件ではありません。同梱構成を利用する場合の事前検査基準は、Docker Engine、Compose v2、Linux `amd64` container、2 CPU以上、Docker割当メモリ4 GiB以上、空きディスク10 GiB以上です。これは事前検査の下限で、実際のモデル・文書量・同時実行数に応じて増設します。モデル初回取得には外部通信が必要です。

Docker is not required by Standard RAG or the PostgreSQL Adapter. The bundled stack's preflight baseline is Docker Engine, Compose v2, Linux `amd64` containers, at least 2 CPUs, 4 GiB of memory allocated to Docker, and 10 GiB free disk. These are preflight thresholds; size actual resources for models, document volume, and concurrency. Initial model download requires outbound network access.

```text
HTTPS reverse proxy
└── 127.0.0.1:8080 -> openconcept-web:80   同梱既定 / Bundled default
    ├── openconcept-worker                同じ設定・永続領域 / Shared config and storage
    ├── postgres:5432                     PostgreSQL + pgvector
    └── rag-embedding:8000                 Python 3.12 / BGE-M3

Docker volumes                            永続化 / Persistence
├── openconcept_storage                   DB・添付・鍵・状態 / DB, uploads, keys, state
├── openconcept_published                 静的公開サイト / Published static sites
├── postgres_data                         PostgreSQLデータ / PostgreSQL data
└── embedding_cache                       モデルキャッシュ / Model cache
```

同梱Docker WebイメージはApacheの互換構成でアプリルートを扱います。通常の直接設置で推奨する`public/` Document Rootとは異なるため、コンテナ内の`.htaccess`を保持し、下記の拒否確認を行います。PostgreSQLとEmbeddingは内部ネットワークまたはloopbackへ限定します。

The bundled Docker web image uses Apache's compatibility layout at the application root. This differs from the recommended `public/` document root for direct deployments; retain container `.htaccess` files and perform the denial checks below. Keep PostgreSQL and embedding endpoints on internal networks or loopback.

アプリルートでいずれかを実行します / Run either command from the application root:

```sh
sh scripts/setup-rag-stack.sh full
```

```powershell
powershell -ExecutionPolicy Bypass -File scripts/setup-rag-stack.ps1 -Topology full
```

初回はサーバー固有の秘密値を持つ`.env.rag`を生成して終了します。公開URLとPortを確認し、同じコマンドを再実行します。`.env.rag`は公開・再配布せず、モデルキャッシュを含むDocker volumeを他の設置先へ共有しません。AI回答生成の認証情報は構築後にアプリ管理画面で設定します。

The first run creates a private `.env.rag` with deployment-specific secrets and exits. Review the public URL and ports, then run the same command again. Do not publish or redistribute `.env.rag`, or share Docker volumes, including model caches, with other installations. Configure answer-generation AI credentials in the application settings after setup.

## 8. 設置後の確認・保全 / Post-installation checks and preservation

| 確認項目 / Check | 期待結果 / Expected result |
| --- | --- |
| `/`、ログイン、ページ作成 / Home, login, page creation | 画面と保存が正常 / UI and persistence work |
| `/api.php?action=session` | JSON応答 / JSON response |
| `/app-css.php`、`/app-js.php` | CSS・JavaScript応答 / CSS and JavaScript responses |
| `/.env`、`/.user.ini`、`/storage/`、`/database/`、`/app/`、`/plugins/`、`/docs/` | 403または404、内容を表示しない / 403 or 404, no contents disclosed |
| `/unknown.php` | 403または404 / 403 or 404 |
| `/published/<slug>/` | 発行済みの場合のみ静的HTML / Static HTML only for a published site |
| `/published/<slug>/.openconcept-publication.json`、`/published/<slug>/example.php` | 403または404 / 403 or 404 |
| 標準RAG / Standard RAG | PostgreSQL・vector・Embeddingが正常、索引生成完了、権限に沿った検索結果 / Healthy PostgreSQL, vector, and embedding; completed index and permission-aware retrieval |

DB、添付、`storage/`内の鍵・Adapter状態、発行済みサイトと署名鍵を同じ復旧単位で保全します。更新時は新規配布物でこれらを上書き・削除せず、他プロジェクトとのコピー・共有やGit追跡を避けます。配布物の検証は設置先固有の設定・データを追加する前に`DISTRIBUTION-MANIFEST.json`で行います。

Preserve databases, uploads, keys and adapter state in `storage/`, and published sites with signing keys as one recovery unit. Retain these across updates; do not copy or share them with other projects or track them in Git. Verify the distribution against `DISTRIBUTION-MANIFEST.json` before adding deployment-specific configuration and runtime data.

操作方法 / User operations: [日本語操作説明書 / Japanese operation manual](docs/openconcept-operation-manual.ja.md).

V2.6.0の履歴画面は `history.php` を使用します。既存NginxのPHP許可リストへ同梱の `docs/nginx.conf` と同様に追加してください。

The v2.6.0 history view uses `history.php`. Add it to an existing Nginx PHP allowlist as shown in the bundled `docs/nginx.conf`.
