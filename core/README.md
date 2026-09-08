# 本体のバージョン別配布 / Versioned Application Distributions

本体の最新の公開配布物を`core/<version>/`に保持します。配布物には、設置説明書、ZIP、ZIPのSHA-256、manifest付きの展開済みパッケージを含めます。

The current application distribution is stored under `core/<version>/`. It contains a setup guide, ZIP, ZIP checksum, and unpacked package with a file manifest.

| Version | 設置説明書 / Setup guide | ZIP | SHA-256 |
| --- | --- | --- | --- |
| 2.4.1 | [日本語・English](2.4.1/README.md) | [Download](2.4.1/OpenConcept-2.4.1-public.zip) | [Checksum](2.4.1/OpenConcept-2.4.1-public.zip.sha256) |

現在は新規設置用の通常配布版のみを掲載しています。バージョン別のREADMEは、`Dist/README.md`をそのまま配置した配布案内・設置説明書です。

Only the full distribution for new installations is currently listed. The version-specific README is copied unchanged from `Dist/README.md` and contains download information and the setup guide.

更新時はOpenConceptプロジェクトの`Dist/`以下から、手動または管理者が指示したバージョンの配布物を取得します。対象バージョンのフォルダーへ配置して旧配布物を置き換え、この一覧と[リポジトリ先頭の一覧](../README.md)を更新します。このリポジトリで配布物を直接修正したり、旧版を保管したりしません。

For updates, copy distributions manually, or at the version requested by the maintainer, from the OpenConcept project's `Dist/` folder. Place them in the matching version directory, replace superseded distributions, and update this list and the [repository's main list](../README.md). Do not edit distribution contents directly or retain old versions in this repository.
