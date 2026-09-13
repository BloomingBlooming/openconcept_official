# 公式プラグイン / Official Plugins

ここはOpenConcept公式プラグインの配布領域です。[配布カタログ](catalog.json)と[パッケージ一覧](packages/README.md)から最新版を取得できます。

| Plugin | Version | 導入説明 / Guide | ダウンロード / Download |
| --- | --- | --- | --- |
| フロートNavi / Float Navi | 1.2.3 | [日本語・English](packages/float-navi/1.2.3/README.md) | [Package](packages/float-navi/1.2.3/float-navi-1.2.3.oc-plugin.json) · [ZIP](packages/float-navi/1.2.3/float-navi-1.2.3.zip) · [SHA-256](packages/float-navi/1.2.3/float-navi-1.2.3.zip.sha256) |
| 図面管理 / Drawing Manager | 0.9.4 | [日本語・English](packages/drawing-manager/0.9.4/README.md) | [Package](packages/drawing-manager/0.9.4/drawing-manager-0.9.4.oc-plugin.json) · [SHA-256](packages/drawing-manager/0.9.4/drawing-manager-0.9.4.oc-plugin.json.sha256) |

2026-09-13に両パッケージとカタログを同時更新しました。フロートNavi1.2.3は開閉状態・位置・サイズ・透過度・背景モードをブラウザーに保存して復元します。図面管理0.9.4は固定UIの5言語対応を改善しました。図面管理の固定APIエラーの翻訳にはCore V3.0.0が必要です。

## 導入と更新 / Install and update

新規導入は管理者の「設定 > プラグイン > 公式ダウンロード」から行い、その後有効化します。導入済みの場合はプラグイン一覧を再取得し、適合する新版に表示される「UpDate」（V3.0.0では「Update」）から更新します。旧プログラムを退避して新版へ差し替え、有効・無効の状態、設定、登録データ、添付を保持します。

**本体V2.6.1を利用中なら、今回のプラグイン更新のために本体を更新する必要はありません。** V2.6.1およびV3.0.0で、新規導入・旧版からの更新・再起動・データ保持を確認済みです。更新済みV2.5.0以降に対応しますが、未登録IDを拒否する旧V2.5.0／V2.6.0はフロートNaviの同梱互換パッチが必要です。新規導入・更新にはPHP実行ユーザーが`plugins/`へ書き込める必要があります。

For new installations, use Settings > Plugins > Official downloads, then enable the plugin. For an existing installation, refresh the plugin list and choose UpDate (Update on Core V3.0.0) when a compatible release appears. The previous code is retained, and enabled state, settings, records and attachments are preserved.

**Core V2.6.1 does not need an application update to install these plugin updates.** New installations, updates from the previous plugins, restarts and data preservation were verified on Core V2.6.1 and V3.0.0. Drawing Manager 0.9.4 improves UI localization; its translated API errors require Core V3.0.0. Float Navi 1.2.3 retains window preferences across reloads. Older restricted V2.5.0/V2.6.0 builds need the included matching Float Navi compatibility patch.

## 配布仕様 / Distribution contract

配布物はOpenConceptプロジェクトの `Dist/plugins/<plugin-id>/<version>/` で生成・検証したものです。図面管理の外側READMEは検証済みパッケージ内のREADMEをそのまま展開しています。この公開領域には各最新版を置き、過去版は開発側のアーカイブとGit履歴に保持します。

カタログは `schema_version: 1` です。ID・バージョン・HTTPS URL・SHA-256・Plugin API要件をパッケージのmanifestと一致させます。本体はこのリポジトリの `main/plugins/` を取得先として使用します。新しいプラグイン版とカタログを同じコミットで公開し、公開後に実URLとハッシュを照合します。

Packages are generated and verified in the development project's Dist directory. The external Drawing Manager README is extracted unchanged from the verified package. This published tree retains current plugin versions; older versions remain in the development archive and Git history. Publish packages and catalog changes in one commit, then verify the public URLs and hashes. Catalog schema, identities, API requirements and package checksums must match.

本体同梱プラグインは `core/<version>/OpenConcept-<version>-public/plugins/` に含まれます。詳しくは [Plugin API説明](../core/3.0.0/OpenConcept-3.0.0-public/docs/plugin-api-reference.md) を参照してください。HTTPSとSHA-256で取得先・内容を確認します。電子署名の検証は実装していません。

Bundled plugins are part of the corresponding Core distribution. See the [Plugin API guide](../core/3.0.0/OpenConcept-3.0.0-public/docs/plugin-api-reference.md). HTTPS and SHA-256 verify the endpoint and package integrity; publisher signature verification is not implemented.
