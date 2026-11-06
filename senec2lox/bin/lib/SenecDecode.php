<?php
/** Dekodierung der lala.cgi-Werte (Praefixe fl_, u1_, i1_ ...). Basiert auf ioBroker.senec (MIT, (c) NoBl). */
final class SenecDecode
{
    public const array ABSENT = ['VARIABLE_NOT_FOUND', 'OBJECT_NOT_FOUND', 'FORBIDDEN'];

    /** @return mixed decodierter Wert oder null = nicht veroeffentlichen */
    public static function value(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map([self::class, 'value'], $v);
        }
        if (!is_string($v)) {
            return $v;
        }
        if (str_starts_with($v, 'FILE_VARIABLE_NOT_READABLE')) {
            return '';
        }
        if (strlen($v) < 3 || $v[2] !== '_') {
            return null; // Sentinels, Unbekanntes
        }
        $type = substr($v, 0, 2);
        $hex = substr($v, 3);
        switch ($type) {
            case 'fl':
                if (strlen($hex) !== 8 || !ctype_xdigit($hex)) {
                    return null;
                }
                $f = unpack('G', hex2bin($hex))[1];
                return (is_nan($f) || is_infinite($f)) ? null : round($f, 2);
            case 'u1':
            case 'u3':
            case 'u6':
            case 'u8':
                return ($hex !== '' && ctype_xdigit($hex)) ? hexdec($hex) : null;
            case 'i8':
            case 'i1':
            case 'i3':
                if ($hex === '' || !ctype_xdigit($hex)) {
                    return null;
                }
                $bits = ['i8' => 8, 'i1' => 16, 'i3' => 32][$type];
                $n = hexdec($hex);
                return $n >= (1 << ($bits - 1)) ? $n - (1 << $bits) : $n;
            case 'st':
            case 'ch':
                return $hex;
            default: // er_ und Unbekanntes
                return null;
        }
    }

    public static function isAbsent(mixed $raw): bool
    {
        return is_string($raw) && in_array($raw, self::ABSENT, true);
    }
}
