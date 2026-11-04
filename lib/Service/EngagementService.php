<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\ArticleMapper;
use OCA\Pages\Db\Comment;
use OCA\Pages\Db\CommentMapper;
use OCA\Pages\Db\Vote;
use OCA\Pages\Db\VoteMapper;
use OCP\Files\NotPermittedException;
use OCP\IL10N;

/**
 * What readers do with an article: rate it (one vote each, changeable and withdrawable) and discuss it
 * (comments, with moderation and a rate limit).
 *
 * Two boundaries are deliberate:
 *
 *  - **who may act on an article** is not decided here. {@see ArticleIndexService::single()} owns that
 *    answer - `live`, or the caller's own article, or the caller may moderate (the review grant also
 *    carries publishing) - and every entry point of this class asks it rather than repeating the rule.
 *    A second copy of the rule would be a second answer the day one of them changes.
 *  - **what state an article is in** is not written here either: votes and comments are community data
 *    and never touch the review manifest ({@see ReviewStore}), which stays the only truth about a
 *    state.
 *
 * Both write paths go through the unique index on `(article_id, voter_uid)`: a changed vote is an
 * UPDATE of the existing row, a withdrawn one is a DELETE, so a voter's opinion is always one row.
 */
final class EngagementService
{
    /** A comment is what a person types: short enough to be read, never empty. */
    public const COMMENT_MIN_CHARS = 1;
    public const COMMENT_MAX_CHARS = 2000;

    /** At most this many comments per author and article within the sliding window below. */
    public const COMMENT_LIMIT = 10;
    public const COMMENT_WINDOW_SECONDS = 3600;

    /** And at least this long between two comments of the same author on the same article. */
    public const COMMENT_COOLDOWN_SECONDS = 20;

    public function __construct(
        private ArticleIndexService $index,
        private ArticleMapper $articles,
        private VoteMapper $votes,
        private CommentMapper $comments,
        private AuthorAreaService $areas,
        private IL10N $l10n,
    ) {
    }

    /**
     * Rates an article and answers the caller with the article's new community data, so the reading
     * view can update the counters in place instead of reloading the list.
     *
     * @param string $vote `up`, `down`, or `none` to withdraw the caller's vote
     * @return array<string,mixed> the vote summary of the article
     * @throws NotFoundException when there is no such article
     * @throws NotPermittedException when the caller may not read it
     * @throws \InvalidArgumentException when the vote is none of the three accepted values
     */
    public function vote(string $viewerUid, string $target, string $vote, bool $moderate): array
    {
        $value = match ($vote) {
            'up' => Vote::UP,
            'down' => Vote::DOWN,
            // withdrawing is the absence of a row, not a third value in the column
            'none' => 0,
            default => throw new \InvalidArgumentException($this->l10n->t('A vote must be "up", "down" or "none"; got: %s', [$vote])),
        };
        $article = $this->article($viewerUid, $target, $moderate);
        $this->votes->setVote((int)$article['id'], $viewerUid, $value);

        // the answer is read after the write: the counters in it are the new ones, and `mine` is what
        // the caller's own row now says (absent = 0 = no vote)
        return $this->engagement($viewerUid, $target, $moderate);
    }

    /**
     * The comment thread of an article, oldest first.
     *
     * A deleted comment is gone for everybody except a caller with the review grant (which carries
     * moderating), who sees it with `deleted` set: moderation has to be able to tell "no comment" from
     * "a comment that was taken down", and the alternative - hiding the fact from the moderator as
     * well - would make a mistake invisible while its row is still there.
     *
     * @return array{comments: list<array<string,mixed>>, count: int}
     * @throws NotFoundException when there is no such article
     * @throws NotPermittedException when the caller may not read it
     */
    public function comments(string $viewerUid, string $target, bool $moderate): array
    {
        $article = $this->article($viewerUid, $target, $moderate);
        $rows = $this->comments->findForArticle((int)$article['id'], $moderate);
        $comments = [];
        foreach ($rows as $row) {
            $comments[] = $this->shape($row, $viewerUid, $moderate);
        }

        return ['comments' => $comments, 'count' => count($comments)];
    }

