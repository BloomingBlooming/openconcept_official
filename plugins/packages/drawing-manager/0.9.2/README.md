# 図面管理

## 個別配布と導入 / Standalone distribution and installation

図面管理0.9.2はOpenConceptの追加プラグインです。この版は共通Plugin API 1.0.0を使用するため、更新済みのOpenConcept V2.5.0が必要です。本体ZIPには含まれず、個別パッケージとして配布します。新規導入したパッケージは無効状態で、管理者が有効化します。

Drawing Manager 0.9.2 is an optional OpenConcept plugin requiring the updated V2.5.0 application and Plugin API 1.0.0. It is distributed separately from the application ZIP. New package installations are disabled until an administrator enables the plugin.

配布元 / Publisher: [BloomingBlooming/openconcept_official](https://github.com/BloomingBlooming/openconcept_official/tree/main/plugins).

配置先は`<application-root>/plugins/drawing-manager/`です。導入にはPHP実行ユーザーが`plugins/`へ書き込める必要があります。OpenConcept本体の認証、権限、DB、共通AI設定を使用し、SQLite・MySQL・PostgreSQLに対応します。

The installation directory is `<application-root>/plugins/drawing-manager/`. The PHP service identity needs write access to `plugins/` for installation. The plugin uses OpenConcept's authentication, permissions, database, and shared AI settings, and supports SQLite, MySQL, and PostgreSQL.

既存環境では`storage/plugins/drawing-manager/`、図面関連DBテーブル、有効状態を保持してください。ダウンロード機能は導入済みフォルダーを上書きしません。本体2.3.0から更新する場合も、既存の図面プラグインを削除する必要はありません。

Preserve `storage/plugins/drawing-manager/`, drawing database tables, and enabled state in existing installations. The downloader does not overwrite an installed directory. Updating from application 2.3.0 does not require deleting its existing drawing plugin.

個別パッケージにはOpenConceptの利用許諾条件とPDF.js等の第三者LICENSE・NOTICEを含めます。

Standalone packages include the OpenConcept license terms and third-party LICENSE/NOTICE files for PDF.js and other bundled assets.

## 機能

金属加工向け図面をOpenConceptへ保存し、検索・閲覧する簡易プラグインです。PDFまたは画像をアップロードすると、GPT-5.6 LunaのVision機能が「図面番号」「改訂番号」「品名」の3項目だけを読み取ります。

## 登録と承認の流れ

1. `admin`または`editor`がPDF、画像、またはCADファイルを選ぶ
2. PDF・画像ではGPT-5.6 Lunaが3項目と、その候補文字を囲む矩形座標を読み取る（CAD形式はファイル名による補助候補だけ）
3. ファイルと候補を、解析結果の成否にかかわらず「未承認」として自動保存する
4. 利用者が図面と照合し、図面番号・改訂番号・品名を必要に応じて訂正する
5. 照合確認にチェックして承認する

図面番号と品名は承認時に必須です。改訂番号が空欄の場合は「改訂なし」を表す`-`へ正規化して承認し、空欄を含めて照合確認の対象とします。顧客、材質、加工工程、加工方法の自由記入、担当部署、備考はすべて任意で、承認条件には含めません。

アップロード直後の自動保存は確定登録ではありません。未承認図面には明確な表示を付け、一般閲覧者には公開しません。`admin`と`editor`が確認・承認した後に通常の図面一覧へ公開されます。

## AI簡易読み取り

- 対象：PDF、PNG、JPEG、WebP
- AI抽出項目：図面番号、改訂番号、品名
- OCR矩形：候補文字、候補項目、ページ番号、ページ全体を0〜1000とした長方形座標
- モデル：`gpt-5.6-luna`
- API：OpenAI Responses APIの画像・PDF入力とStructured Outputs
- PDF詳細度：`high`
- 画像詳細度：`original`

PDFと画像は読み取りのためOpenAI APIへ送信されます。リクエストでは`store: false`を指定し、APIの生レスポンスや図面本文はプラグインのデータベースへ保存しません。画面で再利用するため、検証済みのOCR候補文字と矩形座標だけを図面ファイルのメタデータとして保存します。APIキー未設定、通信失敗、形式不一致、AI用サイズ上限超過、または未検出の場合でもファイルは失われず、未承認図面として保存されます。

AIの出力は確定値ではありません。画面の「3項目中N項目」は読み取れた項目数であり、正答率や信頼度ではありません。

DXF、DWG、STEP、IGESはOpenAI APIへ送信しません。

## 設定

AI読み取りにはサーバー環境変数`OPENAI_API_KEY`とPHP cURL拡張が必要です。互換用に`OpenAI_key`も読み取ります。

```dotenv
OPENAI_API_KEY=sk-your-key
OPENCONCEPT_DRAWING_VISION_TIMEOUT=120
OPENCONCEPT_DRAWING_VISION_RETRIES=1
OPENCONCEPT_DRAWING_VISION_MAX_MB=15
OPENCONCEPT_DRAWING_VISION_RATE_LIMIT=6
OPENCONCEPT_DRAWING_VISION_IMAGE_DETAIL=original
OPENCONCEPT_DRAWING_VISION_PDF_DETAIL=high
```

`OPENCONCEPT_DRAWING_VISION_MAX_MB`はBase64変換時のPHPメモリ消費とAPI費用を抑えるためのAI専用上限で、1〜49MBの範囲です。一般の保存上限とは別で、標準15MBです。一般の保存上限は`OPENCONCEPT_DRAWING_MAX_MB`で1〜200MBの範囲に変更でき、標準50MBです。

PHPの`upload_max_filesize`または`post_max_size`が一般の保存上限より小さい場合は、PHP側の上限が先に適用されます。

`OPENCONCEPT_DRAWING_VISION_RATE_LIMIT`は1セッション・1分あたりのAI読み取り回数で、標準6回です。

## 一覧と詳細

- `admin`と`editor`：すべて／未承認／承認済みを切り替えて表示
- その他のログインユーザー：承認済み図面だけを表示
- 図面番号、品名、顧客、材質、加工工程、加工方法、元ファイル名で検索
- カード表示：PDF先頭ページまたは画像図面のサムネイルを表示（CAD形式はファイル種別を表示）
- 一覧表示：サムネイルを読み込まず、状態・図面番号・改訂・品名・顧客・材質・更新日をテキストで表示
- 表示方法：カード／一覧の選択をブラウザーへ保存し、次回も同じ表示で開始
- ページング：1ページあたり20件（既定）・50件・100件から選択し、前後ボタンまたはページ番号で移動。表示件数の選択はブラウザーへ保存
- 未承認画面：アップロード図面を左、入力欄を右に表示しながら、3項目の確認・訂正、任意情報の入力、承認
- OCR矩形：入力欄を選択して最初の枠を押すと値を置換し、続けて別の枠を押すとOCR文字を末尾へ追加
- PDF：同梱したPDF.jsでページを描画し、ページ切替・75〜250%の拡大縮小とOCR矩形の座標同期に対応
- 画像図面：100〜300%の拡大縮小、全体表示、拡大時のスクロール／パン
- 狭い画面：図面を先、その下に入力欄を表示
- 終了操作：×またはEscapeで「図面管理を閉じますか？」を表示し、「はい」「いいえ」で選択
- 詳細ヘッダー：図面番号と品名を同じ大きさで横並びにし、「← 図面一覧へ」をスクロールしても見える位置に固定
- 管理者編集：`admin`は承認状態にかかわらず、図面詳細を常に編集モードで開いて保存可能
- 訂正コメント：対象図面を閲覧できるログインユーザーが登録内容の誤りと訂正内容を連絡すると、有効なシステム管理者の受信トレイへ通知。管理者本人の投稿も通知対象
- 通知から開く：受信トレイの通知をクリックすると、該当図面を開き、対象の訂正連絡を表示。現在の閲覧権限を再確認
- 未対応の表示：未対応の訂正連絡が1件以上あれば、カード・一覧の両表示に「訂正連絡あり」を表示。すべて対応済みになると解除
- 対応内容：`admin`が各訂正連絡へ対応内容を入力して対応済みに変更。内容・対応者・日時を保存し、元の連絡者にも受信トレイで通知
- 図面削除：詳細画面から警告を確認してアーカイブし、一覧・検索・比較・同一図面判定・ファイル取得の対象から除外
- 承認済み画面：承認者と承認日時を表示
- PDFと画像：画面内プレビュー
- 単一PDFの閲覧画面：PDFプレビューの高さを図面情報カードに合わせて表示
- DWG、DXF、STEP、IGES：ファイルを開いて確認

承認済み図面の一意性は「正規化した図面番号＋改訂番号」で判定します。同じ図面番号でも改訂番号が異なれば別図面として保持できます。未承認図面は候補の重複を許可し、承認時に競合を通知します。アーカイブ済み図面はこの同一判定から除外されるため、同じ図面番号・改訂番号を再登録できます。

## データ保存

メインアプリと同じPDO接続を使い、接頭辞付きの専用テーブルを作成します。

- `plugin_dwg_documents`
- `plugin_dwg_document_files`
- `plugin_dwg_correction_comments`

ファイル本体はMySQLへ格納せず、`storage/plugins/drawing-manager/`へ保存します。スキーマv2以前で移行開始時に登録済みの図面は承認済みとして引き継ぎ、デプロイ中に旧版から追加された図面は安全側の未承認になります。旧版の未登録一時アップロードは、従来どおり有効期限（2時間）を過ぎたものだけ自動削除します。

スキーマv4では`plugin_dwg_document_files.ocr_regions_json`へ、最大24件の検証済みOCR矩形メタデータを保存します。既存ファイルは空配列として移行されます。

スキーマv5では`plugin_dwg_documents.process_metadata_json`へ、選択した加工工程を安定した工程コードと日本語ラベルの組で保存します。加工方法の自由記入欄`process`とは分離され、既存図面は空配列として移行されます。

スキーマv6では`plugin_dwg_correction_comments`へ、図面登録の誤り・訂正内容、投稿者、投稿日時、対応者、対応日時を保存します。図面をアーカイブするとコメントもアプリから参照・投稿・更新できなくなります。

スキーマv7では同テーブルの`resolution_body`へ対応内容を追加保存します。既存の図面・訂正連絡・対応済み履歴は保持します。更新前の未対応連絡は、有効化後の通常起動で管理者の受信トレイへ補完し、同一連絡の通知を重複させません。通知から閲覧できなくなった図面や無効化されたプラグインを開くことはできません。

通知・アクション連携には本体の共通APIを使用します。通知をクリックした後に別のお知らせへ移った場合や閉じた場合、遅れて届いた古い応答で図面画面を開きません。

AI読み取り結果または登録時の改訂番号が空の場合は、改訂なしを表す`-`として保存します。ファイル名などから`1`を補完しません。

画面上の図面削除は物理削除ではなく、`plugin_dwg_documents.archived_at`を設定するアーカイブです。アーカイブ後は一覧・検索・比較・同一図面判定・詳細・ファイル配信から除外します。アプリには復活機能を設けず、運用上は永久削除として扱います。

PDF表示には`pdfjs-dist` 6.1.200（Apache-2.0）をプラグインへ同梱しています。ライセンスとNOTICEは`vendor/pdfjs/`にあります。PDF内スクリプトと動的評価は無効です。

## 権限

- 承認済み図面の閲覧・検索：ログインユーザー
- 訂正コメントの閲覧・投稿：対象図面を閲覧できるログインユーザー
- 未承認図面の閲覧、アップロード、訂正、承認：`admin`、`editor`
- 承認済みを含む図面詳細の変更、訂正コメントの対応済み化：`admin`
- 承認済み・未承認図面の削除：`admin`、`editor`

## 検証

```powershell
php scripts/validate-plugins.php
php plugins/drawing-manager/tests/DrawingVisionClientTest.php
php plugins/drawing-manager/tests/DrawingMetadataExtractorTest.php
php plugins/drawing-manager/tests/DrawingProcessMetadataTest.php
php plugins/drawing-manager/tests/DrawingControllerArchiveTest.php
php plugins/drawing-manager/tests/DrawingControllerAdminCorrectionTest.php
php plugins/drawing-manager/tests/DrawingCorrectionNotificationTest.php
php plugins/drawing-manager/tests/DrawingTransactionRetryTest.php
node plugins/drawing-manager/tests/DrawingCorrectionUiTest.cjs
php plugins/drawing-manager/tests/DrawingRepositoryTest.php
php plugins/drawing-manager/tests/DrawingRepositoryMySqlTest.php
php plugins/drawing-manager/tests/DrawingPluginContractTest.php
```

- Plugin ID: `drawing-manager`
- Version: `0.9.2`
