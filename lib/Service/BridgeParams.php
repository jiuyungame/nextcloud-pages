<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * Type coercion for the global bridge parameters, shared by the HTTP client and the pair endpoint.
 *
 * The HTTP body of `PUT /api/admin/config` hands every value over as a string, while booleans and
 * numbers have to reach the bridge as real JSON booleans and numbers: a string "false" is truthy in
 * Python and would silently turn a switch on.
 */
final class BridgeParams
{
    /**
     * Coerces one value to the type the bridge expects for that key. An empty string, an empty list
     * or null means "not set" and is passed through as null so the caller can remove the key.
     */
    public static function coerce(string $key, mixed $value): mixed
    {
        $type = SiteRegistry::GLOBAL_TYPES[$key] ?? null;
        if ($type === null) {
            throw new \InvalidArgumentException('Unknown bridge parameter: ' . $key);
        }
        if ($value === null) {
            return null;
        }
        // a JSON payload may carry a real number or a real boolean; the bridge expects its own type
        if (is_int($value) || is_float($value)) {
            if ($type === 'string') {
                return (string)$value;
            }
            return $value;
        }
        if (is_array($value)) {
            if ($type !== 'stringList') {
                throw new \InvalidArgumentException('Bridge parameter ' . $key . ' does not take a list');
            }
            $list = self::stringList($value);
            return $list === [] ? null : $list;
        }
        if (is_bool($value)) {
            if ($type === 'bool') {
                return $value;
            }
            $value = $value ? '1' : '0';
        }

        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        switch ($type) {
            case 'bool':
                // only the spellings a checkbox or a hand-written payload can produce
                if (in_array(strtolower($text), ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
                if (in_array(strtolower($text), ['0', 'false', 'no', 'off'], true)) {
                    return false;
                }
                throw new \InvalidArgumentException('Bridge parameter ' . $key . ' must be true or false');
            case 'int':
                if (!preg_match('/^-?\d+$/', $text)) {
                    throw new \InvalidArgumentException('Bridge parameter ' . $key . ' must be a whole number');
                }
                return (int)$text;
            case 'float':
                if (!is_numeric($text)) {
                    throw new \InvalidArgumentException('Bridge parameter ' . $key . ' must be a number');
                }
                return (float)$text;
            case 'stringList':
                $list = self::stringList(preg_split('/[\r\n,]+/', $text) ?: []);
                return $list === [] ? null : $list;
            default:
                return $text;
        }
    }

    /**
     * Coerces a whole map, dropping the keys that are "not set" and rejecting unknown ones: the
     * bridge answers 400 for an unknown key, and a typo should fail before the request is sent.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function coerceMap(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $key = (string)$key;
            $coerced = self::coerce($key, $value);
            if ($coerced !== null) {
                $out[$key] = $coerced;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = trim((string)$item);
            if ($text !== '' && !in_array($text, $out, true)) {
                $out[] = $text;
            }
        }
        return $out;
    }
}
