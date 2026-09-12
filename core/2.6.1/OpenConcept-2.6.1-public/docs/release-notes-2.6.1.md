# OpenConcept V2.6.1 — ダークモード・ページ外観・ナビゲーションと管理機能の改善

V2.6.1では、アプリ全体のダークモード、ページのカバーとアイコンの追加、左メニューの折りたたみを追加しました。ページの状態表示と配下ページへの一括変更、記録と履歴のページ送り、プラグイン有効化時の確認も含みます。本体の固定ID表にないプラグインが有効化を拒否される問題も修正し、新規IDの追加のために本体のPHPコードを編集する必要がなくなります。

## 変更内容

- 「設定 → 一般」の最後尾でライト／ダークモードを切り替えられます。設定はブラウザーに保存し、再読み込み・再ログイン・別タブにも反映します。ダークモードではページツリーと「新しいページ」の境界を控えめに表示します。
- カバー選択のサンプルが空になる表示を修正。黒、ネイビー、ミッドナイトブルー、原色の赤・青・黄色を追加し、「カバーなし」を含め13種類になりました。保存・再読み込み・公開ページでも色を保持します。
- ページアイコンを24種類から96種類へ増やし、文房具・ノート、オフィス用品、パソコン・フォルダー、基本・その他の4分類に整理しました。
- 左メニューの「AI検索」と「記録と履歴」を受信トレイの下へ移動し、初期状態で隠します。三点アイコンで表示・非表示を切り替え、再読み込み時は非表示に戻ります。
- 個別配布のフロートNavi 1.2.2に対応します。同期するページツリーを移動・サイズ変更可能な窓に表示し、歯車から透過度や背景モードを選択できます。AI検索との重なりでもフロートNaviが手前に表示されます。本体とは別に導入・更新します。
- `plugins/<plugin-id>/plugin.json` の形式と宣言されたAPI要件に基づいて有効化を判定します。新規IDのインストール、有効化、次のリクエストでの起動、サイドバー・宣言アセットの提供、無効化に対応します。
- `requires.plugin_api` のmajor・最低版と `requires.capabilities` を確認します。APIを宣言しない従来のHookプラグインも引き続き利用できます。
- 既存プラグインの最低対応版を保持します。固定ID表は新規IDの許可リストとして使用しません。
- フロートNaviの最低対応プラグイン版を1.0.0として登録しました。最新版1.2.2を含む1.0.0以降の版を使用でき、V2.6.1への更新後はフロートNavi用の本体互換パッチは不要です。
- 不正なID、フォルダー名との不一致、予約ID、不正なマニフェストは引き続き拒否します。非対応API・不足機能・起動失敗時の無効化、アセット公開の制御、管理者権限とCSRFの検査を維持します。
- サイドバーのページアイコンと名前の間に、承認待ちは黄色の「♦」、下書きは白い「■」、非公開は赤い「✖」を表示します。公開ページにはマークを付けません。表示対象はシステム管理者・コンテンツ管理者・編集者・投稿者です。ツリー、検索結果、お気に入りに適用し、閲覧権限は変更しません。
- 通常表示は「アイコン＋状態マーク＋ページ名」とし、状態名の文字や区切りの「：」は付けません。ページ名やマークにマウスを合わせると、「（状態）ページ名」のツールチップで全文を確認できます。公開ページも「（公開）ページ名」と表示し、ツールチップはすべての閲覧可能な役割で利用できます。
- 子ページを持つフォルダーページのプロパティーに「子ページ・子フォルダーも同じ状態にする」を追加しました。チェックして状態を選び保存すると、フォルダー自身と孫ページを含む配下のすべてに適用します。チェックは保存操作ごとに選択し、子ページのないページには表示しません。ゴミ箱内のページは対象外です。
- 一括変更は対象すべての編集権限とWeb公開制限を確認し、途中で失敗した場合は全体を取り消します。各ページの本文・添付・翻訳情報を保持し、変更履歴を残します。状態の「非公開」は既存の単独変更と同様に閲覧範囲も非公開へ連動し、非公開から戻す場合は社内へ戻ります。その他の子ページの共有設定は保持します。
- 「記録と履歴」の根拠一覧にページ送りと表示件数（10・25・50件、初期値10件）を追加しました。一覧の上下からページを移動でき、検索条件や表示件数を変更すると先頭へ戻ります。
- プラグインを有効化する前に確認画面を表示します。キャンセルでは設定を変更せず、有効化成功後に画面を再読み込みして反映します。

