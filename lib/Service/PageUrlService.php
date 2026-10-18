<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * The address a page of an author area has in the built site.
 *
 * The site source is the `jekyll-authors-template`, whose `_plugins/Authors.rb` mirrors every author
 * folder into the site before Jekyll reads it. An author file is therefore a template **page**, and its
 * built address is decided by the plugin, not by the site's `permalink` pattern (that one applies to
 * posts):
 *
 *   <author>/<file> with a usable category  ->  /<category>/<slug>.html
 *   <author>/<file> without one             ->  /<page_dir>/<author>/<relative path>.html
 *
 * A category is usable when the site root holds a page named after it (`<category>.md`, `.markdown`,
 * `.html` or `.htm`) - a copy for a missing category is skipped by the plugin, so the app must not
 * claim an address the build never produces. The slug is the front-matter `title` run through Jekyll's
 * default slugify (letters of any script survive, which is what keeps a Chinese title addressable);
 * the file name is the fallback.
 *
 * Everything here is read-only: `_config.yml` is the site owner's file and this class only reads the
 * `authors:` block out of it. The single exception to "read only" is nothing at all - no method writes.
 */
final class PageUrlService
{
    /** The site's own configuration, read for the `authors:` block and nothing else. */
    public const CONFIG_FILE = '_config.yml';

    /** The plugin's own defaults (see Authors.rb DEFAULTS): used when the block says nothing. */
    public const DEFAULT_SOURCE = 'authors-src';
    public const DEFAULT_PAGE_DIR = 'authors';

    /** The extensions a site-root category page may have (Authors.rb CATEGORY_FILE_EXTS). */
    public const CATEGORY_EXTENSIONS = ['md', 'markdown', 'html', 'htm'];

    /** Folder names the plugin refuses as a category (Authors.rb RESERVED_CATEGORIES). */
    public const RESERVED_CATEGORIES = ['tmp', 'authors', 'authors-src'];

    /** @var array<string,array{source:string,page_dir:string}> */
    private array $configs = [];

    /** @var array<string,array<string,string>|null> */
    private array $blocks = [];

    /** @var array<string,bool> */
    private array $categoryPages = [];

    public function __construct(
        private FileService $files,
    ) {
    }

    /**
     * The `authors:` block as the site declares it, or null when the site has no readable `_config.yml`.
     *
     * The raw answer is what the doctor compares against the app's author root: "the block says
     * something else" and "there is no block at all" are two different situations, and only the caller
     * can say which one is a failure.
     *
     * @return array<string,string>|null
     */
    public function authorsConfig(string $userId, string $ncPath): ?array
    {
        $key = $userId . '|' . $ncPath;
        if (array_key_exists($key, $this->blocks)) {
            return $this->blocks[$key];
        }
        try {
            $raw = (string)($this->files->read($userId, $ncPath, self::CONFIG_FILE)['content'] ?? '');
        } catch (NotFoundException|NotPermittedException) {
            // no configuration, or one the Files API will not hand out as text: there is no block to
            // read, which is a fact the caller may want to report
            return $this->blocks[$key] = null;
        }

        return $this->blocks[$key] = self::authorsBlock($raw);
    }

    /**
     * The `authors:` block of the site's `_config.yml`, with the plugin's defaults filled in.
     *
     * A missing, unreadable or block-less configuration answers the defaults: the file belongs to the
     * site owner, and a site that does not carry the block is a site whose mirror uses them.
     *
     * @return array{source:string,page_dir:string}
     */
    public function config(string $userId, string $ncPath): array
    {
        $key = $userId . '|' . $ncPath;
        if (isset($this->configs[$key])) {
            return $this->configs[$key];
        }
        $block = $this->authorsConfig($userId, $ncPath) ?? [];
        $source = self::oneSegment((string)($block['source'] ?? ''));
        $pageDir = self::oneSegment((string)($block['page_dir'] ?? ''));

        return $this->configs[$key] = [
            'source' => $source !== '' ? $source : self::DEFAULT_SOURCE,
            'page_dir' => $pageDir !== '' ? $pageDir : self::DEFAULT_PAGE_DIR,
        ];
    }

    /**
     * The categories of one page that the build can actually use: single level, path-safe, and named
     * after a page at the site root. Order is the author's, duplicates are dropped.
     *
     * @param array<string,mixed> $data the page's front matter
     * @return list<string>
     */
    public function usableCategories(string $userId, string $ncPath, array $data): array
    {
        $out = [];
        foreach (FrontMatter::categories($data) as $category) {
            if (!self::isCategoryName($category)) {
                continue;
            }
            if (!$this->categoryPageExists($userId, $ncPath, $category)) {
                continue;
            }
            if (!in_array($category, $out, true)) {
                $out[] = $category;
            }
        }

        return $out;
    }

    /**
     * The address the built site serves one author page at, '' when the site does not render it (a
     * non-renderable file, or one under a `_`/`.`-prefixed folder - Jekyll skips those).
     *
     * @param string              $areaRelative the file's path inside the author's area (`notes/a.md`)
     * @param array<string,mixed> $data         the page's front matter
     */
    public function forAuthorPage(
        string $userId,
        string $ncPath,
        string $authorUid,
        string $areaRelative,
        array $data,
    ): string {
        $rel = ltrim(str_replace('\\', '/', trim($areaRelative)), '/');
        if ($rel === '' || !self::isRenderable($rel) || self::hasSpecialSegment($rel)) {
            return '';
        }
        $slug = self::slugOf($data, $rel);
        if ($slug === '') {
            return '';
        }
        $categories = $this->usableCategories($userId, $ncPath, $data);
        if ($categories !== []) {
            return '/' . $categories[0] . '/' . $slug . '.html';
        }
        $config = $this->config($userId, $ncPath);
        // the plugin keeps the author's own directory structure under <page_dir>/<author>/; the
        // extension becomes .html because that is what Jekyll renders every page extension to
        $withoutExtension = (string)preg_replace('/\.[A-Za-z0-9]+$/', '', $rel);

        return '/' . $config['page_dir'] . '/' . $authorUid . '/' . $withoutExtension . '.html';
    }

