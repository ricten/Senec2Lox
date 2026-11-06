<?php
/** Duenner curl-Wrapper mit optionaler TOFU-Zertifikatspruefung (Fingerprint-Rueckgabe). */
final class Http
{
    /**
     * @return array{status:int,body:string,error:string,errno:int,fingerprint:?string,headers:array}
     */
    public static function request(string $method, string $url, array $headers, ?string $body, int $timeout, bool $verify, int $maxBytes = 4194304): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        if (!$verify) {
            curl_setopt($ch, CURLOPT_CERTINFO, true);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $fp = null;
        if (!$verify && $errno === 0) {
            $ci = curl_getinfo($ch, CURLINFO_CERTINFO);
            if (!empty($ci[0]['Cert'])) {
                $f = @openssl_x509_fingerprint($ci[0]['Cert'], 'sha256');
                $fp = $f ?: null;
            }
        }
        curl_close($ch);
        $resp = is_string($resp) ? $resp : '';
        if (strlen($resp) > $maxBytes) {
            $resp = '';
            $err = 'Antwort zu gross';
            $errno = -1;
        }
        return ['status' => $status, 'body' => $resp, 'error' => $err, 'errno' => $errno,
            'fingerprint' => $fp, 'headers' => $respHeaders];
    }
}
