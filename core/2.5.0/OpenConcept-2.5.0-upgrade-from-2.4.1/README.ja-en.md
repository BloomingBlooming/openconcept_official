# OpenConcept 2.4.1 → 2.5.0 アップグレード

このパッケージは、既存の **OpenConcept 2.4.1を2.5.0へ更新**します。新規設置には公開版を使用します。2.4.0以前の環境には、それぞれ対応する更新を順番に適用・検証してから使用してください。

2.5.0では共通プラグインAPI、AIファイル読み取りプラグイン、アプリ更新通知を追加します。共通APIには原本取得、イベント、ジョブ、検索用本文、受信ボックスの通知アクション・モーダル、正本読み取り専用スナップショットが含まれます。正本をSQL・テキストへ整形するバックアッププラグインや復元機能は、この配布物の機能には含みません。

| 同梱内容 | 適用方法 |
| --- | --- |
| `files/` | 中身だけを既存アプリルートへ結合します。 |
| `reference/compose.rag.yaml` | Docker利用時の手動比較用です。既存設定を自動上書きしません。 |
| `UPGRADE-MANIFEST.json` | 対象版、変更前後のハッシュ、追加・置換対象、Schema移行の明示情報です。 |
| `LICENSE`、`LICENSE.*` | 利用許諾条件です。 |

## 保持するものとSchema移行

`files/`には、実DB、`storage/`、`private/`、添付、暗号化鍵、保存済みAPIキー、`.env`、サーバー設定、公開済みサイトを含めません。更新対象にないファイルを削除せず、既存DB接続、利用者、ページ、履歴、添付、プラグインの有効・無効設定を維持します。既存の操作説明書ページを配布Markdownで置換しません。

**この更新はCore Schema 5から6への追加移行を含みます。** 新しいSchema artifactと本体の`CoreSchemaRuntime`が、既存の正本Schemaを検証したうえで次の4テーブルを追加し、検証後に世代マーカーを更新します。

- `extension_records`
- `extension_jobs`
- `extension_events`
- `extension_search_content`

既存の正本テーブルを削除・再作成する更新ではありません。SQLiteが標準であり、選択済みのMySQL／PostgreSQLでも同じ論理追加を既存Adapter経路で行います。DBの選択先を変更しません。Schema不一致、接続・権限不足、途中状態の問題がある場合は停止し、別DBへの切り替えや無検証の補修を行いません。過去の`core-v3`・`core-v4`・`core-v5` artifactとsealも保持してください。

新しい`plugins/ai-file-reader/`は必要ファイル一式を追加します。初期状態は無効です。既存プラグインを削除・置換せず、既存の有効・無効状態を引き継ぎます。同名プラグインを独自に導入済みの場合は、新規追加とみなして上書きせず、保存済みコードと設定を比較して統合してください。AI読み取りを利用する場合は、更新検証後に設定と送信先を確認して有効化します。

## 更新手順

1. 設置先の`VERSION`が`2.4.1`であることを確認し、`UPGRADE-MANIFEST.json`の対象と変更前ハッシュを比較します。独自改変や別途更新したファイルは先に統合します。
2. 利用者のアクセス・編集、RAG worker、cronなどを停止し、Webをメンテナンス状態にします。旧コード、実DB、添付、`storage/`、`private/`、鍵、環境・サーバー設定、公開サイト、導入済みプラグインを、同じ時点の復元可能な一式として非公開領域へバックアップします。外部DBも対象です。書き込み中のSQLite単体コピーは使用しません。
3. ZIPを隣接する`.zip.sha256`と照合し、別の作業フォルダーへ展開します。
4. **`files/`の中身だけ**を既存アプリルートへ結合します。`files/public/`だけの転送、ディレクトリ丸ごとの置換、転送元にないファイルの削除は行いません。新しい本体PHP、共通APIのJS配信入口、`assets/`・`public/assets/`の両方、翻訳、新プラグイン、`database/schema/core-v6.json`と対応する`.sha256`をすべて転送します。`VERSION`は最後に更新し、既存の所有者と権限を保持します。
5. Docker利用者は`reference/compose.rag.yaml`を既存設定と手動比較し、更新コードでイメージを再ビルド・再作成します。接続先、秘密値、マウント、永続volumeを維持します。通常のPHPホスティングではこの手順は不要です。
6. すべてのファイル転送後、PHP-FPMなど利用中のPHPプロセスを再起動し、OPcacheの旧コードを更新します。ブラウザーの全タブを再読み込みします。
7. 管理者だけで起動し、既存正本のSchema 5→6移行と検証を完了させます。管理者ログイン、2.5.0の版表示、既存ページ・翻訳・添付・履歴、保存、既存プラグインの有効状態を確認します。受信ボックスと共通モーダルを確認し、新しいAIファイル読み取りは送信先・設定を確認後に有効化して試します。
8. 検証後に通常アクセスとバックグラウンド処理を再開します。

初期セットアップ画面が出た場合は新規登録せず、元のDB接続と保存先を確認してください。移行に失敗した場合も、世代マーカーやSchemaファイルを手動変更して回避しません。