    /**
     * Writes one comment.
     *
     * The body is normalised and checked before anything else happens, and the rate limit is checked
     * before the row is written: a refused comment must not leave a trace, because the limit is what
     * the trace is counted from.
     *
     * @return array{comment: array<string,mixed>, count: int} the new comment and the thread length
     * @throws NotFoundException when there is no such article
     * @throws NotPermittedException when the caller may not read it
     * @throws \InvalidArgumentException when the body is empty or too long
     * @throws RateLimitException when the caller is writing too fast
     */
    public function comment(string $viewerUid, string $target, string $body, bool $moderate): array
    {
        $text = $this->normalizeBody($body);
        $this->checkLength($text);
        $article = $this->article($viewerUid, $target, $moderate);
        $this->checkRate($viewerUid, (int)$article['id']);

        $row = $this->comments->add((int)$article['id'], $viewerUid, $text);
        $count = count($this->comments->findForArticle((int)$article['id'], $moderate));

        return ['comment' => $this->shape($row, $viewerUid, $moderate), 'count' => $count];
    }

    /**
     * Deletes a comment (soft: the row stays, `deleted_at` is set).
     *
     * The author may delete their own comment, a caller with the review grant (which carries
     * moderating) may delete any. Asking twice is not an error - the second call changes nothing and
     * answers the same way - so a client that lost the first answer can simply try again. A comment
     * that is already deleted is therefore answered with the current state instead of a 404, while an
     * id nobody ever wrote is a 404.
     *
     * @return array{id: int, count: int, deletedAt: int}
     * @throws NotFoundException when no comment carries that id
     * @throws NotPermittedException when it is somebody else's comment and the caller may not moderate
     */
    public function deleteComment(string $viewerUid, int $id, bool $moderate): array
    {
        $row = $this->comments->findById($id);
        if ($row === null) {
            throw new NotFoundException($this->l10n->t('No such comment: %s', [(string)$id]));
        }
        if ((string)$row->getAuthorUid() !== $viewerUid && !$moderate) {
            throw new NotPermittedException($this->l10n->t('You may only delete your own comments.'));
        }
        if (!$row->isDeleted()) {
            $this->comments->softDelete($id);
            $row = $this->comments->findById($id) ?? $row;
        }

        // the caller's own view of the thread, so the new length is what that caller will see - a
        // moderator keeps counting the deleted comment, a reader does not
        $count = count($this->comments->findForArticle((int)$row->getArticleId(), $moderate));

        return ['id' => $id, 'count' => $count, 'deletedAt' => (int)$row->getDeletedAt()];
    }

    /**
     * The article a caller may act on, under the read rules of the index: the site has it, or it is the
     * caller's own article, or the caller may moderate.
     *
     * "No such article" and "you may not see it" are two different answers, and the difference is the
     * article row: the index only lists articles it found as files, so a path with no row is unknown to
     * the app, while a row the index left out is an article the caller is not meant to see.
     *
     * @return array<string,mixed> the article as the index describes it (id, path, up, down, score, mine, comments…)
     * @throws NotFoundException when the app has no row for that path
     * @throws NotPermittedException when the row exists but the caller may not read it
     * @throws \InvalidArgumentException when the path is unusable
     */
    private function article(string $viewerUid, string $target, bool $moderate): array
    {
        $path = $this->path($target);
        $article = $this->index->single($viewerUid, $path, $moderate);
        if ($article !== null) {
            return $article;
        }
        if ($this->articles->findByPath($path) === null) {
            throw new NotFoundException($this->l10n->t('No such article: %s', [$path]));
        }

        throw new NotPermittedException($this->l10n->t('You do not have permission to see this article.'));
    }

    /**
     * @return array<string,mixed> the vote summary the client updates in place
     */
    private function engagement(string $viewerUid, string $target, bool $moderate): array
    {
        $article = $this->article($viewerUid, $target, $moderate);

        return [
            'path' => (string)$article['path'],
            'up' => (int)$article['up'],
            'down' => (int)$article['down'],
            'score' => (int)$article['score'],
            'mine' => (int)$article['mine'],
            // carried along so a view that shows both counters can update both from one answer
            'comments' => (int)$article['comments'],
        ];
    }

    /**
     * A row as the client reads it. The body is plain text: it is user input, the browser renders it as
     * text, and `format` says so next to it so no client has to guess whether it may interpret it.
     *
     * @return array<string,mixed>
     */
    private function shape(Comment $row, string $viewerUid, bool $moderate): array
    {
        $author = (string)$row->getAuthorUid();
        $out = [
            'id' => (int)$row->getId(),
            'author' => $author,
            'authorName' => $this->areas->displayName($author),
            'body' => (string)$row->getBody(),
            'format' => 'text',
            'createdAt' => (int)$row->getCreatedAt(),
            'updatedAt' => (int)$row->getUpdatedAt(),
            'mine' => $author === $viewerUid,
        ];
        if ($moderate) {
            // only a moderator is ever handed a deleted comment; for everybody else the caller already
            // filtered it out, so the flag is present exactly when it can be true
            $out['deleted'] = $row->isDeleted();
        }

        return $out;
    }

