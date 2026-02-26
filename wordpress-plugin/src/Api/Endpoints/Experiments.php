<?php

declare(strict_types=1);

namespace AiWpCache\Api\Endpoints;

use AiWpCache\Storage\Logger;
use AiWpCache\Storage\Options;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint: GET/POST /wp-json/ai-wp-cache/v1/experiments
 *
 * Exposes and manages the active caching strategy experiment.
 * Requires manage_options capability for all operations.
 */
final class Experiments
{
    private const NAMESPACE = 'ai-wp-cache/v1';

    /** Valid strategy identifiers. */
    private const STRATEGIES = ['disk_only', 'disk_edge', 'r2', 'full'];

    public function __construct(
        private readonly Options $options,
        private readonly Logger  $logger,
    ) {}

    /** Register GET and POST routes. */
    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/experiments', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'getCallback'],
                'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'postCallback'],
                'permission_callback' => static fn(): bool => current_user_can('manage_options'),
                'args'                => [
                    'strategy' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => self::STRATEGIES,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);
    }

    /** Return current strategy and any stored benchmark metrics. */
    public function getCallback(): WP_REST_Response
    {
        $metrics = $this->options->get('experiment_metrics', []);

        return new WP_REST_Response([
            'active_strategy' => $this->options->getActivePolicy(),
            'strategies'      => self::STRATEGIES,
            'metrics'         => is_array($metrics) ? $metrics : [],
        ], 200);
    }

    /**
     * Update the active strategy.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function postCallback(WP_REST_Request $request): WP_REST_Response
    {
        $strategy = (string) $request->get_param('strategy');

        if (!in_array($strategy, self::STRATEGIES, true)) {
            return new WP_REST_Response(['error' => 'Invalid strategy'], 400);
        }

        $this->options->setActivePolicy($strategy);
        $this->logger->info('Experiment strategy updated', ['strategy' => $strategy]);

        return new WP_REST_Response([
            'status'          => 'updated',
            'active_strategy' => $strategy,
        ], 200);
    }
}
