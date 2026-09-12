# OpenConcept 公式配布 / Official Distribution

OpenConcept本体と公式プラグインの公開用フォルダです。最新の本体配布物は **V2.6.1** です。V2.5.0とV2.6.0の通常版・更新版も継続掲載しています。

This repository holds OpenConcept and official plugin distributions. The latest application release is **V2.6.1**. Full releases and upgrades for V2.5.0 and V2.6.0 also remain available.

公式公開先 / Official repository: [BloomingBlooming/openconcept_official](https://github.com/BloomingBlooming/openconcept_official).

## 本体ダウンロード / Application downloads

| バージョン / Version | ダウンロード / Download | 検証値 / Checksum | 説明書 / Guide |
| --- | --- | --- | --- |
| 2.6.1（新規設置 / New installation） | [OpenConcept 2.6.1 ZIP](core/2.6.1/OpenConcept-2.6.1-public.zip) | [SHA-256](core/2.6.1/OpenConcept-2.6.1-public.zip.sha256) | [日本語・English](core/2.6.1/README.md) |
| 2.6.0 → 2.6.1（更新 / Upgrade） | [Upgrade ZIP](core/2.6.1/OpenConcept-2.6.1-upgrade-from-2.6.0.zip) | [SHA-256](core/2.6.1/OpenConcept-2.6.1-upgrade-from-2.6.0.zip.sha256) | [更新手順](core/2.6.1/OpenConcept-2.6.1-upgrade-from-2.6.0/README.ja-en.md) |
| 2.6.0（継続掲載 / Retained full release） | [OpenConcept 2.6.0 ZIP](core/2.6.0/OpenConcept-2.6.0-public.zip) | [SHA-256](core/2.6.0/OpenConcept-2.6.0-public.zip.sha256) | [日本語・English](core/2.6.0/README.md) |
| 2.5.0 → 2.6.0（中間更新 / Intermediate upgrade） | [Upgrade ZIP](core/2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0.zip) | [SHA-256](core/2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0.zip.sha256) | [更新手順](core/2.6.0/OpenConcept-2.6.0-upgrade-from-2.5.0/README.ja-en.md) |
| 2.5.0（継続掲載 / Retained full release） | [OpenConcept 2.5.0 ZIP](core/2.5.0/OpenConcept-2.5.0-public.zip) | [SHA-256](core/2.5.0/OpenConcept-2.5.0-public.zip.sha256) | [日本語・English](core/2.5.0/README.md) |
| 2.4.1 → 2.5.0（継続掲載 / Retained upgrade） | [Upgrade ZIP](core/2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1.zip) | [SHA-256](core/2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1.zip.sha256) | [更新手順](core/2.5.0/OpenConcept-2.5.0-upgrade-from-2.4.1/README.ja-en.md) |

V2.6.1にはアプリ全体のダークモード、カバー13種類（なしを含む）・アイコン96種類、受信トレイ下の三点アイコンによるAI検索・記録と履歴の開閉を含みます。ページ状態の一括変更、ツリー表示、履歴のページ送り、プラグイン管理の改善もまとめました。[リリースノート](core/2.6.1/OpenConcept-2.6.1-public/docs/release-notes-2.6.1.md)と[配布案内](core/2.6.1/README.md)をご覧ください。

V2.6.1 adds dark mode, 13 cover choices including No cover, 96 icons, and the three-dot toggle below Inbox for AI search and Records and history. It also includes bulk page status changes, improved tree display, history pagination and plugin management. See the [release notes](core/2.6.1/OpenConcept-2.6.1-public/docs/release-notes-2.6.1.md) and [distribution guide](core/2.6.1/README.md).

新規設置はV2.6.1を使用してください。既存V2.6.0からは更新版を結合し、DB・添付・設定・個別プラグインを保持します。Core DBスキーマ世代6とPlugin API 1.0.0は維持します。V2.4.1からは2.5.0、2.6.0、2.6.1の順で更新します。[V2.5.0の変更前ハッシュに関する説明](core/2.5.0/README.md)も確認してください。

Use V2.6.1 for new installations. Existing V2.6.0 installations use the merge-only upgrade, retaining data, settings and plugins. Core schema generation 6 and Plugin API 1.0.0 remain unchanged. Upgrade V2.4.1 through 2.5.0 and 2.6.0 to 2.6.1, noting the [V2.5.0 guide's before-hash information](core/2.5.0/README.md).

## リポジトリ構成 / Repository layout

```text
openconcept_official/
├── README.md, LICENSE, LICENSE.*
├── core/
│   ├── README.md
│   ├── 2.5.0/       通常版・2.4.1からの更新版 / Full release and upgrade
│   ├── 2.6.0/       通常版・2.5.0からの更新版 / Full release and upgrade
│   └── 2.6.1/       最新の通常版・更新版 / Latest full release and upgrade
│       ├── README.md
│       ├── OpenConcept-2.6.1-public.zip, .zip.sha256
│       ├── OpenConcept-2.6.1-public/
│       ├── OpenConcept-2.6.1-upgrade-from-2.6.0.zip, .zip.sha256
│       ├── OpenConcept-2.6.1-upgrade-from-2.6.0/
│       ├── OpenConcept-2.6.1-artifacts.json
│       └── release-notes-2.6.1.md
└── plugins/
    ├── README.md, catalog.json
    └── packages/
        ├── README.md
        ├── float-navi/1.2.2/
        └── drawing-manager/0.9.3/
```

本体はOpenConceptプロジェクトの `Dist/core/`、個別プラグインは `Dist/plugins/<plugin-id>/<version>/` で生成・検証した配布物を配置します。ZIP、チェックサム、manifest、展開済みファイルを揃えています。V2.5.0とV2.6.0は、通常版と中間更新版の両方を継続掲載します。配布物の内容はここで直接編集しません。

Application distributions are generated and verified in the OpenConcept project's `Dist/core/` directory; standalone plugins come from `Dist/plugins/<plugin-id>/<version>/`. ZIPs, checksums, manifests and unpacked files are kept consistent. Full releases and intermediate upgrades for V2.5.0 and V2.6.0 remain available. Packaged contents are not edited here.

Dist内のフォルダー整理により取得元のパスが変わりました。公開用リポジトリの配置とダウンロードURLは維持しています。同梱説明書に旧生成先が記載されている場合は、上記の取得元に読み替えてください。

The Dist folders have been reorganized. Public repository paths and download URLs remain unchanged. If a bundled guide mentions an older build output path, use the source locations above.

## 公式プラグイン / Official plugins

[フロートNavi 1.2.2](plugins/packages/float-navi/1.2.2/README.md)を追加しました。ページ検索・全開閉・ツリーを、左ナビと同期する移動・サイズ変更可能な窓に表示します。AI検索ウィンドウと重なっても手前に表示されます。歯車の「透過」から通常は隠れているスライダーを表示し、「背景モード → ダークモード」で黒背景・白文字に変更できます。V2.5.0／V2.6.0／V2.6.1対応で、未登録IDを拒否する旧本体向けの互換パッチも同梱しています。

[Float Navi 1.2.2](plugins/packages/float-navi/1.2.2/README.md) adds movable, resizable page navigation synchronized with the sidebar and keeps it in front of the AI search window. The gear menu reveals transparency settings and offers a black-background, white-text dark mode. Supports V2.5.0, V2.6.0 and V2.6.1, with compatibility patches for older Core builds that reject unlisted plugin IDs.

[公式Plugin配布フォルダー](plugins/)と[カタログ](plugins/catalog.json)から、[図面管理0.9.3](plugins/packages/drawing-manager/0.9.3/README.md)を個別配布しています。最低対応版は更新済みの本体2.5.0で、本体2.6.1でも利用できます。

The [official plugin directory](plugins/) and [catalog](plugins/catalog.json) distribute [Drawing Manager 0.9.3](plugins/packages/drawing-manager/0.9.3/README.md) separately. Its minimum supported application is the updated 2.5.0 release; it also supports 2.6.1.

本体2.6.1では図面管理を同梱せず、「設定 > プラグイン > 公式ダウンロード」から追加します。ダウンロード直後は無効です。有効化すると図面管理メニューが表示されます。既存の図面管理は本体更新後、プラグイン一覧の「UpDate」から更新できます。

Application 2.6.1 distributes Drawing Manager separately. Add it in Settings > Plugins > Official downloads, then enable it to show the drawing menu. Downloads are initially disabled. After updating the application, use UpDate in the plugin list to update an existing Drawing Manager installation.

本体2.6.1の公式カタログとパッケージの取得先は、このリポジトリの`main/plugins/`内に固定しています。環境変数で別の配布元へ変更することはできません。HTTPSとSHA-256で取得先・ファイルの一致を確認します。発行者の電子署名検証は実装していません。

Application 2.6.1 pins its official catalog and package URLs to this repository under `main/plugins/`. Environment variables cannot select a different publisher. HTTPS and SHA-256 check the source connection and file integrity; publisher-signature verification is not implemented.

## ライセンス / License

OpenConceptには独自の「OpenConcept 利用許諾条件 第1.1版」が適用されます。公開ソースであることは、任意のライセンスで利用できることを意味しません。全文を確認してください。

OpenConcept is distributed under the custom OpenConcept License Terms, Version 1.1. Public source availability does not grant use under an arbitrary license. Please read the full terms.

- 日本語原文 / Japanese original: [Markdown](LICENSE.ja.md) / [TXT](LICENSE.ja.txt)
- English translation: [Markdown](LICENSE.en.md) / [TXT](LICENSE.en.txt)

同梱された第三者資産には、それぞれのライセンスが適用されます。

Bundled third-party assets retain their respective licenses.
