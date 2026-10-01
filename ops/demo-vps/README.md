# 公開デモVPS運用ファイル

このディレクトリは公開デモの運用専用であり、一般配布ZIPには含めない。

## 基準DB

1. 本番相当のデモ環境を通常どおりインストールする。
2. 公開領域外の `config/app.php` で `demo_mode` を `true` にする。
3. 管理者を `qr-sr-demo@example.com`、イベントを「商店街スタンプラリー」、スポットを表示順に「まちの電気屋さん」「ひだまりベーカリー」「さくら書店」とし、架空の参加者・取得履歴・応募データを整える。公開用パスワードは通常環境と使い回さず、別途安全に管理する。
4. `php ops/demo-vps/bin/capture-baseline.php` を実行する。
5. 公開領域外の `demo/baseline.sqlite` が所有者のみ読み取り可能であることを確認する。

## 初期化

手動確認は `php ops/demo-vps/bin/reset-demo.php` で行う。cronはアプリと同じPHPで、作業ディレクトリをアプリルートに固定して毎時00分に実行する。

```cron
0 * * * * cd /var/www/qr-sr-demo/app && /usr/bin/php ops/demo-vps/bin/reset-demo.php
```

環境に応じてPHPの絶対パスとアプリルートを置き換える。初期化ログは公開領域外の `logs/demo-reset.log` に記録し、VPS側のlogrotateで7日間だけ保持する。
