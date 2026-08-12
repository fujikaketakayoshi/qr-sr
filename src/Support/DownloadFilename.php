<?php

declare(strict_types=1);

namespace QrRally\Support;

final class DownloadFilename
{
    public function spotQrSvg(int $displayOrder, string $spotName): string
    {
        $name = preg_replace('~[\\/:*?"<>|\x00-\x1F\x7F]+~u', '-', $spotName);
        $name = preg_replace('/^[\s.-]+|[\s.-]+$/u', '', (string) $name);
        if ($name === '') {
            $name = 'スポット';
        }

        return sprintf('%02d-%s-qr.svg', $displayOrder, $name);
    }
}
