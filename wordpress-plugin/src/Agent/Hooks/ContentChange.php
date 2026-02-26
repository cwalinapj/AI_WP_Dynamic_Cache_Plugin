<?php

declare(strict_types=1);

namespace AiWpCache\Agent\Hooks;

use AiWpCache\Api\Client;
use AiWpCache\Cache\Tags;
use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;
use WP_Post;
use WP_Term;

/**
 * Registers WordPress (and optionally WooCommerce) content-change hooks and
 * triggers cache purge + preload whenever managed content is modified.
 */
final class ContentChange
{
    public function __construct(
        private readonly Options $options,
        private readonly Client  $client,
        private readonly Logger  $logger,
        private readonly Tags    $tags,
    ) {}

    /** Register all content-change hooks with WordPress. */
    public function register(): void
    {
        add_action('save_post',   [$this, 'onSavePost'],   10, 2);
        add_action('delete_post', [$this, 'onDeletePost'], 10, 2);
        add_action('edited_term', [$this, 'onEditedTerm'], 10, 3);
        add_action('delete_term', [$this, 'onDeleteTerm'], 10, 4);
        add_action('switch_theme', [$this, 'onSwitchTheme']);

        if ($this->isWooCommerceActive()) {
            add_action(
                'woocommerce_product_object_updated',
                [$this, 'onWooCommerceProductUpdated'],
                10,
                2
            );
        }
    }

    // -------------------------------------------------------------------------
    // Hook handlers
    // -------------------------------------------------------------------------

    /** Purge and schedule preload when a post is saved. */
    public function onSavePost(int $postId, WP_Post $post): void
    {
        // Skip revisions, auto-drafts, and non-public post types.
        if (wp_is_post_revision($postId) || $post->post_status === 'auto-draft') {
            return;
        }

        $cacheTags = $this->tags->forPost($post);
        $this->purgeAndPreload($cacheTags, [get_permalink($postId) ?: '']);
    }

    /** Purge when a post is deleted. */
    public function onDeletePost(int $postId, WP_Post $post): void
    {
        $cacheTags = $this->tags->forPost($post);
        $this->purge($cacheTags);
    }

    /** Purge and schedule preload when a taxonomy term is updated. */
    public function onEditedTerm(int $termId, int $ttId, string $taxonomy): void
    {
        $term = get_term($termId, $taxonomy);
        if (!($term instanceof WP_Term)) {
            return;
        }

        $cacheTags = $this->tags->forTerm($term);
        $termUrl   = get_term_link($term);
        $this->purgeAndPreload($cacheTags, [is_string($termUrl) ? $termUrl : '']);
    }

    /** Purge when a term is deleted. */
    public function onDeleteTerm(int $termId, int $ttId, string $taxonomy, WP_Term|false $deletedTerm): void
    {
        if (!($deletedTerm instanceof WP_Term)) {
            return;
        }

        $this->purge($this->tags->forTerm($deletedTerm));
    }

    /** Purge everything when the active theme changes. */
    public function onSwitchTheme(): void
    {
        $this->purge($this->tags->forSite());
    }

    /** Purge and preload when a WooCommerce product is updated. */
    public function onWooCommerceProductUpdated(mixed $product, mixed $data): void
    {
        if (!method_exists($product, 'get_id')) {
            return;
        }

        $postId   = (int) $product->get_id();
        $postObj  = get_post($postId);
        if (!($postObj instanceof WP_Post)) {
            return;
        }

        $cacheTags = $this->tags->forPost($postObj);
        $this->purgeAndPreload($cacheTags, [get_permalink($postId) ?: '']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Purge cache tags and schedule URL preloads.
     *
     * @param list<string> $cacheTags
     * @param list<string> $urls
     */
    private function purgeAndPreload(array $cacheTags, array $urls): void
    {
        $this->purge($cacheTags);

        $urls = array_filter($urls, static fn(string $u): bool => $u !== '');
        foreach ($urls as $url) {
            wp_schedule_single_event(time() + 5, 'aiwpc_preload_url', [$url]);
        }
    }

    /** Dispatch a purge request for the given tags. */
    private function purge(array $cacheTags): void
    {
        if (!$this->options->isEnabled() || empty($cacheTags)) {
            return;
        }

        $ok = $this->client->purge($cacheTags);

        if (!$ok) {
            $this->logger->warn('Purge failed', ['tags' => $cacheTags]);
        }
    }

    /** Check whether WooCommerce is active. */
    private function isWooCommerceActive(): bool
    {
        return defined('WC_VERSION');
    }
}
