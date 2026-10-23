<?php

/**
 * De V2-weg: de engine zegt dat er iets klaarstaat, deze site haalt het op en
 * zet het neer.
 *
 * Eén artikel als er gebeld wordt, en een hele inhaalronde als de site zelf
 * wil weten wat hij gemist heeft. Allebei door dezelfde toewijzing als de
 * oude ophaalronde (Content_Studio_Storage::save_one), zodat er maar één plek
 * is waar een veld van de engine een veld hier wordt.
 *
 * Dubbele artikelen kunnen niet ontstaan: er wordt gezocht op het engine-id
 * in de postmeta en bijgewerkt wat er al staat. Een herinstallatie met
 * dezelfde database herkent dus alles terug.
 */
class Content_Studio_Engine_Sync
{
    /** Wanneer de laatste geldige webhook binnenkwam. */
    const LAST_WEBHOOK_OPTION = 'content_studio_last_webhook_at';

    /**
     * Hoeveel nieuwe artikelen de inhaalronde op rij binnenhaalde zonder dat
     * er tussendoor een webhook aankwam.
     */
    const MISSED_WEBHOOKS_OPTION = 'content_studio_missed_webhooks';

    /**
     * Vanaf hoeveel gemiste webhooks op rij de statuskaart waarschuwt. Eén is
     * nog geen bewijs: de engine kondigt alleen artikelen aan die nog op
     * approved staan, dus wint de inhaalronde de race, dan komt er voor dat
     * artikel nooit een webhook.
     */
    const MISSED_WEBHOOKS_WARNING = 3;

    /**
     * Of deze klasse nu opslaat. Hij meldt zelf terug, dus de
     * publicatiebevestiging hoeft dan niets te doen.
     *
     * @var bool
     */
    private static $syncing = false;

    public static function is_syncing()
    {
        return self::$syncing;
    }

    /**
     * Een geldige webhook kwam binnen: niets houdt hem tegen.
     */
    public static function record_webhook()
    {
        update_option(self::LAST_WEBHOOK_OPTION, time(), false);
        update_option(self::MISSED_WEBHOOKS_OPTION, 0, false);
    }

    /** Het adres waarop de engine deze site mag bellen. */
    public static function webhook_url()
    {
        return rest_url('content-studio/v1/webhook');
    }

    /**
     * Eén aangekondigd artikel. Wat er ook gebeurt, de engine hoort het - ook
     * een mislukking, want anders blijft daar staan dat het is afgeleverd.
     *
     * @param int    $article_id
     * @param string $action
     *
     * @return bool
     */
    public static function one($article_id, $action = 'published')
    {
        $article_id = (int) $article_id;
        $item = Content_Studio_Engine_Client::article($article_id);

        if (!is_array($item)) {
            // Geen fout: de engine mag dit artikel met opzet nog niet geven,
            // bijvoorbeeld omdat het publicatiemoment net nog niet bereikt is.
            return false;
        }

        if (!self::publishes_language($item)) {
            Content_Studio_Engine_Client::acknowledge($article_id, 'skipped');

            return false;
        }

        $post_id = self::save($item);

        if (!$post_id) {
            Content_Studio_Engine_Client::acknowledge(
                $article_id,
                'failed',
                null,
                null,
                'De site kon het artikel niet verwerken.'
            );

            return false;
        }

        if ('publish' !== get_post_status($post_id)) {
            Content_Studio_Engine_Client::acknowledge($article_id, 'skipped', (string) $post_id);

            return false;
        }

        self::acknowledge_live($article_id, $action, $post_id);

        return true;
    }

