<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI-commando's, de tegenhanger van php artisan content-studio:articles
 * in de Laravel-plugin.
 */
class Content_Studio_CLI
{
    /**
     * Synct de artikelen uit Content Studio.
     *
     * Zonder opties is dit dezelfde sync als de cron en de knop op de
     * instellingenpagina: alleen wat nieuw of gewijzigd is.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Haalt elk artikel opnieuw op, ook wat al op de site staat. Bestaande
     * posts worden bijgewerkt, niet dubbel aangemaakt. Handig na een
     * plugin-update die een nieuw veld opslaat, of om een artikel te
     * herstellen.
     *
     * ## EXAMPLES
     *
     *     wp content-studio sync
     *     wp content-studio sync --all
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function sync($args, $assoc_args)
    {
        $all = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'all', false);

        if ($all) {
            WP_CLI::log(Content_Studio_Engine_Client::is_connected()
                ? 'Connected: starting the catch-up from the beginning.'
                : 'Not connected: fetching articles of every status.');
        }

        $result = Content_Studio_Admin_Settings::run_sync($all);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success(sprintf('%d article(s) added or updated.', absint($result)));
    }
}
