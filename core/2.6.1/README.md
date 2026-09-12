# OpenConcept 2.6.1

最新版 / Latest: **v2.6.1**

これまでの変更をまとめた通常配布版と、V2.6.0からの更新版です。

This release includes all changes through V2.6.1, with a full installation package and an upgrade from V2.6.0.

| 用途 / Purpose | ダウンロード / Download | SHA-256 | 内容・手順 / Instructions |
| --- | --- | --- | --- |
| 新規設置 / New installation | [OpenConcept 2.6.1 ZIP](OpenConcept-2.6.1-public.zip) | [Checksum](OpenConcept-2.6.1-public.zip.sha256) | [設置手順 / Setup](OpenConcept-2.6.1-public/HTTP-SERVER-SETUP.ja-en.md) |
| V2.6.0から更新 / Upgrade from V2.6.0 | [Upgrade ZIP](OpenConcept-2.6.1-upgrade-from-2.6.0.zip) | [Checksum](OpenConcept-2.6.1-upgrade-from-2.6.0.zip.sha256) | [更新手順 / Instructions](OpenConcept-2.6.1-upgrade-from-2.6.0/README.ja-en.md) |

## 主な変更 / Changes

- 設定 → 一般の最後尾にアプリ全体のライト／ダークモードを追加。ブラウザーに保存します。
- カバーサンプルの表示を修正し、黒・濃い青系・原色の赤／青／黄色を追加。「なし」を含め13種類です。
- アイコンを96種類へ拡充。文房具・ノート、オフィス用品、パソコン・フォルダーなど4分類で選べます。
- 受信トレイ下の三点アイコンで「AI検索」「記録と履歴」を開閉。初期状態は非表示です。
- ページツリーの状態マークとツールチップ、フォルダー配下の状態一括変更、履歴のページ送り、プラグイン有効化の確認と新規IDへの対応を含みます。
- フロートNavi 1.2.2は個別配布です。左ツリーとの同期、移動・サイズ変更、透過、背景モード、AI検索より手前への表示に対応します。
- フロートNaviの最低対応版1.0.0の登録を含みます。V2.6.1では最新版1.2.2をそのまま有効化でき、追加の本体互換パッチは不要です。図面管理の個別配布最新版は0.9.3です。

Adds browser-persisted dark mode, 13 cover choices including No cover, 96 icons in four groups, and the three-dot navigation toggle below Inbox. Also includes page status marks and tooltips, bulk status changes for descendants, history pagination, and improved plugin activation. Float Navi 1.2.2 is a separate optional plugin with synchronized floating navigation, transparency, background modes, and display above AI search.

[リリースノート / Release notes](OpenConcept-2.6.1-public/docs/release-notes-2.6.1.md) · [外観と左メニュー / Appearance and navigation](OpenConcept-2.6.1-public/docs/dark-mode-2.6.1.md) · [日本語操作説明書 / Operation manual](OpenConcept-2.6.1-public/docs/openconcept-operation-manual.ja.md) · [配布物情報 / Artifact metadata](OpenConcept-2.6.1-artifacts.json)

## 設置・更新 / Installation and upgrade

新規設置は本体ZIPを展開し、同梱の設置手順に従います。通常のDocument Rootはアプリ内の `public/` です。

V2.6.0からは環境全体をバックアップして書き込み・ワーカーを停止し、更新版の `files/` を既存アプリルートへ結合します。既存フォルダー全体を削除・置換しないでください。DB、添付、環境設定、鍵、個別プラグインを保持します。Dockerのcomposeは参照ファイルとの差分を手動で反映します。

V2.5.0以前からは、2.5.0 → 2.6.0 → 2.6.1の順で、使用中の版に合う更新を適用してください。Core DBスキーマ世代6とPlugin API 1.0.0は維持します。以前のID制限で起動できなかったプラグインでも、保存設定がONで互換条件を満たす場合は更新後に起動するため、導入済みプラグインとON/OFFを確認してください。図面管理・フロートNaviの本体は個別に導入・更新します。

For a new installation, extract the public ZIP and follow its setup guide, normally using the application's `public/` directory as the document root. For an existing V2.6.0 installation, back up the entire environment, pause writers and workers, and merge the upgrade's `files/` into the application root. Preserve runtime data and private configuration; review the Docker compose reference manually. Older installations must follow the intermediate upgrades in order. Core schema generation 6 and Plugin API 1.0.0 remain unchanged. Review installed plugins and their enabled states; previously blocked IDs may start if compatible. Drawing Manager and Float Navi are installed or updated separately.

## 検証 / Verification

ZIPは隣接する `.sha256`、展開済みファイルは[本体manifest](OpenConcept-2.6.1-public/DISTRIBUTION-MANIFEST.json)と[更新manifest](OpenConcept-2.6.1-upgrade-from-2.6.0/UPGRADE-MANIFEST.json)で確認できます。全ファイルのサイズ・SHA-256・ZIPとの一致、新規設置とV2.6.0からの更新を検証済みです。

Verify ZIPs with the adjacent `.sha256` files and unpacked contents with the manifests. File sizes, hashes, ZIP contents, new installation and the V2.6.0 upgrade have been verified. Distribution files are generated in the OpenConcept project's `Dist/core/` directory.