    /** The stub a page without a usable category lands on: `<page_dir>/<author>/…`. */
    public function pageDir(string $userId, string $ncPath): string
    {
        return $this->config($userId, $ncPath)['page_dir'];
    }

    /** Whether one value may be a category the plugin mirrors into (Authors.rb category_name_ok?). */
    public static function isCategoryName(string $category): bool
    {
        $category = trim($category);
        if ($category === '' || $category === '.' || $category === '..') {
            return false;
        }
        if (str_starts_with($category, '_') || str_starts_with($category, '.')) {
            return false;
        }
        if (str_contains($category, '/') || str_contains($category, '\\')) {
            return false;
        }
        if (preg_match('/[<>:"|?*\x00-\x1f]/', $category) === 1) {
            return false;
        }
        if (str_ends_with($category, ' ') || str_ends_with($category, '.')) {
            return false;
        }

        return !in_array($category, self::RESERVED_CATEGORIES, true);
    }

    /** Whether Jekyll renders a path as a page at all: a page extension and no special folder. */
    public static function isRenderable(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
        if ($rel === '' || self::hasSpecialSegment($rel)) {
            return false;
        }

        return in_array(
            strtolower((string)pathinfo($rel, PATHINFO_EXTENSION)),
            AuthorAreaService::RENDERABLE_EXTENSIONS,
            true,
        );
    }

    /**
     * Jekyll's `:default` slugify mode: every run of characters that is neither a letter nor a digit
     * becomes one hyphen, leading and trailing hyphens go, the result is lower-cased. Letters of any
     * script survive, because a Chinese title is a perfectly good address on a Chinese site.
     */
    public static function slugify(string $text): string
    {
        $slug = (string)preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '-', $text);
        $slug = trim($slug, '-');

        return mb_strtolower($slug, 'UTF-8');
    }

    /**
     * The slug the plugin uses for one page: the front-matter title, slugified, when it has one that
     * can be a path; the file name as it is otherwise (that is the plugin's own fallback).
     *
     * @param array<string,mixed> $data
     */
    private static function slugOf(array $data, string $rel): string
    {
        $title = is_scalar($data['title'] ?? null) ? trim((string)$data['title']) : '';
        if ($title !== '') {
            $slug = self::slugify($title);
            // a title whose slug carries a separator cannot be one path segment: the plugin falls
            // back to the file name for exactly this case
            if ($slug !== '' && !str_contains($slug, '/') && !str_contains($slug, '\\')) {
                return $slug;
            }
        }
        $base = (string)preg_replace('/\.[A-Za-z0-9]+$/', '', basename($rel));

        return str_contains($base, '/') || str_contains($base, '\\') ? '' : $base;
    }

    /** Whether a path carries a segment Jekyll treats as special (`.` or `_` in front). */
    private static function hasSpecialSegment(string $rel): bool
    {
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (str_starts_with($segment, '.') || str_starts_with($segment, '_')) {
                return true;
            }
        }

        return false;
    }

    /** Whether the site root holds a page of that name, in any of the extensions the plugin accepts. */
    private function categoryPageExists(string $userId, string $ncPath, string $category): bool
    {
        $key = $userId . '|' . $ncPath . '|' . $category;
        if (array_key_exists($key, $this->categoryPages)) {
            return $this->categoryPages[$key];
        }
        $found = false;
        foreach (self::CATEGORY_EXTENSIONS as $extension) {
            try {
                if ($this->files->fileExists($userId, $ncPath, $category . '.' . $extension)) {
                    $found = true;
                    break;
                }
            } catch (NotFoundException|NotPermittedException) {
                // a folder named like the category, or a site the caller may not read: no page
                continue;
            }
        }

        return $this->categoryPages[$key] = $found;
    }

    /**
     * The `authors:` block of `_config.yml`, as a flat map. The app's front-matter dialect does not
     * model nesting, and `FrontMatter::document()` would read `authors:` as the empty string and lose
     * the two settings this class exists for, so the block is read here - narrowly, and only for the
     * keys the plugin reads.
     *
     * @return array<string,string>
     */
    private static function authorsBlock(string $yaml): array
    {
        $out = [];
        $inside = false;
        foreach (preg_split("/\r\n|\n|\r/", $yaml) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_.-]+)\s*:/', $line, $top) === 1) {
                // a top-level key ends the block; `authors:` itself opens it
                $inside = $top[1] === 'authors';
                continue;
            }
            if (!$inside) {
                continue;
            }
            if (preg_match('/^\s+([A-Za-z0-9_.-]+)\s*:\s*(.*)$/', $line, $entry) !== 1) {
                continue;
            }
            $value = trim($entry[2]);
            $comment = strpos($value, ' #');
            if ($comment !== false) {
                $value = trim(substr($value, 0, $comment));
            }
            $out[$entry[1]] = trim($value, " \t\"'");
        }

        return $out;
    }

    /** One value as a single path segment, or '' when it is not one (a hand-edited config). */
    private static function oneSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '/') || str_contains($value, '\\')
            || $value === '.' || $value === '..' || str_starts_with($value, '.')) {
            return '';
        }

        return $value;
    }
}
