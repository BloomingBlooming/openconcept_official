# Plugin API 1.0.0（OpenConcept V2.6.1）

## 読み込み

```json
"requires": {"plugin_api":"1.0.0","capabilities":["sources","jobs","actions"]}
```

`plugin.php` の戻り値のコールバックに `$context['plugin_api']`（PluginApiClient）を渡す。旧Hook・DB Adapter・RAG Provider・Translation Providerの契約は継続利用できる。新しいプラグインIDを本体へ事前登録する必要はない。`plugins/<plugin-id>/plugin.json` の検証と宣言されたAPI要件により、有効化できるかを判定する。

## 本体側の対応版と取り外し

`app/PluginCompatibility.php` は既存プラグインの最低対応版を保持する。この表は新規IDの許可リストではなく、表にないプラグインには個別の最低版制限を適用しない。V2.6.1でも、V2.5.0／V2.6.0と同じ以下の最低版を適用する。更新版は必ず従来より大きい番号にし、プラグイン作者が下位互換性を保つか、本体を停止させず非対応機能を扱うことを契約とする。

| プラグインID | 最低対応版 |
| --- | --- |
| ai-file-reader | 0.1.0 |
| ai-search-voice | 0.3.1 |
| database-mysql-adapter | 1.1.8 |
| database-postgresql-adapter | 1.5.1 |
| drawing-manager | 0.9.2 |
| float-navi | 1.0.0 |
| translation-openai | 1.0.0 |
| voice-conversation | 0.15.1 |

