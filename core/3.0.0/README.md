# OpenConcept V3.0.0 配布物 / Release downloads

V2.6.1以降の変更を集約した公開版です。配布ファイルは生成・検証済みのDistと同じ内容です。[リリースノート](OpenConcept-3.0.0-public/docs/release-notes-3.0.0.md)に変更点と運用条件を記載しています。

This release consolidates changes since V2.6.1. Files match the verified development distributions. See the [release notes](OpenConcept-3.0.0-public/docs/release-notes-3.0.0.md) for changes and requirements.

| 用途 / Purpose | ZIP | SHA-256 | 展開版・手順 / Unpacked files and guide |
| --- | --- | --- | --- |
| 新規設置 / New installation | [Download](OpenConcept-3.0.0-public.zip) | [Checksum](OpenConcept-3.0.0-public.zip.sha256) | [Files](OpenConcept-3.0.0-public/) · [Guide](OpenConcept-3.0.0-public/README.md) |
| V2.4.1 → V3.0.0 | [Upgrade](OpenConcept-3.0.0-upgrade-from-2.4.1.zip) | [Checksum](OpenConcept-3.0.0-upgrade-from-2.4.1.zip.sha256) | [Files](OpenConcept-3.0.0-upgrade-from-2.4.1/) · [Guide](OpenConcept-3.0.0-upgrade-from-2.4.1/README.ja-en.md) |
| V2.5.0 → V3.0.0 | [Upgrade](OpenConcept-3.0.0-upgrade-from-2.5.0.zip) | [Checksum](OpenConcept-3.0.0-upgrade-from-2.5.0.zip.sha256) | [Files](OpenConcept-3.0.0-upgrade-from-2.5.0/) · [Guide](OpenConcept-3.0.0-upgrade-from-2.5.0/README.ja-en.md) |
| V2.6.0 → V3.0.0 | [Upgrade](OpenConcept-3.0.0-upgrade-from-2.6.0.zip) | [Checksum](OpenConcept-3.0.0-upgrade-from-2.6.0.zip.sha256) | [Files](OpenConcept-3.0.0-upgrade-from-2.6.0/) · [Guide](OpenConcept-3.0.0-upgrade-from-2.6.0/README.ja-en.md) |
| V2.6.1 → V3.0.0 | [Upgrade](OpenConcept-3.0.0-upgrade-from-2.6.1.zip) | [Checksum](OpenConcept-3.0.0-upgrade-from-2.6.1.zip.sha256) | [Files](OpenConcept-3.0.0-upgrade-from-2.6.1/) · [Guide](OpenConcept-3.0.0-upgrade-from-2.6.1/README.ja-en.md) |

[全配布物の検証値 / Artifact hashes](OpenConcept-3.0.0-artifacts.json) · [HTTP/HTTPS設置説明](OpenConcept-3.0.0-public/HTTP-SERVER-SETUP.ja-en.md)

## 更新について / Upgrading

更新前に環境全体をバックアップし、書き込みとワーカーを停止します。導入済みの版に対応する更新版のREADMEに従い、`files/`を既存アプリへ結合してください。既存のDB・添付・設定・鍵・プラグインを保持します。Compose差分は参照用の構成と比較して手動で反映します。本体更新通知からの自動インストールは行いません。

V2.4.1は初回公開版とSQLite初回起動修正版の両方を同じ更新版で検証済みです。V2.4.1のみCoreスキーマ世代5から6への追加テーブル移行があり、ほかの3版は世代6を維持します。開発途中のV2.6.2・V2.7.0や独自改修には、旧公開版の変更前ハッシュをそのまま適用できないため、変更内容を比較して反映してください。

Back up the complete installation and stop writers/workers before merging the matching package's `files/` directory. Retain databases, uploads, settings, keys and standalone plugins, and review Compose changes manually. Application update notifications do not install Core automatically. Both published V2.4.1 revisions are supported, with an additive schema generation 5-to-6 transition; the other three versions retain generation 6. Development snapshots and local modifications require a separate comparison.

## プラグイン / Plugins

図面管理0.9.4・フロートNavi1.2.3は [公式プラグイン一覧](../../plugins/README.md) から個別導入・更新できます。既存0.9.3・1.2.2からの更新とデータ保持を検証しています。プラグインの更新に本体パッケージの再インストールは不要です。

Drawing Manager 0.9.4 and Float Navi 1.2.3 are installed and updated separately through the [official plugin directory](../../plugins/README.md). Updates from 0.9.3 and 1.2.2 preserve data; plugin updates do not require reinstalling Core.

[公開トップページ](../../README.md) · [ライセンス](OpenConcept-3.0.0-public/LICENSE.en.md)
