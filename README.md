# QRデジタルスタンプラリー

PHP 8.3とSQLiteで動作する、セルフホスト型QRデジタルスタンプラリーです。現在はフェーズ2のスポット管理・個別QR出力まで実装しています。

## ローカルセットアップ

PHP 8.3以上とComposerが必要です。最初にCLIのバージョンを確認してください。

```console
php -v
composer install
php bin/setup-local.php
```

複数のPHPが入っているMacでは、`php -v` が8.3未満を示す場合があります。その場合はPHP 8.3の実行ファイルを明示してください（Homebrewの例: `/opt/homebrew/opt/php@8.3/bin/php bin/setup-local.php`）。

初回セットアップで `config/app.php` と `storage/app.sqlite` が作られます。`config/app.php` の `base_url` を、Apacheで公開するLAN内URLへ変更してください。Apacheのドキュメントルートには `public/` を指定する構成を推奨します。リポジトリ直下を公開する場合も、同梱の `.htaccess` が非公開ディレクトリへのアクセスを拒否します。

設定ファイルを直接変更せず、一時的に環境変数で上書きすることもできます。

```console
APP_ENV=development APP_BASE_URL=http://192.168.11.5/ php bin/migrate.php
```

### 開発用コマンド

```console
php bin/migrate.php
php bin/reset-local.php
composer test
```

`reset-local.php` はdevelopment環境かつ、このリポジトリの `storage/` にあるDBだけを削除・再作成します。本番設定では実行できません。

## 開発用管理者

フェーズ1以降の管理画面を確認する前に、開発用管理者を1人作成します。パスワードは画面に表示されない対話入力です。

```console
php bin/create-admin.php --email=admin@example.com
```

作成時に復旧キーが一度だけ表示されます。パスワードを忘れた場合に必要なため、安全な場所へ保存してください。管理画面は設定したベースURLの `admin/`（例: `http://localhost/qr-sr/admin/`）です。

パスワードと復旧キーの両方を紛失した場合は、サーバーへSSH接続し、次のCLI専用コマンドを実行します。イベントや参加者データは削除されません。パスワードをコマンド引数へ指定しないでください。

```console
php bin/reset-admin-credentials.php
```

新しい復旧キーは一度だけ表示され、再設定前にログインしていた管理者セッションはすべて無効になります。

## スポットとQRの確認

管理画面の「スポット」から、追加・編集・並び替え・停止・QR再発行を行えます。QR確認ページのSVGは印刷にも使用できます。スマートフォンで確認するときは、`config/app.php` の `base_url` にMacのLAN内IPとサブディレクトリを含めてください。

```php
'base_url' => 'http://192.168.11.5/qr-sr/',
```

QR再発行後は古いQRコードが無効になります。取得履歴があるスポットは削除できず、停止のみ可能です。

## ディレクトリ

- `public/`: Web公開する入口と静的ファイル
- `src/`: アプリケーションコード
- `templates/`: HTMLテンプレート
- `config/`: 設定ひな型とローカル設定
- `storage/`: SQLite DBとログ（Web非公開、生成物はGit対象外）
- `database/migrations/`: 順番に適用するDB変更
- `bin/`: ローカルセットアップ、マイグレーション、リセット
- `tests/`: 自動テスト
