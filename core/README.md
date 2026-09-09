# 本体のバージョン別配布 / Versioned Application Distributions

本体の最新の公開配布物を`core/<version>/`に保持します。配布物には、設置説明書、ZIP、ZIPのSHA-256、manifest付きの展開済みパッケージを含めます。

The current application distribution is stored under `core/<version>/`. It contains a setup guide, ZIP, ZIP checksum, and unpacked package with a file manifest.

| Version | 設置説明書 / Setup guide | ZIP | SHA-256 |
| --- | --- | --- | --- |
| 2.5.0（新規設置 / New installation） | [日本語・English](2.5.0/README.md) | [Download](2.5.0/OpenConcept-2.5.0-public.zip) | [Checksum](2.5.0/OpenConcept-2.5.0-public.zip.sha256) |
| 2.4.1 → 2.5.0（更新 / Upgrade） | [更新手順 / Instructions](2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1/README.ja-en.md) | [Download](2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1.zip) | [Checksum](2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1.zip.sha256) |

新規設置用の通常配布版とV2.4.1からの更新版を掲載しています。バージョン別のREADMEから、配布物に同梱された設置説明書・更新手順・リリースノートを参照できます。

The full distribution for new installations and the upgrade from V2.4.1 are available. The version-specific README links to the setup guide, upgrade instructions, and release notes included in the distribution.

更新時はOpenConceptプロジェクトの`Dist/`以下から、手動または管理者が指示したバージョンの配布物を取得します。対象バージョンのフォルダーへ配置して旧配布物を置き換え、この一覧と[リポジトリ先頭の一覧](../README.md)を更新します。このリポジトリで配布物を直接修正したり、旧版を保管したりしません。

For updates, copy distributions manually, or at the version requested by the maintainer, from the OpenConcept project's `Dist/` folder. Place them in the matching version directory, replace superseded distributions, and update this list and the [repository's main list](../README.md). Do not edit distribution contents directly or retain old versions in this repository.
