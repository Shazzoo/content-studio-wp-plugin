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

        $post_id = Content_Studio_Storage::save_one($item);

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

        Content_Studio_Engine_Client::acknowledge(
            $article_id,
            $action,
            (string) $post_id,
            self::url_for($post_id)
        );

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

                $is_new = !Content_Studio_Storage::knows_external_id((string) $item['id']);
                $post_id = Content_Studio_Storage::save_one($item);

                if (!$post_id) {
                    continue;
                }

                $handled++;

                // Nieuw hier, of een bestaand artikel bijgewerkt - de engine
                // hoort het verschil, want alleen bij een eerste publicatie
                // gebeurt daar nog iets mee.
                Content_Studio_Engine_Client::acknowledge(
                    (int) $item['id'],
                    $is_new ? 'published' : 'updated',
                    (string) $post_id,
                    self::url_for($post_id)
                );
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
