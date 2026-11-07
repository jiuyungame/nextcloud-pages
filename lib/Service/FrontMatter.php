<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * The small front-matter dialect Jekyll posts use here, without a YAML dependency.
 *
 * Deliberately a subset: scalars, inline lists, block lists, quoted strings and booleans. A nested
 * map is read as "present but empty" instead of failing, because a post that carries settings the
 * app does not care about must still be listable. Nothing in here throws on bad input: an
 * unparsable block yields the data that could be read and the body that follows it.
 */
final class FrontMatter
{
    /** Jekyll's default when `_config.yml` says nothing. */
    public const DEFAULT_PERMALINK = '/:categories/:title:output_ext';

    /**
     * The ASCII characters a bare scalar may contain: the ones this dialect has always written bare
     * (letters, digits, `_`, `.`, `/`, `-` and the space), plus `:` and `+` - a date and a timestamp
     * are spelled with those, and quoting one would change its type in YAML 1.1, which is the one
     * thing this writer must not do to a date (Jekyll sorts and formats on it).
     *
     * Everything else out of ASCII is quoted, because every shape YAML gives a meaning to is ASCII:
     * `*` an alias, `&` an anchor, `!` a tag, `|`/`>` a block scalar, `%` a directive, `@`/`` ` ``
     * reserved, `#` a comment, `[`/`]`/`{`/`}`/`,` a collection, `?` a key indicator and the quotes
     * a string. A Chinese character is not made syntax by standing next to them, which is why the
     * set stops at ASCII and everything above it is text.
     *
     * The class is applied to the value's ASCII characters only (the others are dropped first), so
     * it does not have to spell out "or anything that is not ASCII" a second time.
     */
    private const BARE_ASCII = '/^[A-Za-z0-9_.\/+: -]*$/';

    /**
     * What a bare scalar may not *start* with: YAML's indicators, the two quote characters (they
     * would open a string the reader then looks the end of), and `,` - a `c-indicator` too, and
     * `key: ,x` is a parse error rather than text.
     *
     * {@see self::BARE_ASCII} already excludes most of these. The test is still needed for `-` and
     * `:`, the two indicators a value may carry inside it (a date and a timestamp are spelled with
     * them), and it keeps stating the rule where a later widening of the set would otherwise lose it.
     */
    private const LEADING_INDICATOR = '*&!|>%@`#-?:[]{}\'",';

    /**
     * Split a document into its front matter and its body.
     *
     * @return array{data: array<string,mixed>, body: string}
     */
    public static function parse(string $markdown): array
    {
        $text = ltrim($markdown, "\xEF\xBB\xBF");
        if (!str_starts_with($text, "---\n") && !str_starts_with($text, "---\r\n")) {
            return ['data' => [], 'body' => $markdown];
        }
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $end = null;
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            $line = rtrim($lines[$i]);
            if ($line === '---' || $line === '...') {
                $end = $i;
                break;
            }
        }
        if ($end === null) {
            return ['data' => [], 'body' => $markdown];
        }

        $data = self::parseBlock(array_slice($lines, 1, $end - 1));

