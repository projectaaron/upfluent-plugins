<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * WP-CLI: `wp upfluent-sf <command>`.
 *
 * ## EXAMPLES
 *
 *     wp upfluent-sf status
 *     wp upfluent-sf test
 *     wp upfluent-sf send 1042
 *     wp upfluent-sf tracking
 *     wp upfluent-sf stock
 */
class Cli
{
    /**
     * Shows the connection, counts and last runs. Never prints the token.
     */
    public function status(): void
    {
        $state = RestController::stateData();
        \WP_CLI::line('Version: ' . $state['version']);
        \WP_CLI::line('Connection: ' . $state['connection']['status'] . ($state['connection']['message'] ? ' (' . $state['connection']['message'] . ')' : ''));
        foreach ($state['counts'] as $key => $value) {
            \WP_CLI::line(ucfirst(str_replace('_', ' ', $key)) . ': ' . $value);
        }
        foreach ((array) $state['runs'] as $job => $run) {
            \WP_CLI::line(ucfirst($job) . ': ' . ($run ? ($run['last_at'] . ' ' . $run['message']) : 'never'));
        }
    }

    /** Tests the saved token. */
    public function test(): void
    {
        $result = (new Api())->test((string) Settings::get('warehouses')[0]);
        $result['ok'] ? \WP_CLI::success($result['message']) : \WP_CLI::error($result['message']);
    }

    /**
     * Sends one order now, even when automatic sending is off.
     *
     * <order-id>
     * : The FluentCart order ID.
     */
    public function send($args): void
    {
        $result = Orders::retry((int) ($args[0] ?? 0));
        if (!Store::get((int) ($args[0] ?? 0))) {
            Orders::queue((int) $args[0]);
            $result = Orders::send((int) $args[0], true);
        }
        $result['ok'] ? \WP_CLI::success($result['message']) : \WP_CLI::error($result['message']);
    }

    /** Checks tracking for every open shipment. */
    public function tracking(): void
    {
        $result = Cron::checkTracking();
        $result['ok'] ? \WP_CLI::success($result['message']) : \WP_CLI::warning($result['message']);
    }

    /** Copies stock levels from ShipFusion. */
    public function stock(): void
    {
        $result = Cron::syncStock();
        $result['ok'] ? \WP_CLI::success($result['message']) : \WP_CLI::warning($result['message']);
    }
}