    /**
     * Alles wat deze site nog niet had, vanaf zijn eigen cursor. Dit is de
     * vangnetronde: gemiste aankondigingen en gemiste wijzigingen komen hier
     * in dezelfde lijst voorbij.
     *
     * @param int $max_pages
     *
     * @return int Aantal verwerkte artikelen.
     */
    public static function catch_up($max_pages = 20)
    {
        // Net als de oude ophaalronde: de talen van het project bijhouden.
        Content_Studio_Storage::maybe_store_primary_locale();

        $cursor = Content_Studio_Engine_Client::cursor();
        $handled = 0;

        for ($page = 0; $page < $max_pages; $page++) {
            $changes = Content_Studio_Engine_Client::changes($cursor);

            if (null === $changes && 0 === $page && 403 === Content_Studio_Engine_Client::last_status()) {
                // De engine kent deze site niet meer: de koppeling is daar
                // verbroken. Opnieuw aanmelden en één keer opnieuw vragen,
                // anders zou deze site met een geheim in de hand blijven
                // wachten op gebeld worden dat nooit meer komt.
                if (Content_Studio_Engine_Client::register(self::webhook_url())) {
                    $changes = Content_Studio_Engine_Client::changes($cursor);
                }
            }

            if (!is_array($changes) || [] === $changes['data']) {
                break;
            }

            foreach ($changes['data'] as $item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }

                if (!self::publishes_language($item)) {
                    Content_Studio_Engine_Client::acknowledge((int) $item['id'], 'skipped');

                    continue;
                }

                $is_new = !Content_Studio_Storage::knows_external_id((string) $item['id']);
                $post_id = self::save($item);

                if (!$post_id) {
                    continue;
                }

                if ('publish' !== get_post_status($post_id)) {
                    Content_Studio_Engine_Client::acknowledge((int) $item['id'], 'skipped', (string) $post_id);

                    continue;
                }

                $handled++;

                // Nieuw hier, of een bestaand artikel bijgewerkt - de engine
                // hoort het verschil, want alleen bij een eerste publicatie
                // gebeurt daar nog iets mee.
                self::acknowledge_live((int) $item['id'], $is_new ? 'published' : 'updated', $post_id);

                // Een artikel dat hier pas via de inhaalronde live gaat, had
                // een webhook moeten krijgen.
                if ($is_new) {
                    update_option(self::MISSED_WEBHOOKS_OPTION, absint(get_option(self::MISSED_WEBHOOKS_OPTION, 0)) + 1, false);
                }
            }

            $cursor = $changes['next_cursor'];

            // Na elke pagina bewaard, niet pas aan het eind: breekt de rit af,
            // dan begint de volgende waar deze ophield.
            Content_Studio_Engine_Client::remember_cursor($cursor);

            if (!$changes['has_more']) {
                break;
            }
        }

        return $handled;
    }

    /**
     * Een taal die de site niet publiceert is overgeslagen, niet mislukt: de
     * engine schrijft in de talen van het project en deze site toont er soms
     * minder. Net als in de Laravel-plugin.
     *
     * @param array $item
     *
     * @return bool
     */
    private static function publishes_language($item)
    {
        return Content_Studio_Language::is_published_locale(isset($item['locale']) ? (string) $item['locale'] : '');
    }

    /**
     * @param array $item
     *
     * @return int
     */
    private static function save($item)
    {
        self::$syncing = true;

        try {
            return Content_Studio_Storage::save_one($item);
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * Meldt een live artikel terug en legt dat op de post vast, zodat een
     * latere opslag in WordPress het niet nog eens als nieuw meldt.
     *
     * @param int    $article_id
     * @param string $action
     * @param int    $post_id
     */
    private static function acknowledge_live($article_id, $action, $post_id)
    {
        $url = self::url_for($post_id);

        if (Content_Studio_Engine_Client::acknowledge($article_id, $action, (string) $post_id, $url)) {
            Content_Studio_Publish_Confirmation::record_confirmed($post_id, (string) $url);
        }
    }

    /**
     * @param int $post_id
     *
     * @return string|null
     */
    private static function url_for($post_id)
    {
        $permalink = get_permalink($post_id);

        return is_string($permalink) && '' !== $permalink ? $permalink : null;
    }
}
