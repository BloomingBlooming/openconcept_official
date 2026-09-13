# OpenConcept V2.6.2（開発中 / Unreleased）

> 履歴記録：V2.6.2は単独配布せず、V2.7.0の開発内容とともに[V3.0.0](release-notes-3.0.0.md)へ集約しました。以下は開発当時の記録です。 / Historical record: 2.6.2 was not released separately; its changes are included in 3.0.0 together with the 2.7.0 development work.

履歴カレンダーの言語対応とフロートNaviの状態保持をV2.6.2で開発しました。これらの変更はV2.7.0に継承し、現在の開発対象はV2.7.0です。

## 現在の変更

- 「記録と履歴」の開始日・終了日のカレンダーが、アプリで選択した言語に連動します。英語・日本語・韓国語・ベトナム語・簡体字中国語で月名・曜日・操作ボタンを表示します。
- 日付はカレンダーから選択するほか、`YYYY-MM-DD` で直接入力できます。「今日」「クリア」、月移動、キーボード操作に対応します。検索APIの日付形式とサーバー側の検証は維持します。
- 個別プラグインのフロートNavi 1.2.3は、開閉状態・位置・サイズ・透過度・背景モードを保存し、履歴ページとの往復や再読み込み後に復元します。設定は同じブラウザー・同じアプリURLで保持します。

## 互換性と配布

本体のバージョンは2.6.2、フロートNaviの独立したバージョンは1.2.3です。フロートNaviは本体2.5.0以降に対応し、本体2.6.2でも既存の最低対応プラグイン版1.0.0を維持します。新規プラグインIDを受け付ける本体2.6.1以降では、フロートNavi用の互換パッチは不要です。

全文履歴の保存、既存データ形式、Core DBスキーマ世代6、Plugin API 1.0.0は継続します。本体の更新と個別プラグインの更新は別に管理します。

V2.6.2は開発中です。公開済みV2.6.1の配布物・更新版はその時点の版として保持しています。V2.6.2の公開ファイルとアップグレード版は、変更内容の確定・配布検証を経て別途公開します。

## English

Version 2.6.2 is under development. The history calendar now follows the selected application language, including month names, weekdays and controls in all five supported languages. Date filter values remain `YYYY-MM-DD`.

Float Navi 1.2.3 separately adds browser persistence for open/closed state, position, size, transparency and background mode across history-page navigation and reloads. It remains compatible with Core 2.5.0 and later, including 2.6.2. Core schema generation 6, Plugin API 1.0.0 and full history storage remain unchanged.

Subsequent changes will be collected under 2.6.2 until another target version is specified. Existing 2.6.1 releases remain historical distributions; 2.6.2 release and upgrade packages will be published after release validation.
