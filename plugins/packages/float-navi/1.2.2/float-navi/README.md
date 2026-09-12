# フロートNavi / Float Navi 1.2.2

OpenConcept **V2.5.0 / V2.6.0 / V2.6.1** 対応の個別配布プラグインです。

1.2.2では、AI検索ウィンドウと重なってもフロートNaviが手前に表示されるよう修正しました。AI検索を開いたまま、フロートNaviの移動・検索・ツリー・設定・閉じる操作ができます。

## V2.5.0 / V2.6.0への初回導入

公開済みの本体には、登録済みID以外のプラグインを拒否する版があります。その版では、先に**使用中の本体バージョンに一致する互換パッチ**を適用してください。`app/PluginCompatibility.php`の一覧へフロートNaviを1行追加します。本体のバージョン番号・DB・ページ機能は変更しません。

| 本体 | 互換パッチ | SHA-256 |
| --- | --- | --- |
| V2.5.0 | [ZIP](float-navi-1.2.2-core-2.5.0-compatibility.zip) | [Checksum](float-navi-1.2.2-core-2.5.0-compatibility.zip.sha256) |
| V2.6.0 | [ZIP](float-navi-1.2.2-core-2.6.0-compatibility.zip) | [Checksum](float-navi-1.2.2-core-2.6.0-compatibility.zip.sha256) |

ZIP内のREADMEに従い、**VERSIONと変更前ファイルのSHA-256が一致する場合だけ**適用します。既に新規IDを受け付ける本体へ更新済みならパッチは不要です。異なる版・変更済みファイルを上書きしないでください。

プラグイン本体: [Package](float-navi-1.2.2.oc-plugin.json) · [SHA-256](float-navi-1.2.2.oc-plugin.json.sha256)。手動導入用: [ZIP](float-navi-1.2.2.zip) · [SHA-256](float-navi-1.2.2.zip.sha256)。ZIP内の`plugins/float-navi/`を本体の同じ場所へ配置し、管理者が有効化してブラウザーを再読み込みします。公式ダウンロードから導入するには、この配布物とカタログを公式GitHubのmainへ公開する必要があります。

## 使い方

1. 管理者が「設定 > プラグイン > 公式ダウンロード」からフロートNaviを取得し、有効化してブラウザーを再読み込みします。
2. 左ナビのページセクションで「すべて開く」の左にある別窓アイコンをクリックします。
3. 検索、すべて開く、すべて閉じる、ページツリーがフロートNaviに表示されます。
4. タイトルバーをドラッグして移動し、右下をドラッグしてサイズを変更できます。タイトルバーと右下のハンドルは、フォーカスして矢印キーでも操作できます（Shiftで大きく移動）。
5. 同じアイコンをもう一度クリックするか、窓の右上の×で閉じます。窓内でのEscapeキーでも閉じられます。

右上の歯車メニューから「透過」を選ぶと、通常は隠れているスライダーを表示できます。背景を0%（不透明）から100%（透明）まで調整でき、文字・アイコン・操作部品は薄くなりません。透過度の調整欄以外をクリックすると、メニューと調整欄を隠します。設定欄の×、Escapeでも隠せます。窓を閉じると設定欄も隠れますが、調整値は保持します。

歯車メニューの「背景モード → ダークモード」でフロート内を黒背景・白文字へ変更できます。「ライトモード」で元に戻せます。左ナビやページ本体の色は変わりません。背景モードと透過度は同時に使用できます。初期値はライトモード・透過度0%で、窓の開閉やページ移動後も値を保持し、ブラウザーの再読み込みで初期値に戻ります。

歯車メニューはキーボードでも操作できます。上下矢印で項目選択、Enterまたは右矢印でサブメニューへ進み、左矢印またはEscapeで戻ります。メニュー外のクリックでも閉じられます。

V2.6.1では互換パッチは不要です。V2.5.0／V2.6.0で1.0.0の互換パッチを適用済みの場合も、再適用は不要です。

検索語・検索結果・枝の開閉・選択ページ・ページの変更は左ナビと同期します。ページメニュー、ページ追加、ドラッグによる移動は本体と同じ処理・権限を使用します。左右のスクロール位置はそれぞれ操作できます。

別窓は**アプリ画面内の移動可能なウィンドウ**です。背面のページも操作できます。位置とサイズは同じ画面を開いている間保持し、再読み込みすると初期状態に戻ります。画面縮小時には窓を画面内に収めます。プラグイン独自のDB、外部通信、追加ライブラリは使用しません。

## Installation and use

Supports OpenConcept **V2.5.0, V2.6.0 and V2.6.1**. Released builds that reject unlisted plugin IDs need the matching compatibility patch above first. Check the application VERSION and original file SHA-256 before replacing the compatibility file. The patch adds only the Float Navi entry; an already updated Core that accepts new IDs needs no patch.

Version 1.2.2 keeps Float Navi in front of the AI search window, so its navigation, settings and window controls remain usable where the windows overlap.

An administrator downloads and enables Float Navi in **Settings > Plugins > Official downloads**, then reloads the browser. Official downloads require the package and catalog to be published to the official GitHub main branch. Click the pop-out icon immediately to the left of **Expand all** in the sidebar's Pages section.

Search, results, expansion, selected pages and page changes stay synchronized with the sidebar. Page menus, creation and drag-and-drop use the application's existing permissions and handlers. Drag the title bar to move the window and its lower-right handle to resize it; focused handles also support arrow keys (Shift for larger steps). Click the icon again, ×, or press Escape inside the window to close it.

This is a movable window within the application, with independently scrollable content. Position and size persist until the page is reloaded. No separate database, network service or library is required.

Open the gear menu at the top right and choose **Transparency** to reveal the normally hidden slider. Adjust from 0% (opaque) to 100% (transparent), keeping text, icons and controls visible. Click outside the transparency controls to dismiss the menu and settings row. Its × and Escape also hide the row. Closing the window hides the settings row while preserving the value.

Choose **Background mode → Dark mode** for a black background with white text inside Float Navi; **Light mode** restores the original colors. The sidebar and page keep their existing theme. Both modes support background transparency. Mode and transparency persist through window closure and page navigation until the browser is reloaded. The defaults are light mode and 0% transparency. The menu supports arrow keys, Enter and Escape. V2.6.1 needs no compatibility patch; an existing Float Navi 1.0.0 compatibility patch on V2.5.0/V2.6.0 also supports this update.

## 開発・検証 / Development

ソースは `OpenConcept/plugins/float-navi/`、配布物は `OpenConcept/Dist/float-navi/1.2.2/` で生成します。公開用リポジトリ内のパッケージを直接編集しないでください。

```powershell
php scripts/validate-plugins.php
php tests/PluginManagerTest.php
php tests/PluginLanguagePackTest.php
node --check plugins/float-navi/assets/plugin.js
php -d extension=zip scripts/build-float-navi-distribution.php
```
