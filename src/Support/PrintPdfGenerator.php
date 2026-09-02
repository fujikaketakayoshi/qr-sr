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
        private readonly string $fontCacheDirectory,
    ) {
    }

    public function render(string $html): string
    {
        if (!is_dir($this->fontCacheDirectory)
            && !mkdir($this->fontCacheDirectory, 0700, true)
            && !is_dir($this->fontCacheDirectory)) {
            throw new RuntimeException('PDF用フォントキャッシュを作成できません。');
        }
        $options = new Options();
        $options->setChroot($this->projectDirectory);
        $options->setFontDir($this->fontCacheDirectory);
        $options->setFontCache($this->fontCacheDirectory);
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
