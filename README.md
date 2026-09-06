# OpenConcept 公式配布 / Official Distribution

OpenConcept本体をバージョン別に公開し、公式プラグインを配布するためのリポジトリです。

This repository publishes versioned OpenConcept application distributions and official plugins.

公式公開先 / Official repository: [BloomingBlooming/openconcept_official](https://github.com/BloomingBlooming/openconcept_official).

## 本体ダウンロード / Application downloads

| バージョン / Version | ダウンロード / Download | 検証値 / Checksum | 設置説明書 / Setup guide |
| --- | --- | --- | --- |
| 2.3.0 | [OpenConcept 2.3.0 ZIP](core/2.3.0/OpenConcept-2.3.0-public.zip) | [SHA-256](core/2.3.0/OpenConcept-2.3.0-public.zip.sha256) | [日本語・English](core/2.3.0/README.md) |

設置説明書には、HTTP/HTTPSサーバーへの配置ツリー、必要なPHP環境、Pluginの配置先、PostgreSQLとpgvectorが必要になる条件を記載しています。

The setup guide includes HTTP/HTTPS directory layouts, PHP requirements, plugin installation paths, and the conditions requiring PostgreSQL and pgvector.

## リポジトリ構成 / Repository layout

```text
openconcept_official/
├── README.md
├── LICENSE, LICENSE.*
├── core/                              本体をバージョン別に配置 / Versioned application distributions
│   ├── README.md
│   └── 2.3.0/
│       ├── README.md                  日英設置説明書 / Bilingual setup guide
│       ├── OpenConcept-2.3.0-public.zip
│       ├── OpenConcept-2.3.0-public.zip.sha256
│       └── OpenConcept-2.3.0-public/   展開済み本体 / Unpacked application
│           ├── public/
│           ├── app/
│           ├── plugins/              本体同梱Plugin / Plugins bundled with this version
│           ├── HTTP-SERVER-SETUP.ja-en.md
│           ├── DISTRIBUTION-MANIFEST.json
│           └── ...
└── plugins/                           公式Pluginの配布領域 / Official plugin distribution
    ├── README.md
    ├── catalog.json                   配布カタログ / Distribution catalog
    └── packages/                      個別配布パッケージ / Standalone plugin packages
        └── README.md
```

新しい本体バージョンは`core/<version>/`へ追加します。既存バージョンの配布物を別バージョンの内容で上書きしません。

Add new application versions under `core/<version>/`. Do not overwrite an existing version with the contents of another version.

## 公式プラグイン / Official plugins

[公式Plugin配布フォルダー](plugins/)と[カタログ](plugins/catalog.json)を用意しています。個別ダウンロード用のカタログは現在空です。本体2.3.0に含まれるPluginは、本体の配布パッケージ内にあります。

The [official plugin directory](plugins/) and [catalog](plugins/catalog.json) are available. The standalone download catalog is currently empty. Plugins bundled with application version 2.3.0 are included in that application's distribution package.

このリポジトリの準備だけでは、既存アプリの配布元URL固定や公式署名検証は有効になりません。それらは別途アプリ側で実装・設定する必要があります。SHA-256はファイルの一致確認に使用し、発行者の電子署名を意味しません。

Creating this repository does not enable a fixed official download source or publisher-signature verification in existing applications. Those require separate application changes and configuration. SHA-256 checks verify file integrity; they are not publisher signatures.

## ライセンス / License

OpenConceptには独自の「OpenConcept 利用許諾条件 第1.1版」が適用されます。公開ソースであることは、任意のライセンスで利用できることを意味しません。全文を確認してください。

OpenConcept is distributed under the custom OpenConcept License Terms, Version 1.1. Public source availability does not grant use under an arbitrary license. Please read the full terms.

- 日本語原文 / Japanese original: [Markdown](LICENSE.ja.md) / [TXT](LICENSE.ja.txt)
- English translation: [Markdown](LICENSE.en.md) / [TXT](LICENSE.en.txt)

同梱された第三者資産には、それぞれのライセンスが適用されます。

Bundled third-party assets retain their respective licenses.
