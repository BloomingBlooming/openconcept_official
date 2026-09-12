# OpenConcept 2.6.0 → 2.6.1

2026-09-12 履歴表示・検索改善版。全文履歴を保持したまま、ページ単位の表示・保存状態の比較・検索の継続と再開・AI候補の重複整理を追加しました。バージョン番号は2.6.1です。

History display and search refresh, 2026-09-12. Full snapshots are preserved, with grouped history, saved-state comparisons, resumable search, and duplicate handling in AI retrieval. The version remains 2.6.1.

既存2.6.1へ今回の改善だけを取り込む場合も、以下の停止・バックアップ・独自改修確認を行い `files/` を手動で結合できます。manifestの元版・変更前ハッシュは2.6.0を基準としているため、2.6.1への自動適用の事前条件には使いません。同じ版番号の配布物はZIPのSHA-256とリリースノートで識別してください。

For an existing 2.6.1 installation, manually merge `files/` after the same backup, pause and customization review described below. The manifest's source version and before-hashes describe 2.6.0 and must not be used as automatic preconditions for 2.6.1. Distinguish same-version releases by the ZIP SHA-256 and release notes.

アプリのダークモード（設定 → 一般の最後尾）、カバー13種類（なしを含む）・アイコン96種類、カバーサンプルの表示修正、受信トレイ下の三点アイコンによるAI検索・記録と履歴の開閉も含みます。フロートNaviの透過・背景モード・AI検索より手前への表示は、個別プラグイン1.2.2で利用できます。既存のフロートNaviは別途更新してください。

Also includes app-wide dark mode at the end of Settings → General, 13 cover choices including No cover, 96 icons, corrected cover swatches, and the three-dot toggle below Inbox. Float Navi's transparency, background modes and display above AI search are provided by the separately updated Float Navi 1.2.2 plugin.

このパッケージはV2.6.0公開配布版を対象とするファイル結合更新です。新規プラグインIDの事前登録制限を解除します。Core DBスキーマ世代6、Plugin API 1.0.0、既存プラグインの最低対応版は変更しません。

ページツリーの状態マーク、「（状態）ページ名」のツールチップ、フォルダーと配下ページの状態一括変更、記録と履歴のページ送り（10・25・50件）、プラグイン有効化時の確認を含みます。更新後、管理者・編集者・投稿者では状態マークを確認できます。フォルダーのプロパティーで一括変更をチェックして状態を保存すると、孫ページまで反映されます。ブラウザーに古い表示が残る場合は再読み込みしてください。

1. `.env`、DB、添付、鍵、設定を含む環境全体をバックアップし、復旧手順を確認します。インストール済みプラグインの配布元とON/OFF設定も確認してください。以前はID制限で起動できなかったプラグインでも、保存設定がONなら更新後に起動対象になる場合があります。
2. 書き込み・ワーカーを停止し、独自改修と `UPGRADE-MANIFEST.json` の差分を確認します。
3. `files/` の内容を既存アプリルートへ結合します。既存フォルダー全体を削除・置換せず、DB・添付・秘密情報・個別プラグインを保持します。
4. Docker利用時は `reference/compose.rag.yaml` を既存の設定と比較し、必要なイメージ版の変更を手動で反映・再ビルドします。参照ファイルで独自の環境設定を上書きしないでください。
5. PHPキャッシュを更新して再開し、表示バージョン2.6.1、ログイン、ページ、添付、記録と履歴、プラグインのON/OFFを確認します。必要なら評価済みの新規IDプラグインをOFFでインストールし、管理者が有効化・動作確認します。

更新用ファイルは個別プラグイン本体を置き換えません。新規設置用ZIPを既存環境へ丸ごと展開する手順とは区別してください。V2.5.0以前からは、先に対応する更新手順でV2.6.0へ更新します。

This merge-only update targets the sanitized v2.6.0 distribution. It removes Core ID preregistration for plugins while retaining Core schema generation 6, Plugin API 1.0.0, and existing minimum plugin versions.

It also adds sidebar status marks, status-prefixed full-title tooltips, opt-in status changes for a folder and all active descendants, history pagination with 10/25/50 results per page, and confirmation before enabling plugins. Reload the browser after the update if stale assets remain visible.

Back up the complete environment and review installed plugins and their stored enabled states. A previously unlisted plugin may now start if its saved state is enabled and its other requirements are met. Pause writers and workers, review local customizations against `UPGRADE-MANIFEST.json`, and merge `files/` into the application root without deleting existing directories or runtime data.

For Docker installations, manually compare `reference/compose.rag.yaml`, retain deployment-specific settings, and rebuild the application image as needed. Refresh PHP caches, resume, and verify version 2.6.1, sign-in, pages, attachments, history, and plugin states. Individual plugin packages are not replaced by this update. See `docs/release-notes-2.6.1.md` for the behavior and compatibility contract.
