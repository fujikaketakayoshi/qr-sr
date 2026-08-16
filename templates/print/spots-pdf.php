<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<style>
@font-face { font-family: "Noto Sans JP"; font-style: normal; font-weight: 400; src: url("<?= htmlspecialchars($fontDirectory, ENT_QUOTES, 'UTF-8') ?>/NotoSansJP-Regular.ttf") format("truetype"); }
@font-face { font-family: "Noto Sans JP"; font-style: normal; font-weight: 700; src: url("<?= htmlspecialchars($fontDirectory, ENT_QUOTES, 'UTF-8') ?>/NotoSansJP-Bold.ttf") format("truetype"); }
@page { size: A4 portrait; margin: 12mm; }
* { box-sizing: border-box; }
body { margin: 0; color: #15201d; font-family: "Noto Sans JP", sans-serif; }
.page { position: relative; height: 265mm; overflow: hidden; text-align: center; page-break-after: always; }
.page:last-child { page-break-after: auto; }
.event { width: 172mm; margin: 3mm auto 1mm; color: #52615d; font-size: 11pt; line-height: 1.35; text-align: center; white-space: normal; word-wrap: break-word; }
.number { margin: 0; color: #087f5b; font-size: 13pt; font-weight: 700; letter-spacing: .12em; }
h1 { width: 172mm; margin: 4mm auto 3mm; font-size: 27pt; line-height: 1.35; overflow-wrap: break-word; }
.qr { display: block; width: 116mm; height: 116mm; margin: 2mm auto 3mm; }
.lead { margin: 0 0 4mm; font-size: 17pt; font-weight: 700; }
.notice { margin: 0 8mm; padding: 4mm 6mm; color: #612b13; background: #fff4e5; border: 1.2pt solid #e87817; border-radius: 4mm; font-size: 10.5pt; line-height: 1.55; text-align: left; }
.notice p { margin: 0 0 2mm; }
.notice p:last-child { margin-bottom: 0; }
</style>
</head>
<body>
<?php foreach ($spots as $spot): ?>
<section class="page">
    <p class="event"><?php $eventNameChunks = array_map(static fn (string $chunk): string => htmlspecialchars($chunk, ENT_QUOTES, 'UTF-8'), mb_str_split((string) $event['name'], 24)); ?><?= implode('<wbr>', $eventNameChunks) ?></p>
    <p class="number">SPOT <?= sprintf('%02d', (int) $spot['display_order']) ?></p>
    <h1><?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <img class="qr" src="<?= htmlspecialchars($spot['qr_data_uri'], ENT_QUOTES, 'UTF-8') ?>" alt="">
    <p class="lead">標準カメラで読み取ってスタンプを獲得！</p>
    <div class="notice"><p>普段使用する通常ブラウザで開き、イベント中は毎回同じブラウザをご利用ください。</p><p>iPhoneは「コードスキャナー」ではなく、標準カメラからSafariで開いてください。</p></div>
</section>
<?php endforeach; ?>
</body>
</html>
