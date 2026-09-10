# OpenConcept 2.6.0

最新版 / Latest: **v2.6.0**

| 用途 / Purpose | ダウンロード / Download | SHA-256 | 内容・手順 / Contents and instructions |
| --- | --- | --- | --- |
| 新規設置 / New installation | [OpenConcept 2.6.0 ZIP](OpenConcept-2.6.0-public.zip) | [Checksum](OpenConcept-2.6.0-public.zip.sha256) | [展開済み / Unpacked](OpenConcept-2.6.0-public/) |
| V2.5.0から更新 / Upgrade from V2.5.0 | [Upgrade ZIP](OpenConcept-2.6.0-upgrade-from-2.5.0.zip) | [Checksum](OpenConcept-2.6.0-upgrade-from-2.5.0.zip.sha256) | [更新手順 / Instructions](OpenConcept-2.6.0-upgrade-from-2.5.0/README.ja-en.md) |
| 図面管理 / Drawing Manager 0.9.2 | [Package](../../plugins/packages/drawing-manager/0.9.2/drawing-manager-0.9.2.oc-plugin.json) | [Checksum](../../plugins/packages/drawing-manager/0.9.2/drawing-manager-0.9.2.oc-plugin.json.sha256) | [導入・更新 / Installation and update](../../plugins/packages/drawing-manager/0.9.2/README.md) |

## 設置・更新 / Installation and upgrade

新規設置は[HTTP/HTTPSサーバー配置・必要環境（日本語・English）](OpenConcept-2.6.0-public/HTTP-SERVER-SETUP.ja-en.md)に従い、配布物一式を配置してください。通常のDocument Rootはアプリ内の`public/`です。

For a new installation, deploy the complete distribution following the [HTTP/HTTPS server layout and requirements (Japanese and English)](OpenConcept-2.6.0-public/HTTP-SERVER-SETUP.ja-en.md). The normal document root is the application's `public/` directory.

V2.5.0からの更新は、既存環境全体をバックアップして書き込み・ワーカーを停止し、[同梱の更新手順](OpenConcept-2.6.0-upgrade-from-2.5.0/README.ja-en.md)に従って`files/`を既存アプリルートへ結合します。Coreスキーマ世代6を維持し、DBスキーマ変更はありません。`reference/compose.rag.yaml`は手動比較・結合用です。NginxのPHP許可リストを使用している場合は`history.php`を追加してください。

To upgrade from V2.5.0, back up the complete environment, pause writers and workers, and merge `files/` into the existing application root following the [included upgrade instructions](OpenConcept-2.6.0-upgrade-from-2.5.0/README.ja-en.md). Core schema generation 6 remains unchanged. Compare and merge `reference/compose.rag.yaml` manually. Add `history.php` if using an Nginx PHP allowlist.

この更新版はV2.5.0向けです。V2.4.1以前の環境は対応する更新を順に適用してください。[2.4.1から2.5.0への更新版](../2.5.0/README.md)も引き続き掲載します。

This upgrade targets V2.5.0. Earlier installations must apply the corresponding intermediate upgrades in sequence. The [upgrade from 2.4.1 to 2.5.0](../2.5.0/README.md) remains available.

## V2.6.0の変更 / Changes in V2.6.0

発言・訂正・判断の履歴、共通JSON会話取込、旧添付を含む正本出力と隔離SQLite復元を追加しました。詳しくは[リリースノート](OpenConcept-2.6.0-public/docs/release-notes-2.6.0.md)、[記録と履歴の仕様](OpenConcept-2.6.0-public/docs/knowledge-history-2.6.0.md)、[日本語操作説明書](OpenConcept-2.6.0-public/docs/openconcept-operation-manual.ja.md)をご覧ください。

This release adds records and history, append-only corrections, common-format conversation imports, and portable exports including retained attachments with verified isolated SQLite restoration. See the [release notes](OpenConcept-2.6.0-public/docs/release-notes-2.6.0.md), [records and history specification](OpenConcept-2.6.0-public/docs/knowledge-history-2.6.0.md), and [Japanese operation manual](OpenConcept-2.6.0-public/docs/openconcept-operation-manual.ja.md).

旧添付とゴミ箱の保持により保存容量が増加します。根拠不足時の一般知識への自動切替は停止します。クラウド会話は共通JSON形式への変換が必要です。正本アーカイブは稼働環境の資格情報を含まず、既存DBへの上書き復元は行いません。環境全体のバックアップも継続してください。

Storage usage grows as old attachments and trashed pages are retained. AI answers no longer automatically fall back to general knowledge when evidence is insufficient. Cloud conversations require conversion to the common JSON format. Portable archives omit runtime credentials and restore only into a new isolated destination. Continue backing up the complete environment.

図面管理0.9.2は別配布で、本体V2.6.0でも利用できます。最低対応版は更新済みの本体V2.5.0とPlugin API 1.0.0のままです。

Drawing Manager 0.9.2 is distributed separately and supports V2.6.0. Its minimum requirements remain the updated V2.5.0 application and Plugin API 1.0.0.

## 配布物の検証 / Distribution verification

ZIPは隣接する`.sha256`で、展開済みファイルは[本体manifest](OpenConcept-2.6.0-public/DISTRIBUTION-MANIFEST.json)または[更新manifest](OpenConcept-2.6.0-upgrade-from-2.5.0/UPGRADE-MANIFEST.json)で検証できます。配布物はOpenConceptプロジェクトの`Dist/`から内容を変更せずに配置しています。

Verify ZIP archives with their adjacent `.sha256` files and unpacked files with the [application manifest](OpenConcept-2.6.0-public/DISTRIBUTION-MANIFEST.json) or [upgrade manifest](OpenConcept-2.6.0-upgrade-from-2.5.0/UPGRADE-MANIFEST.json). Distribution contents are copied unchanged from the OpenConcept project's `Dist/` directory.
