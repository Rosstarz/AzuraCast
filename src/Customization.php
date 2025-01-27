<?php

namespace App;

use App\Entity;
use App\Http\ServerRequest;
use App\Service\NChan;
use Psr\Http\Message\ServerRequestInterface;

class Customization
{
    public const DEFAULT_THEME = 'browser';

    public const THEME_BROWSER = 'browser';
    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';

    protected ?Entity\User $user = null;

    protected Entity\Settings $settings;

    protected Locale $locale;

    protected string $theme = self::DEFAULT_THEME;

    protected string $publicTheme = self::DEFAULT_THEME;

    protected string $instanceName = '';

    public function __construct(
        protected Environment $environment,
        Entity\Repository\SettingsRepository $settingsRepo,
        ServerRequestInterface $request
    ) {
        $this->settings = $settingsRepo->readSettings();

        $this->instanceName = $this->settings->getInstanceName() ?? '';

        // Register current user
        $this->user = $request->getAttribute(ServerRequest::ATTR_USER);

        // Register current theme
        $queryParams = $request->getQueryParams();

        if (!empty($queryParams['theme'])) {
            $this->publicTheme = $this->theme = $queryParams['theme'];
        } else {
            $this->publicTheme = $this->settings->getPublicTheme() ?? $this->publicTheme;

            if (null !== $this->user && !empty($this->user->getTheme())) {
                $this->theme = (string)$this->user->getTheme();
            }
        }

        // Register locale
        $this->locale = new Locale($environment, $request);
        $this->locale->register();
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    /**
     * Returns the user-customized or system default theme.
     */
    public function getTheme(): string
    {
        return $this->theme;
    }

    /**
     * Get the instance name for this AzuraCast instance.
     */
    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    /**
     * Get the theme name to be used in public (non-logged-in) pages.
     */
    public function getPublicTheme(): string
    {
        return $this->publicTheme;
    }

    /**
     * Return the administrator-supplied custom CSS for public (minimal layout) pages, if specified.
     */
    public function getCustomPublicCss(): string
    {
        return $this->settings->getPublicCustomCss() ?? '';
    }

    /**
     * Return the administrator-supplied custom JS for public (minimal layout) pages, if specified.
     */
    public function getCustomPublicJs(): string
    {
        return $this->settings->getPublicCustomJs() ?? '';
    }

    /**
     * Return the administrator-supplied custom CSS for internal (full layout) pages, if specified.
     */
    public function getCustomInternalCss(): string
    {
        return $this->settings->getInternalCustomCss() ?? '';
    }

    /**
     * Return whether to show or hide album art on public pages.
     */
    public function hideAlbumArt(): bool
    {
        return $this->settings->getHideAlbumArt();
    }

    /**
     * Return the calculated page title given branding settings and the application environment.
     *
     * @param string|null $title
     */
    public function getPageTitle($title = null): string
    {
        if (!$this->hideProductName()) {
            if ($title) {
                $title .= ' - ' . $this->environment->getAppName();
            } else {
                $title = $this->environment->getAppName();
            }
        }

        if (!$this->environment->isProduction()) {
            $title = '(' . ucfirst($this->environment->getAppEnvironment()) . ') ' . $title;
        }

        return $title;
    }

    /**
     * Return whether to show or hide the AzuraCast name from public-facing pages.
     */
    public function hideProductName(): bool
    {
        return $this->settings->getHideProductName();
    }

    public function useWebSocketsForNowPlaying(): bool
    {
        if (!NChan::isSupported()) {
            return false;
        }

        return $this->settings->getEnableWebsockets();
    }
}
