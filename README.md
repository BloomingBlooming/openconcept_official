# OpenConcept 公式配布 / Official Distribution

最新の本体は **V3.0.0** です。V2.6.1以降の変更をまとめ、送信メール設定、5言語の招待・ログイン・初回パスワード変更、プロフィール写真の縮小、各機能の翻訳を改善しました。履歴カレンダーの言語対応と、独立プラグインのフロートNaviによる状態保持も利用できます。

The latest application release is **V3.0.0**. It consolidates changes since V2.6.1, including outgoing email settings, invitations and authentication in five languages, profile photo compression, localized system messages and exports, and the history calendar. Float Navi preferences are provided by its separately distributed plugin.

## 本体ダウンロード / Application downloads

| 用途 / Purpose | ZIP | SHA-256 | 手順 / Guide |
| --- | --- | --- | --- |
| V3.0.0 新規設置 / New installation | [Download](core/3.0.0/OpenConcept-3.0.0-public.zip) | [Checksum](core/3.0.0/OpenConcept-3.0.0-public.zip.sha256) | [設置手順](core/3.0.0/OpenConcept-3.0.0-public/README.md) |
| V2.4.1 → V3.0.0 | [Upgrade](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.4.1.zip) | [Checksum](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.4.1.zip.sha256) | [更新手順](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.4.1/README.ja-en.md) |
| V2.5.0 → V3.0.0 | [Upgrade](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.5.0.zip) | [Checksum](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.5.0.zip.sha256) | [更新手順](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.5.0/README.ja-en.md) |
| V2.6.0 → V3.0.0 | [Upgrade](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.6.0.zip) | [Checksum](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.6.0.zip.sha256) | [更新手順](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.6.0/README.ja-en.md) |
| V2.6.1 → V3.0.0 | [Upgrade](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.6.1.zip) | [Checksum](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.6.1.zip.sha256) | [更新手順](core/3.0.0/OpenConcept-3.0.0-upgrade-from-2.6.1/README.ja-en.md) |

[配布物一式・展開版](core/3.0.0/README.md) · [リリースノート](core/3.0.0/OpenConcept-3.0.0-public/docs/release-notes-3.0.0.md) · [検証済みハッシュ一覧](core/3.0.0/OpenConcept-3.0.0-artifacts.json)

更新元に合う更新版を使用してください。V2.4.1は初回公開版とSQLite初回起動修正版の両方に対応し、中間バージョンの導入は不要です。バックアップを取り、同梱手順に従って更新用ファイルを結合します。DB・添付・設定・鍵・独立プラグインのデータを保持します。更新通知は案内を表示する機能で、本体を自動的にインストールしません。

Use the upgrade matching the installed version. Both published V2.4.1 revisions are supported, and no intermediate release is needed. Back up the installation and follow the included merge instructions, retaining databases, uploaded files, settings, keys and standalone plugin data. Application update notifications provide instructions; they do not automatically install the Core release.

## V3.0.0の主な改善 / Highlights

- 管理者が画面からSMTP送信アカウントを設定し、招待メールを相手の言語で送信できます。実際のメール到達は導入先の送信アカウントで確認してください。
- 日本語・英語・ベトナム語・韓国語・簡体字中国語で、ログイン・初回パスワード変更・システム案内・通知・出力に対応します。言語選択のラベルは全言語で **Language** です。人間が入力した名前・本文等は言語変更で自動翻訳しません。
- プロフィール写真を長辺256px以内・128KiB以内へ縮小します。PHP GDのJPEG・PNG・WebP対応が必要です。
- SQLiteを標準DBとし、Coreスキーマ世代6とPlugin API 1.0.0を維持します。V2.4.1からの更新では世代5から6への追加テーブル移行を行います。

Administrators can configure SMTP accounts and invite members in their language. Verify actual email delivery with the installation's sending account. Authentication, fixed system messages, notifications and exports support Japanese, English, Vietnamese, Korean and Simplified Chinese; user-authored content remains unchanged. Profile photos are reduced to at most 256px and 128KiB using PHP GD. SQLite remains standard, with Core schema generation 6 and Plugin API 1.0.0; the V2.4.1 upgrade adds the generation 6 extension tables.

## 公式プラグイン / Official plugins

| プラグイン | 最新版 | パッケージ・手順 |
| --- | --- | --- |
| 図面管理 / Drawing Manager | 0.9.4 | [Package](plugins/packages/drawing-manager/0.9.4/drawing-manager-0.9.4.oc-plugin.json) · [SHA-256](plugins/packages/drawing-manager/0.9.4/drawing-manager-0.9.4.oc-plugin.json.sha256) · [Guide](plugins/packages/drawing-manager/0.9.4/README.md) |
| フロートNavi / Float Navi | 1.2.3 | [Package](plugins/packages/float-navi/1.2.3/float-navi-1.2.3.oc-plugin.json) · [ZIP](plugins/packages/float-navi/1.2.3/float-navi-1.2.3.zip) · [Guide](plugins/packages/float-navi/1.2.3/README.md) |

両プラグインは本体と独立して更新できます。新規導入は「設定 > プラグイン > 公式ダウンロード」、導入済みの更新はプラグイン一覧の「Update」（旧本体では「UpDate」）を使用します。設定・登録データ・添付・ON/OFFを保持します。図面管理0.9.4とフロートNavi1.2.3はCore V2.6.1・V3.0.0で検証済みです。図面管理の固定APIエラー翻訳はCore V3.0.0で利用できます。

Plugins are updated independently of Core. Use Official downloads for new installations and Update (UpDate on older Core) for existing plugins. Settings, records, attachments and enabled state are retained. Both plugins were verified on Core V2.6.1 and V3.0.0. Drawing Manager's translated API errors are available on Core V3.0.0. See the [plugin directory](plugins/README.md) and [catalog](plugins/catalog.json).

## 配布物の管理 / Distribution management

配布物はOpenConcept開発プロジェクトのDistから取得し、検証済みの内容のまま掲載しています。本体は `core/3.0.0/`、独立プラグインは `plugins/packages/<plugin-id>/<version>/` に配置します。旧配布物は開発側のアーカイブとGit履歴に保持します。

Distributions are copied unchanged from the development project's verified Dist artifacts. Current Core packages are under `core/3.0.0/`, and standalone plugins under `plugins/packages/<plugin-id>/<version>/`. Earlier artifacts remain in the development archive and Git history.

## ライセンス / License

OpenConceptには独自の「OpenConcept 利用許諾条件 第1.1版」が適用されます。全文を確認してください。第三者資産には、それぞれのライセンスが適用されます。

OpenConcept is distributed under the custom OpenConcept License Terms, Version 1.1. Please read the full terms. Bundled third-party assets retain their respective licenses.

- 日本語原文：[Markdown](LICENSE.ja.md) / [TXT](LICENSE.ja.txt)
- English translation: [Markdown](LICENSE.en.md) / [TXT](LICENSE.en.txt)
