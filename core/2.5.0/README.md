# OpenConcept 2.5.0

最新版 / Latest: **v2.5.0**

| 用途 / Purpose | ダウンロード / Download | SHA-256 | 内容・手順 / Contents and instructions |
| --- | --- | --- | --- |
| 新規設置 / New installation | [OpenConcept 2.5.0 ZIP](OpenConcept-2.5.0-public.zip) | [Checksum](OpenConcept-2.5.0-public.zip.sha256) | [展開済み / Unpacked](OpenConcept-2.5.0-public/) |
| V2.4.1から更新 / Upgrade from V2.4.1 | [Upgrade ZIP](OpenConcept-2.5.0-upgrade-from-2.4.1.zip) | [Checksum](OpenConcept-2.5.0-upgrade-from-2.4.1.zip.sha256) | [更新手順 / Instructions](OpenConcept-2.5.0-upgrade-from-2.4.1/README.ja-en.md) |
| 図面管理 / Drawing Manager 0.9.2 | [Package](../../plugins/packages/drawing-manager/0.9.2/drawing-manager-0.9.2.oc-plugin.json) | [Checksum](../../plugins/packages/drawing-manager/0.9.2/drawing-manager-0.9.2.oc-plugin.json.sha256) | [導入・更新 / Installation and update](../../plugins/packages/drawing-manager/0.9.2/README.md) |

## 設置・更新 / Installation and upgrade

新規設置は[HTTP/HTTPSサーバー配置・必要環境（日本語・English）](OpenConcept-2.5.0-public/HTTP-SERVER-SETUP.ja-en.md)に従い、配布物一式を配置してください。

For a new installation, deploy the complete distribution following the [HTTP/HTTPS server layout and requirements (Japanese and English)](OpenConcept-2.5.0-public/HTTP-SERVER-SETUP.ja-en.md).

V2.4.1からの更新は、既存環境全体をバックアップしてから、[同梱の更新手順](OpenConcept-2.5.0-upgrade-from-2.4.1/README.ja-en.md)に従ってください。Core Schema 5から6への移行を含みます。

To upgrade from V2.4.1, back up the complete existing installation and follow the [included upgrade instructions](OpenConcept-2.5.0-upgrade-from-2.4.1/README.ja-en.md). The upgrade includes migration from Core Schema 5 to 6.

更新manifestの変更前ハッシュは初期のV2.4.1配布物が基準です。このリポジトリで公開していたSQLite初期化修正版のV2.4.1では、`app/CoreSchemaRuntime.php`と配布manifestのハッシュが異なります。更新手順に従って差分を比較してください。SQLite初期化の修正はV2.5.0にも含まれ、新規設置版と更新版の更新後ファイルは一致しています。

The upgrade manifest's before hashes use the original V2.4.1 distribution. The refreshed V2.4.1 previously published here, which fixes SQLite initialization, has different hashes for `app/CoreSchemaRuntime.php` and the distribution manifest. Compare the differences as directed by the upgrade guide. V2.5.0 retains the SQLite initialization fix, and the updated files in the full and upgrade distributions match.

## V2.5.0の変更 / Changes in V2.5.0

共通プラグインAPI、AIファイル読み取り、管理者向け更新通知、受信トレイのアーカイブ・ページ送り、正本読み取り専用APIを追加しました。詳細は[リリースノート](OpenConcept-2.5.0-public/docs/release-notes-2.5.0.md)をご覧ください。

This release adds the common Plugin API, AI File Reader, administrator update notifications, inbox archiving and pagination, and a read-only canonical snapshot API. See the [release notes](OpenConcept-2.5.0-public/docs/release-notes-2.5.0.md) for details.

AI File Readerは初期状態では無効です。設定で有効にし、対応するLLM接続を確認してから自動読み取りを開始してください。図面管理0.9.2は別配布で、更新済みの本体2.5.0が必要です。

AI File Reader is disabled by default. Enable it in settings, verify a compatible LLM connection, and then enable automatic reading. Drawing Manager 0.9.2 is distributed separately and requires the updated 2.5.0 application.

ZIP、展開済みファイル、manifest、チェックサムは、OpenConceptプロジェクトの`Dist/`から変更せずに配置しています。

The ZIPs, unpacked files, manifests, and checksums are copied unchanged from the OpenConcept project's `Dist/` folder.

- [本体manifest / Application manifest](OpenConcept-2.5.0-public/DISTRIBUTION-MANIFEST.json)
- [更新manifest / Upgrade manifest](OpenConcept-2.5.0-upgrade-from-2.4.1/UPGRADE-MANIFEST.json)
- [配布一覧 / All downloads](../../README.md)
