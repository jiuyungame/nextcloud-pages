<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * The thing a request names does not exist. Distinct from {@see \OCP\Files\NotFoundException}, which is
 * about a file that is missing, and from an invalid argument, which is about a request that names
 * something the app does not address at all: "there is no such article" is a different answer.
 */
class NotFoundException extends \InvalidArgumentException
{
}
