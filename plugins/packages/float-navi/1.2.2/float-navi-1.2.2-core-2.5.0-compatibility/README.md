# フロートNavi: OpenConcept 2.5.0 用互換パッチ

本体 2.5.0 の登録済みプラグイン一覧へ float-navi 1.0.0 を1行追加します。ほかの挙動は変更しません。

1. 本体VERSIONが 2.5.0 であることを確認します。ほかの版には適用しないでください。
2. 現在の app/PluginCompatibility.php のSHA-256が下記の変更前ハッシュと一致することを確認します。異なる場合は上書きせず、更新済みの本体でフロートNaviが使用可能か確認してください。
3. 現在のファイルをアプリ公開領域の外へバックアップします。
4. このフォルダーの app/PluginCompatibility.php を本体の同じ場所へ配置します。
5. 管理者がフロートNaviをダウンロード・有効化し、ブラウザーを再読み込みします。

Before SHA-256: `c754ab164d11d90e1639ad5eb903e462e656dd1d4bb815775b949020cb04c0f3`

After SHA-256: `068e85a4ba5a1e02018dfbe0c4237ae9fd5bfa41c072fa8189214922377a7366`

For OpenConcept 2.5.0 only. Verify VERSION and the exact before hash, back up the existing file outside the public application directory, then copy app/PluginCompatibility.php into the application. This adds only float-navi 1.0.0 to the release compatibility list. Do not overwrite a different or newer compatibility file. Download and enable the plugin, then reload the browser.
