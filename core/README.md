# 本体のバージョン別配布 / Versioned Application Distributions

本体の公開配布物を`core/<version>/`に保持します。各バージョンには、設置説明書、ZIP、ZIPのSHA-256、manifest付きの展開済みパッケージを含めます。

Application distributions are stored under `core/<version>/`. Each version contains a setup guide, ZIP, ZIP checksum, and unpacked package with a file manifest.

| Version | 設置説明書 / Setup guide | ZIP | SHA-256 |
| --- | --- | --- | --- |
| 2.3.0 | [日本語・English](2.3.0/README.md) | [Download](2.3.0/OpenConcept-2.3.0-public.zip) | [Checksum](2.3.0/OpenConcept-2.3.0-public.zip.sha256) |

新しいバージョンは新しいフォルダーへ追加し、この一覧と[リポジトリ先頭の一覧](../README.md)を更新します。公開済みバージョンの内容変更が必要な場合は、新しい修正版のバージョンとして配布します。

Add each new version in a new directory and update this list and the [repository's main list](../README.md). Changes to a published version should be distributed as a new patch version.