フロートNaviの最新版1.2.2はOpenConcept V2.5.0以降に対応し、1.0.0以降の既存版も引き続き使用できる。公開済みV2.5.0／V2.6.0で「最低対応版の登録なし」と表示される場合は、本体側の登録が未反映のため、V2.6.1への更新、または使用中の版に合う[フロートNavi互換パッチ](https://github.com/BloomingBlooming/openconcept_official/blob/main/plugins/packages/float-navi/1.2.2/README.md)を適用する。V2.6.1では追加の互換パッチは不要。プラグインの再インストールだけでは本体の登録一覧は更新されない。

新規IDも既存IDも、必要APIのmajor・最低版・能力を照合する。API要件を宣言しない従来のHookプラグインも利用できる。本体のバージョンごとの最低版表がないことだけを理由に起動を拒否しない。IDの形式、フォルダー名との一致、Coreが予約したID、宣言ファイルの検証は継続する。

プラグインのmanifestで既存プラグインの最低対応版を上書きできない。最低対応版未満の導入済みプラグインは実効OFFになるが、旧版のファイルや保存データを本体更新で削除しない。新規IDの追加にも、既存IDの新版の利用にも、本体のID・版登録は不要。バージョンはSemVerの順序で比較し、ビルド情報は比較に含めず、同じ数値版では先行版を正式版より古く扱う。

`GET plugins` は導入版、`requested_enabled`（保存設定）、`enabled`（実効状態）、`compatibility`、安全な`error`、`can_detach`、`detach_block_reason`、`installation_token`を返す。`POST toggle-plugin {plugin_id,enabled}` は現在の管理者認証と配置を再確認し、非対応版のONを409で拒否する。OFFは許可する。起動でもPHP読み込み前に版を確認し、例外時は部分登録されたHookを戻して起動エラーを表示する。

`POST detach-plugin {plugin_id,version,installation_token}` は管理者・CSRFが必要。エラーのあるプラグインだけを取り外す。確認時のmanifest SHA-256が現在と違えば409で拒否する。この識別値はmanifestの変更検出用であり、全コードの整合性証明ではない。

取り外しは`plugins/<id>`を非公開storageの`detached-plugins/<id>-日時-乱数`へ移動し、DBの有効状態をOFFにする。導入・有効化・取り外しは共通の配置ロックで直列化し、DB処理が失敗した場合はコードの配置を戻す。プラグインの削除処理は実行せず、DB・原本・設定・秘密情報・通知を保持する。共通APIの後続書き込みも無効状態で拒否する。使用中または接続設定・移行状態のあるDB Adapterは取り外さない。

DB Adapterの専用読み込み経路でも、実行前にmanifestと本体の対応版を検証する。正本として選択済みのDB Adapterが非対応・欠落の場合は明示的に停止し、SQLiteへ自動的に接続先を変更しない。DBの設定・切り替えは排他権の取得後にプラグインの配置を再確認し、待機中に取り外し・差し替えがあれば中断する。

## インストール済みプラグインの更新

`GET plugins` は一覧を読み込むたびに公式カタログを確認し、導入済み版より新しく最低対応版/API要件を満たす候補だけを各行の`update: {version, sha256, compatibility}`に返す。候補がなければ`null`。受信トレイへプラグイン更新通知は送らず、一覧に現在版→新版と`UpDate`を表示する。一覧上部の再取得ボタンと、個別プラグインの更新ボタンは別の操作である。

`POST update-plugin {plugin_id, version, installation_token, target_version, package_sha256}` は管理者・CSRFが必要。現在版・manifest識別値・候補版・候補SHAを確認時に固定する。カタログも`requires`を公開でき、未対応のAPI要件を持つ候補は表示しない。パッケージ自体の要件も適用前に再検証するため、カタログと異なる未対応要件なら旧版を維持して失敗する。

処理はダウンロード・パッケージSHA・全ファイルSHA・manifest・PHP構文の検証後、短い本体の排他区間で現在の認証・配置・有効設定を再確認し、旧コードの退避→新版配置→設定/audit確定を行う。設定済みON/OFFを保持し、DBの登録データ・原本・秘密情報は更新処理で変更しない。設定/移行処理中のDB Adapterは更新を拒否する。

旧コードは非公開storageの`plugin-updates/<id>-日時-乱数/previous/<id>`に残す。更新処理内の配置・保存失敗は旧コードを復元し、PHP停止などで配置が未完了なら次のDB接続前にjournalから復元する。新PHPは更新リクエスト内では実行せず、更新後の通常起動で読み込む。プラグイン自身の移行・外部処理に対するDB全体の巻き戻しは行わない。通常起動で捕捉した例外は既存の起動エラー表示・実効OFFで扱い、復旧用の旧コードは保持する。

## PHP契約

| 用途 | メソッド |
| --- | --- |
| 版と能力 | `version()`, `capabilities()`, `appInfo()` |
| 独自データ | `getData(collection,key)`, `putData(collection,key,value,expectedRevision)`, `listData(collection,cursor,limit)` |
| 設定・秘密 | `getSetting`, `setSetting`, `getSecret`, `setSecret` |
| 原本 | `listPublishedSources(cursor,limit,extensions)`, `source(ref,actor)`, `readSource(ref)`, `registerSourceProvider` |
| 実行 | `registerJob`, `enqueue`, `registerTrigger`, `subscribe`, `events`, `publishEvent` |
| 検索登録 | `acceptSearchData(ref,text,provenance,actor)` |
| 通知・画面 | `notify`, `notifyAdmins`, `registerAction`, `setFileState` |
| 短い一括更新 | `transaction(callback)`。DB操作だけに使用する |
| 正本読み取り | `canonicalRead(operation,actor,arguments)` |
| 正本所有元 | `registerCanonicalProvider(provider)`（`canonical.provide`宣言、所有元ID一致） |
| ファイル操作 | `registerFileAction(name,provider)`、本体の差し替えAPI |
| 処理状態 | `jobs(state,cursor,limit)`, `getJob(id)` |
| 検索データ管理 | `searchData(ref,actor)`, `invalidateSearchData(ref,revision,actor)` |

原本参照は `source_type`, `source_id`, `source_version`, `content_hash`。本体ファイルは `source_type=file`。版とSHA-256を省略しない。返却される現在の公開状態・閲覧可否を使用し、保存済みの古い権限で公開しない。

データ取得は `{value,revision}`、ページングは `{items,next_cursor}`。初回登録のexpectedRevisionは0、更新には取得済みrevisionを渡す。競合はPluginApiConflictとなる。

ジョブのoptionsには `triggers` と `lease_seconds` を指定できる。ファイル読み取りの起動条件は `login`・`page-view`。`admin.login` は管理者ログイン専用で、更新確認は独立したscopeの実行枠を使う。同scope内は同時1ジョブ。期限を過ぎた旧ワーカーは、再取得したワーカーの結果を上書きできない。ネットワーク通信中はトランザクションを保持しない。

アクションのコールバックは `(string $mode, array $payload, array $actor): array`。modeは `view` または `invoke`。本体は認証・有効状態・POSTのCSRFを検証し、各アクションは自分に必要な役割・原本権限・対象版を再確認する。

## HTTP／JavaScript

- `GET extension-info`：アプリ版・API版・能力。
- `GET/POST extension-action`：scope、extension_action、payload。GETは表示、POSTは実行。
- `GET extension-notification&id=...`：本人宛て通知の登録済みアクション。
- 本体の受信トレイは `GET notifications&filter=all|unread|archived&page=N` で10件ずつ取得する。countsは現在閲覧できる通知全体の件数、paginationは表示ページ・全ページ数を返す。
- 既読通知は `POST notification-archive {id}`、アーカイブ済み通知の非表示は `POST notification-delete {id}`。本人だけが操作でき、削除操作でも通知・アクションのメタデータを物理削除しない。アーカイブ通知のアクションは引き続き開けるが、非表示通知を受信トレイから取得・表示することはできない。
- `POST extension-tick`：ログイン票を消費して起動条件を判定する。呼び出し側からadmin.loginを指定できない。
- `POST extension-canonical-read`：scope、operation、arguments。管理者＋export権限が必要。
- `POST extension-file-replace`：multipartのfileとsource（JSON原本参照）。現在の閲覧権限と原本版を確認し、システム管理者か元のアップロード者だけが差し替えられる。旧版のバイトは復旧用に保持する。

URLは `api.php?action=...`。ブラウザーは `window.OpenConceptPluginApi.request` を利用し、CSRF・既存WAF互換モードを本体に任せる。

```js
OpenConceptPluginApi.openAction({plugin_id:'my-plugin',action:'details',payload:{id:'123'}});
```

モーダル形式は `{title,description,blocks,buttons,payload,version}`。blocksはtext、details、table/list（列・行・ページ送り）、input/select/checkbox、progress、custom。buttonsはid、label、kind、close、href、action、disabledを指定する。実行時はbutton、values、versionとpayloadを送る。閉じる・Escapeは承認操作を発行しない。

操作結果は任意の `feedback: {tone,title?,message}` で返せる。toneはsuccess、error、warning、info。本文のスクロール領域の外、操作ボタン直上へ表示する。ボタンに `busy_label` と `busy_message` を指定すると、要求中のボタン名・進行表示に使用する。未指定時も共通の処理中表示が出る。保存済み設定の接続テストなど、未保存フォームの入力検証を必要としない操作は `validate:false` を指定する。

独自表示は `registerRenderer(pluginId,name,renderer)`、独自画面アクションは `registerAction(pluginId,name,handler)`。任意HTMLを通知本文に埋め込まず、安全な文字表示または登録済み描画処理を使う。

JavaScriptのアクションは `handler(mode,payload,context)` で呼ばれる。任意の第3引数contextは `signal`（中止通知）、`isCurrent()`（現在もこの画面を操作してよいか）、`close()`（自分のモーダルだけを閉じる）を持つ。独自画面への遷移は必要なサーバー権限確認後に `isCurrent()` を確認し、`close()` してから行う。独自handlerの登録だけではサーバーのアクション検証は実行されないため、対象の権限確認には `request('extension-action', ...)` または権限検証済みのプラグインAPIを使う。別の通知を開いた後や閉じた後に到着した古い結果は、画面遷移へ使用しない。

## 正本スナップショット

マニフェストに `"permissions":["canonical.export"]` を宣言する。操作はcapabilities、begin、records、attachment、manifest、finish、close。beginのoptionsはtables、providers、include_attachments、ttl_seconds。recordsはcursorでページング、attachmentはoffset/lengthで最大1 MiBずつ取得する。

beginで書き込み側を短時間停止し、DB読み取りスナップショットと原本ファイルを非公開領域に固定する。その後の読み取りは固定コピーを参照する。接続先・スキーマ世代・管理者権限・プラグイン状態の変更を再確認し、期限切れや不整合なら失敗する。DB接続、SQL実行器、資格情報は返さない。

既定の全Core出力はページ、手動タグ、ACL、改訂、コメント、必要なユーザー属性、現行添付、本体AI会話に加え、knowledge-history providerの取込原文・訂正関係・音声会話・保持済み旧添付を含む。RAGのチャンク・Embedding・索引・再生成可能なスナップショットは対象外。providersに `extracted-content` を明示すると受理済み補助本文を追加し、manifestにもその区分を記録する。

v2.6.0はスナップショット契約version 2を公開する。既定の全Core指定で `providers` を省略すると `knowledge-history` を選択する。部分表指定またはproviders明示時は指定範囲を優先し、除外対象をmanifestへ明記する。補助本文も併せて取得する場合は `providers: ["knowledge-history", "extracted-content"]` を指定する。

既定の原本範囲は `original_file_version_scope=current_and_retained_versions`。v2.5.0の `current_files_table_only` から旧版を追加した。固定JSONL形式のCore出力と隔離SQLite復元を `scripts/knowledge-archive.php` で提供する。独自providerの出力形式・復元器はプラグイン側の責務。環境復旧・資格情報・任意プラグイン全体を含むバックアップとは区別する。詳しくは [v2.6.0](release-notes-2.6.0.md)。
