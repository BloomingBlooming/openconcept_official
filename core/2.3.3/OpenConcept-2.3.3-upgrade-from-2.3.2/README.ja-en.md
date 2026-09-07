# OpenConcept 2.3.2 → 2.3.3 アップグレード

このパッケージは、稼働中のOpenConcept **2.3.2を2.3.3へ更新する差分パッケージ**です。新規インストールや、2.3.2以外からの更新には使用できません。

2.3.3では、管理者の「設定 > 一般」に「WAF誤検知を回避」を追加しました。既定値はOFFです。有効にすると対象の保存要求をAES-256-GCMで暗号化します。利用にはHTTPS、Web Crypto対応ブラウザー、AES-GCM対応のPHP OpenSSLが必要です。通常の利用で誤検知がなければOFFのまま使用できます。

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

- `storage/`、DB実体、添付、資格情報鍵、接続先の状態
- `.env`などの設置先固有の環境設定
- `.htaccess`、`.user.ini`、Docker構成などのサーバー設定
- `published/`や`public/published/`内の発行済みサイト
- 今回変更のないプラグインや、別途導入した図面管理プラグイン

既存のファイルを残し、同名の更新ファイルだけ上書きします。このパッケージによるファイル削除はありません。2.3.2から2.3.3では、標準DBの構造も変わりません。登録済みの操作説明書ページは置き換えず、同梱のMarkdown操作説明書を更新します。

## 更新手順

1. **更新元を確認します。** 設置先の`VERSION`が`2.3.2`であることを確認してください。独自に変更したアプリのPHP・JavaScript・CSS・言語ファイルがある場合は、更新対象との違いを先に確認して変更を統合します。
2. **利用とバックグラウンド処理を停止し、バックアップします。** 利用者の編集、RAG worker、cronなどを止め、Webアクセスをメンテナンスに切り替えてから、DB、`storage/`、`.env`、公開サイト、導入済みプラグイン、サーバー設定と旧アプリ一式を非公開の保存先へ保全します。MySQL/PostgreSQL利用時は外部DBのバックアップも必要です。書込み中のSQLiteファイルを単独でコピーしてバックアップにしないでください。
3. **ZIPを手元で検証・展開します。** ZIPのSHA-256を隣接する`.zip.sha256`と照合します。展開先は新しい作業フォルダーにし、既存サーバーのフォルダーを展開先として丸ごと置き換えないでください。
4. **`files/`の中身を既存アプリルートへ転送します。** 同名フォルダーは結合し、同名ファイルは上書きします。転送元にないファイルを削除する同期、フォルダーの削除後の再転送は使用しません。`VERSION`はほかのファイルの転送完了後、最後に更新してください。既存の所有者・アクセス権を保ちます。
5. **Docker利用時だけ設定を確認します。** `reference/compose.rag.yaml`と既存構成を比較し、必要な2.3.3のイメージタグ変更を統合して、更新コードを使うイメージを再ビルド・再作成します。既存の接続先、秘密値、マウント先を保ち、永続volumeは削除しません。通常のPHPレンタルサーバーではこの手順は不要です。
6. **更新後を確認します。** 全ファイルが転送されたことを確認して、必要に応じてPHPのOPcacheを更新します。まず管理者だけがアクセスできる状態でブラウザーを再読み込みし、2.3.3の表示、既存アカウントでのログイン、既存ページ・翻訳・添付の表示を確認します。保存も確認した後、通常のアクセスとバックグラウンド処理を再開します。

更新後に初期セットアップ画面が出た場合は、初期登録を実行せず、元の`storage/`やDB接続設定を参照しているか確認してください。失敗した場合は利用を止めたまま、途中の更新ファイルを残さず旧コードを復元します。データの復元が必要なときは、DB・添付・鍵・設定を整合したバックアップから戻します。

WAF対策が必要な環境では更新後に管理者が「WAF誤検知を回避」をONにして保存します。WAFでの通過をすべてのサーバーで保証する機能ではありません。復号後もアプリの入力検証・権限確認・SQL対策を適用します。詳しくは`files/docs/openconcept-operation-manual.ja.md`を参照してください。

## English instructions

This is a **delta upgrade from OpenConcept 2.3.2 to 2.3.3 only**. It is not a new installation package or an upgrade from other versions.

Upload **only the contents of `files/` into the existing application root**, merging directories and overwriting matching files. Preserve files absent from this package. Do not upload just `files/public/`, create a new `files/` directory on the server, replace whole directories, or use synchronization that deletes destination-only files.

The upload payload excludes runtime storage, live databases, uploads, keys, environment settings, `.htaccess`, `.user.ini`, generated sites, server configuration, and unchanged or separately installed plugins. This upgrade does not change the standard database schema or replace existing manual pages. Review and merge local changes to application code before overwriting it.

1. Confirm that the installed `VERSION` is `2.3.2`.
2. Stop user access and background writes, then back up the database, runtime data, environment and server settings, plugins, published sites, and old application code to private storage. Include the external database for MySQL/PostgreSQL. Do not copy only an actively written SQLite database file as a backup.
3. Verify the ZIP against its `.zip.sha256` and extract it into a separate working folder.
4. Merge the contents of `files/` into the existing application root. Preserve ownership and permissions. Transfer `VERSION` last, after every other file is complete.
5. Docker users should compare `reference/compose.rag.yaml`, merge the required image tag changes, and rebuild/recreate their application images with the updated code. Preserve credentials, connection settings, mounts, and persistent volumes. This step is unnecessary for ordinary PHP hosting.
6. Refresh PHP OPcache if required and reload the browser. With access initially limited to the administrator, verify version 2.3.3, existing login, pages, translations, attachments, and saving. Then restore normal access and background processing.

If the initial setup screen appears, do not initialize a new workspace: check the original storage location and database connection. If recovery is needed, keep the site offline, restore the old code without leaving partially updated files, and restore a consistent set of database, uploads, keys, and settings when data restoration is required.

The new **Avoid WAF false positives** option is disabled by default. Enable it under **Settings > General** only if needed. It requires HTTPS, Web Crypto and PHP OpenSSL with AES-GCM. WAF passage is not guaranteed; application validation, authorization and SQL protections still apply after decryption.

OpenConcept is distributed under the terms in [LICENSE](LICENSE), with [Japanese](LICENSE.ja.md) and [English](LICENSE.en.md) editions included.
