# OpenConcept 2.6.0 通常版・更新版 / Full Release and Upgrade

通常版と **V2.5.0 → V2.6.0** の更新版を継続掲載しています。新規設置には最新版の [OpenConcept 2.6.1](../2.6.1/README.md) を使用してください。V2.6.0が必要な場合は、以下の通常版を使用できます。

The full V2.6.0 release and the **V2.5.0 → V2.6.0** upgrade remain available. Use [OpenConcept 2.6.1](../2.6.1/README.md) for new installations. The full release below is available for installations requiring V2.6.0.

| 用途 / Purpose | ZIP | SHA-256 | 手順 / Instructions |
| --- | --- | --- | --- |
| V2.6.0通常版 / Full release | [Download](OpenConcept-2.6.0-public.zip) | [Checksum](OpenConcept-2.6.0-public.zip.sha256) | [設置手順 / Installation](OpenConcept-2.6.0-public/HTTP-SERVER-SETUP.ja-en.md) |
| V2.5.0から更新 / Upgrade from V2.5.0 | [Download](OpenConcept-2.6.0-upgrade-from-2.5.0.zip) | [Checksum](OpenConcept-2.6.0-upgrade-from-2.5.0.zip.sha256) | [更新手順](OpenConcept-2.6.0-upgrade-from-2.5.0/README.ja-en.md) |

環境全体をバックアップし、書き込み・ワーカーを停止して、同梱の手順に従い `files/` を既存アプリルートへ結合します。Coreスキーマ世代6を維持します。Dockerのcomposeは手動比較用です。NginxのPHP許可リストを使用している場合は `history.php` を追加してください。

V2.6.0への更新を確認したら、[V2.6.1への更新版](../2.6.1/README.md)を適用します。V2.4.1からは先に[2.5.0へ更新](../2.5.0/README.md)します。

Back up the environment, pause writers and workers, and merge `files/` following the included instructions. Core schema generation 6 is retained. Review Docker compose changes manually and add `history.php` when using an Nginx PHP allowlist. Verify V2.6.0, then apply the [V2.6.1 upgrade](../2.6.1/README.md). V2.4.1 installations first require [V2.5.0](../2.5.0/README.md).

V2.6.0では記録と履歴、会話取込、旧添付を含む正本出力と隔離SQLite復元を追加しました。[リリースノート / Release notes](OpenConcept-2.6.0-upgrade-from-2.5.0/files/docs/release-notes-2.6.0.md) · [記録と履歴の仕様 / Records and history](OpenConcept-2.6.0-upgrade-from-2.5.0/files/docs/knowledge-history-2.6.0.md)

配布物は `Dist/` から変更せずに配置しています。ZIPは隣接する `.sha256`、展開済みファイルは [UPGRADE-MANIFEST.json](OpenConcept-2.6.0-upgrade-from-2.5.0/UPGRADE-MANIFEST.json) で検証できます。

Packages are copied unchanged from `Dist/`. Verify the ZIP with its adjacent `.sha256` file and unpacked contents with the upgrade manifest.
