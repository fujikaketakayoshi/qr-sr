# QRコードスタンプラリー

QRコードを読み取ってスタンプを集めるデジタルスタンプラリーを運営できる、PHP 8.3／SQLite製のセルフホスト型Webアプリケーションです。参加者はスマートフォンの通常ブラウザでスタンプを集め、達成後に応募できます。管理者はイベント・スポット・応募項目の設定、QRコード印刷、集計、CSV出力、バックアップと復元をブラウザから行えます。

現在の製品バージョンは [`VERSION`](VERSION) を参照してください。

- [公式サイト](https://fujikake-products.com/qr-sr/)
- [公開デモと利用案内](https://fujikake-products.com/qr-sr/demo/)
- [インストール手順](INSTALL.md)

## English overview

QR Code Stamp Rally is a self-hosted PHP 8.3 and SQLite web application for running small-scale digital stamp rally events. Participants collect stamps by scanning QR codes with a regular smartphone browser, while organizers configure the event, print QR codes, review statistics, export CSV files, and manage backups through the web interface. See the [official website](https://fujikake-products.com/qr-sr/) for the distributed package and current information.

## 主な機能

- Cookieを使った参加者識別と、任意のニックネーム登録
- イベント期間、達成数、一時停止、参加者向け案内の設定
- スポットの追加、編集、並べ替え、停止、削除、QRコード再発行
- イベント入口・スポットQRコードの印刷、SVG・PDF出力
- 達成後の応募、応募項目・締切・個人情報利用目的の設定
- 参加・応募集計、全参加者CSV、応募者CSV、操作ログ
- 管理者パスワードの復旧と緊急時のCLI再設定
- SQLiteデータを含むバックアップZIPの作成と復元
- ドメイン直下、サブディレクトリ、独自ドメインへの設置

## 想定規模

XServerスタンダードでの実測を基に、次を小規模イベント向けの基礎規模としています。

- スポット50件
- 参加者2,000人
- スタンプ取得履歴100,000件
- 応募者1,000人
- 同時取得試験8ワーカー

参加者2,000人の完全な同時接続や、すべてのサーバーで同じ性能が得られることを保証するものではありません。実施前に利用予定環境と想定アクセス数で確認してください。

## 動作環境

- PHP 8.3以上
- SQLite 3.26.0以上（PDO SQLiteとSQLite3の両方）
- PHP拡張: `pdo_sqlite`、`sqlite3`、`mbstring`、`dom`、`session`、`filter`、`hash`、`fileinfo`、`zip`
- HTTPSが利用できるWebサーバー
- 公開領域外に作成・書き込みできる非公開領域
- URL書き換えが利用できる環境

`zlib`を推奨します。`gd`は任意です。配布ZIPには本番用依存ライブラリを同梱するため、設置先サーバーでComposerを実行する必要はありません。

## インストール

配布ZIPを利用する場合は [INSTALL.md](INSTALL.md) に従ってください。概要は次のとおりです。

GitHubがタグやコミットから自動生成する「Source code (zip)」「Source code (tar.gz)」は、一般利用者向けの配布ZIPではありません。設置には、公式サイトで案内する検証済み配布ZIPを使用してください。

1. 必要に応じて、ZIPとSHA-256を照合する
2. HTTPS、PHP 8.3、エラー非表示、サーバーキャッシュ等を確認する
3. ZIPを1ファイルのままサーバーへアップロードし、サーバー上で展開・配置する
4. `/install`を開き、ベースURLと公開領域外の絶対パスを確認する
5. 環境診断がすべて成功してから管理者を作成する
6. 一度だけ表示される復旧キーを安全に保存する
7. 初回ログイン後にバックアップを取得する

管理者向け操作は [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md)、参加者への案内は [docs/PARTICIPANT_GUIDE.md](docs/PARTICIPANT_GUIDE.md) を参照してください。

## 重要な注意

- 本番環境ではHTTPSを使用してください。
- SQLite DB、設定、ログ、バックアップは公開領域外へ保存してください。
- 復旧キーは再表示できません。管理者パスワードとは別の安全な場所へ保管してください。
- 参加履歴はブラウザCookieで識別します。iPhoneではコントロールセンターのコードスキャナーを避け、標準カメラからSafariで開いてください。
- QRコード再発行後は古いQRコードが無効になります。印刷・掲示・配布済みのQRコードをすべて交換してください。
- バックアップには管理者情報や応募者の個人情報が含まれます。安全に保管し、不要になったら削除してください。

## 文書

- [インストール手順](INSTALL.md)
- [管理者ガイド](docs/ADMIN_GUIDE.md)
- [参加者ガイド](docs/PARTICIPANT_GUIDE.md)
- [トラブル対応](docs/TROUBLESHOOTING.md)
- [アンインストール手順](docs/UNINSTALL.md)
- [リリースノート](RELEASE_NOTES.md)
- [利用条件](LICENSE.txt)
- [第三者ライセンス](THIRD_PARTY_NOTICES.md)

## 既知の制限

- 参加履歴はCookieに依存し、端末間・ブラウザ間で自動同期されません。
- Cookie削除、プライベートブラウズ、アプリ内ブラウザ、機種変更では履歴を引き継げません。
- 抽選機能と当選者への自動連絡機能はありません。応募者CSVを利用してください。
- 複数イベントの同時運営には対応していません。
- 大規模・高同時接続イベント向けの構成ではありません。
- 配布物の再配布および再販売は禁止しています。詳しくは [LICENSE.txt](LICENSE.txt) を確認してください。

## 商標

QRコードは株式会社デンソーウェーブの登録商標です。

## 開発者向け

このGitリポジトリから開発する場合は、PHP 8.3以上とComposerが必要です。

```console
composer install
php bin/setup-local.php
composer test
```

開発用の`config/app.php`と`storage/`内の生成物は配布対象ではありません。一般利用者向け配布物は`tools/build-release.php`で許可リストから生成します。
