<?php

declare(strict_types=1);

namespace AiWpCache\Agent\Hooks;

use AiWpCache\Cache\Headers;
use AiWpCache\Cache\Tags;
use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;

/**
 * Decides whether to cache a frontend response and applies the appropriate
 * cache headers via the template_redirect hook.
 */
final class TemplateSignals
{
    public function __construct(
        private readonly Options $options,
        private readonly Headers $headers,
        private readonly Tags    $tags,
        private readonly Logger  $logger,
    ) {}

    /** Register the template_redirect hook. */
    public function register(): void
    {
        add_action('template_redirect', [$this, 'onTemplateRedirect'], 1);
    }

    // -------------------------------------------------------------------------
    // Hook handlers
    // -------------------------------------------------------------------------

    /**
     * Inspect the current request and emit the correct cache headers.
     *
     * Called very early (priority 1) so downstream code can override if needed.
     */
    public function onTemplateRedirect(): void
    {
        if (!$this->options->isEnabled()) {
            $this->headers->setBypass();
            return;
        }

        if ($this->headers->isBypass()) {
            $this->headers->setBypass();
            $this->logger->debug('Cache bypassed', ['url' => $_SERVER['REQUEST_URI'] ?? '']);
            return;
        }

        $ttl      = $this->options->getCacheTtl();
        $pageTags = $this->buildTagsForCurrentPage();

        $this->headers->setForPage($ttl, $pageTags);

        $this->logger->debug('Cache headers set', [
            'ttl'  => $ttl,
            'tags' => $pageTags,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Collect cache tags appropriate for the current WordPress template context.
     *
     * @return list<string>
     */
    private function buildTagsForCurrentPage(): array
    {
        $pageTags = $this->tags->forSite();

        if (is_singular()) {
            $post = get_post();
            if ($post !== null) {
                $pageTags = array_merge($pageTags, $this->tags->forPost($post));
            }
            $pageTags = array_merge($pageTags, $this->tags->forTemplate('single'));
        } elseif (is_archive() || is_home() || is_front_page()) {
            $pageTags = array_merge($pageTags, $this->tags->forTemplate('archive'));
        } elseif (is_search()) {
            $pageTags = array_merge($pageTags, $this->tags->forTemplate('search'));
        }

        if (is_tax() || is_category() || is_tag()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $pageTags = array_merge($pageTags, $this->tags->forTerm($term));
            }
        }

        return array_values(array_unique($pageTags));
    }
}
