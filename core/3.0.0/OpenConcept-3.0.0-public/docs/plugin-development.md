# プラグイン開発

OpenConcept のアプリ本体とプラグインは、別々のバージョンとして管理します。プラグインは必ず `plugins/<plugin-id>/` に置き、フォルダーをまたいでファイルを共有しません。

新しいプラグインIDを本体へ登録する作業は不要です。有効な `plugin.json` と宣言ファイルを配置すると自動検出され、「設定 > プラグイン」で有効化できます。本体の個別ID一覧にないことを理由に拒否しません。API要件や既存プラグインの最低対応版は [APIリファレンス](plugin-api-reference.md) に従います。

## 新規作成

プロジェクトルートで次を実行します。

```powershell
php scripts/create-plugin.php activity-log "Activity Log" 0.1.0
php scripts/validate-plugins.php
```

生成される基本構造は次のとおりです。

```text
plugins/
└── activity-log/
    ├── plugin.json   # プラグイン固有のメタデータとバージョン
    ├── plugin.php    # PHP エントリーポイント
    └── README.md
```

CSS や JavaScript が必要な場合は、そのプラグインの中に `assets/` を作り、マニフェストへ相対パスを追加します。

## マニフェスト

```json
{
  "schema_version": 1,
  "id": "activity-log",
  "name": "Activity Log",
  "version": "0.1.0",
  "enabled": false,
  "description": "Records selected application activity.",
  "entry": "plugin.php",
  "assets": {
    "styles": ["assets/plugin.css"],
    "scripts": ["assets/plugin.js"]
  },
  "ui": {
    "sidebar": {
      "label": "Activity Log",
      "icon": "🧩",
      "placement": "default"
    }
  }
}
```

- `id` は小文字の kebab-case とし、フォルダー名と一致させます。
- `version` は SemVer とし、アプリ本体のバージョンから独立して更新します。
- `enabled` は初期状態です。設定画面のトグル状態がDBに保存されている場合は、DBの状態が優先されます。
- `entry`、`assets.styles`、`assets.scripts` はすべてプラグインフォルダー内の相対パスです。
- 宣言されていないファイルやフォルダー外のファイルは、Web 経由で配信されません。
- `ui.sidebar` を指定した有効なプラグインは、サイドバーへアイコンとラベルを表示します。
- `ui.sidebar.placement` は `default` または `ai-search` を指定できます。`ai-search` はAI検索の直下へ表示され、汎用プラグイン欄には重複表示されません。
- 共通Plugin APIを使う場合は、`"requires": {"plugin_api":"1.0.0","capabilities":["actions"]}` のように必要版と使用する能力を宣言します。必要APIが利用できないプラグインは、PHPを実行する前に拒否します。

## PHP エントリーポイント

`plugin.php` は callable を返せます。callable には実行コンテキストが渡されます。

Database Adapterだけは正本DBへの接続前に発見される必要があるため、通常の`plugin.php`とは
別に`database-adapter.php`を置き、`DatabaseAdapterInterface`実装を返します。通常のPlugin
lifecycle、UI、APIは引き続き`plugin.php`へ登録してください。Adapter entryでSchema変更や
Migrationを開始してはいけません。接続診断、Schema、コピー、検証、正本切替はCoreの
`DatabaseAdapterCoordinator`から明示的に呼ばれます。詳細は
[`database-adapter-plugins.md`](database-adapter-plugins.md)を参照してください。

```php
<?php

declare(strict_types=1);

return static function (array $context): void {
    $pdo = $context['pdo'];

    Hooks::addAction('article_published', static function (array $article) use ($pdo): void {
        // Plugin behavior belongs here.
    });
};
```

現在のコンテキストには `app_root`、`database`、`pdo`、`plugin`、`plugin_root`、
`database_capabilities`、`rag_documents` が含まれます。RAG ProviderはDB資格情報や正本tableを
参照せず、後者2つの読み取り専用境界を使用してください。契約は
[`rag-plugin-interface.md`](rag-plugin-interface.md)を参照してください。プラグインの起動エラーは
記録され、そのプラグインのフロントエンド資産はページへ追加されません。

### プラグイン固有API

ログイン済みユーザー向けのAPIは`api_request`アクションへ登録できます。アクション名は`plugin-<plugin-id>-...`のように名前空間化し、GET以外では必ず`requireCsrf()`を呼び出します。担当しないアクションでは何もせずreturnし、担当する場合は`jsonResponse()`または独自のストリーム応答を送ってexitしてください。

```php
Hooks::addAction('api_request', static function (string $action, string $method, PDO $pdo, array $user): void {
    if ($action !== 'plugin-activity-log-events' || $method !== 'GET') {
        return;
    }
    jsonResponse(['events' => []]);
});
```