有効化可能という判定は、宣言された互換条件の確認です。第三者プラグインの安全性や動作を保証するものではありません。管理者が配布元と内容を確認し、導入先に適したテストを行ってください。

## 下位互換

Core DBスキーマは世代6、Plugin APIは1.0.0を維持します。既存API、設定キー、保存形式、プラグインのID・バージョン、ON/OFF状態、利用者データを変更しません。既存プラグインの最低対応版もV2.6.0と同じです。

以前の固定ID制限で起動できなかったプラグインについて、保存済みの有効化設定がONの場合、更新後に他の互換条件を満たせば起動対象になります。更新前にインストール済みプラグインとON/OFF設定を確認してください。新規パッケージのインストール時は従来どおりOFFで登録され、管理者による有効化が必要です。

V2.6.0の記録と履歴、旧添付の保持、会話取込、正本出力と隔離復元は継続します。操作説明書の版表示はV2.6.1になりますが、既存の利用者ページを書き換えません。

## 配布と更新

- 新規設置用：`OpenConcept-2.6.1-public.zip`
- V2.6.0からの更新用：`OpenConcept-2.6.1-upgrade-from-2.6.0.zip`
- 各ZIPにSHA-256検証ファイルを付属します。

更新前に環境全体をバックアップし、書き込みとワーカーを停止して、更新パッケージの説明書に従い `files/` を既存アプリルートへ結合します。既存フォルダーを丸ごと削除・置換しないでください。環境設定、DB、添付、鍵、個別のプラグインを保持します。Dockerを利用している場合は、参照用composeファイルとの差分を確認してイメージを再ビルドします。

V2.5.0以前からは対応する既存の更新パッケージでV2.6.0にしてから、この更新を適用してください。図面管理とフロートNaviは別配布で、本体の更新パッケージには含みません。

## English

Version 2.6.1 adds browser-persisted light/dark appearance under Settings → General, fixes missing cover swatches, and expands covers to 13 choices including No cover and icons to 96 choices in four categories. New covers include black, navy, midnight blue and primary red, blue and yellow. AI search and Records and history move below Inbox, hidden initially and toggled with the three-dot icon. Float Navi 1.2.2 is a separate optional plugin with synchronized floating navigation, transparency, dark backgrounds and foreground display over AI search.

Version 2.6.1 allows valid new plugin IDs without editing a Core registration table. Manifest validation, API major/minimum version checks, capability checks, existing plugin minimum versions, administrator authorization, and failure isolation remain in place. This compatibility decision is not a safety or functionality guarantee for third-party plugins.

Sidebar pages show a yellow ♦ for pending approval, a white ■ for drafts, and a red ✖ for private pages between the icon and title, without a status word or colon. Published pages have no mark. Marks appear only for system/content administrators, editors, and authors, including search results and favorites. Hovering shows the localized status in parentheses before the full title, including published pages, for all roles that can view the page.

Pages with children have an opt-in property to apply the selected status to themselves and every active descendant, including nested folders. Each save requires selecting the checkbox again. Archived branches are excluded. All editing permissions and active web-publication restrictions are checked before saving; failures roll back the entire change. Child content, attachments, translation metadata, and history are retained. Private status also makes visibility private; returning from private uses company visibility, matching single-page status changes. Other child sharing settings are preserved.

Records and history now provide pagination and 10/25/50 results per page, with 10 as the default. Plugin activation now requires confirmation and reloads the UI after success; canceling leaves the enabled state unchanged.

Core schema generation 6 and Plugin API 1.0.0 are unchanged. Existing settings, enabled states, data, and APIs are retained. A previously unlisted plugin with a stored enabled state may now start if it meets the other requirements; review installed plugins before upgrading. Newly installed packages still start disabled.

Use the public ZIP for a new installation, or the merge-only upgrade ZIP for an existing v2.6.0 installation. Preserve runtime files and private configuration, review the Docker compose reference when applicable, and verify sign-in, pages, attachments, history, and plugin states after the update.
