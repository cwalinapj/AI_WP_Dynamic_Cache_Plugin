<?php

declare(strict_types=1);

namespace AiWpCache\Cache;

use WP_Post;
use WP_Term;

/**
 * Generates cache tags (surrogate keys) for WordPress content.
 *
 * Tags follow the convention <type>:<id> so that the CDN can group and purge
 * entries by any dimension (post, taxonomy term, author, post type, …).
 */
final class Tags
{
    /**
     * Build the full tag set for a single post.
     *
     * @return list<string>
     */
    public function forPost(WP_Post $post): array
    {
        $tags = [
            $this->sanitizeTag('post:' . $post->ID),
            $this->sanitizeTag('post_type:' . $post->post_type),
        ];

        if ((int) $post->post_author > 0) {
            $tags[] = $this->sanitizeTag('author:' . $post->post_author);
        }

        // Attach taxonomy term tags.
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $tags[] = $this->sanitizeTag('term:' . $term->term_id);
                $tags[] = $this->sanitizeTag('taxonomy:' . $taxonomy);
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Build the tag set for a single taxonomy term.
     *
     * @return list<string>
     */
    public function forTerm(WP_Term $term): array
    {
        return [
            $this->sanitizeTag('term:' . $term->term_id),
            $this->sanitizeTag('taxonomy:' . $term->taxonomy),
        ];
    }

    /**
     * Build the tag set for a template type.
     *
     * @param string $template Template identifier (e.g. 'single', 'archive').
     * @return list<string>
     */
    public function forTemplate(string $template): array
    {
        return [
            $this->sanitizeTag('template:' . $template),
            'site',
        ];
    }

    /**
     * Return the site-wide tag – used to purge everything.
     *
     * @return list<string>
     */
    public function forSite(): array
    {
        return ['site'];
    }

    /**
     * Sanitise a single cache tag.
     *
     * Replaces whitespace and characters outside [a-zA-Z0-9\-_:.] with hyphens.
     */
    public function sanitizeTag(string $tag): string
    {
        $tag = strtolower(trim($tag));
        return preg_replace('/[^a-z0-9\-_:.]/', '-', $tag) ?? $tag;
    }
}
