<?php
/**
 * Plugin Name: AI WP Dynamic Cache Plugin
 * Description: Signed edge-agent cache controls for WordPress with Cloudflare Worker integration and sandbox scheduler/conflict workflows.
 * Version: 0.1.0
 * Author: AI WP Plugin Family
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AI_WPDYN_OPTION_KEY', 'ai_wpdyn_settings');
define('AI_WPDYN_MENU_SLUG', 'ai-wp-dynamic-cache');
define('AI_WPDYN_CRON_HOOK', 'ai_wpdyn_benchmark_event');

register_activation_hook(__FILE__, 'ai_wpdyn_activate');
register_deactivation_hook(__FILE__, 'ai_wpdyn_deactivate');

add_action('admin_menu', 'ai_wpdyn_register_admin_menu');
add_action('admin_init', 'ai_wpdyn_handle_settings_submit');
add_action('admin_init', 'ai_wpdyn_handle_benchmark_submit');
add_action('admin_init', 'ai_wpdyn_handle_sandbox_request_submit');
add_action('admin_init', 'ai_wpdyn_handle_sandbox_vote_submit');
add_action('admin_init', 'ai_wpdyn_handle_sandbox_claim_submit');
add_action('admin_init', 'ai_wpdyn_handle_sandbox_release_submit');
add_action('admin_init', 'ai_wpdyn_handle_conflict_report_submit');
add_action('admin_init', 'ai_wpdyn_handle_conflict_resolve_submit');
add_action('send_headers', 'ai_wpdyn_send_cache_headers');
add_action(AI_WPDYN_CRON_HOOK, 'ai_wpdyn_run_scheduled_benchmark');

function ai_wpdyn_activate() {
    $existing = get_option(AI_WPDYN_OPTION_KEY, null);
    if (!is_array($existing)) {
        update_option(AI_WPDYN_OPTION_KEY, ai_wpdyn_default_settings(), false);
    }

    if (!wp_next_scheduled(AI_WPDYN_CRON_HOOK)) {
        wp_schedule_event(time() + 300, 'hourly', AI_WPDYN_CRON_HOOK);
    }
}

function ai_wpdyn_deactivate() {
    wp_clear_scheduled_hook(AI_WPDYN_CRON_HOOK);
}

function ai_wpdyn_default_settings() {
    return [
        'worker_base_url' => '',
        'plugin_shared_secret' => '',
        'plugin_instance_id' => '',
        'sandbox_capability_token' => '',
        'site_id' => ai_wpdyn_default_site_id(),
        'default_agent_id' => '',
        'cache_strategy' => 'edge-balanced',
        'default_ttl_seconds' => 300,
        'send_cache_headers' => 1,
        'auto_benchmark' => 0,
        'last_benchmark_at' => 0,
        'last_benchmark_status' => '',
        'last_benchmark_notes' => '',
    ];
}

function ai_wpdyn_get_settings() {
    $defaults = ai_wpdyn_default_settings();
    $stored = get_option(AI_WPDYN_OPTION_KEY, []);
    if (!is_array($stored)) {
        $stored = [];
    }

    return array_merge($defaults, $stored);
}

function ai_wpdyn_save_settings($input) {
    $current = ai_wpdyn_get_settings();
    $next = [
        'worker_base_url' => isset($input['worker_base_url']) ? esc_url_raw(trim((string)$input['worker_base_url'])) : (string)$current['worker_base_url'],
        'plugin_shared_secret' => isset($input['plugin_shared_secret']) ? trim((string)$input['plugin_shared_secret']) : (string)$current['plugin_shared_secret'],
        'plugin_instance_id' => isset($input['plugin_instance_id']) ? ai_wpdyn_sanitize_instance_id($input['plugin_instance_id']) : (string)$current['plugin_instance_id'],
        'sandbox_capability_token' => isset($input['sandbox_capability_token']) ? trim((string)$input['sandbox_capability_token']) : (string)$current['sandbox_capability_token'],
        'site_id' => isset($input['site_id']) ? ai_wpdyn_sanitize_site_id($input['site_id']) : (string)$current['site_id'],
        'default_agent_id' => isset($input['default_agent_id']) ? sanitize_text_field(trim((string)$input['default_agent_id'])) : (string)$current['default_agent_id'],
        'cache_strategy' => isset($input['cache_strategy']) ? ai_wpdyn_normalize_strategy($input['cache_strategy'], (string)$current['cache_strategy']) : (string)$current['cache_strategy'],
        'default_ttl_seconds' => isset($input['default_ttl_seconds']) ? ai_wpdyn_clamp_int($input['default_ttl_seconds'], 30, 86400, (int)$current['default_ttl_seconds']) : (int)$current['default_ttl_seconds'],
        'send_cache_headers' => !empty($input['send_cache_headers']) ? 1 : 0,
        'auto_benchmark' => !empty($input['auto_benchmark']) ? 1 : 0,
        'last_benchmark_at' => (int)($current['last_benchmark_at'] ?? 0),
        'last_benchmark_status' => sanitize_text_field((string)($current['last_benchmark_status'] ?? '')),
        'last_benchmark_notes' => sanitize_text_field((string)($current['last_benchmark_notes'] ?? '')),
    ];

    update_option(AI_WPDYN_OPTION_KEY, $next, false);
    return $next;
}

function ai_wpdyn_register_admin_menu() {
    add_menu_page(
        'AI Dynamic Cache',
        'AI Dynamic Cache',
        'manage_options',
        AI_WPDYN_MENU_SLUG,
        'ai_wpdyn_render_settings_page',
        'dashicons-database-view',
        81
    );
}

function ai_wpdyn_handle_settings_submit() {
    if (!isset($_POST['ai_wpdyn_settings_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('ai_wpdyn_settings_save', 'ai_wpdyn_nonce');

    ai_wpdyn_save_settings([
        'worker_base_url' => isset($_POST['worker_base_url']) ? wp_unslash($_POST['worker_base_url']) : '',
        'plugin_shared_secret' => isset($_POST['plugin_shared_secret']) ? wp_unslash($_POST['plugin_shared_secret']) : '',
        'plugin_instance_id' => isset($_POST['plugin_instance_id']) ? wp_unslash($_POST['plugin_instance_id']) : '',
        'sandbox_capability_token' => isset($_POST['sandbox_capability_token']) ? wp_unslash($_POST['sandbox_capability_token']) : '',
        'site_id' => isset($_POST['site_id']) ? wp_unslash($_POST['site_id']) : '',
        'default_agent_id' => isset($_POST['default_agent_id']) ? wp_unslash($_POST['default_agent_id']) : '',
        'cache_strategy' => isset($_POST['cache_strategy']) ? wp_unslash($_POST['cache_strategy']) : '',
        'default_ttl_seconds' => isset($_POST['default_ttl_seconds']) ? wp_unslash($_POST['default_ttl_seconds']) : 300,
        'send_cache_headers' => isset($_POST['send_cache_headers']) ? 1 : 0,
        'auto_benchmark' => isset($_POST['auto_benchmark']) ? 1 : 0,
    ]);

    add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_saved', 'Settings saved.', 'updated');
}

function ai_wpdyn_handle_benchmark_submit() {
    if (!isset($_POST['ai_wpdyn_benchmark_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('ai_wpdyn_benchmark_run', 'ai_wpdyn_benchmark_nonce');
    $settings = ai_wpdyn_get_settings();
    $result = ai_wpdyn_run_benchmark_and_apply($settings);

    if ($result['ok']) {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_benchmark_ok', $result['message'], 'updated');
        return;
    }

    add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_benchmark_error', $result['message'], 'error');
}

function ai_wpdyn_handle_sandbox_request_submit() {
    if (!isset($_POST['ai_wpdyn_sandbox_request_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('ai_wpdyn_sandbox_request', 'ai_wpdyn_sandbox_request_nonce');
    $settings = ai_wpdyn_get_settings();

    $siteId = ai_wpdyn_sanitize_site_id(wp_unslash($_POST['ai_wpdyn_sandbox_site_id'] ?? ''));
    $agentId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_agent_id'] ?? '')));
    if ($siteId === '' || $agentId === '') {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_request_missing', 'Sandbox request requires Site ID and Agent ID.', 'error');
        return;
    }

    $taskType = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_task_type'] ?? 'cache-benchmark')));
    $priorityBase = ai_wpdyn_clamp_int(wp_unslash($_POST['ai_wpdyn_sandbox_priority_base'] ?? 3), 1, 5, 3);
    $estimatedMinutes = ai_wpdyn_clamp_int(wp_unslash($_POST['ai_wpdyn_sandbox_estimated_minutes'] ?? 20), 5, 240, 20);
    $earliestStartAt = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_earliest_start_at'] ?? '')));
    $contextRaw = trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_context_json'] ?? ''));

    $payload = [
        'site_id' => $siteId,
        'requested_by_agent' => $agentId,
        'task_type' => ($taskType !== '') ? $taskType : 'cache-benchmark',
        'priority_base' => $priorityBase,
        'estimated_minutes' => $estimatedMinutes,
    ];

    if ($earliestStartAt !== '') {
        $payload['earliest_start_at'] = $earliestStartAt;
    }

    if ($contextRaw !== '') {
        $decoded = json_decode($contextRaw, true);
        if (is_array($decoded)) {
            $payload['context'] = $decoded;
        }
    }

    $response = ai_wpdyn_request_sandbox($settings, $payload);
    list($status, $decoded, $errorText) = ai_wpdyn_decode_worker_json_response($response);

    if ($status >= 200 && $status < 300 && !empty($decoded['ok'])) {
        $requestId = sanitize_text_field((string)($decoded['request']['id'] ?? ''));
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_request_ok', 'Sandbox request queued' . (($requestId !== '') ? ': ' . $requestId : '.'), 'updated');
        return;
    }

    if ($errorText === '') {
        $errorText = 'Unable to queue sandbox request.';
    }
    add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_request_failed', 'Sandbox request failed: ' . $errorText, 'error');
}

function ai_wpdyn_handle_sandbox_vote_submit() {
    if (!isset($_POST['ai_wpdyn_sandbox_vote_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('ai_wpdyn_sandbox_vote', 'ai_wpdyn_sandbox_vote_nonce');
    $settings = ai_wpdyn_get_settings();

    $requestId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_vote_request_id'] ?? '')));
    $agentId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_vote_agent_id'] ?? '')));
    if ($requestId === '' || $agentId === '') {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_vote_missing', 'Sandbox vote requires Request ID and Agent ID.', 'error');
        return;
    }

    $vote = ai_wpdyn_clamp_int(wp_unslash($_POST['ai_wpdyn_sandbox_vote_value'] ?? 0), -5, 5, 0);
    $reason = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_vote_reason'] ?? '')));

    $response = ai_wpdyn_vote_sandbox($settings, [
        'request_id' => $requestId,
        'agent_id' => $agentId,
        'vote' => $vote,
        'reason' => ($reason !== '') ? $reason : null,
    ]);
    list($status, $decoded, $errorText) = ai_wpdyn_decode_worker_json_response($response);

    if ($status >= 200 && $status < 300 && !empty($decoded['ok'])) {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_vote_ok', 'Sandbox vote recorded.', 'updated');
        return;
    }

    if ($errorText === '') {
        $errorText = 'Unable to submit sandbox vote.';
    }
    add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_vote_failed', 'Sandbox vote failed: ' . $errorText, 'error');
}

function ai_wpdyn_handle_sandbox_claim_submit() {
    if (!isset($_POST['ai_wpdyn_sandbox_claim_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('ai_wpdyn_sandbox_claim', 'ai_wpdyn_sandbox_claim_nonce');
    $settings = ai_wpdyn_get_settings();

    $agentId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_claim_agent_id'] ?? '')));
    if ($agentId === '') {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_claim_missing', 'Sandbox claim requires Agent ID.', 'error');
        return;
    }

    $sandboxId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_claim_sandbox_id'] ?? '')));
    $slotMinutes = ai_wpdyn_clamp_int(wp_unslash($_POST['ai_wpdyn_sandbox_claim_slot_minutes'] ?? 30), 5, 240, 30);

    $payload = [
        'agent_id' => $agentId,
        'slot_minutes' => $slotMinutes,
    ];
    if ($sandboxId !== '') {
        $payload['sandbox_id'] = $sandboxId;
    }

    $response = ai_wpdyn_claim_sandbox($settings, $payload);
    list($status, $decoded, $errorText) = ai_wpdyn_decode_worker_json_response($response);

    if ($status >= 200 && $status < 300 && !empty($decoded['ok'])) {
        $selected = sanitize_text_field((string)($decoded['selected_request']['id'] ?? ''));
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_claim_ok', 'Sandbox claim succeeded' . (($selected !== '') ? ' for request: ' . $selected : '.'), 'updated');
        return;
    }

    if ($errorText === '') {
        $errorText = 'Unable to claim sandbox request.';
    }
    add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_claim_failed', 'Sandbox claim failed: ' . $errorText, 'error');
}

function ai_wpdyn_handle_sandbox_release_submit() {
    if (!isset($_POST['ai_wpdyn_sandbox_release_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('ai_wpdyn_sandbox_release', 'ai_wpdyn_sandbox_release_nonce');
    $settings = ai_wpdyn_get_settings();

    $requestId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_release_request_id'] ?? '')));
    $agentId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_release_agent_id'] ?? '')));
    if ($requestId === '' || $agentId === '') {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_release_missing', 'Sandbox release requires Request ID and Agent ID.', 'error');
        return;
    }

    $outcomeRaw = strtolower(sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_sandbox_release_outcome'] ?? 'completed'))));
    $outcome = ($outcomeRaw === 'failed' || $outcomeRaw === 'requeue') ? $outcomeRaw : 'completed';
    $note = sanitize_textarea_field((string)wp_unslash($_POST['ai_wpdyn_sandbox_release_note'] ?? ''));

    $response = ai_wpdyn_release_sandbox($settings, [
        'request_id' => $requestId,
        'agent_id' => $agentId,
        'outcome' => $outcome,
        'note' => ($note !== '') ? $note : null,
    ]);
    list($status, $decoded, $errorText) = ai_wpdyn_decode_worker_json_response($response);

    if ($status >= 200 && $status < 300 && !empty($decoded['ok'])) {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_release_ok', 'Sandbox release submitted.', 'updated');
        return;
    }

    if ($errorText === '') {
        $errorText = 'Unable to release sandbox request.';
    }
    add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_sandbox_release_failed', 'Sandbox release failed: ' . $errorText, 'error');
}

function ai_wpdyn_handle_conflict_report_submit() {
    if (!isset($_POST['ai_wpdyn_conflict_report_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('ai_wpdyn_sandbox_conflict_report', 'ai_wpdyn_conflict_report_nonce');
    $settings = ai_wpdyn_get_settings();

    $siteId = ai_wpdyn_sanitize_site_id(wp_unslash($_POST['ai_wpdyn_conflict_site_id'] ?? ''));
    $summary = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_summary'] ?? '')));
    if ($siteId === '' || $summary === '') {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_conflict_missing_fields', 'Conflict report requires Site ID and summary.', 'error');
        return;
    }

    $requestId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_request_id'] ?? '')));
    $blockedBy = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_blocked_by_request_id'] ?? '')));
    $sandboxId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_sandbox_id'] ?? '')));
    $agentId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_agent_id'] ?? '')));
    if ($agentId === '') {
        $agentId = ai_wpdyn_default_sandbox_agent_id($settings);
    }

    $conflictType = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_type'] ?? 'general')));
    if ($conflictType === '') {
        $conflictType = 'general';
    }
    $severity = ai_wpdyn_clamp_int(wp_unslash($_POST['ai_wpdyn_conflict_severity'] ?? 3), 1, 5, 3);

    $detailsRaw = trim((string)wp_unslash($_POST['ai_wpdyn_conflict_details'] ?? ''));
    $details = null;
    if ($detailsRaw !== '') {
        $decoded = json_decode($detailsRaw, true);
        $details = is_array($decoded) ? $decoded : sanitize_textarea_field($detailsRaw);
    }

    $response = ai_wpdyn_report_sandbox_conflict($settings, [
        'site_id' => $siteId,
        'request_id' => ($requestId !== '') ? $requestId : null,
        'agent_id' => $agentId,
        'conflict_type' => $conflictType,
        'severity' => $severity,
        'summary' => $summary,
        'details' => $details,
        'blocked_by_request_id' => ($blockedBy !== '') ? $blockedBy : null,
        'sandbox_id' => ($sandboxId !== '') ? $sandboxId : null,
    ]);
    list($status, $decoded, $errorText) = ai_wpdyn_decode_worker_json_response($response);

    if ($status >= 200 && $status < 300 && !empty($decoded['ok'])) {
        $conflictId = sanitize_text_field((string)($decoded['conflict']['id'] ?? ''));
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_conflict_report_ok', 'Conflict reported' . (($conflictId !== '') ? ': ' . $conflictId : '.'), 'updated');
        return;
    }

    if ($errorText === '') {
        $errorText = 'Unable to report sandbox conflict.';
    }
    add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_conflict_report_failed', 'Conflict report failed: ' . $errorText, 'error');
}

function ai_wpdyn_handle_conflict_resolve_submit() {
    if (!isset($_POST['ai_wpdyn_conflict_resolve_submit'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('ai_wpdyn_sandbox_conflict_resolve', 'ai_wpdyn_conflict_resolve_nonce');
    $settings = ai_wpdyn_get_settings();

    $conflictId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_id'] ?? '')));
    if ($conflictId === '') {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_conflict_resolve_missing', 'Conflict ID is required.', 'error');
        return;
    }

    $agentId = sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_resolve_agent_id'] ?? '')));
    if ($agentId === '') {
        $agentId = ai_wpdyn_default_sandbox_agent_id($settings);
    }

    $statusRaw = strtolower(sanitize_text_field(trim((string)wp_unslash($_POST['ai_wpdyn_conflict_resolve_status'] ?? 'resolved'))));
    $status = ($statusRaw === 'dismissed') ? 'dismissed' : 'resolved';
    $note = sanitize_textarea_field((string)wp_unslash($_POST['ai_wpdyn_conflict_resolution_note'] ?? ''));

    $response = ai_wpdyn_resolve_sandbox_conflict($settings, [
        'conflict_id' => $conflictId,
        'agent_id' => $agentId,
        'status' => $status,
        'resolution_note' => ($note !== '') ? $note : null,
    ]);
    list($httpStatus, $decoded, $errorText) = ai_wpdyn_decode_worker_json_response($response);

    if ($httpStatus >= 200 && $httpStatus < 300 && !empty($decoded['ok'])) {
        add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_conflict_resolve_ok', 'Conflict status updated.', 'updated');
        return;
    }

    if ($errorText === '') {
        $errorText = 'Unable to resolve conflict.';
    }
    add_settings_error('ai_wpdyn_messages', 'ai_wpdyn_conflict_resolve_failed', 'Conflict resolve failed: ' . $errorText, 'error');
}

function ai_wpdyn_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = ai_wpdyn_get_settings();
    $defaultAgentId = ai_wpdyn_default_sandbox_agent_id($settings);
    $conflictStatusFilter = ai_wpdyn_sandbox_conflict_status(wp_unslash($_GET['ai_wpdyn_conflict_status'] ?? 'open'), true);
    $conflictSiteFilter = ai_wpdyn_sanitize_site_id(wp_unslash($_GET['ai_wpdyn_conflict_site'] ?? $settings['site_id']));
    $conflictRequestFilter = sanitize_text_field(trim((string)wp_unslash($_GET['ai_wpdyn_conflict_request'] ?? '')));

    $sandboxConfigReady = (
        trim((string)($settings['worker_base_url'] ?? '')) !== '' &&
        trim((string)($settings['plugin_shared_secret'] ?? '')) !== '' &&
        trim((string)($settings['sandbox_capability_token'] ?? '')) !== ''
    );

    $conflictList = [];
    $conflictListError = '';

    if ($sandboxConfigReady) {
        $listResponse = ai_wpdyn_list_sandbox_conflicts($settings, [
            'status' => $conflictStatusFilter,
            'site_id' => ($conflictSiteFilter !== '') ? $conflictSiteFilter : null,
            'request_id' => ($conflictRequestFilter !== '') ? $conflictRequestFilter : null,
            'limit' => 50,
        ]);
        list($listStatus, $listDecoded, $listErrorText) = ai_wpdyn_decode_worker_json_response($listResponse);
        if ($listStatus >= 200 && $listStatus < 300 && !empty($listDecoded['ok']) && is_array($listDecoded['conflicts'] ?? null)) {
            $conflictList = $listDecoded['conflicts'];
        } else {
            $conflictListError = ($listErrorText !== '') ? $listErrorText : 'Unable to fetch sandbox conflict list.';
        }
    }

    settings_errors('ai_wpdyn_messages');

    $lastBenchmarkAt = (int)($settings['last_benchmark_at'] ?? 0);
    $lastBenchmarkText = ($lastBenchmarkAt > 0) ? gmdate('Y-m-d H:i:s', $lastBenchmarkAt) . ' UTC' : 'Never';
    ?>
    <div class="wrap">
      <h1>AI WP Dynamic Cache</h1>
      <p>Control dynamic cache strategy and integrate with signed sandbox scheduling endpoints.</p>

      <form method="post">
        <?php wp_nonce_field('ai_wpdyn_settings_save', 'ai_wpdyn_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="worker_base_url">Worker Base URL</label></th>
            <td><input name="worker_base_url" id="worker_base_url" type="url" class="regular-text" value="<?php echo esc_attr((string)$settings['worker_base_url']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="plugin_shared_secret">Plugin Shared Secret</label></th>
            <td><input name="plugin_shared_secret" id="plugin_shared_secret" type="text" class="regular-text code" value="<?php echo esc_attr((string)$settings['plugin_shared_secret']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="plugin_instance_id">Plugin Instance ID</label></th>
            <td><input name="plugin_instance_id" id="plugin_instance_id" type="text" class="regular-text" value="<?php echo esc_attr((string)$settings['plugin_instance_id']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="sandbox_capability_token">Sandbox Capability Token</label></th>
            <td><input name="sandbox_capability_token" id="sandbox_capability_token" type="text" class="regular-text code" value="<?php echo esc_attr((string)$settings['sandbox_capability_token']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="site_id">Site ID</label></th>
            <td><input name="site_id" id="site_id" type="text" class="regular-text" value="<?php echo esc_attr((string)$settings['site_id']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="default_agent_id">Default Agent ID</label></th>
            <td>
              <input name="default_agent_id" id="default_agent_id" type="text" class="regular-text" value="<?php echo esc_attr((string)$settings['default_agent_id']); ?>" />
              <p class="description">Fallback: current WordPress username.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Cache Strategy</th>
            <td>
              <select id="cache_strategy" name="cache_strategy">
                <?php foreach (ai_wpdyn_available_strategies() as $strategy): ?>
                <option value="<?php echo esc_attr($strategy); ?>" <?php selected((string)$settings['cache_strategy'], $strategy); ?>><?php echo esc_html($strategy); ?></option>
                <?php endforeach; ?>
              </select>
              <label for="default_ttl_seconds" style="margin-left:12px;">Default TTL (seconds)</label>
              <input name="default_ttl_seconds" id="default_ttl_seconds" type="number" min="30" max="86400" value="<?php echo esc_attr((string)$settings['default_ttl_seconds']); ?>" />
              <p>
                <label><input name="send_cache_headers" type="checkbox" value="1" <?php checked((int)$settings['send_cache_headers'], 1); ?> /> Send cache headers on frontend responses</label><br/>
                <label><input name="auto_benchmark" type="checkbox" value="1" <?php checked((int)$settings['auto_benchmark'], 1); ?> /> Hourly automatic benchmark via Worker</label>
              </p>
            </td>
          </tr>
        </table>
        <p class="submit"><button type="submit" name="ai_wpdyn_settings_submit" class="button button-primary">Save Changes</button></p>
      </form>

      <hr />
      <h2>Benchmark</h2>
      <p>Current strategy: <strong><?php echo esc_html((string)$settings['cache_strategy']); ?></strong></p>
      <p>Last benchmark: <?php echo esc_html($lastBenchmarkText); ?>, status: <code><?php echo esc_html((string)$settings['last_benchmark_status']); ?></code></p>
      <?php if (!empty($settings['last_benchmark_notes'])): ?>
      <p>Notes: <?php echo esc_html((string)$settings['last_benchmark_notes']); ?></p>
      <?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('ai_wpdyn_benchmark_run', 'ai_wpdyn_benchmark_nonce'); ?>
        <p><button type="submit" class="button" name="ai_wpdyn_benchmark_submit">Run Benchmark Now</button></p>
      </form>

      <hr />
      <h2>Sandbox Queue Operations</h2>
      <?php if (!$sandboxConfigReady): ?>
      <div class="notice notice-warning inline">
        <p><strong>Sandbox controls unavailable:</strong> set Worker Base URL, Plugin Shared Secret, and Sandbox Capability Token first.</p>
      </div>
      <?php endif; ?>

      <h3>Request Slot</h3>
      <form method="post">
        <?php wp_nonce_field('ai_wpdyn_sandbox_request', 'ai_wpdyn_sandbox_request_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_site_id">Site ID</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_sandbox_site_id" name="ai_wpdyn_sandbox_site_id" value="<?php echo esc_attr((string)$settings['site_id']); ?>" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_agent_id">Agent ID</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_sandbox_agent_id" name="ai_wpdyn_sandbox_agent_id" value="<?php echo esc_attr($defaultAgentId); ?>" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_task_type">Task Type</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_sandbox_task_type" name="ai_wpdyn_sandbox_task_type" value="cache-benchmark" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_priority_base">Priority</label></th>
            <td><input type="number" min="1" max="5" id="ai_wpdyn_sandbox_priority_base" name="ai_wpdyn_sandbox_priority_base" value="3" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_estimated_minutes">Estimated Minutes</label></th>
            <td><input type="number" min="5" max="240" id="ai_wpdyn_sandbox_estimated_minutes" name="ai_wpdyn_sandbox_estimated_minutes" value="20" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_earliest_start_at">Earliest Start (ISO-8601)</label></th>
            <td><input type="text" class="regular-text code" id="ai_wpdyn_sandbox_earliest_start_at" name="ai_wpdyn_sandbox_earliest_start_at" value="" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_context_json">Context JSON</label></th>
            <td><textarea id="ai_wpdyn_sandbox_context_json" name="ai_wpdyn_sandbox_context_json" class="large-text code" rows="3">{"reason":"cache_tuning"}</textarea></td>
          </tr>
        </table>
        <p class="submit"><button type="submit" class="button button-secondary" name="ai_wpdyn_sandbox_request_submit">Queue Sandbox Request</button></p>
      </form>

      <h3>Vote</h3>
      <form method="post">
        <?php wp_nonce_field('ai_wpdyn_sandbox_vote', 'ai_wpdyn_sandbox_vote_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_vote_request_id">Request ID</label></th>
            <td><input type="text" class="regular-text code" id="ai_wpdyn_sandbox_vote_request_id" name="ai_wpdyn_sandbox_vote_request_id" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_vote_agent_id">Agent ID</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_sandbox_vote_agent_id" name="ai_wpdyn_sandbox_vote_agent_id" value="<?php echo esc_attr($defaultAgentId); ?>" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_vote_value">Vote (-5..5)</label></th>
            <td><input type="number" min="-5" max="5" id="ai_wpdyn_sandbox_vote_value" name="ai_wpdyn_sandbox_vote_value" value="1" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_vote_reason">Reason</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_sandbox_vote_reason" name="ai_wpdyn_sandbox_vote_reason" value="" /></td>
          </tr>
        </table>
        <p class="submit"><button type="submit" class="button button-secondary" name="ai_wpdyn_sandbox_vote_submit">Submit Vote</button></p>
      </form>

      <h3>Claim</h3>
      <form method="post">
        <?php wp_nonce_field('ai_wpdyn_sandbox_claim', 'ai_wpdyn_sandbox_claim_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_claim_agent_id">Agent ID</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_sandbox_claim_agent_id" name="ai_wpdyn_sandbox_claim_agent_id" value="<?php echo esc_attr($defaultAgentId); ?>" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_claim_sandbox_id">Sandbox ID (optional)</label></th>
            <td><input type="text" class="regular-text code" id="ai_wpdyn_sandbox_claim_sandbox_id" name="ai_wpdyn_sandbox_claim_sandbox_id" value="" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_claim_slot_minutes">Slot Minutes</label></th>
            <td><input type="number" min="5" max="240" id="ai_wpdyn_sandbox_claim_slot_minutes" name="ai_wpdyn_sandbox_claim_slot_minutes" value="30" /></td>
          </tr>
        </table>
        <p class="submit"><button type="submit" class="button button-secondary" name="ai_wpdyn_sandbox_claim_submit">Claim Next Request</button></p>
      </form>

      <h3>Release</h3>
      <form method="post">
        <?php wp_nonce_field('ai_wpdyn_sandbox_release', 'ai_wpdyn_sandbox_release_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_release_request_id">Request ID</label></th>
            <td><input type="text" class="regular-text code" id="ai_wpdyn_sandbox_release_request_id" name="ai_wpdyn_sandbox_release_request_id" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_release_agent_id">Agent ID</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_sandbox_release_agent_id" name="ai_wpdyn_sandbox_release_agent_id" value="<?php echo esc_attr($defaultAgentId); ?>" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_release_outcome">Outcome</label></th>
            <td>
              <select id="ai_wpdyn_sandbox_release_outcome" name="ai_wpdyn_sandbox_release_outcome">
                <option value="completed">completed</option>
                <option value="failed">failed</option>
                <option value="requeue">requeue</option>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_sandbox_release_note">Note</label></th>
            <td><textarea id="ai_wpdyn_sandbox_release_note" name="ai_wpdyn_sandbox_release_note" class="large-text" rows="2"></textarea></td>
          </tr>
        </table>
        <p class="submit"><button type="submit" class="button button-secondary" name="ai_wpdyn_sandbox_release_submit">Release Request</button></p>
      </form>

      <hr />
      <h2>Sandbox Conflict Pool</h2>
      <h3>Report Conflict</h3>
      <form method="post">
        <?php wp_nonce_field('ai_wpdyn_sandbox_conflict_report', 'ai_wpdyn_conflict_report_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_site_id">Site ID</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_conflict_site_id" name="ai_wpdyn_conflict_site_id" value="<?php echo esc_attr((string)$settings['site_id']); ?>" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_request_id">Request ID</label></th>
            <td><input type="text" class="regular-text code" id="ai_wpdyn_conflict_request_id" name="ai_wpdyn_conflict_request_id" value="" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_agent_id">Agent ID</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_conflict_agent_id" name="ai_wpdyn_conflict_agent_id" value="<?php echo esc_attr($defaultAgentId); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_type">Type</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_conflict_type" name="ai_wpdyn_conflict_type" value="resource_lock" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_severity">Severity</label></th>
            <td><input type="number" min="1" max="5" id="ai_wpdyn_conflict_severity" name="ai_wpdyn_conflict_severity" value="3" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_summary">Summary</label></th>
            <td><input type="text" class="large-text" id="ai_wpdyn_conflict_summary" name="ai_wpdyn_conflict_summary" value="" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_details">Details (JSON or text)</label></th>
            <td><textarea id="ai_wpdyn_conflict_details" name="ai_wpdyn_conflict_details" class="large-text code" rows="3"></textarea></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_blocked_by_request_id">Blocked By Request ID</label></th>
            <td><input type="text" class="regular-text code" id="ai_wpdyn_conflict_blocked_by_request_id" name="ai_wpdyn_conflict_blocked_by_request_id" value="" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_sandbox_id">Sandbox ID</label></th>
            <td><input type="text" class="regular-text code" id="ai_wpdyn_conflict_sandbox_id" name="ai_wpdyn_conflict_sandbox_id" value="" /></td>
          </tr>
        </table>
        <p class="submit"><button type="submit" class="button button-secondary" name="ai_wpdyn_conflict_report_submit">Report Conflict</button></p>
      </form>

      <h3>Conflict Feed</h3>
      <form method="get" style="margin-bottom: 12px;">
        <input type="hidden" name="page" value="<?php echo esc_attr(AI_WPDYN_MENU_SLUG); ?>" />
        <label for="ai_wpdyn_conflict_status"><strong>Status</strong></label>
        <select id="ai_wpdyn_conflict_status" name="ai_wpdyn_conflict_status">
          <option value="open" <?php selected($conflictStatusFilter, 'open'); ?>>Open</option>
          <option value="resolved" <?php selected($conflictStatusFilter, 'resolved'); ?>>Resolved</option>
          <option value="dismissed" <?php selected($conflictStatusFilter, 'dismissed'); ?>>Dismissed</option>
          <option value="all" <?php selected($conflictStatusFilter, 'all'); ?>>All</option>
        </select>
        <label for="ai_wpdyn_conflict_site"><strong>Site ID</strong></label>
        <input type="text" class="regular-text" id="ai_wpdyn_conflict_site" name="ai_wpdyn_conflict_site" value="<?php echo esc_attr($conflictSiteFilter); ?>" />
        <label for="ai_wpdyn_conflict_request"><strong>Request ID</strong></label>
        <input type="text" class="regular-text" id="ai_wpdyn_conflict_request" name="ai_wpdyn_conflict_request" value="<?php echo esc_attr($conflictRequestFilter); ?>" />
        <button type="submit" class="button">Refresh Feed</button>
      </form>

      <?php if ($sandboxConfigReady && $conflictListError !== ''): ?>
      <div class="notice notice-error inline"><p><?php echo esc_html('Conflict feed error: ' . $conflictListError); ?></p></div>
      <?php endif; ?>

      <?php if ($sandboxConfigReady && $conflictListError === ''): ?>
        <?php if (empty($conflictList)): ?>
        <p><em>No sandbox conflicts found for current filter.</em></p>
        <?php else: ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th>Created</th>
              <th>Status</th>
              <th>Severity</th>
              <th>Site</th>
              <th>Request</th>
              <th>Agent</th>
              <th>Type</th>
              <th>Summary</th>
              <th>Conflict ID</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($conflictList as $conflict): ?>
            <tr>
              <td><?php echo esc_html((string)($conflict['created_at'] ?? '')); ?></td>
              <td><?php echo esc_html((string)($conflict['status'] ?? '')); ?></td>
              <td><?php echo esc_html((string)($conflict['severity'] ?? '')); ?></td>
              <td><?php echo esc_html((string)($conflict['site_id'] ?? '')); ?></td>
              <td><code><?php echo esc_html((string)($conflict['request_id'] ?? '')); ?></code></td>
              <td><?php echo esc_html((string)($conflict['agent_id'] ?? '')); ?></td>
              <td><?php echo esc_html((string)($conflict['conflict_type'] ?? '')); ?></td>
              <td><?php echo esc_html((string)($conflict['summary'] ?? '')); ?></td>
              <td><code><?php echo esc_html((string)($conflict['id'] ?? '')); ?></code></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      <?php endif; ?>

      <h3>Resolve Conflict</h3>
      <form method="post">
        <?php wp_nonce_field('ai_wpdyn_sandbox_conflict_resolve', 'ai_wpdyn_conflict_resolve_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_id">Conflict ID</label></th>
            <td><input type="text" class="regular-text code" id="ai_wpdyn_conflict_id" name="ai_wpdyn_conflict_id" required /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_resolve_agent_id">Agent ID</label></th>
            <td><input type="text" class="regular-text" id="ai_wpdyn_conflict_resolve_agent_id" name="ai_wpdyn_conflict_resolve_agent_id" value="<?php echo esc_attr($defaultAgentId); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_resolve_status">Status</label></th>
            <td>
              <select id="ai_wpdyn_conflict_resolve_status" name="ai_wpdyn_conflict_resolve_status">
                <option value="resolved">resolved</option>
                <option value="dismissed">dismissed</option>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ai_wpdyn_conflict_resolution_note">Resolution Note</label></th>
            <td><textarea id="ai_wpdyn_conflict_resolution_note" name="ai_wpdyn_conflict_resolution_note" class="large-text" rows="3"></textarea></td>
          </tr>
        </table>
        <p class="submit"><button type="submit" class="button button-secondary" name="ai_wpdyn_conflict_resolve_submit">Update Conflict</button></p>
      </form>
    </div>
    <?php
}

function ai_wpdyn_send_cache_headers() {
    if (is_admin()) {
        return;
    }

    $settings = ai_wpdyn_get_settings();
    if (empty($settings['send_cache_headers'])) {
        return;
    }

    if (is_user_logged_in()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
        header('Pragma: no-cache', true);
        header('X-AI-Dynamic-Cache-Bypass: logged-in', true);
        return;
    }

    $strategy = ai_wpdyn_normalize_strategy((string)$settings['cache_strategy'], 'edge-balanced');
    $ttl = ai_wpdyn_clamp_int($settings['default_ttl_seconds'], 30, 86400, 300);

    $cacheControl = 'public, max-age=' . $ttl;
    if ($strategy === 'edge-r2') {
        $cacheControl = 'public, max-age=' . $ttl . ', s-maxage=' . ($ttl * 3) . ', stale-while-revalidate=120';
    } elseif ($strategy === 'origin-disk') {
        $cacheControl = 'public, max-age=' . $ttl . ', s-maxage=' . ($ttl * 2) . ', stale-while-revalidate=60';
    } elseif ($strategy === 'object-cache') {
        $cacheControl = 'public, max-age=60, s-maxage=' . $ttl . ', stale-while-revalidate=30';
    } else {
        $cacheControl = 'public, max-age=' . $ttl . ', s-maxage=' . ($ttl * 2) . ', stale-while-revalidate=90';
    }

    header('Cache-Control: ' . $cacheControl, true);
    header('X-AI-Dynamic-Cache-Strategy: ' . $strategy, true);
    header('X-AI-Dynamic-Cache-TTL: ' . (string)$ttl, true);
}

function ai_wpdyn_run_scheduled_benchmark() {
    $settings = ai_wpdyn_get_settings();
    if (empty($settings['auto_benchmark'])) {
        return;
    }

    ai_wpdyn_run_benchmark_and_apply($settings);
}

function ai_wpdyn_run_benchmark_and_apply($settings) {
    $payload = [
        'site_id' => (string)($settings['site_id'] ?? ai_wpdyn_default_site_id()),
        'site_url' => home_url('/'),
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'object_cache_enabled' => wp_using_ext_object_cache(),
        'current_strategy' => (string)($settings['cache_strategy'] ?? 'edge-balanced'),
        'current_ttl_seconds' => (int)($settings['default_ttl_seconds'] ?? 300),
    ];

    $response = ai_wpdyn_post_cache_benchmark($settings, $payload);
    list($status, $decoded, $errorText) = ai_wpdyn_decode_worker_json_response($response);

    $next = ai_wpdyn_get_settings();
    $next['last_benchmark_at'] = time();

    if ($status >= 200 && $status < 300 && !empty($decoded['ok'])) {
        $recommended = ai_wpdyn_normalize_strategy((string)($decoded['recommended_strategy'] ?? $next['cache_strategy']), (string)$next['cache_strategy']);
        $recommendedTtl = ai_wpdyn_clamp_int($decoded['ttl_seconds'] ?? $next['default_ttl_seconds'], 30, 86400, (int)$next['default_ttl_seconds']);
        $notes = sanitize_text_field((string)($decoded['notes'] ?? 'benchmark_applied'));

        $next['cache_strategy'] = $recommended;
        $next['default_ttl_seconds'] = $recommendedTtl;
        $next['last_benchmark_status'] = 'ok';
        $next['last_benchmark_notes'] = $notes;
        update_option(AI_WPDYN_OPTION_KEY, $next, false);

        return [
            'ok' => true,
            'message' => 'Benchmark applied strategy=' . $recommended . ', ttl=' . $recommendedTtl . 's.',
        ];
    }

    $next['last_benchmark_status'] = 'error';
    $next['last_benchmark_notes'] = sanitize_text_field(($errorText !== '') ? $errorText : 'benchmark_failed');
    update_option(AI_WPDYN_OPTION_KEY, $next, false);

    return [
        'ok' => false,
        'message' => 'Benchmark failed: ' . (($errorText !== '') ? $errorText : 'worker_error'),
    ];
}

function ai_wpdyn_post_cache_benchmark($settings, $payload) {
    return ai_wpdyn_signed_post($settings, '/plugin/wp/cache/benchmark', $payload, 15, null, false);
}

function ai_wpdyn_request_sandbox($settings, $payload) {
    return ai_wpdyn_signed_mutation_post($settings, '/plugin/wp/sandbox/request', $payload, 12);
}

function ai_wpdyn_vote_sandbox($settings, $payload) {
    return ai_wpdyn_signed_mutation_post($settings, '/plugin/wp/sandbox/vote', $payload, 12);
}

function ai_wpdyn_claim_sandbox($settings, $payload) {
    return ai_wpdyn_signed_mutation_post($settings, '/plugin/wp/sandbox/claim', $payload, 12);
}

function ai_wpdyn_release_sandbox($settings, $payload) {
    return ai_wpdyn_signed_mutation_post($settings, '/plugin/wp/sandbox/release', $payload, 12);
}

function ai_wpdyn_report_sandbox_conflict($settings, $payload) {
    return ai_wpdyn_signed_mutation_post($settings, '/plugin/wp/sandbox/conflicts/report', $payload, 12);
}

function ai_wpdyn_list_sandbox_conflicts($settings, $payload) {
    return ai_wpdyn_signed_mutation_post($settings, '/plugin/wp/sandbox/conflicts/list', $payload, 12);
}

function ai_wpdyn_resolve_sandbox_conflict($settings, $payload) {
    return ai_wpdyn_signed_mutation_post($settings, '/plugin/wp/sandbox/conflicts/resolve', $payload, 12);
}

function ai_wpdyn_signed_mutation_post($settings, $path, $payload, $timeout = 10) {
    $capabilityToken = trim((string)($settings['sandbox_capability_token'] ?? ''));
    if ($capabilityToken === '') {
        return new WP_Error('ai_wpdyn_sandbox_capability_missing', 'Sandbox Capability Token is required for sandbox endpoints.');
    }

    return ai_wpdyn_signed_post($settings, $path, $payload, $timeout, $capabilityToken, true);
}

function ai_wpdyn_signed_post($settings, $path, $payload, $timeout = 10, $capabilityToken = null, $requireCapability = false) {
    $secret = trim((string)($settings['plugin_shared_secret'] ?? ''));
    $workerBase = trim((string)($settings['worker_base_url'] ?? ''));
    if ($secret === '' || $workerBase === '') {
        return new WP_Error('ai_wpdyn_worker_not_configured', 'Worker Base URL and Plugin Shared Secret are required.');
    }

    if ($requireCapability && (!is_string($capabilityToken) || trim($capabilityToken) === '')) {
        return new WP_Error('ai_wpdyn_missing_capability_token', 'Capability token is required for this endpoint.');
    }

    $body = wp_json_encode($payload);
    if (!is_string($body)) {
        return new WP_Error('ai_wpdyn_payload_invalid', 'Failed to encode JSON payload.');
    }

    $normalizedPath = '/' . ltrim((string)$path, '/');
    $timestamp = (string)time();
    $nonce = wp_generate_uuid4();
    $idempotencyKey = wp_generate_uuid4();
    $bodyHash = hash('sha256', $body);
    $canonical = $timestamp . '.' . $nonce . '.POST.' . $normalizedPath . '.' . $bodyHash;
    $signature = hash_hmac('sha256', $canonical, $secret);
    $endpoint = trailingslashit($workerBase) . ltrim($normalizedPath, '/');

    $headers = [
        'Content-Type' => 'application/json',
        'X-Plugin-Id' => ai_wpdyn_effective_plugin_instance_id($settings),
        'X-Plugin-Timestamp' => $timestamp,
        'X-Plugin-Nonce' => $nonce,
        'X-Plugin-Signature' => $signature,
        'Idempotency-Key' => $idempotencyKey,
    ];

    if (is_string($capabilityToken) && trim($capabilityToken) !== '') {
        $headers['X-Capability-Token'] = trim($capabilityToken);
    }

    return wp_remote_post($endpoint, [
        'method' => 'POST',
        'timeout' => max(3, (int)$timeout),
        'headers' => $headers,
        'body' => $body,
    ]);
}

function ai_wpdyn_decode_worker_json_response($response) {
    if (is_wp_error($response)) {
        return [0, [], $response->get_error_message()];
    }

    $status = (int)wp_remote_retrieve_response_code($response);
    $rawBody = wp_remote_retrieve_body($response);
    $decoded = json_decode((string)$rawBody, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $errorText = '';
    if ($status < 200 || $status >= 300) {
        $errorText = sanitize_text_field((string)($decoded['error'] ?? ('worker_http_' . $status)));
    }

    return [$status, $decoded, $errorText];
}

function ai_wpdyn_effective_plugin_instance_id($settings) {
    $explicit = ai_wpdyn_sanitize_instance_id((string)($settings['plugin_instance_id'] ?? ''));
    if ($explicit !== '') {
        return $explicit;
    }

    $siteId = ai_wpdyn_sanitize_site_id((string)($settings['site_id'] ?? ''));
    if ($siteId !== '') {
        return $siteId;
    }

    $host = parse_url(home_url('/'), PHP_URL_HOST);
    if (is_string($host)) {
        $fromHost = ai_wpdyn_sanitize_instance_id($host);
        if ($fromHost !== '') {
            return $fromHost;
        }
    }

    return 'wp-' . substr(md5((string)home_url('/')), 0, 12);
}

function ai_wpdyn_default_sandbox_agent_id($settings = []) {
    $configured = sanitize_text_field(trim((string)($settings['default_agent_id'] ?? '')));
    if ($configured !== '') {
        return $configured;
    }

    $user = wp_get_current_user();
    if ($user instanceof WP_User && $user->ID > 0) {
        $login = sanitize_text_field((string)$user->user_login);
        if ($login !== '') {
            return $login;
        }

        return 'wp-user-' . (int)$user->ID;
    }

    return 'wp-admin';
}

function ai_wpdyn_default_site_id() {
    $host = parse_url(home_url('/'), PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return ai_wpdyn_sanitize_site_id($host);
    }

    return 'site-' . substr(md5((string)home_url('/')), 0, 8);
}

function ai_wpdyn_available_strategies() {
    return ['edge-balanced', 'edge-r2', 'origin-disk', 'object-cache'];
}

function ai_wpdyn_normalize_strategy($raw, $fallback = 'edge-balanced') {
    $value = strtolower(trim((string)$raw));
    foreach (ai_wpdyn_available_strategies() as $strategy) {
        if ($value === $strategy) {
            return $strategy;
        }
    }

    return in_array($fallback, ai_wpdyn_available_strategies(), true) ? $fallback : 'edge-balanced';
}

function ai_wpdyn_sandbox_conflict_status($raw, $allowAll = false) {
    $value = strtolower(trim((string)$raw));
    if ($value === 'resolved' || $value === 'dismissed') {
        return $value;
    }
    if ($allowAll && $value === 'all') {
        return 'all';
    }

    return 'open';
}

function ai_wpdyn_sanitize_instance_id($value) {
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }

    $clean = preg_replace('/[^A-Za-z0-9._:-]+/', '-', $raw);
    $clean = trim((string)$clean, '-');
    if ($clean === '') {
        return '';
    }

    return substr($clean, 0, 80);
}

function ai_wpdyn_sanitize_site_id($value) {
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }

    $clean = preg_replace('/[^A-Za-z0-9._:-]+/', '-', $raw);
    $clean = trim((string)$clean, '-');
    if ($clean === '') {
        return '';
    }

    return substr($clean, 0, 120);
}

function ai_wpdyn_clamp_int($value, $min, $max, $default) {
    $n = is_numeric($value) ? (int)$value : (int)$default;
    if ($n < $min) {
        return $min;
    }
    if ($n > $max) {
        return $max;
    }

    return $n;
}
