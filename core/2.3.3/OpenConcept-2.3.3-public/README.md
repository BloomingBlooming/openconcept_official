# OpenConcept 2.3.3 公開配布版

このフォルダーは、不特定多数への配布を目的としたOpenConceptの実行パッケージです。個別環境のホスト名、Database接続情報、APIキー、メールアカウント、SSH設定、利用者データ、ログ、添付ファイルは含まれていません。

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
