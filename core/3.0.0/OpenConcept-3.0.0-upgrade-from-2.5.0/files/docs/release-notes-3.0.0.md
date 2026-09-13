# OpenConcept V3.0.0 — 累積リリース / Cumulative release

V3.0.0はV2.6.1以降の差分と、未配布のV2.6.2・V2.7.0で開発・修正した内容をまとめた版です。履歴カレンダーやフロートNaviの改善、送信メール設定、招待・認証と各機能の5言語対応、写真の圧縮を含みます。V2.6.2とV2.7.0を順番に導入する必要はありません。本体の版番号を3.0.0に統一し、個別プラグインはそれぞれの版番号を維持します。

## 履歴・画面・ナビゲーション

- V2.6.1で追加・改善した履歴の全文保存、同一ページの履歴をまとめた表示、隣接する保存状態の比較、継続検索と中断後の再開を引き継ぎます。AI検索で同じページ本文が重複して候補を占めることを抑え、別のページ・発言・訂正付き履歴を保持します。
- ダークモード、13種類のカバー、96種類のページアイコン、左メニューの開閉、ページ状態の表示と配下への一括変更、履歴のページ送り、有効化前のプラグイン確認を引き継ぎます。
- 「記録と履歴」の開始日・終了日のカレンダーは英語・日本語・ベトナム語・韓国語・簡体字中国語に対応します。月名、曜日、今日・クリア、月移動、キーボード操作を選択言語で利用できます。検索APIと直接入力の日付は従来の `YYYY-MM-DD` です。
- 別配布のフロートNavi 1.2.3は、開閉状態、位置、サイズ、透過度、背景モードを同じブラウザー・同じアプリURLで記憶し、履歴画面との往復や再読み込み後に復元します。
- 既公開の図面管理0.9.3とフロートNavi 1.2.2の互換性を維持し、今回の修正を含む図面管理0.9.4とフロートNavi 1.2.3を独立配布します。本体ZIPで既存プラグインを置き換えず、必要なプラグイン更新を別に適用します。
- 新しいプラグインIDもマニフェスト・API・機能要件に従って利用できます。既存プラグインの最低版確認、管理者権限、CSRF、無効化状態を保持します。

## メール・メンバー招待・ログイン

- 管理者の「設定 > メール」で、送信元、SMTPサーバー・ポート、SSL/TLSまたはSTARTTLS、アカウント、パスワード、返信先、タイムアウトを設定できます。保存後に管理者本人宛てのテストメールを送信できます。
- UIで保存したSMTP設定は既存のSecretストアを使って暗号化します。パスワードを画面、API応答、監査ログへ返しません。既存の環境変数設定は初回保存まで利用できます。ローカル起動のログ専用ガードを維持し、ログ記録と実送信を画面で区別します。
- 招待時にメンバーの言語を選択できます。招待、再送、パスワード再設定、アカウント復活の件名・本文とログインリンクに反映します。新規招待APIで言語を省略した場合は英語です。
- ログイン前、初期セットアップ、初回パスワード変更から言語を選べます。`?lang=vi-VN` のようなリンクにも対応します。認証・セッション・入力エラーと、パスワード変更後の画面に選択を引き継ぎます。明示的な言語指定のない通常ログインは保存済みのユーザー言語を優先します。
- 言語選択のラベルは全言語で英語の **Language** とし、選択肢は各言語の自国語名で表示します。初回パスワード変更が終わるまではワークスペース機能を利用できません。

## プロフィール写真

- 新しい写真をサーバー側で長辺256px以内・128KiB以内へ縮小・再エンコードし、不要な撮影情報等を除去します。JPEGの向き、PNG・WebPの透明度を保持します。
- 不正な画像や圧縮失敗時は既存のアイコンを保持します。以前に保存した大きな写真には軽量画像のキャッシュを作り、元画像とDB参照を保持します。
- PHP GDでJPEG・PNG・WebPを利用可能にしてください。同梱Dockerイメージのビルド定義はGDを含みます。GDがない場合は新しい写真の保存時に設定不足を表示し、既存写真の閲覧は維持します。

## 5言語の表示と出力

- メンバー管理、プロフィール、ページ、ファイル、共有、履歴、検索、RAG、AI、翻訳、プラグインのシステム案内・検証エラーを5言語へ反映しました。通知と新しいページの初期タイトル・分類も選択言語で表示します。
- Markdown、印刷、PDF用HTMLの状態、メタ情報、説明、日時、空内容、エラーを翻訳します。HTMLの画面言語と本文の言語を分けて指定します。旧動的共有URLの廃止案内、保守中の進捗、起動前の診断案内にも対応します。
- 静的公開ページの日付形式の変更も更新判定へ反映します。Windowsで旧生成物の削除時に列挙ハンドルが残り、更新が復旧待ちになる問題を修正しました。管理対象外のパスとリンクの拒否は維持します。
- UI言語を切り替えても、人間が入力したタイトル・本文・名前・分類・タグ・ファイル名・コード・リンクを自動翻訳しません。ページ本文の正式な翻訳は、従来どおり翻訳機能で明示的に実行します。

## 互換性と運用

