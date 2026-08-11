# QRデジタルスタンプラリー

PHP 8.3とSQLiteで動作する、セルフホスト型QRデジタルスタンプラリーです。現在はフェーズ0の開発基盤まで実装しています。

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

## ディレクトリ

- `public/`: Web公開する入口と静的ファイル
- `src/`: アプリケーションコード
- `templates/`: HTMLテンプレート
- `config/`: 設定ひな型とローカル設定
- `storage/`: SQLite DBとログ（Web非公開、生成物はGit対象外）
- `database/migrations/`: 順番に適用するDB変更
- `bin/`: ローカルセットアップ、マイグレーション、リセット
- `tests/`: 自動テスト
