# OpenConcept 公式配布 / Official Distribution

OpenConcept本体と公式プラグインの公開用フォルダを管理するリポジトリです。最新の公開配布物は2.6.0です。2.4.1から2.5.0への更新版も引き続き掲載します。

This repository manages the public distribution folder for OpenConcept and its official plugins. The latest distribution is 2.6.0. The upgrade from 2.4.1 to 2.5.0 also remains available.

公式公開先 / Official repository: [BloomingBlooming/openconcept_official](https://github.com/BloomingBlooming/openconcept_official).

## 本体ダウンロード / Application downloads

| バージョン / Version | ダウンロード / Download | 検証値 / Checksum | 設置説明書 / Setup guide |
| --- | --- | --- | --- |
| 2.6.0（新規設置 / New installation） | [OpenConcept 2.6.0 ZIP](core/2.6.0/OpenConcept-2.6.0-public.zip) | [SHA-256](core/2.6.0/OpenConcept-2.6.0-public.zip.sha256) | [日本語・English](core/2.6.0/README.md) |
| 2.5.0 → 2.6.0（更新 / Upgrade） | [Upgrade ZIP](core/2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0.zip) | [SHA-256](core/2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0.zip.sha256) | [更新手順 / Instructions](core/2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0/README.ja-en.md) |
| 2.4.1 → 2.5.0（継続掲載・更新 / Retained upgrade） | [Upgrade ZIP](core/2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1.zip) | [SHA-256](core/2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1.zip.sha256) | [更新手順 / Instructions](core/2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1/README.ja-en.md) |

最新の本体配布物は、新規設置用の2.6.0通常配布版と、2.5.0から2.6.0への更新版です。2.4.1から2.5.0への更新版も保持しています。いずれもZIP、SHA-256チェックサム、展開済みファイル一式を掲載しています。

The latest downloads are the full 2.6.0 distribution for new installations and the upgrade from 2.5.0 to 2.6.0. The upgrade from 2.4.1 to 2.5.0 remains available. All include a ZIP, SHA-256 checksum, and unpacked files.

V2.5.0からの更新ではCoreスキーマ世代6を維持します。環境全体をバックアップして書き込みを止め、更新ファイルを結合してください。NginxのPHP許可リストを使用している場合は`history.php`を追加します。詳しくは[配布案内](core/2.6.0/README.md)を確認してください。

The upgrade from V2.5.0 keeps Core schema generation 6. Back up the complete environment, pause writes, and merge the update files. Add `history.php` if using an Nginx PHP allowlist. See the [distribution guide](core/2.6.0/README.md).

V2.4.1からは2.5.0、2.6.0の順に更新します。[2.5.0配布案内の変更前ハッシュに関する説明](core/2.5.0/README.md)も確認してください。

From V2.4.1, upgrade to 2.5.0 and then to 2.6.0. Also read the [2.5.0 distribution guide's note about before hashes](core/2.5.0/README.md).

2.6.0では発言・訂正・判断の履歴、共通JSON会話取込、旧添付を含む正本出力と隔離SQLite復元を追加しました。詳しくは[リリースノート](core/2.6.0/OpenConcept-2.6.0-public/docs/release-notes-2.6.0.md)をご覧ください。

Version 2.6.0 adds records and history, append-only corrections, common-format conversation imports, and portable exports including retained attachments with verified isolated SQLite restoration. See the [release notes](core/2.6.0/OpenConcept-2.6.0-public/docs/release-notes-2.6.0.md).

設置説明書には、HTTP/HTTPSサーバーへの配置ツリー、必要なPHP環境、Pluginの配置先、PostgreSQLとpgvectorが必要になる条件を記載しています。

The setup guide includes HTTP/HTTPS directory layouts, PHP requirements, plugin installation paths, and the conditions requiring PostgreSQL and pgvector.

## リポジトリ構成 / Repository layout

```text
openconcept_official/
├── README.md
├── LICENSE, LICENSE.*
├── core/                              本体をバージョン別に配置 / Versioned application distributions
│   ├── README.md
│   ├── 2.5.0/                         更新版を継続掲載 / Retained upgrade
│   │   ├── README.md
│   │   ├── OpenConcept-2.5.0-upgrade-from-2.4.1.zip
│   │   ├── OpenConcept-2.5.0-upgrade-from-2.4.1.zip.sha256
│   │   └── OpenConcept-2.5.0-upgrade-from-2.4.1/
│   └── 2.6.0/
│       ├── README.md                  配布案内・設置説明書 / Downloads and setup guide
│       ├── OpenConcept-2.6.0-public.zip
│       ├── OpenConcept-2.6.0-public.zip.sha256
│       ├── OpenConcept-2.6.0-upgrade-from-2.5.0.zip
│       ├── OpenConcept-2.6.0-upgrade-from-2.5.0.zip.sha256
│       ├── OpenConcept-2.6.0-upgrade-from-2.5.0/  更新手順・差分 / Upgrade guide and files
│       └── OpenConcept-2.6.0-public/   展開済み本体 / Unpacked application
│           ├── public/
│           ├── app/
│           ├── plugins/              同梱6種・図面管理は別配布 / Six bundled plugins; drawings separate
│           ├── HTTP-SERVER-SETUP.ja-en.md
│           ├── DISTRIBUTION-MANIFEST.json
│           └── ...
└── plugins/                           公式Pluginの配布領域 / Official plugin distribution
    ├── README.md
    ├── catalog.json                   配布カタログ / Distribution catalog
    └── packages/                      個別配布パッケージ / Standalone plugin packages
        ├── README.md
        └── drawing-manager/
            └── 0.9.2/
                ├── README.md
                ├── drawing-manager-0.9.2.oc-plugin.json
                └── drawing-manager-0.9.2.oc-plugin.json.sha256
```

更新済みの配布物は、OpenConceptプロジェクトの`Dist/`以下から手動、または管理者が指示したバージョンを取得して配置します。自動同期は行いません。本体は`core/<version>/`へ配置し、フォルダー名と内容のバージョンを一致させます。原則として更新対象の旧配布物を置き換えますが、管理者の指定により2.4.1から2.5.0への更新版は保持します。2.5.0通常配布版は公開用リポジトリ外へ退避しました。ダウンロード一覧やカタログを配置内容に合わせ、作業用ファイルは保管しません。

Updated distributions are copied manually, or at the version requested by the maintainer, from the OpenConcept project's `Dist/` folder. There is no automatic synchronization. Place application files under `core/<version>/`, matching the directory name to the contents. Superseded distributions are normally replaced; the maintainer has requested retaining the upgrade from 2.4.1 to 2.5.0. The full 2.5.0 distribution has been archived outside this public repository. Keep download lists and catalogs aligned with the files, and do not retain working files.

## 公式プラグイン / Official plugins

[公式Plugin配布フォルダー](plugins/)と[カタログ](plugins/catalog.json)から、[図面管理0.9.2](plugins/packages/drawing-manager/0.9.2/README.md)を個別配布しています。最低対応版は更新済みの本体2.5.0で、本体2.6.0でも利用できます。

The [official plugin directory](plugins/) and [catalog](plugins/catalog.json) distribute [Drawing Manager 0.9.2](plugins/packages/drawing-manager/0.9.2/README.md) separately. Its minimum supported application is the updated 2.5.0 release; it also supports 2.6.0.

本体2.6.0では図面管理を同梱せず、「設定 > プラグイン > 公式ダウンロード」から追加します。ダウンロード直後は無効です。有効化すると図面管理メニューが表示されます。既存の図面管理は本体更新後、プラグイン一覧の「UpDate」から更新できます。

Application 2.6.0 distributes Drawing Manager separately. Add it in Settings > Plugins > Official downloads, then enable it to show the drawing menu. Downloads are initially disabled. After updating the application, use UpDate in the plugin list to update an existing Drawing Manager installation.

本体2.6.0の公式カタログとパッケージの取得先は、このリポジトリの`main/plugins/`内に固定しています。環境変数で別の配布元へ変更することはできません。HTTPSとSHA-256で取得先・ファイルの一致を確認します。発行者の電子署名検証は実装していません。

Application 2.6.0 pins its official catalog and package URLs to this repository under `main/plugins/`. Environment variables cannot select a different publisher. HTTPS and SHA-256 check the source connection and file integrity; publisher-signature verification is not implemented.

## ライセンス / License

OpenConceptには独自の「OpenConcept 利用許諾条件 第1.1版」が適用されます。公開ソースであることは、任意のライセンスで利用できることを意味しません。全文を確認してください。

OpenConcept is distributed under the custom OpenConcept License Terms, Version 1.1. Public source availability does not grant use under an arbitrary license. Please read the full terms.

- 日本語原文 / Japanese original: [Markdown](LICENSE.ja.md) / [TXT](LICENSE.ja.txt)
- English translation: [Markdown](LICENSE.en.md) / [TXT](LICENSE.en.txt)

同梱された第三者資産には、それぞれのライセンスが適用されます。

Bundled third-party assets retain their respective licenses.
