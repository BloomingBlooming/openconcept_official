# OpenConcept 2.6.0 — 通常版の配布停止 / Full release withdrawn

V2.6.0通常版は、プラグインID制限の不具合により配布を停止しました。通常版ZIP・チェックサム・展開済みファイルは公開対象から削除しています。

The full V2.6.0 release has been withdrawn because of its plugin ID restriction defect. Its full installation ZIP, checksum and unpacked files are no longer distributed.

新規設置には [V2.6.1通常版](../2.6.1/OpenConcept-2.6.1-public.zip) を使用してください。既存V2.6.0は、以下のV2.6.1への更新版で更新できます。

Use the [V2.6.1 full release](../2.6.1/OpenConcept-2.6.1-public.zip) for new installations. Existing V2.6.0 installations can use the upgrade below.

| 用途 / Purpose | ZIP | SHA-256 | 手順 / Instructions |
| --- | --- | --- | --- |
| V2.6.0 → V2.6.1 | [Upgrade](../2.6.1/OpenConcept-2.6.1-upgrade-from-2.6.0.zip) | [Checksum](../2.6.1/OpenConcept-2.6.1-upgrade-from-2.6.0.zip.sha256) | [更新手順](../2.6.1/OpenConcept-2.6.1-upgrade-from-2.6.0/README.ja-en.md) |
| V2.5.0 → V2.6.0（中間更新のみ / Intermediate step only） | [Upgrade](OpenConcept-2.6.0-upgrade-from-2.5.0.zip) | [Checksum](OpenConcept-2.6.0-upgrade-from-2.5.0.zip.sha256) | [更新手順](OpenConcept-2.6.0-upgrade-from-2.5.0/README.ja-en.md) |

V2.5.0からは中間更新を適用した後、続けてV2.6.1へ更新してください。V2.4.1からは先に [V2.5.0へ更新](../2.5.0/README.md) します。中間更新版は既存環境の移行経路を維持するために掲載しており、V2.6.0での新規設置や継続利用を案内するものではありません。

For V2.5.0, apply the intermediate upgrade and then continue to V2.6.1. V2.4.1 installations first require [V2.5.0](../2.5.0/README.md). The intermediate package is retained solely to preserve the upgrade path.

環境全体をバックアップし、書き込み・ワーカーを停止して、各更新版の手順に従い `files/` を既存アプリルートへ結合します。DB・添付・設定・個別プラグインを保持します。Dockerのcomposeは手動比較用です。

Back up the environment, pause writers and workers, and merge `files/` following each package's instructions. Preserve the database, uploads, settings and standalone plugins; review Docker compose changes manually.
