# OpenConcept 3.0.0 直接アップグレード / Direct upgrade

この配布物は、ファイル名と `UPGRADE-MANIFEST.json` の `from_version` に一致する既存公開版を、**直接3.0.0へ更新**するための差分です。2.4.1、2.5.0、2.6.0、2.6.1の各元版に対応する4種類を用意しています。中間版の順次適用は不要です。新規設置には3.0.0の公開版を使用してください。

2.4.1用ZIPは、初版と同時初回起動の修正版の両方に対応します。`source_public_manifest_sha256s` に検証済みの2種類の配布manifestを記録し、各変更の `accepted_from` に元配布物ごとの変更前ハッシュとサイズを記録しています。未知の独自改修を許可する指定ではありません。

3.0.0は2.6.1以降の累積変更をまとめています。履歴と検索、画面言語、管理者の送信メール設定、多言語の招待・ログイン・初回パスワード変更、プロフィール写真の軽量化、固定エラー・通知・出力の5言語対応を含みます。日本語を含む利用者の本文、タイトル、氏名、分類、ファイル名を翻訳して書き換える更新ではありません。

## 対象と移行

| 設置済みVERSION | 使用する配布物 | Core Schema |
| --- | --- | --- |
| 2.4.1 | `OpenConcept-3.0.0-upgrade-from-2.4.1.zip` | 5→6の検証済み追加移行 |
| 2.5.0 | `OpenConcept-3.0.0-upgrade-from-2.5.0.zip` | 6を維持 |
| 2.6.0 | `OpenConcept-3.0.0-upgrade-from-2.6.0.zip` | 6を維持 |
| 2.6.1 | `OpenConcept-3.0.0-upgrade-from-2.6.1.zip` | 6を維持 |

2.4.1からの移行では、既存の `CoreSchemaRuntime` が正本を検証し、`extension_records`、`extension_jobs`、`extension_events`、`extension_search_content` の4テーブルだけを追加します。既存の正本テーブルとSchema 3・4・5のartifact/sealを保持します。2.5.0以降はSchema 6のままです。SQLiteが標準であり、設定済みのDB接続先とAdapterの選択を維持します。接続・権限・Schemaに問題がある場合は停止します。Schemaマーカーを書き換えて回避しないでください。

## 保持するもの

`files/` は必要なアプリファイルだけの差分です。`.env`、実DB、`storage/`、`private/`、添付、鍵、保存済みAPI・メール設定、サーバー設定、生成済み公開サイトを同梱しません。既存ディレクトリへ結合し、転送元にないファイルを削除しないでください。独自プラグイン、そのデータ、既存の有効・無効設定を保持します。

同梱プラグインの修正もmanifestに列挙しています。更新対象ファイルを独自変更した場合、または同じプラグインを個別に新しい版へ更新済みの場合は、`from_sha256` と比較して先に統合してください。2.4.1には `ai-file-reader` 一式を追加します。新規追加時は無効であり、既存の別途導入済み同名プラグインを無条件に置換するものではありません。元版を問わず、送信先・保存済み秘密値・既存プラグイン設定を変更しません。

`reference/compose.rag.yaml` はDocker構成を手動比較するための参考ファイルです。既存の接続、秘密値、マウント、永続volumeを保持して必要な変更だけを統合します。`database/mysql.sql` と `database/postgresql.sql` は新規設置用のDDLであり、アップグレードのために実行しません。

## 更新手順

1. 設置先の `VERSION` とZIPの元版を一致させ、`UPGRADE-MANIFEST.json` の対象ファイル・変更前ハッシュを確認します。同じ版番号の再配布物や独自改修は、版番号だけで判断せず差分を統合します。
2. 利用者のアクセスと編集、RAG worker、cronなどの書き込みを停止します。旧コード、DB、添付、鍵、設定、プラグイン、公開済みサイトを同じ時点の復元可能な一式として非公開領域にバックアップします。外部DBも対象です。書き込み中のSQLite単体コピーは使用しません。
3. ZIPを付属の `.zip.sha256` と照合し、別の作業フォルダーへ展開します。
4. **`files/` の中身だけを既存アプリルートへ結合**します。`files/public/` だけの転送やディレクトリ全体の置換、同期による削除は行いません。所有者とアクセス権を保持し、両方のassetsミラー、新PHP、翻訳catalog、同梱プラグイン、必要なSchema artifactをすべて転送します。`VERSION` は最後に更新します。
5. Docker利用者はcompose参考ファイルを比較し、更新コードでイメージを再ビルド・再作成します。PHP-FPMなどのプロセスを再起動して古いOPcacheを更新し、ブラウザーの全タブを再読み込みします。NginxのPHP入口を許可リストにしている場合は `docs/nginx.conf` の履歴などの入口を確認します。
6. 管理者だけで起動し、2.4.1の場合はSchema 5→6移行の完了を確認します。3.0.0の版表示、既存ログイン、ページ、翻訳、履歴、添付、保存、プラグインの有効状態を検証します。
7. 送信メール設定を確認し、管理者自身へのテスト送信を行います。5言語のログインと初回パスワード変更、招待、通知、プロフィール写真、出力を確認します。保存済み写真はそのまま保持され、新規アップロードから軽量化が適用されます。新しいプラグインは設定と送信先の確認後に有効化します。
8. 検証後に通常アクセスとバックグラウンド処理を再開します。

