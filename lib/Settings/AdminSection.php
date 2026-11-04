<?php

declare(strict_types=1);

namespace OCA\Pages\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/** The Pages section of the admin settings sidebar; the panel it holds is {@see Admin}. */
class AdminSection implements IIconSection
{
    /** Constructor injection only: the old \OC::$server->getXxx() getters no longer exist. */
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return 'pages';
    }

    public function getName(): string
    {
        return $this->l->t('Pages');
    }

    public function getPriority(): int
    {
        return 55;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('pages', 'app.svg');
    }
}
