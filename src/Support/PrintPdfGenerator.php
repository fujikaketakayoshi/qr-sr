<?php

declare(strict_types=1);

namespace QrRally\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

final class PrintPdfGenerator
{
    public function __construct(
        private readonly string $projectDirectory,
    ) {
    }

    public function render(string $html): string
    {
        $fontCache = $this->projectDirectory . '/storage/fonts';
        if (!is_dir($fontCache) && !mkdir($fontCache, 0755, true) && !is_dir($fontCache)) {
            throw new RuntimeException('PDF用フォントキャッシュを作成できません。');
        }
        $options = new Options();
        $options->setChroot($this->projectDirectory);
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);
        $options->setDefaultFont('Noto Sans JP');
        $options->setIsFontSubsettingEnabled(true);
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