    /**
     * The body as it is stored: trimmed, without control characters, with one line ending.
     *
     * The server does this rather than trusting the browser: the same text arrives from a form, from the
     * API and from a script, and only one of those three would normalise it the same way.
     */
    private function normalizeBody(string $body): string
    {
        // a NUL byte would survive the JSON round trip and the database, and it means nothing in a
        // comment: it makes a string that compares unequal to itself in some consumers
        $text = str_replace("\0", '', $body);
        $text = (string)preg_replace("/\r\n?/", "\n", $text);

        return trim($text);
    }

    /**
     * @throws \InvalidArgumentException when the body is shorter than one character or longer than the limit
     */
    private function checkLength(string $text): void
    {
        // characters rather than bytes: a comment of 2000 Chinese characters is one comment, and a
        // byte limit would refuse it while accepting 2000 ASCII ones
        $length = mb_strlen($text);
        if ($length < self::COMMENT_MIN_CHARS) {
            throw new \InvalidArgumentException($this->l10n->t('A comment must not be empty'));
        }
        if ($length > self::COMMENT_MAX_CHARS) {
            throw new \InvalidArgumentException($this->l10n->t(
                'A comment must be at most %s characters (yours has %s)',
                [(string)self::COMMENT_MAX_CHARS, (string)$length],
            ));
        }
    }

    /**
     * The rate limit, read from the rows themselves: how many comments this author wrote on this
     * article inside the window, and how long ago the newest one was. No second table, because the
     * comments *are* the record of when they were written.
     *
     * @throws RateLimitException with the wait the caller has to observe
     */
    private function checkRate(string $viewerUid, int $articleId): void
    {
        $now = time();
        $recent = $this->comments->countForAuthorSince(
            $articleId,
            $viewerUid,
            $now - self::COMMENT_WINDOW_SECONDS,
        );
        if ($recent >= self::COMMENT_LIMIT) {
            // the window is sliding, so the wait is until the oldest comment inside it falls out of it
            $oldest = $this->comments->oldestCreatedAtSince(
                $articleId,
                $viewerUid,
                $now - self::COMMENT_WINDOW_SECONDS,
            );
            $wait = $oldest === null
                ? self::COMMENT_WINDOW_SECONDS
                : max(1, $oldest + self::COMMENT_WINDOW_SECONDS - $now);
            throw new RateLimitException(
                $this->l10n->t('You have reached the limit of %1$s comments per article per hour. Try again in %2$s.', [
                    (string)self::COMMENT_LIMIT,
                    $this->wait($wait),
                ]),
                $wait,
            );
        }

        $newest = $this->comments->newestCreatedAtForAuthor($articleId, $viewerUid);
        if ($newest === null) {
            return;
        }
        $elapsed = $now - $newest;
        if ($elapsed >= self::COMMENT_COOLDOWN_SECONDS) {
            return;
        }
        $wait = max(1, self::COMMENT_COOLDOWN_SECONDS - $elapsed);
        throw new RateLimitException(
            $this->l10n->t('Please wait %s before writing another comment.', [$this->wait($wait)]),
            $wait,
        );
    }

    /** A wait as people read it; the number is always part of the message so the client can show it. */
    private function wait(int $seconds): string
    {
        return $seconds === 1
            ? $this->l10n->t('1 second')
            : $this->l10n->t('%s seconds', [(string)$seconds]);
    }

    /**
     * A target in the app's stored form (`/<author_root>/<uid>/<file>`). The same normalisation the
     * review service applies, because both address the same rows by the same key.
     *
     * @throws \InvalidArgumentException when the path is empty or tries to walk up
     */
    private function path(string $target): string
    {
        $path = trim(str_replace('\\', '/', $target));
        if ($path === '') {
            throw new \InvalidArgumentException($this->l10n->t('A path is required'));
        }
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException($this->l10n->t('An article path must not contain ..'));
        }

        return '/' . ltrim($path, '/');
    }
}