        return ['data' => $data, 'body' => implode("\n", array_slice($lines, $end + 1))];
    }

    /**
     * Rewrites the front-matter block of a document: `$set` adds or replaces keys, `$remove` drops
     * them, and both may be combined (a key named in neither is untouched).
     *
     * Guarantees, in the order they matter:
     *  - the body is never modified; the keys already in the block keep their order and their line,
     *    and new keys are appended at the end of the block;
     *  - a document without a front-matter block gets one, with a blank line before the body, which is
     *    what Jekyll requires to read the block at all;
     *  - a `null` value in `$set` means "remove", so a caller can build one map without branching;
     *  - an unparsable document is returned unchanged rather than rewritten into a broken file, while a
     *    value this dialect cannot carry is **refused** (`\InvalidArgumentException`, which the
     *    controller answers as 400) instead of being written wrong: before anything is written, the
     *    block about to be produced is read back through {@see self::parse()} and every written key
     *    has to come back as it went in.
     *
     * @param array<string,mixed> $set   key => value (null removes the key)
     * @param list<string>        $remove keys to drop
     * @throws \InvalidArgumentException when a value cannot be written and read back the same
     */
    public static function update(string $markdown, array $set, array $remove = []): string
    {
        // null is the "remove" spelling of a set entry, so both parameters end up in one remove list
        $removing = [];
        foreach ($remove as $key) {
            if (is_string($key) && self::isKey($key)) {
                $removing[$key] = true;
            }
        }
        $writing = [];
        foreach ($set as $key => $value) {
            $key = (string)$key;
            if (!self::isKey($key)) {
                continue;
            }
            if ($value === null) {
                $removing[$key] = true;
                continue;
            }
            $writing[$key] = $value;
        }
        if ($writing === [] && $removing === []) {
            return $markdown;
        }

        // the BOM is part of the file, not of the block: it has to come back in front
        $bom = str_starts_with($markdown, "\xEF\xBB\xBF") ? "\xEF\xBB\xBF" : '';
        $parsed = self::parse($markdown);
        // null means "no block yet": one is added below, because "set a key" has no other meaning
        $existing = self::blockLines($markdown);
        $hasBlock = $existing !== null;
        $block = $existing ?? [];

        $seen = [];
        $out = [];
        // the key the lines are currently under, taken from the *source* block: an indented list item
        // belongs to the key above it in the file, which is not the last line written (that key may
        // have been removed, or rewritten)
        $current = null;
        foreach ($block as $line) {
            if (preg_match('/^\s+-\s*/', $line) === 1) {
                // A replaced key's block list belongs to the value that is being replaced: the new
                // value is written on the key's own line, and keeping the old items under it would
                // leave two values for one key (which is YAML that does not parse). A key named in
                // neither list keeps its items, exactly like the line it was written on.
                if ($current !== null && (isset($removing[$current]) || array_key_exists($current, $writing))) {
                    continue;
                }
                $out[] = $line;
                continue;
            }
            $key = self::keyOfLine($line);
            if ($key === null) {
                $out[] = $line;
                continue;
            }
            $current = $key;
            if (isset($removing[$key])) {
                continue;
            }
            if (array_key_exists($key, $writing)) {
                // one entry per key: a duplicate key would keep the old value alive further down
                if (isset($seen[$key])) {
                    continue;
                }
                $out[] = $key . ': ' . self::toYaml($writing[$key], $key === 'categories');
                $seen[$key] = true;
                continue;
            }
            $out[] = $line;
        }

        foreach ($writing as $key => $value) {
            if (!isset($seen[$key])) {
                $out[] = $key . ': ' . self::toYaml($value, $key === 'categories');
            }
        }

        // One read-back for the whole block, before the file is touched: a value that would not
        // survive the trip (a newline that splits it off its key, an indicator the parser reads as
        // syntax, a `#` that becomes a comment) is refused here, where the caller can still answer
        // 400, rather than written into a file that says something else.
        if ($writing !== []) {
            self::assertRoundTrip($writing, $out);
        }

        $body = $parsed['body'];
        // parse() strips a BOM before reading, so it can appear at the front of the body it returns;
        // it belongs in front of the document (put back above) and nowhere else, otherwise a second
        // update would find a BOM inside the body and add another one in front of the file
        $body = ltrim($body, "\xEF\xBB\xBF");
        if (!$hasBlock) {
            // a document gaining its first block: the body follows a blank line, as Jekyll expects
            return $bom . "---\n" . implode("\n", $out) . "\n---\n\n" . ltrim($body, "\r\n");
        }
        // parse() splits the document into lines and drops the line break that followed the closing
        // fence, so a non-empty body still starts with the line break that separated it from the
        // fence. Exactly that prefix is what makes the file below byte-identical to the input; adding
        // another would grow a blank line on every save.
        $tail = $body === ''
            ? ''
            : (str_starts_with($body, "\n") || str_starts_with($body, "\r") ? $body : "\n" . $body);

        return $bom . "---\n" . implode("\n", $out) . "\n---\n" . $tail;
    }

    /**
     * The same document with its body replaced: front matter, one blank line, the body, one trailing
     * newline. The keys are not touched (update() is what changes them).
     *
     * The blank lines around the fence are the document's punctuation, not the body's content. Jekyll
     * only reads a block that a blank line separates from the body, and a client that sends the text it
     * edited cannot know how many blank lines the file happened to carry. So the app normalises exactly
     * that - and nothing inside the body, which is stored as it was sent (no reflowing, and a line's own
     * trailing space is content).
     *
     * A document without a front-matter block has no body that could be told apart from it: what is
     * returned is the body itself, so a caller that replaced the body of such a document gets the
     * document a save would write once update() has added the block.
     */
    public static function updateBody(string $markdown, string $body): string
    {
        // the BOM belongs in front of the document, never inside the body
        $bom = str_starts_with($markdown, "\xEF\xBB\xBF") ? "\xEF\xBB\xBF" : '';
        $clean = self::normalizeBody($body);
        $block = self::blockLines($markdown);
        if ($block === null) {
            return $bom . $clean;
        }
        $head = $bom . "---\n" . implode("\n", $block) . "\n---\n";

        // an empty body is the fence and nothing after it; the blank line only separates a body from
        // the fence, and a body of nothing has nothing to separate
        return $clean === '' ? $head : $head . "\n" . $clean;
    }

    /**
     * A body with the blank lines around the fence normalised away: leading blank (or whitespace-only)
     * lines dropped, trailing ones collapsed into the one newline the file ends with. Everything from
     * the first line of content to the last is returned untouched — internal blank lines and the
     * trailing spaces of a line are the body's own business.
     */
    public static function normalizeBody(string $body): string
    {
        $body = (string)preg_replace('/\A(?:[ \t]*\r\n|[ \t]*\n|[ \t]*\r)+/', '', $body);
        $body = (string)preg_replace('/(?:[ \t]*(?:\r\n|\n|\r))+\z/', '', $body);

        return $body === '' ? '' : $body . "\n";
    }

    /**
     * The raw lines of the front-matter block, or null when the document has none. Kept separate
     * from parse() because a rewrite has to preserve keys the parser does not model (nested maps,
     * comments) byte for byte.
     *
     * @return list<string>|null
     */
    private static function blockLines(string $markdown): ?array
    {
        $text = ltrim($markdown, "\xEF\xBB\xBF");
        if (!str_starts_with($text, "---\n") && !str_starts_with($text, "---\r\n")) {
            return null;
        }
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            $line = rtrim($lines[$i]);
            if ($line === '---' || $line === '...') {
                // start and end fence both identified, so the block is updatable
                return array_slice($lines, 1, $i - 1);
            }
        }

        return null;
    }

    /** The key a front-matter line assigns, or null for a continuation, a comment or a blank line. */
    private static function keyOfLine(string $line): ?string
    {
        if (preg_match('/^([A-Za-z0-9_.-]+)\s*:/', $line, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private static function isKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]+$/', $key) === 1;
    }

    /**
     * One value as YAML. A value that cannot be written bare - see {@see self::isBare()} - is
     * double-quoted with its own characters escaped, so a newline stays *inside* the value instead of
     * splitting the key off it, and an indicator stays text instead of becoming syntax.
     *
     * A line break is spelled `\n` (or `\r`) inside the quotes, never as a `|` block scalar: a block
     * scalar cannot carry the difference between `\n`, `\r\n` and `\r`, and the app has to read back
     * exactly the bytes it was given. The quoted form is what both Jekyll's YAML reader and
     * {@see self::scalar()} undo, so the value survives the trip unchanged.
     */
    private static function toYaml(mixed $value, bool $quoteStrings = false): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if ($item === null) {
                    continue;
                }
                if (is_array($item)) {
                    // a nested map has no inline spelling this dialect can read back: skip it
                    continue;
                }
                // `categories` is the one list written with every item quoted: a category names a
                // folder in the site, and a quoted item keeps a slash out of the YAML syntax no
                // matter what a reader makes of the bare form.
                $items[] = $quoteStrings && is_string($item) ? self::quoted($item) : self::toYaml($item);
            }
            return '[' . implode(', ', $items) . ']';
        }
        $text = (string)$value;
        if ($text === '') {
            return '';
        }

        return self::isBare($text) ? $text : self::quoted($text);
    }

    /**
     * Whether a string may be written as it is.
     *
     * This used to be a whitelist of ASCII (`^[A-Za-z0-9_./ -]+$`), which was safe but rewrote files
     * that were not wrong: a Chinese title came back quoted, and a date came back a *string* where
     * YAML 1.1 would have read a date. The second one is not cosmetic - Jekyll reads `modified_date`
     * as a time when the value is a time, and sorts and formats on that - so quoting it changes what
     * the site builds. What is checked now is what actually breaks a plain scalar:
     *
     *  - the value has to be valid UTF-8, or there are no characters to classify;
     *  - no control or format character - the line breaks {@see self::toYaml()} has to escape among
     *    them - and no U+2028/U+2029: YAML reads a line break there and the value splits off its key;
     *  - no leading and no trailing space: a plain scalar is trimmed on the way back in;
     *  - the first character may not be an indicator (see {@see self::LEADING_INDICATOR});
     *  - no ` #`, which opens a comment;
     *  - no `:` followed by a space or by the end of the value, which would make the value a nested
     *    mapping, or a key with nothing under it;
     *  - not a word YAML reads as a boolean or as null, and not a number in any spelling YAML 1.1
     *    accepts ({@see self::isNumberLike()}): written bare, those come back as another type than
     *    the one the caller wrote, and a title that reads as `true` is not the title that was saved;
     *  - and, outside those, every ASCII character has to be one {@see self::BARE_ASCII} names.
     *    Everything above ASCII is text and stays bare, so `中文标题`, `café` and `日本語` keep the
     *    shape the author gave them.
     *
     * Dates and timestamps need no exemption from any of this, which is worth saying out loud
     * because it looks like they should: `2026-10-31` starts with a digit, `2026-05-01T10:00:00Z`
     * only uses characters the ASCII set names, and a well-formed stamp puts a digit after every
     * colon (`10:00:00`, `+08:00`) - never a space, which is the only thing the `:` test looks for.
     * So they stay bare, and YAML reads them as the date and the timestamp they are. A *malformed*
     * one (`2026-05-01 10: 00`) does hit the `:` test and is quoted, which is what is wanted.
     *
     * The final line of defence is not here: {@see self::update()} parses the block it is about to
     * write and refuses a value that does not come back as it went in.
     */
    private static function isBare(string $text): bool
    {
        // a byte string that is not valid UTF-8 has no characters to classify: it is quoted (and
        // escaped byte by byte) instead of being written out as it came in
        if (preg_match('//u', $text) !== 1) {
            return false;
        }
        // control (`\n`, `\r`, `\t`, NUL), format (U+FEFF, U+200B ...) and the two Unicode line
        // separators: each of them ends a line in YAML, so the value would end in the middle of
        // itself and the rest of it would be read as the next line of the file
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $text) === 1) {
            return false;
        }
        // an empty value has nothing to write bare; a leading space is indentation once read and a
        // trailing one is trimmed away
        if ($text === '' || $text[0] === ' ' || str_ends_with($text, ' ')) {
            return false;
        }
        // an indicator at the front turns the value into syntax (see the constant's own note)
        if (str_contains(self::LEADING_INDICATOR, $text[0])) {
            return false;
        }
        // ` #` opens a comment: everything from there on is not the value any more
        if (str_contains($text, ' #')) {
            return false;
        }
        // `:` followed by nothing is a key without a value, and followed by a space it starts a
        // mapping; followed by anything else (`10:00:00`, `+08:00`) it is text
        if (preg_match('/:(?:[ \t]|\z)/', $text) === 1) {
            return false;
        }
        // every ASCII character has to be one a value may be spelled with; what is left after
        // dropping the non-ASCII characters is what the class sees
        $ascii = (string)preg_replace('/[^\x00-\x7F]/', '', $text);
        if (preg_match(self::BARE_ASCII, $ascii) !== 1) {
            return false;
        }
        // the words YAML spells a boolean or nothing with: bare, they come back as `true` or `null`
        if (preg_match('/^(?:true|false|yes|no|on|off|y|n|null|~)$/i', $text) === 1) {
            return false;
        }
        // a number in any spelling YAML 1.1 accepts would come back as an int or a float where the
        // caller wrote text; a date is not one of those spellings (see the test itself)
        return !self::isNumberLike($text);
    }

    /**
     * Whether the text spells a number in a form YAML 1.1 resolves to an int or a float: decimal with
     * an optional fraction and exponent, `_` as a digit separator, `0x`/`0o`/`0b` radix prefixes,
     * base 60 (`2:30` is 150, `190:20:30` is 685230) and `.inf`/`.nan`.
     *
     * Base 60 and the `_` separator are the two that a plain reading of "a number" misses, and both
     * change the type of a value that looks like text: `2:30` is an int to YAML, and `1_000` is the
     * int 1000. Neither is a date - `2026-10-31` has a `-` where only an exponent sign could be, and
     * the `:` of a timestamp is not the `:` of base 60 - which is why dates need no exemption from
     * this test.
     */
    private static function isNumberLike(string $text): bool
    {
        if (preg_match('/^[-+]?(?:\d[\d_]*(?:\.[\d_]*)?|\.\d[\d_]*)(?:[eE][-+]?\d+)?$/', $text) === 1) {
            return true;
        }
        if (preg_match('/^(?:0[xXoObB][0-9a-fA-F_]+|[+-]?\.(?:inf|nan))$/i', $text) === 1) {
            return true;
        }

        return preg_match('/^[-+]?\d+(?::\d+)+$/', $text) === 1;
    }

    /**
     * A value as a double-quoted YAML scalar, with the escapes both YAML and `stripcslashes()` (what
     * {@see self::scalar()} uses to read a quoted value back) understand.
     */
    private static function quoted(string $text): string
    {
        $escaped = strtr($text, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
        // the other control characters have no C escape of their own; `\xNN` is understood by YAML and
        // by stripcslashes() alike, so a value carrying one still comes back unchanged
        $escaped = (string)preg_replace_callback(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
            $escaped,
        );

        return '"' . $escaped . '"';
    }

    /**
     * The block as it is about to be written, read back through {@see self::parse()}: every value the
     * caller named has to come back as it went in.
     *
     * One parse for the whole block, not one per value - the fragment is the exact text the write is
     * about to produce, and comparing the values it was built from costs nothing extra. A mismatch is
     * a value this dialect cannot carry (an inline list item carrying a comma, for instance, is split
     * in two by the reader however it is quoted), and writing it anyway would make the file say
     * something nobody wrote.
     *
     * @param array<string,mixed> $writing
     * @param list<string>        $lines
     * @throws \InvalidArgumentException when a value would not read back as it was written
     */
    private static function assertRoundTrip(array $writing, array $lines): void
    {
        $parsed = self::parse("---\n" . implode("\n", $lines) . "\n---\n")['data'];
        foreach ($writing as $key => $value) {
            if (self::sameValue($value, $parsed[$key] ?? null)) {
                continue;
            }
            throw new \InvalidArgumentException(sprintf(
                'The value of “%s” cannot be written as front matter: it would not read back as it was saved.',
                $key,
            ));
        }
    }

    /**
     * Whether a parsed value is the one that was written: the same text, the same boolean, the same
     * integer, the same number, or the same list. A float is compared as a number because
     * {@see self::scalar()} reads `1.5` as text (this dialect has no float type) while the value
     * written is the one that number spells.
     */
    private static function sameValue(mixed $written, mixed $parsed): bool
    {
        if (is_array($written)) {
            return is_array($parsed) && self::listOf($written) === self::listOf($parsed);
        }
        if (is_bool($written)) {
            return $parsed === $written;
        }
        if (is_int($written)) {
            return is_int($parsed) && $parsed === $written;
        }
        if (is_float($written)) {
            return is_numeric($parsed) && (float)$parsed === $written;
        }
        if (is_string($written)) {
            return is_string($parsed) && $parsed === $written;
        }

        return false;
    }

    /**
     * A list as the strings {@see self::toYaml()} emits: a null and a nested map are skipped there, and
     * a scalar is spelled the same way on both sides of the round trip.
     *
     * @param array<array-key,mixed> $value
     * @return list<string>
     */
    private static function listOf(array $value): array
    {
        $out = [];
        foreach ($value as $item) {
            if ($item === null || is_array($item)) {
                continue;
            }
            $out[] = is_bool($item) ? ($item ? 'true' : 'false') : (string)$item;
        }

        return $out;
    }

    /**
     * Parse a plain YAML document (no `---` fences). `_config.yml` is written that way, and the app
     * only needs its `permalink` pattern from it.
     *
     * @return array<string,mixed>
     */
    public static function document(string $yaml): array
    {
        return self::parseBlock(preg_split("/\r\n|\n|\r/", ltrim($yaml, "\xEF\xBB\xBF")) ?: []);
    }

    /**
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private static function parseBlock(array $lines): array
    {
        $data = [];
        $openKey = null;

        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^\s+-\s*(.*)$/', $line, $m) === 1) {
                // a block list item belongs to the key that was left without a value; the key still
                // holds the empty string at that point, so it has to become a list first
                if ($openKey !== null) {
                    if (!is_array($data[$openKey] ?? null)) {
                        $data[$openKey] = [];
                    }
                    $data[$openKey][] = self::scalar(trim($m[1]));
                }
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_.-]+)\s*:\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $key = $m[1];
            $raw = trim($m[2]);
            if ($raw === '') {
                $data[$key] = '';
                $openKey = $key;
                continue;
            }
            $openKey = null;
            $data[$key] = self::scalar($raw);
        }

        return $data;
    }

    /** One front-matter value: quoted string, inline list, boolean, integer or plain text. */
    private static function scalar(string $raw): mixed
    {
        $raw = self::stripComment($raw);
        if ($raw === '') {
            return '';
        }
        $first = $raw[0];
        $last = $raw[strlen($raw) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return stripcslashes(substr($raw, 1, -1));
        }
        if ($first === '[' && $last === ']') {
            $inner = trim(substr($raw, 1, -1));
            if ($inner === '') {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn (string $item): string => (string)self::scalar(trim($item)),
                explode(',', $inner),
            ), static fn (string $item): bool => $item !== ''));
        }
        $lower = strtolower($raw);
        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }
        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int)$raw;
        }

        return $raw;
    }

    /**
     * A trailing ` # comment` is dropped, a `#` inside a value is kept (URLs carry fragments).
     *
     * A value that *is* a quoted scalar keeps its `#`: `"Release #2"` is one value, and cutting it at
     * the `#` would leave half a quotation behind. Only a quote at the very front of the value opens
     * one - that is the only shape {@see self::scalar()} strips, so an apostrophe inside a plain value
     * (`Author's note # by bob`) still starts no string and the comment still goes.
     */
    private static function stripComment(string $raw): string
    {
        $raw = ltrim($raw);
        $quote = $raw !== '' && ($raw[0] === '"' || $raw[0] === "'") ? $raw[0] : '';
        if ($quote !== '') {
            for ($i = 1, $n = strlen($raw); $i < $n; $i++) {
                if ($quote === '"' && $raw[$i] === '\\') {
                    // an escaped character cannot close the string
                    $i++;
                    continue;
                }
                if ($raw[$i] === $quote) {
                    // everything after the closing quote is a comment or nothing at all
                    return trim(substr($raw, 0, $i + 1));
                }
            }
            // no closing quote: not a quoted scalar after all, so the plain rule applies
        }
        $at = strpos($raw, ' #');
        if ($at === false) {
            return trim($raw);
        }

        return trim(substr($raw, 0, $at));
    }

    /**
     * A front-matter value as a list of strings: `tags: a b`, `tags: a, b`, `tags: [a, b]` and block
     * lists all mean the same thing to Jekyll.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                foreach (self::stringList($item) as $one) {
                    $out[] = $one;
                }
            }

            return array_values(array_unique($out));
        }
        if (!is_scalar($value)) {
            return [];
        }
        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $text) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), static fn (string $v): bool => $v !== '')));
    }

    /** @return list<string> */
    public static function tags(array $data): array
    {
        return self::stringList($data['tags'] ?? null);
    }

    /** @return list<string> */
    public static function categories(array $data): array
    {
        return self::stringList($data['categories'] ?? null);
    }

    /**
     * Jekyll's own visibility switch: `published: false` keeps a post out of the build unless the
     * site is built with `--unpublished`. Anything else (including a missing key) means visible.
     */
    public static function isPublished(array $data): bool
    {
        if (!array_key_exists('published', $data)) {
            return true;
        }
        $value = $data['published'];
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['false', 'no', 'off', '0'], true);
        }

        return (bool)$value;
    }

    /** The post's date: front matter first, then the `YYYY-MM-DD-` file-name prefix. */
    public static function date(array $data, string $relPath): int
    {
        $value = $data['date'] ?? null;
        if (is_string($value) && trim($value) !== '') {
            $ts = strtotime(trim($value));
            if ($ts !== false) {
                return $ts;
            }
        }
        if (preg_match('~(?:^|/)(\d{4})-(\d{2})-(\d{2})-~', $relPath, $m) === 1) {
            return (int)mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        }

        return 0;
    }

    /** The title: front matter first, then a readable form of the file name. */
    public static function title(array $data, string $relPath): string
    {
        $value = $data['title'] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return self::slug($relPath);
    }

    /** The file name without the date prefix and extension, as Jekyll's `:title` sees it. */
    public static function slug(string $relPath): string
    {
        $base = basename($relPath);
        $base = (string)preg_replace('/\.[A-Za-z0-9]+$/', '', $base);
        $base = (string)preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $base);

        return $base;
    }

    /**
     * The URL the built site serves this post at, following the site's permalink pattern.
     *
     * Returns '' when the pattern uses a placeholder this app does not know: the caller then falls
     * back to the site root instead of linking to a URL that may not exist.
     */
    public static function url(string $pattern, array $data, string $relPath): string
    {
        $own = trim((string)($data['permalink'] ?? ''));
        if ($own !== '') {
            return '/' . ltrim($own, '/');
        }
        $pattern = trim($pattern);
        if ($pattern === '') {
            $pattern = self::DEFAULT_PERMALINK;
        }
        $date = self::date($data, $relPath);
        $url = strtr($pattern, [
            ':categories' => implode('/', self::categories($data)),
            ':title' => self::slug($relPath),
            ':slug' => self::slug($relPath),
            ':year' => $date > 0 ? date('Y', $date) : '',
            ':month' => $date > 0 ? date('m', $date) : '',
            ':day' => $date > 0 ? date('d', $date) : '',
            ':i_month' => $date > 0 ? (string)(int)date('m', $date) : '',
            ':i_day' => $date > 0 ? (string)(int)date('d', $date) : '',
            ':output_ext' => '.html',
        ]);
        if (str_contains($url, ':')) {
            return '';
        }
        $url = (string)preg_replace('~/+~', '/', $url);

        return '/' . ltrim($url, '/');
    }

    /**
     * A plain-text teaser for cards. This is not a Markdown renderer: it drops the syntax that would
     * look like noise in a one-line summary and keeps the words.
     */
    public static function summary(string $body, int $limit = 200): string
    {
        $text = (string)preg_replace('/```.*?```/s', ' ', $body);
        $text = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text);
        $text = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string)preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text);
        $text = (string)preg_replace('/^\s{0,3}>\s?/m', '', $text);
        $text = (string)preg_replace('/^\s{0,3}[-*+]\s+/m', '', $text);
        $text = (string)preg_replace('/[*_`~]/', '', $text);
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit)) . '…';
    }
}
