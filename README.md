# OpenConcept 公式配布 / Official Distribution

OpenConcept本体と公式プラグインの公開用フォルダを管理するリポジトリです。交換用の最新の公開配布物のみを置きます。

This repository manages the public distribution folder for OpenConcept and its official plugins, keeping only the current distribution files for replacement.

公式公開先 / Official repository: [BloomingBlooming/openconcept_official](https://github.com/BloomingBlooming/openconcept_official).

## 本体ダウンロード / Application downloads

| バージョン / Version | ダウンロード / Download | 検証値 / Checksum | 設置説明書 / Setup guide |
| --- | --- | --- | --- |
| 2.4.0 | [OpenConcept 2.4.0 ZIP](core/2.4.0/OpenConcept-2.4.0-public.zip) | [SHA-256](core/2.4.0/OpenConcept-2.4.0-public.zip.sha256) | [日本語・English](core/2.4.0/OpenConcept-2.4.0-public/HTTP-SERVER-SETUP.ja-en.md) |
| 2.3.3 → 2.4.0 | [更新用ZIP / Upgrade ZIP](core/2.4.0/OpenConcept-2.4.0-upgrade-from-2.3.3.zip) | [SHA-256](core/2.4.0/OpenConcept-2.4.0-upgrade-from-2.3.3.zip.sha256) | [更新手順 / Instructions](core/2.4.0/OpenConcept-2.4.0-upgrade-from-2.3.3/README.ja-en.md) |

設置説明書には、HTTP/HTTPSサーバーへの配置ツリー、必要なPHP環境、Pluginの配置先、PostgreSQLとpgvectorが必要になる条件を記載しています。

The setup guide includes HTTP/HTTPS directory layouts, PHP requirements, plugin installation paths, and the conditions requiring PostgreSQL and pgvector.

## リポジトリ構成 / Repository layout

```text
openconcept_official/
├── README.md
├── LICENSE, LICENSE.*
├── core/                              本体をバージョン別に配置 / Versioned application distributions
│   ├── README.md
│   └── 2.4.0/
│       ├── README.md                  配布物・説明書へのリンク / Downloads and guides
│       ├── OpenConcept-2.4.0-public.zip
│       ├── OpenConcept-2.4.0-public.zip.sha256
│       ├── OpenConcept-2.4.0-upgrade-from-2.3.3.zip
│       ├── OpenConcept-2.4.0-upgrade-from-2.3.3.zip.sha256
│       ├── OpenConcept-2.4.0-upgrade-from-2.3.3/
│       └── OpenConcept-2.4.0-public/   展開済み本体 / Unpacked application
│           ├── public/
│           ├── app/
│           ├── plugins/              同梱5種・図面管理は別配布 / Five bundled plugins; drawings separate
│           ├── HTTP-SERVER-SETUP.ja-en.md
│           ├── DISTRIBUTION-MANIFEST.json
│           └── ...
└── plugins/                           公式Pluginの配布領域 / Official plugin distribution
    ├── README.md
    ├── catalog.json                   配布カタログ / Distribution catalog
    └── packages/                      個別配布パッケージ / Standalone plugin packages
        ├── README.md
        └── drawing-manager/
            └── 0.9.1/
                ├── README.md
                ├── drawing-manager-0.9.1.oc-plugin.json
                └── drawing-manager-0.9.1.oc-plugin.json.sha256
```

更新済みの配布物は、OpenConceptプロジェクトの`Dist/`以下から手動、または管理者が指示したバージョンを取得して配置します。自動同期は行いません。本体は`core/<version>/`へ配置し、フォルダー名と内容のバージョンを一致させます。更新対象の旧配布物を置き換え、ダウンロード一覧やカタログも更新します。旧版や作業用ファイルは保管しません。

Updated distributions are copied manually, or at the version requested by the maintainer, from the OpenConcept project's `Dist/` folder. There is no automatic synchronization. Place application files under `core/<version>/`, matching the directory name to the contents. Replace superseded distributions and update download lists and catalogs. Do not retain old versions or working files.

## 公式プラグイン / Official plugins

[公式Plugin配布フォルダー](plugins/)と[カタログ](plugins/catalog.json)から、[図面管理0.9.1](plugins/packages/drawing-manager/0.9.1/README.md)を個別配布しています。

The [official plugin directory](plugins/) and [catalog](plugins/catalog.json) distribute [Drawing Manager 0.9.1](plugins/packages/drawing-manager/0.9.1/README.md) separately.

本体2.4.0では図面管理を同梱せず、「設定 > プラグイン > 公式ダウンロード」から追加します。ダウンロード直後は無効です。有効化すると図面管理メニューが表示されます。他の同梱プラグインは従来どおりです。

Application 2.4.0 distributes Drawing Manager separately. Add it in Settings > Plugins > Official downloads, then enable it to show the drawing menu. Downloads are initially disabled. Other bundled plugins remain included.

本体2.4.0の公式カタログとパッケージの取得先は、このリポジトリの`main/plugins/`内に固定しています。環境変数で別の配布元へ変更することはできません。HTTPSとSHA-256で取得先・ファイルの一致を確認します。発行者の電子署名検証は実装していません。

Application 2.4.0 pins its official catalog and package URLs to this repository under `main/plugins/`. Environment variables cannot select a different publisher. HTTPS and SHA-256 check the source connection and file integrity; publisher-signature verification is not implemented.

## ライセンス / License

OpenConceptには独自の「OpenConcept 利用許諾条件 第1.1版」が適用されます。公開ソースであることは、任意のライセンスで利用できることを意味しません。全文を確認してください。

OpenConcept is distributed under the custom OpenConcept License Terms, Version 1.1. Public source availability does not grant use under an arbitrary license. Please read the full terms.

- 日本語原文 / Japanese original: [Markdown](LICENSE.ja.md) / [TXT](LICENSE.ja.txt)
- English translation: [Markdown](LICENSE.en.md) / [TXT](LICENSE.en.txt)

同梱された第三者資産には、それぞれのライセンスが適用されます。

Bundled third-party assets retain their respective licenses.
