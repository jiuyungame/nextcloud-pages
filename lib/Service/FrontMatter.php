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
     *  - nothing throws: unparsable input yields the document unchanged rather than a broken file.
     *
     * @param array<string,mixed> $set   key => value (null removes the key)
     * @param list<string>        $remove keys to drop
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
                $out[] = $key . ': ' . self::toYaml($writing[$key]);
                $seen[$key] = true;
                continue;
            }
            $out[] = $line;
        }

        foreach ($writing as $key => $value) {
            if (!isset($seen[$key])) {
                $out[] = $key . ': ' . self::toYaml($value);
            }
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
     * One value as YAML. A string that could be read back as another type (or that carries a
     * character the parser would cut) is quoted, so that a round trip through parse() is stable.
     */
    private static function toYaml(mixed $value): string
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
                $items[] = self::toYaml($item);
            }
            return '[' . implode(', ', $items) . ']';
        }
        $text = (string)$value;
        if ($text === '') {
            return '';
        }
        $needsQuotes = preg_match('/^[\s]|[\s]$/', $text) === 1
            || str_contains($text, ' #')
            || str_contains($text, ': ')
            || str_contains($text, '"')
            || str_contains($text, "'")
            || preg_match('/^(true|false|yes|no|on|off|null|~)$/i', $text) === 1
            || preg_match('/^-?\d+(\.\d+)?$/', $text) === 1
            || str_starts_with($text, '[')
            || str_starts_with($text, '{')
            || str_starts_with($text, '-');

        return $needsQuotes ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"' : $text;
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

    /** A trailing ` # comment` is dropped, a `#` inside a value is kept (URLs carry fragments). */
    private static function stripComment(string $raw): string
    {
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
