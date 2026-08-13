<?php

namespace App\Modulos\Procedimientos\Services;

final class BlankOfficeDocumentFactory
{
    /**
     * @return array{name:string, mime:string, binary:string}|null
     */
    public function create(string $title, string $type): ?array
    {
        $type = strtolower(trim($type));
        $template = $this->template($type);
        if ($template === null) {
            return null;
        }

        $title = trim($title);
        $title = preg_replace('/\.'.preg_quote($type, '/').'$/i', '', $title) ?? $title;
        $title = trim($title, " .-\t\n\r\0\x0B");
        if ($title === '') {
            $title = $type === 'xlsx' ? 'Nueva planilla' : 'Nuevo documento';
        }

        $binary = base64_decode($template['base64'], true);
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        return [
            'name' => $title.'.'.$type,
            'mime' => $template['mime'],
            'binary' => $binary,
        ];
    }

    /**
     * @return array{mime:string, base64:string}|null
     */
    private function template(string $type): ?array
    {
        return match ($type) {
            'docx' => [
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'base64' => 'UEsDBBQAAAAIACVLq1wouJFP4QAAAFwBAAARAAAAd29yZC9kb2N1bWVudC54bWxFUMtuwyAQ/BXEvVnHSqvIsp1bbq0qtf0AYjY2kmERbELTry84snyZ3dmZfUB7+rWzuGOIhlwn97tKCnQDaePGTv58n1+OUkRWTquZHHbygVGe+jY1moabRcciD3CxSZ2cmH0DEIcJrYo78uiydqVgFWcaRkgUtA80YIx5vp2hrqo3sMo4WUZeSD9K9AVCAe4/bngnsTRpY01eSC0UoWBYcLF7KBhx4M+l049ffyKVs/Z1fcivSs2U89djzuFpeFchV5l8rh+elmDGiTd6IWayG5/xuqqwrF73wXo8bB/T/wNQSwMEFAAAAAgAJUurXHluM9foAAAArQEAABMAAABbQ29udGVudF9UeXBlc10ueG1sfVDJTsMwEP0Va64oceCAEIrTA8sROJQPGNmTxKo3edzS/j1OW3pAhePMW/X61d47saPMNgYFt20HgoKOxoZJwef6tXkAwQWDQRcDKTgQw2ro14dELKo2sIK5lPQoJeuZPHIbE4WKjDF7LPXMk0yoNziRvOu6e6ljKBRKUxYPGPpnGnHrinjZ1/epRybHIJ5OxCVLAabkrMZScbkL5ldKc05oq/LI4dkmvqkEkFcTFuTvgLPuvQ6TrSHxgbm8oa8s+RWzkSbqra/K9n+bKz3jOFpNF/3ilnLUxFwX9669IB5t+Okvj3MP31BLAwQUAAAACAAlS6tcm/036q0AAAApAQAACwAAAF9yZWxzLy5yZWxzjc87DsIwDAbgq0TeaVoGhFDTLgipKyoHsBI3rWgeSsKjtycDA0UMjLZ/f5br9mlmdqcQJ2cFVEUJjKx0arJawKU/bfbAYkKrcHaWBCwUoW3qM82Y8kocJx9ZNmwUMKbkD5xHOZLBWDhPNk8GFwymXAbNPcorauLbstzx8GnA2mSdEhA6VQHrF0//2G4YJklHJ2+GbPpx4iuRZQyakoCHC4qrd7vILPCm5qsXmxdQSwECFAAUAAAACAAlS6tcKLiRT+EAAABcAQAAEQAAAAAAAAAAAAAAAAAAAAAAd29yZC9kb2N1bWVudC54bWxQSwECFAAUAAAACAAlS6tceW4z1+gAAACtAQAAEwAAAAAAAAAAAAAAAAAQAQAAW0NvbnRlbnRfVHlwZXNdLnhtbFBLAQIUABQAAAAIACVLq1yb/TfqrQAAACkBAAALAAAAAAAAAAAAAAAAACkCAABfcmVscy8ucmVsc1BLBQYAAAAAAwADALkAAAD/AgAAAAA=',
            ],
            'xlsx' => [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'base64' => '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',
            ],
            default => null,
        };
    }
}