## JavaScript と CSS

JavaScript はアプリ本体の `app.js` より後に `defer` で読み込まれます。エディター拡張は `window.OpenConceptEditor`、または `openconcept:editor-ready` イベントを利用できます。CSS は本体 CSS の後に読み込まれるため、プラグイン側のスタイルを適用できます。

サイドバーアイコンが押されたときの処理は、次のどちらかで登録できます。

```javascript
window.OpenConceptPlugins.register('activity-log', plugin => {
    console.log(`${plugin.label}を開きます`);
});

window.addEventListener('openconcept:plugin-open', event => {
    if (event.detail.id !== 'activity-log') return;
    event.preventDefault();
    // プラグイン固有の画面を開く処理
});
```

ブラウザーへはプラグイン固有の `version` を含む URL で配信されます。資産を変更して配布する場合は、必ずそのプラグインの `version` も更新してください。

## バージョン運用

- 互換性のある修正は patch（`0.1.0` → `0.1.1`）を上げます。
- 後方互換のある機能追加は minor（`0.1.1` → `0.2.0`）を上げます。
- 破壊的変更は major（`1.4.0` → `2.0.0`）を上げます。
- アプリ本体のリリース時に、理由なくプラグインのバージョンを同期させません。

配布前は次を実行します。

```powershell
php scripts/validate-plugins.php
php tests/PluginManagerTest.php
```

## 多言語化と翻訳 Provider

Plugin の UI 文字列は Plugin 内の `locales/<locale>.json` に置き、起動時に `I18n::registerPackage()` で登録します。公式配布Pluginは `en-US`、`ja-JP`、`vi-VN`、`ko-KR`、`zh-CN` の5 Packを同梱し、全Packでkeyとplaceholderの集合を一致させます。形式、fallback、key 規則は [`language-pack-specification.md`](language-pack-specification.md) を参照してください。`php tests/PluginLanguagePackTest.php` は全インストール済みPluginについてこの契約を検証します。

外部翻訳サービスを追加する Plugin は `TranslationProviderInterface` を実装し、Plugin context の `translation_providers` へ登録します。Page 保存、認可、revision、transaction は Core が担当します。完全な契約は [`translation-plugin-interface.md`](translation-plugin-interface.md) を参照してください。

## 設定画面からのダウンロード

2.3.1以降の管理者設定画面は、次の公式HTTPSカタログを使用します。`OPENCONCEPT_PLUGIN_CATALOG_URL`による任意URLの指定は使用しません。

```text
https://raw.githubusercontent.com/BloomingBlooming/openconcept_official/main/plugins/catalog.json
```

カタログと各パッケージは`BloomingBlooming/openconcept_official`の`main`から配信します。パッケージのパスは`plugins/packages/<plugin-id>/<version>/<plugin-id>-<version>.oc-plugin.json`に固定します。リダイレクト、別オリジン、同じGitHubホスト上の別所有者・別リポジトリ・別ブランチ、認証情報付きURL、追加クエリは拒否されます。HTTPSとSHA-256の確認は発行者の電子署名検証ではありません。

```json
{
  "schema_version": 1,
  "plugins": [
    {
      "id": "activity-log",
      "name": "Activity Log",
      "version": "0.1.0",
      "description": "操作履歴を確認できます。",
      "icon": "🧩",
      "download_url": "https://raw.githubusercontent.com/BloomingBlooming/openconcept_official/main/plugins/packages/activity-log/0.1.0/activity-log-0.1.0.oc-plugin.json",
      "sha256": "64文字の小文字SHA-256"
    }
  ]
}
```

配布パッケージはZIPではなく、拡張モジュールを必要としないJSON形式です。次のコマンドで生成できます。

```powershell
php scripts/package-plugin.php activity-log
```

コマンドが表示するSHA-256を公式カタログへ記載し、生成された `.oc-plugin.json` を上記の公式パスへ配置します。パッケージ化ではテストを除外し、秘密・runtimeファイルを拒否します。独立配布に必要なLICENSE・NOTICEをPlugin内へ含めてください。ダウンロード時にはパッケージ全体と各ファイルのSHA-256、ID、バージョン、マニフェスト、展開先を検証します。1パッケージは展開後10MB・200ファイルまでです。

ダウンロードしたプラグインは必ず無効状態で登録されます。管理者がトグルを有効にすると画面を再読み込みし、PHP・CSS・JavaScriptとサイドバーアイコンをまとめて有効化します。無効化時も同様に再読み込みし、プラグインコードとアイコンを取り除きます。
