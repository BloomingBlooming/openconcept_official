# OpenConcept 2.3.3 → 2.4.0 アップグレード

このパッケージは、稼働中のOpenConcept **2.3.3を2.4.0へ更新するアップグレードパッケージ**です。以前の2.3.3用修正ZIPを適用済みの環境も対象です。新規インストールや、2.3.3以外からの更新には使用できません。

2.4.0では、太枠の「共通API KEY」と、翻訳・Embedding・Custom RAG・個別回答接続の「共通APIキーを使用する」を追加しました。キーや接続先を切り替えても個別キーを暗号化したまま保持し、対応する設定へ戻して再利用できます。削除する場合だけ削除チェックを入れて保存します。共通キー使用中も個別キーだけを削除でき、空欄や認証なしへの切り替えでは削除しません。

共通AI接続全体の利用は、接続先・モデル・キーを共通にします。個別接続先・モデルを選んだうえで共通APIキーだけを使うこともできます。各サービスが対応するAPIキーを選んでください。保存前のキー入力は再描画・接続テスト・保存失敗後も保持します。

設定タブは横並びになり、キー選択後のスクロール位置を保持します。「通常のAI検索（LIKE検索など）」が要約・キーワード・本文を回答根拠として利用すること、MySQLでは全文検索も併用することを明示しました。中間データ生成にはPostgreSQLは不要です。標準ベクトル検索には別途PostgreSQL・pgvector・Embedding接続と、インデックスの構築・有効化が必要です。設定保存と検索準備の状態を分け、接続や再構築の失敗時は待機理由を表示します。

## パッケージの使い分け

| 内容 | 用途 |
| --- | --- |
| `files/` | この中身だけを、既存アプリルートへ転送します。 |
| `reference/compose.rag.yaml` | Docker利用者向けの比較用設定です。既存設定への自動上書き用ではありません。 |
| `UPGRADE-MANIFEST.json` | 対象バージョン、変更対象、変更前後のハッシュ、同梱ファイルの検証情報です。 |
| `LICENSE`、`LICENSE.*` | 利用許諾条件です。 |

`files/`はアプリルートと同じ階層構造です。`files/public/`の中身だけをアップロードしたり、設置先に新しい`files/`フォルダーを作ったりしないでください。既存の`app/`、`assets/`、`locales/`、`public/`などへ結合します。

## 保持するデータと設定

更新用の`files/`には、次のものを含めていません。

- `storage/`、`private/`などの非公開データ、DB実体、添付、資格情報鍵、接続先の状態
- `.env`などの設置先固有の環境設定
- `.htaccess`、`.user.ini`、Docker構成などのサーバー設定
- `published/`や`public/published/`内の発行済みサイト
- 利用者が追加したプラグインや、別途導入した図面管理プラグインのデータ

既存のファイルを残し、同名の更新ファイルだけ上書きします。このパッケージによるファイル削除や標準DBの構造変更はありません。登録済みの操作説明書ページは置き換えず、同梱のMarkdown操作説明書を更新します。キーの新しい保存形式は設置先で管理し、既存の`storage/`と暗号化鍵をそのまま使用します。

## 更新手順

1. **更新元を確認します。** 設置先の`VERSION`が`2.3.3`であることを確認してください。2.3.3用のRAG・APIキー・設定画面の修正ZIPを適用済みでも更新できます。独自に変更したアプリのPHP・JavaScript・CSS・言語ファイルがある場合は、更新対象との違いを先に確認して変更を統合します。
2. **利用とバックグラウンド処理を停止し、バックアップします。** 利用者の編集、RAG worker、cronなどを止め、Webアクセスをメンテナンスに切り替えてから、DB、`storage/`、`private/`、`.env`、公開サイト、導入済みプラグイン、サーバー設定と旧アプリ一式を非公開の保存先へ保全します。MySQL/PostgreSQL利用時は外部DBのバックアップも必要です。書込み中のSQLiteファイルを単独でコピーしてバックアップにしないでください。
3. **ZIPを手元で検証・展開します。** ZIPのSHA-256を隣接する`.zip.sha256`と照合します。展開先は新しい作業フォルダーにし、既存サーバーのフォルダーを展開先として丸ごと置き換えないでください。
4. **`files/`の中身を既存アプリルートへ転送します。** 同名フォルダーは結合し、同名ファイルは上書きします。転送元にないファイルを削除する同期、フォルダーの削除後の再転送は使用しません。`VERSION`はほかのファイルの転送完了後、最後に更新してください。既存の所有者・アクセス権を保ちます。
5. **Docker利用時だけ設定を確認します。** `reference/compose.rag.yaml`と既存構成を比較し、必要な2.4.0のイメージタグ変更を統合して、更新コードを使うイメージを再ビルド・再作成します。既存の接続先、秘密値、マウント先を保ち、永続volumeは削除しません。通常のPHPレンタルサーバーではこの手順は不要です。
6. **更新後を確認します。** 全ファイルが転送されたことを確認して、必要に応じてPHPのOPcacheを更新します。まず管理者だけがアクセスできる状態でブラウザーを再読み込みし、2.4.0の表示、既存アカウントでのログイン、既存ページ・翻訳・添付の表示を確認します。横並びの設定タブ、共通・個別キーの保存状態、選択中のAI検索経路を確認し、保存と参照元付きのAI回答も試してから通常のアクセスとバックグラウンド処理を再開します。

