# 図面管理 0.9.1 / Drawing Manager 0.9.1

OpenConcept用の公式追加プラグインです。図面の登録・確認・承認・訂正連絡・検索に対応します。

An official optional OpenConcept plugin for drawing registration, review, approval, correction comments, and search.

- [パッケージ / Package](drawing-manager-0.9.1.oc-plugin.json)
- [SHA-256](drawing-manager-0.9.1.oc-plugin.json.sha256)
- [公式カタログ / Official catalog](../../../catalog.json)

## 導入 / Installation

OpenConcept 2.3.1以降では、「設定 > プラグイン > 公式ダウンロード」で図面管理をダウンロードし、有効化してください。ダウンロード直後は無効です。導入時はPHP実行ユーザーに`plugins/`への書込み権限が必要です。

In OpenConcept 2.3.1 or later, download Drawing Manager in Settings > Plugins > Official downloads, then enable it. It is disabled immediately after download. The PHP service identity needs write access to `plugins/` during installation.

このJSONはOpenConceptインストーラー用パッケージで、ZIPではありません。展開先は`<application-root>/plugins/drawing-manager/`です。PHP本体、権限、DB、共通AI設定はOpenConceptを使用します。AI読取りを使用する場合は共通AI接続の設定が必要です。

This JSON is an OpenConcept installer package, not a ZIP archive. Its destination is `<application-root>/plugins/drawing-manager/`. It uses OpenConcept's PHP application, permissions, database, and shared AI settings. AI-assisted extraction requires a configured shared AI connection.

## 既存環境 / Existing installations

2.3.0に同梱されていた図面管理0.9.0は、本体更新時も保持してください。ダウンロード機能は既存Pluginを上書きしません。図面DBと`storage/plugins/drawing-manager/`は引き続き同じ設置先で保持します。

Retain Drawing Manager 0.9.0 bundled with application 2.3.0 when updating the application. Downloads do not overwrite installed plugins. Keep the drawing database and `storage/plugins/drawing-manager/` within the same installation.

0.9.1は個別配布用の説明書・ライセンス同梱を整えた版です。図面のDB Schemaや保存形式は変更していません。

Version 0.9.1 adds standalone distribution documentation and license packaging. It does not change drawing database schemas or storage formats.

## ライセンス / License

パッケージ内にOpenConceptの日本語原文・英訳の利用許諾条件と、PDF.js等の第三者LICENSE・NOTICEを含みます。

The package includes the OpenConcept license in Japanese and English and the third-party LICENSE/NOTICE files for PDF.js and other assets.