初期セットアップ画面が出た場合は初期化せず、元の保存先とDB接続を確認してください。復旧時は書き込みを停止したまま、旧コードと同じ時点のDB・添付・鍵・設定を一体で復元してPHPを再起動します。特にSchema 6移行後のDBへ2.4.1のコードだけを戻して稼働させないでください。

履歴の保持により保存容量が増える場合があります。正本の可搬アーカイブや出力は、秘密値を含む環境全体の復旧バックアップの代わりにはなりません。

## English instructions

Choose the ZIP whose `from_version` matches the installed **2.4.1, 2.5.0, 2.6.0 or 2.6.1** release. Each package upgrades directly to **3.0.0**; intermediate updates are unnecessary. Use the public distribution for a new installation. Review before-hashes for customized or rebuilt releases, even when VERSION matches.

The 2.4.1 ZIP supports both the original release and its concurrent-first-start correction. `source_public_manifest_sha256s` pins both reviewed distributions, and each change's `accepted_from` lists their corresponding before-hashes and sizes. Unknown local modifications still require review and integration.

3.0.0 consolidates changes since 2.6.1: history and search improvements, configurable outgoing mail, multilingual invitations and authentication, first-login password changes, optimized profile uploads, and localized system messages, notifications and exports. Human names, titles, content, categories and filenames remain unchanged.

Only the 2.4.1 package performs the existing verified Core Schema 5→6 migration, adding `extension_records`, `extension_jobs`, `extension_events` and `extension_search_content`. Existing tables and predecessor artifacts remain intact. All other supported bases retain Schema 6. Preserve the configured database/adapter; invalid schema, permissions or connection state must be resolved before restarting. Do not execute the installer SQL files during upgrade.

1. Verify installed VERSION and manifest before-hashes; integrate local customizations first.
2. Stop access and all writers. Back up code, databases, uploads, runtime/private storage, secrets, keys, settings, plugins and generated sites together at one consistent recovery point. Include external databases.
3. Verify the ZIP checksum and extract separately.
4. Merge **the contents of `files/` into the existing application root**, retaining destination-only files and permissions. Include both asset mirrors, all new PHP and locale dependencies, reviewed bundled plugin files and any required schema artifacts. Transfer VERSION last. Do not synchronize with deletion or upload only `files/public/`.
5. Compare the compose reference manually for Docker and rebuild images while preserving secrets, mounts and volumes. Restart PHP to clear old OPcache and reload browser tabs. Review PHP entry-point allowlists against `docs/nginx.conf` when applicable.
6. Verify startup, migration when required, version 3.0.0, existing login/content/translations/history/files, editing and retained plugin states with administrator-only access.
7. Check outgoing mail settings and send a test to the administrator. Verify five-language authentication, invitations, notifications, exports and profile uploads. Existing photos are preserved; optimization applies to new uploads. Review service configuration before enabling new plugins.
8. Resume normal access and background processing after verification.

The payload excludes live configuration, databases, uploads, keys and generated sites. Bundled plugin changes are explicitly listed; merge separately updated or customized code before applying them. The newly added AI File Reader for 2.4.1 is disabled initially. Existing plugin settings and private data remain in place.

If initial setup appears, do not create a new workspace; restore the original database/storage configuration. For rollback, keep writers stopped and restore the complete old code and matching database/data/settings backup together. After Schema 6 migration, do not roll back to 2.4.1 code alone. Knowledge exports omit credentials and do not replace complete recovery backups.

OpenConcept is distributed under [LICENSE](LICENSE), with [Japanese](LICENSE.ja.md) and [English](LICENSE.en.md) editions included.