更新後に初期セットアップ画面が出た場合は、初期登録を実行せず、元の`storage/`やDB接続設定を参照しているか確認してください。失敗した場合は利用を止めたまま、途中の更新ファイルを残さず旧コードを復元します。データの復元が必要なときは、DB・添付・鍵・設定を整合したバックアップから戻します。

従来のWAF設定は保持します。必要な環境だけで「WAF誤検知を回避」を使用してください。更新後のキー選択と検索操作の詳細は`files/docs/openconcept-operation-manual.ja.md`を参照してください。

## English instructions

This is an **upgrade from OpenConcept 2.3.3 to 2.4.0 only**, including 2.3.3 installations with earlier fix ZIPs applied. It is not a new installation package or an upgrade from other versions.

Version 2.4.0 adds a prominent **Common API KEY** field and explicit common-key selection for translation, embedding, Custom RAG, and individual answer connections. Saved individual keys survive source, connection, and authentication changes. Delete them only by selecting their deletion checkbox and saving. You can delete an individual key while using the common key without deleting the common key. Blank input or selecting no authentication does not delete a saved key.

Using the entire common AI connection shares its endpoint, model, and key. Individual connections can instead keep their own endpoint and model while selecting only the common key; their service must accept that key. Pending credentials survive re-rendering, connection tests, and failed saves. Horizontal settings tabs and scroll retention keep related guidance visible.

Regular AI Search uses summaries, keywords, and content as evidence through LIKE queries, with additional full-text search on MySQL. Intermediate data generation does not require PostgreSQL. Standard vector search additionally requires PostgreSQL, pgvector, an embedding connection, and a built and activated index. Settings can be saved even while retrieval awaits its connection or index rebuild; the UI reports that waiting state separately.

Upload **only the contents of `files/` into the existing application root**, merging directories and overwriting matching files. Preserve files absent from this package. Do not upload just `files/public/`, create a new `files/` directory on the server, replace whole directories, or use synchronization that deletes destination-only files.

The upload payload excludes `storage/`, `private/`, live databases, uploads, keys, `.env` and other environment settings, `.htaccess`, `.user.ini`, generated sites, and server configuration. Keep user-installed plugins and their data. This upgrade does not delete files, change the standard database schema, or replace existing manual pages. Continue using the installation's existing encrypted-key storage and review local code changes before overwriting files.

1. Confirm that the installed `VERSION` is `2.3.3`. Earlier 2.3.3 RAG, API-key, and settings fix ZIPs may already be installed.
2. Stop user access and background writes, then back up the database, runtime data, environment and server settings, plugins, published sites, and old application code to private storage. Include the external database for MySQL/PostgreSQL. Do not copy only an actively written SQLite database file as a backup.
3. Verify the ZIP against its `.zip.sha256` and extract it into a separate working folder.
4. Merge the contents of `files/` into the existing application root. Preserve ownership and permissions. Transfer `VERSION` last, after every other file is complete.
5. Docker users should compare `reference/compose.rag.yaml`, merge the required image tag changes, and rebuild/recreate their application images with the updated code. Preserve credentials, connection settings, mounts, and persistent volumes. This step is unnecessary for ordinary PHP hosting.
6. Refresh PHP OPcache if required and reload the browser. With access initially limited to the administrator, verify version 2.4.0, existing login, pages, translations, attachments, and saving. Check the horizontal settings tabs, common and individual key state, selected search route, and an AI answer with sources. Then restore normal access and background processing.

If the initial setup screen appears, do not initialize a new workspace: check the original storage location and database connection. If recovery is needed, keep the site offline, restore the old code without leaving partially updated files, and restore a consistent set of database, uploads, keys, and settings when data restoration is required.

Existing WAF settings are preserved. Use **Avoid WAF false positives** only where needed. See `files/docs/openconcept-operation-manual.ja.md` for the updated key-selection and search instructions.

OpenConcept is distributed under the terms in [LICENSE](LICENSE), with [Japanese](LICENSE.ja.md) and [English](LICENSE.en.md) editions included.