SQLiteをネイティブ・標準DBとし、MySQL・PostgreSQLは既存の任意DB Pluginとして扱います。V3.0.0への版更新でDB構成を再編しません。Core DBスキーマ世代6、Plugin API 1.0.0、既存のAPI・設定キー・保存形式・履歴・添付・秘密値・プラグインの有効状態を引き継ぎます。アプリ表示とDockerイメージの版は3.0.0ですが、既存データを参照するComposeプロジェクト名、volume名、環境識別子は変更しません。

SMTP設定は保存後にテストし、受信トレイでも到着を確認してください。送信成功はSMTPサーバーによる受付を表します。暗号化なしのSMTPはローカルテスト用に限ります。既存の操作説明書ページや利用者が編集した本文は更新時に書き換えません。新規設置で登録される説明書はV3.0.0です。

## 配布と更新

- 新規設置用：`OpenConcept-3.0.0-public.zip`
- 直接更新用：`OpenConcept-3.0.0-upgrade-from-2.4.1.zip`、`OpenConcept-3.0.0-upgrade-from-2.5.0.zip`、`OpenConcept-3.0.0-upgrade-from-2.6.0.zip`、`OpenConcept-3.0.0-upgrade-from-2.6.1.zip`
- 各ZIPにはSHA-256検証ファイルを用意します。更新ZIP内の `README.ja-en.md` と `UPGRADE-MANIFEST.json` で元バージョン・収録ファイル・変更前後のハッシュを確認してください。

更新前に環境全体をバックアップし、書き込みとワーカーを停止します。導入済みバージョンに対応する更新ZIPの `files/` を既存アプリルートへ結合し、独自改修は差分を確認して反映してください。環境設定、DB、添付、鍵、独立プラグインを保持し、既存アプリ全体を削除・置換しないでください。Docker利用時は設置先の構成と同梱の参照用構成を比較し、イメージを再ビルドします。図面管理とフロートNaviは本体と別配布です。

開発途中のV2.6.2・V2.7.0を設置した環境や独自改修を含む環境には、公開済み旧版のハッシュ前提をそのまま当てはめられません。元のファイルと変更内容を比較し、データを保持して更新してください。

## 検証範囲

5言語の招待・初回ログイン・パスワード再設定、ローカル疑似SMTPの成功・失敗、写真処理、API・画面描画・通知・出力を隔離環境で検証しました。英語・ベトナム語・韓国語・中国語を担当したエージェントが再現ケースを再検査し、翻訳対象のアプリ文言と保持すべき人間の入力を区別しています。公開・更新パッケージはファイル一覧・ハッシュ・収録範囲と初期化・更新の回帰テストで検査します。

外部SMTPの実配送、外部AIサービス、音声機器、実プリンターとすべてのブラウザー・役割・入力の組合せは、このローカル検証の完了範囲に含みません。導入先でもメール受信、既存ページ・添付・履歴、権限、プラグインのON/OFFを確認してください。

## English

Version 3.0.0 consolidates the changes since 2.6.1 and the unreleased 2.6.2 and 2.7.0 work. It retains full history, grouped revision display, adjacent-state comparisons, resumable search, dark mode, page appearance and status controls, and plugin activation checks. The history calendar follows all five official interface languages. The separately distributed Float Navi 1.2.3 retains its open state, position, size, transparency and background preferences. Existing Drawing Manager 0.9.3 and Float Navi 1.2.2 remain compatible; Drawing Manager 0.9.4 and Float Navi 1.2.3 carry the latest fixes as independent plugin packages.

Administrators can configure outgoing SMTP accounts and send a test to themselves in Settings > Mail. Saved secrets are encrypted; existing environment settings remain available until the first UI save. Invitations, resends, password resets and account reactivation use the member's language. Sign-in, setup and first-password changes support language selection and localized errors. Language selectors retain the English label **Language** and native language names.

Profile photos are resized to a maximum dimension of 256px and at most 128KiB, retaining orientation and supported transparency. Existing large images receive a lightweight cache. Enable PHP GD with JPEG, PNG and WebP support. Failed processing preserves the existing icon.

System errors, notifications, new-page defaults, RAG/AI/plugin messages, Markdown, print/PDF HTML, public-page dates and startup/maintenance messages now follow the selected language. User-authored titles, body text, names, categories, tags, filenames, code and links are never automatically translated by an interface-language change. Static publication now tracks its date-localization dependencies and safely closes directory enumeration before removing old generations on Windows.

SQLite remains the standard database. Core schema generation 6, Plugin API 1.0.0, existing data and settings, and Compose project/volume identities remain intact. Use the public ZIP for new installations or the matching direct upgrade from 2.4.1, 2.5.0, 2.6.0 or 2.6.1. Verify SHA-256 and follow the included bilingual merge instructions, preserving private configuration, databases, uploads, keys and independent plugins. Development snapshots require a separate file comparison. Existing editable manual pages are preserved.

Local tests cover five-language onboarding, simulated SMTP, images, HTTP errors, rendered UI, notifications and exports. Actual external email delivery, AI services, recording devices, printers, and every browser/role combination require installation-specific checks. Historical development notes remain available for [2.6.2](release-notes-2.6.2.md) and [2.7.0](release-notes-2.7.0.md); their changes are included in this cumulative release.
