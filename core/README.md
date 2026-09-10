# 本体のバージョン別配布 / Versioned Application Distributions

本体の公開配布物を`core/<version>/`に保持します。最新版は2.6.0で、2.4.1から2.5.0への更新版も引き続き掲載します。配布物には、設置・更新説明書、ZIP、ZIPのSHA-256、manifest付きの展開済みパッケージを含めます。

Application distributions are stored under `core/<version>/`. The latest version is 2.6.0; the upgrade from 2.4.1 to 2.5.0 also remains available. Each contains an installation or upgrade guide, ZIP, ZIP checksum, and unpacked package with a file manifest.

| Version | 設置説明書 / Setup guide | ZIP | SHA-256 |
| --- | --- | --- | --- |
| 2.6.0（新規設置 / New installation） | [日本語・English](2.6.0/README.md) | [Download](2.6.0/OpenConcept-2.6.0-public.zip) | [Checksum](2.6.0/OpenConcept-2.6.0-public.zip.sha256) |
| 2.5.0 → 2.6.0（更新 / Upgrade） | [更新手順 / Instructions](2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0/README.ja-en.md) | [Download](2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0.zip) | [Checksum](2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0.zip.sha256) |
| 2.4.1 → 2.5.0（継続掲載・更新 / Retained upgrade） | [更新手順 / Instructions](2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1/README.ja-en.md) | [Download](2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1.zip) | [Checksum](2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1.zip.sha256) |

新規設置には最新版2.6.0を使用してください。V2.4.1からは2.5.0、2.6.0の順に更新します。バージョン別のREADMEから、配布物に同梱された設置説明書・更新手順・リリースノートを参照できます。

Use the latest 2.6.0 distribution for new installations. From V2.4.1, upgrade to 2.5.0 and then to 2.6.0. The version-specific README links to the setup guide, upgrade instructions, and release notes included in each distribution.

更新時はOpenConceptプロジェクトの`Dist/`以下から、手動または管理者が指示したバージョンの配布物を取得します。対象バージョンのフォルダーへ配置し、この一覧と[リポジトリ先頭の一覧](../README.md)を更新します。旧配布物は原則として置き換えますが、管理者の指定により2.4.1から2.5.0への更新版は保持します。2.5.0通常配布版は公開用リポジトリ外へ退避しました。このリポジトリで配布物を直接修正しません。

For updates, copy distributions manually, or at the version requested by the maintainer, from the OpenConcept project's `Dist/` folder. Place them in the matching version directory and update this list and the [repository's main list](../README.md). Superseded distributions are normally replaced; the maintainer has requested retaining the upgrade from 2.4.1 to 2.5.0. The full 2.5.0 distribution has been archived outside this public repository. Do not edit distribution contents directly.