**Schema 6へ進んだDBに旧2.4.1コードだけを戻して稼働させないでください。** 戻す必要がある場合はアクセスと書き込みを停止し、旧コードと移行前のDB・添付・鍵・設定を、同じ時点のバックアップから一体で復元してPHPを再起動します。未検証の新旧コード混在や、元の正本とは別DBへの自動退避は行いません。

正本読み取り専用スナップショットは管理者権限が必要で、正本の固定コピーと添付を分割取得する契約です。環境設定・秘密鍵を含む全環境復旧バックアップの代わりにはなりません。既存の一体バックアップを継続してください。

`database/mysql.sql`と`database/postgresql.sql`は新規設置用のDDLテンプレートとして更新します。アップグレード時にこのSQLファイルを実行しないでください。既存DBの移行は本体の検証済みSchema移行経路が行います。

正本スナップショットの原本範囲は現在の`files`テーブルが参照する版です。差し替え前の版と復旧用に保持する旧原本は含まず、`original_file_version_scope=current_files_table_only`と除外情報で明示します。全履歴を含む復旧用バックアップは別に保持してください。

## English instructions

This package upgrades an existing **OpenConcept 2.4.1 installation to 2.5.0**. Use the public distribution for a new installation. Earlier releases must first apply and verify their supported upgrade steps.

Version 2.5.0 adds the common plugin API, AI File Reader, and application update notifications. The API includes original access, events, jobs, search content, inbox actions and modals, and read-only canonical snapshots. A plugin that formats backups as SQL/text and a restore implementation are outside this release.

Merge **only the contents of `files/` into the existing application root**. Preserve destination-only files and merge matching directories. Do not upload only `files/public/`, replace entire directories, or synchronize with deletion. `reference/compose.rag.yaml` is for manual comparison. Review all before/after hashes and the schema transition in `UPGRADE-MANIFEST.json`.

The payload excludes live databases, runtime storage, private data, uploads, encryption keys, stored API keys, environment/server settings, and generated sites. Existing connection selection, users, pages, history, attachments, and plugin enablement remain in place.

The updated `database/mysql.sql` and `database/postgresql.sql` files are installer DDL templates. Do not execute them during an upgrade. The application's verified schema migration path updates the existing database.

**This release migrates Core Schema 5 to 6**, using the existing verified `CoreSchemaRuntime` path. It adds only `extension_records`, `extension_jobs`, `extension_events`, and `extension_search_content`; existing Core tables are retained. SQLite remains the default. An already selected MySQL/PostgreSQL adapter receives the same logical addition without changing the canonical database selection. Missing permissions or inconsistent schemas stop startup instead of selecting another database or silently repairing it. Retain the immutable predecessor schema artifacts and seals.

The complete `plugins/ai-file-reader/` directory is added and is disabled initially. Existing plugins and enabled states are retained. If a plugin with this ID was installed independently, compare and integrate it before merging; do not overwrite local code blindly. Review the reader configuration and outbound service before enabling it.

1. Confirm installed `VERSION` is `2.4.1`, compare source hashes, and integrate local modifications.
2. Stop user access, edits, workers, cron, and other writers. Back up the old code, database, uploads, runtime/private storage, credentials and keys, configuration, generated sites, and installed plugins as one consistent restorable set in private storage. Include external databases; do not copy only an actively written SQLite file.
3. Verify the ZIP with its `.zip.sha256` and extract into a separate working directory.
4. Merge the payload into the application root, including new PHP/JS entry points, both asset mirrors, translations, the complete new plugin, and `database/schema/core-v6.json` with its `.sha256`. Preserve permissions. Transfer `VERSION` last.
5. Docker users should compare the compose reference and rebuild/recreate images with updated code, preserving connections, secrets, mounts, and persistent volumes.
6. Restart the PHP processes to replace old OPcache code and reload all browser tabs.
7. Allow administrator access first. Complete and verify the Schema 5→6 transition. Check version 2.5.0, existing login, pages, translations, attachments, history, saving, and retained plugin states. Check inbox/modals; configure AI File Reader before enabling and testing it.
8. Resume normal access and background work after verification.

If initial setup appears, do not initialize a new workspace: check the original storage and connection. Do not edit schema markers or artifacts to bypass an upgrade failure. **After Schema 6 migration, reverting only the application code to 2.4.1 is insufficient.** Keep writers stopped and restore the old code together with the pre-upgrade database, attachments, keys, and settings from the same backup point, then restart PHP.

Canonical read snapshots require administrator authorization and provide paginated access to fixed canonical records and original files. They omit environment secrets and are not complete environment recovery backups. Continue the existing consistent recovery backup procedure.

Original-file snapshots include only the current versions referenced by the `files` table. Superseded versions and old recovery blobs are excluded; `original_file_version_scope=current_files_table_only` and the exclusions disclose this limit. Keep a separate recovery backup covering the full retained history.

OpenConcept is distributed under [LICENSE](LICENSE), with [Japanese](LICENSE.ja.md) and [English](LICENSE.en.md) editions included.
