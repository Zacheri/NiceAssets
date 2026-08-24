<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use RuntimeException;

final class Barcode
{
    public static function qr(string $payload, int $size = 300): string
    {
        $dir = Config::get('storage.labels');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/qr_' . md5($payload . '|' . $size) . '.png';
        if (is_file($file)) {
            return $file;
        }
        try {
            $qrCode = new QrCode($payload, new Encoding('UTF-8'), ErrorCorrectionLevel::Low, $size, 8);
            (new PngWriter())->write($qrCode)->saveToFile($file);
        } catch (\Throwable $e) {
            throw new RuntimeException('QR generation failed: ' . $e->getMessage(), 0, $e);
        }
        return $file;
    }
}
