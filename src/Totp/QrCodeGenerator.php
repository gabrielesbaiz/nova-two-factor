<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Totp;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders a provisioning URI to an inline SVG, locally.
 *
 * There is deliberately no remote code path here at all. 1.x defaulted to
 * handing the `otpauth://` URI — shared secret included — to
 * `api.qrserver.com` in a GET query string, which put every secret it generated
 * into a third party's access logs, the admin's browser history and any
 * TLS-inspecting proxy in between. An arch test asserts no such host appears in
 * this package.
 */
class QrCodeGenerator
{
    public function svg(string $provisioningUri, int $size = 240): string
    {
        // Pure black on transparent. The UI places this on an explicitly white
        // plate in both themes — scanners need the contrast, and a themed QR
        // that inverts in dark mode simply will not scan.
        $renderer = new ImageRenderer(
            new RendererStyle(
                size: $size,
                margin: 0,
                fill: Fill::uniformColor(
                    backgroundColor: new Rgb(255, 255, 255),
                    foregroundColor: new Rgb(15, 23, 42),
                ),
            ),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($provisioningUri);

        return $this->stripXmlDeclaration($svg);
    }

    /**
     * BaconQrCode emits a standalone SVG document. The declaration is invalid
     * inline in HTML, and it is what makes a raw-SVG QR silently fail to render
     * — the reason the "secure" QR option in 1.x looked broken and pushed
     * integrators back to the leaky default.
     */
    protected function stripXmlDeclaration(string $svg): string
    {
        return trim(preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg);
    }
}
