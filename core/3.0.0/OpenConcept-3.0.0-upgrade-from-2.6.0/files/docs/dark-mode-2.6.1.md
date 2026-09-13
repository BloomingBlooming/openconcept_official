# アプリの表示モード（V2.6.1）

「設定 → 一般」の最後にある「表示モード」でライトモード／ダークモードを選択できます。管理者以外のユーザーにも表示されます。選択はすぐに画面へ反映し、保存ボタンは不要です。

設定はこのサイトを利用するブラウザーに保存します。ページ移動・再読み込み・再ログイン後も保持し、同じサイトの別タブにも反映します。初期値はライトモードです。ブラウザーのサイトデータを削除すると初期値に戻ります。ブラウザーが保存を禁止している場合も、そのページ内では切り替えられます。

左ナビ、ホーム、ページ編集、検索、コメント、設定、各種ダイアログと「記録と履歴」に適用します。画像・表で指定した背景色・ページカバー・文書プレビューの元の色は維持します。フロートNaviの背景モードは従来どおり個別に選択できます。

実装は `assets/theme.js` と `assets/theme.css`（それぞれ `public/assets/` にミラー）です。HTMLのheadでテーマを復元するため、再表示時に明るい画面を挟みません。DB、共通設定、権限、ページ本文の保存形式に変更はありません。

English: Choose **Settings → General → Appearance** at the end of the General panel. Light/Dark mode applies immediately and persists in this browser, including the History view. The preference is local to the browser, with no database migration. Authored image and document colors are preserved.

## ページのカバーとアイコン

カバーは従来の6色に、ブラック、ネイビー、ミッドナイトブルー、原色のレッド・ブルー・イエローを追加しました。「カバーなし」を含め13種類から選べます。選択画面とホームのカードにも同じ背景を表示し、保存・再読み込み・公開ページへの出力で選択を保持します。

ページアイコンは96種類です。文房具・ノート、オフィス用品、パソコン・フォルダー、基本・その他の4分類から選択できます。従来の24種類も含みます。選択済みのアイコンには枠が付きます。

Page covers now include Black, Navy, Midnight blue, and primary Red, Blue and Yellow, with 13 choices including No cover. All choices are preserved on save, reload and public-page rendering. The 96 page icons are grouped into stationery, office supplies, computers/folders and general symbols, including all previous choices.

## 左メニューの折りたたみ

「AI検索」と「記録と履歴」は「受信トレイ」の下に移動し、初期表示では隠します。受信トレイの下の三点アイコンを押すと「受信トレイ → AI検索 → 記録と履歴 → 三点アイコン」の順に表示し、最下部のアイコンをもう一度押すと2項目を隠します。アイコンは円の内側に小さな丸い点を3つ横並びに表示します。AI検索に付属するプラグイン項目も一緒に開閉します。アプリ内のページ移動中は開閉状態を保ち、再読み込み・再ログイン時は非表示に戻ります。

AI search and Records and history are below Inbox and hidden initially. Use the three-dot icon to expand the menu in the order Inbox → AI search → Records and history → three-dot icon. Three small round dots are centered horizontally inside a circular outline. The toggle stays below the revealed items and hides them again when pressed. AI-search plugin entries expand together with AI search. The expanded state lasts during in-app navigation and resets on reload or sign-in.
