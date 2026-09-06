# 公式プラグイン / Official Plugins

ここはOpenConcept公式プラグインの配布領域です。[配布カタログ](catalog.json)と[パッケージ保存先](packages/)を保持します。

This directory holds the [distribution catalog](catalog.json) and [packages](packages/) for official OpenConcept plugins.

| Plugin | Version | 導入説明 / Installation guide |
| --- | --- | --- |
| 図面管理 / Drawing Manager | 0.9.1 | [日本語・English](packages/drawing-manager/0.9.1/README.md) |

図面管理は2.3.1以降の本体配布から分離されています。必要な管理者が本体の「公式ダウンロード」から追加します。

Drawing Manager is distributed separately from application 2.3.1 onward and can be added through Official downloads in the application.

カタログのHTTPS配信先 / HTTPS catalog endpoint:

```text
https://raw.githubusercontent.com/BloomingBlooming/openconcept_official/main/plugins/catalog.json
```

本体に同梱するPluginは、対応する`core/<version>/OpenConcept-<version>-public/plugins/`に含まれます。この配布フォルダーをアプリの実行フォルダーとして使用するものではありません。

Bundled plugins are included under `core/<version>/OpenConcept-<version>-public/plugins/` for the corresponding application version. This distribution directory is not an application's runtime plugin directory.

## パッケージ配置 / Package layout

個別配布を開始するときは、次の形式で追加します。以下のIDとバージョンは配置例です。

When standalone distribution begins, add packages using the following layout. The ID and version below are examples.

```text
plugins/
├── README.md
├── catalog.json
└── packages/
    ├── README.md
    └── <plugin-id>/
        └── <version>/
            └── <plugin-id>-<version>.oc-plugin.json
```

カタログは現行アプリが読み取る`schema_version: 1`形式です。配布するPluginのID・名前・バージョン・説明・アイコン・HTTPSダウンロードURL・パッケージSHA-256を登録します。カタログとダウンロードURLは同じHTTPSオリジンで提供します。

The catalog uses `schema_version: 1`, supported by the current application. Register each plugin's ID, name, version, description, icon, HTTPS download URL, and package SHA-256. Serve the catalog and download URLs from the same HTTPS origin.

PluginのIDとバージョンはパッケージのmanifestと一致させます。公開済みパッケージを修正する場合はPluginのバージョンを上げ、新しいファイルとして追加します。

Plugin IDs and versions must match the package manifest. Publish corrections with an incremented plugin version as a new package file.

公式の発行者確認と単なるファイルハッシュ検証は別の機能です。現行のカタログ形式には公式署名検証の契約はまだ含まれていません。

Publisher authentication and file-hash verification are separate functions. The current catalog format does not yet define official-signature verification.
