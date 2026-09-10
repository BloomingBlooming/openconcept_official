# OpenConcept 2.5.0 → 2.6.0

このパッケージはv2.5.0公開配布版を対象にしたファイル結合更新です。v2.6.0は過去の会話・ページ旧版・添付原本を保持して検索・追記・出力する機能を追加します。Core DBスキーマは世代6のままです。

1. `.env`、DB、添付、鍵、設定を含む従来の環境全体バックアップを作成し、復旧手順を確認します。新しい正本出力は環境全体バックアップの代替ではありません。
2. 書き込み・ワーカーを止め、独自改修と `UPGRADE-MANIFEST.json` の差分を確認します。
3. `files/` の内容を既存アプリルートへ結合します。既存フォルダー全体の削除・置換は行いません。
4. NginxでPHP入口を許可リストにしている場合は、同梱docs/nginx.confを参照してhistory.phpを追加します。PHPキャッシュを更新して再開し、表示バージョン2.6.0、ログイン、既存ページ・添付・プラグイン状態を確認します。
5. 「記録と履歴」で旧版・取込・原文参照を確認します。詳しくは `docs/release-notes-2.6.0.md` を参照してください。

完全削除は無効となり、旧添付を保持するため保存容量が増加します。根拠不足時のAI一般知識への自動切替を停止します。変更案の承認／却下は元の回答を残して追記します。

This merge-only update targets the sanitized v2.5.0 distribution. Core schema generation 6 remains unchanged. Back up the complete environment, pause writers and workers, review local customizations against the manifest, and merge `files/` into the application root. Keep existing runtime directories and configuration. Refresh PHP caches, resume, and verify version 2.6.0, sign-in, pages, attachments, and plugin state.

The new history view retains originals and separate corrections. Permanent page deletion is disabled, removed attachments remain retained, and unsupported AI answers no longer automatically fall back to general knowledge. Storage usage grows with retained history. Portable knowledge archives support verification and restoration into a new isolated SQLite destination; they omit credentials and do not replace environment recovery backups.
