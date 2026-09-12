# 公式プラグイン / Official Plugins

ここはOpenConcept公式プラグインの配布領域です。[配布カタログ](catalog.json)と[パッケージ保存先](packages/)を保持します。

This directory holds the [distribution catalog](catalog.json) and [packages](packages/) for official OpenConcept plugins.

| Plugin | Version | 導入説明 / Installation guide | ダウンロード / Download |
| --- | --- | --- | --- |
| フロートNavi / Float Navi | 1.2.2 | [日本語・English](packages/float-navi/1.2.2/README.md) | [Package](packages/float-navi/1.2.2/float-navi-1.2.2.oc-plugin.json) · [手動導入ZIP](packages/float-navi/1.2.2/float-navi-1.2.2.zip) · [SHA-256](packages/float-navi/1.2.2/float-navi-1.2.2.zip.sha256) |
| 図面管理 / Drawing Manager | 0.9.3 | [日本語・English](packages/drawing-manager/0.9.3/README.md) | [Package](packages/drawing-manager/0.9.3/drawing-manager-0.9.3.oc-plugin.json) · [SHA-256](packages/drawing-manager/0.9.3/drawing-manager-0.9.3.oc-plugin.json.sha256) |

図面管理は2.3.1以降の本体配布から分離されています。必要な管理者が本体の「公式ダウンロード」から追加します。

フロートNaviはV2.5.0、V2.6.0、V2.6.1に対応します。ページ検索・全開閉・ツリーを左ナビと同期する移動・サイズ変更可能な窓を追加します。1.2.2ではAI検索ウィンドウより手前に表示するよう修正しました。歯車から「透過」や背景のライト／ダークモードを選択できます。公開済み本体が未登録プラグインIDを拒否する場合は、先に[各バージョン用の互換パッチ](packages/float-navi/1.2.2/README.md)を適用してください。

Float Navi supports V2.5.0, V2.6.0 and V2.6.1 and adds a movable, resizable window synchronized with page navigation. Version 1.2.2 keeps it in front of the AI search window. The gear menu offers transparency controls and light/dark background modes. Older Core builds that reject unlisted IDs need the [matching compatibility patch](packages/float-navi/1.2.2/README.md) first.

Drawing Manager is distributed separately from application 2.3.1 onward and can be added through Official downloads in the application.

図面管理0.9.3の最低対応版は更新済みのOpenConcept V2.5.0とPlugin API 1.0.0で、本体V2.6.0／V2.6.1でも利用できます。新規導入は「設定 > プラグイン > 公式ダウンロード」からダウンロードし、管理者が有効化してください。導入にはPHP実行ユーザーの`plugins/`への書込み権限が必要です。

Drawing Manager 0.9.3 requires at least the updated OpenConcept V2.5.0 application and Plugin API 1.0.0, and also supports V2.6.0 and V2.6.1. For a new installation, download it in Settings > Plugins > Official downloads, then enable it as an administrator. The PHP service identity needs write access to `plugins/` for installation.

導入済みの場合は、プラグイン一覧を開くか再取得して、適合する新版に表示される「UpDate」から更新します。有効・無効の状態、設定、登録データ、原本は保持されます。詳細は[本体同梱のプラグインAPI説明](../core/2.6.1/OpenConcept-2.6.1-public/docs/plugin-api-reference.md)を参照してください。

For an installed plugin, open or refresh the plugin list and use UpDate when a compatible newer version is available. Updates preserve the enabled state, settings, registered data, and original files. See the [bundled Plugin API guide](../core/2.6.1/OpenConcept-2.6.1-public/docs/plugin-api-reference.md).

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

カタログは現行アプリが読み取る`schema_version: 1`形式です。配布するPluginのID・名前・バージョン・説明・アイコン・HTTPSダウンロードURL・パッケージSHA-256を登録します。API要件がある場合は、manifestと同じ`requires.plugin_api`と`requires.capabilities`を含めます。カタログとダウンロードURLは同じHTTPSオリジンで提供します。

The catalog uses `schema_version: 1`, supported by the current application. Register each plugin's ID, name, version, description, icon, HTTPS download URL, and package SHA-256. When a plugin declares API requirements, include the manifest's `requires.plugin_api` and `requires.capabilities`. Serve the catalog and download URLs from the same HTTPS origin.

PluginのIDとバージョンはパッケージのmanifestと一致させます。公開済みパッケージを修正する場合はPluginのバージョンを上げ、新しいファイルとして追加します。

Plugin IDs and versions must match the package manifest. Publish corrections with an incremented plugin version as a new package file.

公式の発行者確認と単なるファイルハッシュ検証は別の機能です。現行のカタログ形式には公式署名検証の契約はまだ含まれていません。

Publisher authentication and file-hash verification are separate functions. The current catalog format does not yet define official-signature verification.
