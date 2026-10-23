<?php

/**
 * De V2-sync: de Engine belt deze site zodra een artikel klaarstaat, en de
 * site haalt het zelf op en meldt terug wat ermee gebeurde.
 *
 * Aanmelden gebeurt vanzelf met de API key en het project uit de
 * instellingen. De Engine geeft daarbij één keer een geheim terug, waarmee
 * elke binnenkomende webhook wordt gecontroleerd. Lukt het aanmelden niet,
 * bijvoorbeeld omdat de site niet van buiten bereikbaar is, dan blijft de
 * oude ophaalronde werken.
 *
 * Is de site aangemeld, dan is de cron alleen nog het vangnet: hij haalt op
 * wat een gemiste webhook liet liggen, vanaf de eigen cursor. Nooit beide
 * routes tegelijk, want dan kan een artikel twee keer gepubliceerd worden.
 */
class Content_Studio_Engine_Sync
{
    const SECRET_OPTION = 'content_studio_webhook_secret';

    const CURSOR_OPTION = 'content_studio_sync_cursor';

    const CONNECT_ERROR_OPTION = 'content_studio_connect_error';

    /** Wanneer de laatste geldige webhook binnenkwam. */
    const LAST_WEBHOOK_OPTION = 'content_studio_last_webhook_at';

    /**
     * Hoeveel nieuwe artikelen de inhaalronde op rij binnenhaalde zonder dat
     * er tussendoor een webhook aankwam.
     */
    const MISSED_WEBHOOKS_OPTION = 'content_studio_missed_webhooks';

    /**
     * Vanaf hoeveel gemiste webhooks op rij de statuskaart waarschuwt. Eén is
     * nog geen bewijs: de Engine kondigt alleen artikelen aan die nog op
     * approved staan, dus wint de inhaalronde de race, dan komt er voor dat
     * artikel nooit een webhook.
     */
    const MISSED_WEBHOOKS_WARNING = 3;

    /** Hoeveel klokverschil tussen Engine en site geaccepteerd wordt. */
    const SIGNATURE_TOLERANCE = 300;

    /**
     * Of een V2-sync loopt. Die meldt zelf terug, dus de publicatiebevestiging
     * hoeft dan niets te doen.
     *
     * @var bool
     */
    private static $syncing = false;

    /** @var bool */
    private static $connect_scheduled = false;

    public function __construct()
    {
        foreach (['content_studio_api_key', 'content_studio_project_id'] as $option) {
            add_action('add_option_' . $option, [self::class, 'schedule_connect']);
            add_action('update_option_' . $option, [self::class, 'schedule_connect']);
        }

        // Een ander project heeft een eigen volgorde van wijzigingen.
        add_action('update_option_content_studio_project_id', [self::class, 'reset_cursor']);
    }

    public static function is_configured()
    {
        return '' !== (string) get_option('content_studio_api_key', '')
            && '' !== (string) get_option('content_studio_project_id', '');
    }

    public static function is_connected()
    {
        return self::is_configured() && '' !== (string) get_option(self::SECRET_OPTION, '');
    }

    public static function is_syncing()
    {
        return self::$syncing;
    }

    public static function webhook_url()
    {
        return rest_url('content-studio/v1/webhook');
    }

    /**
     * Wie een andere sleutel of een ander project opslaat, wil daarmee
     * verbonden zijn. Pas aan het eind van het verzoek, zodat beide opties al
     * hun nieuwe waarde hebben.
     */
    public static function schedule_connect()
    {
        if (self::$connect_scheduled) {
            return;
        }

        self::$connect_scheduled = true;
        add_action('shutdown', [self::class, 'connect']);
    }

    public static function reset_cursor()
    {
        delete_option(self::CURSOR_OPTION);
    }

    /**
     * Meldt de site aan bij de Engine. Mislukt het, dan blijft het oude
     * geheim staan: de Engine kan daar dan nog mee bellen. De reden komt op
     * de instellingenpagina.
     *
     * Alleen in productie, net als de publicatiebevestiging. De Engine koppelt
     * een project aan één website; een lokale of staging-kopie met dezelfde
     * sleutels zou die koppeling anders inpikken, en dan kan de echte site
     * zich niet meer aanmelden.
     *
     * @return bool
     */
    public static function connect()
    {
        if (!self::is_configured() || !Content_Studio_Publish_Confirmation::is_production()) {
            return false;
        }

        $secret = Content_Studio_API_Client::register_connector(self::webhook_url());

        if (is_wp_error($secret)) {
            update_option(self::CONNECT_ERROR_OPTION, $secret->get_error_message());

            return false;
        }

        update_option(self::SECRET_OPTION, $secret);
        update_option(self::CONNECT_ERROR_OPTION, '');
        // Een nieuwe koppeling begint met een schone lei.
        update_option(self::MISSED_WEBHOOKS_OPTION, 0, false);

        return true;
    }

    /**
     * Of een webhook echt van de Engine komt. De handtekening gaat over de
     * tijdstempel en de body samen, dus een opgevangen aanroep is later niet
     * opnieuw te gebruiken.
     *
     * @param string      $secret
     * @param string|null $timestamp
     * @param string      $body
     * @param string|null $signature
     * @param int|null    $now
     *
     * @return bool
     */
    public static function signature_matches($secret, $timestamp, $body, $signature, $now = null)
    {
        if ('' === (string) $secret || !$timestamp || !$signature || !ctype_digit((string) $timestamp)) {
            return false;
        }

        if (abs((null !== $now ? $now : time()) - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, (string) $secret), (string) $signature);
    }

    /**
     * Waar de Engine aanklopt. De aanroep draagt alleen een id; de inhoud
     * haalt de site zelf op met zijn eigen sleutel.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public static function handle_webhook($request)
    {
        if (!self::is_connected()) {
            return new WP_REST_Response(['ok' => false, 'message' => 'This site is not connected.'], 409);
        }

        $valid = self::signature_matches(
            get_option(self::SECRET_OPTION, ''),
            $request->get_header('X-Content-Studio-Timestamp'),
            $request->get_body(),
            $request->get_header('X-Content-Studio-Signature')
        );

        if (!$valid) {
            return new WP_REST_Response(['ok' => false], 401);
        }

        // De webhook komt aan: niets blokkeert hem.
        update_option(self::LAST_WEBHOOK_OPTION, time(), false);
        update_option(self::MISSED_WEBHOOKS_OPTION, 0, false);

        $payload = json_decode($request->get_body(), true);
        $payload = is_array($payload) ? $payload : [];
        $event = !empty($payload['event']) ? (string) $payload['event'] : (string) $request->get_header('X-Content-Studio-Event');
        $article_id = isset($payload['article_id']) ? absint($payload['article_id']) : 0;

        switch ($event) {
            case 'article.publish':
            case 'article.update':
                self::sync_one($article_id);
                break;

            // Een duwtje zonder inhoud: de site kijkt zelf wat hij sinds zijn
            // cursor heeft gemist.
            case 'sync.requested':
                self::catch_up();
                break;
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Eén aangekondigd artikel.
     *
     * @param int $article_id
     *
     * @return bool
     */
    public static function sync_one($article_id)
    {
        $article = Content_Studio_API_Client::fetch_article($article_id);

        // Geen fout: de Engine kan het artikel met opzet nog niet geven, als
        // het publicatiemoment net nog niet bereikt is.
        if (is_wp_error($article) || [] === $article) {
            return false;
        }

        return '' !== self::save_and_acknowledge($article);
    }

    /**
     * Alles wat deze site nog niet had, vanaf zijn eigen cursor. De cursor
     * wordt na elke pagina bewaard, zodat een afgebroken ronde verdergaat
     * waar hij ophield.
     *
     * @param int $max_pages
     *
     * @return int|WP_Error Aantal opgeslagen artikelen.
     */
    public static function catch_up($max_pages = 20)
    {
        Content_Studio_Storage::maybe_store_primary_locale();

        $cursor = (string) get_option(self::CURSOR_OPTION, '');
        $saved = 0;

        for ($page = 0; $page < $max_pages; $page++) {
            $changes = Content_Studio_API_Client::fetch_changes($cursor);

            // De Engine kent deze site niet meer: de koppeling is daar
            // verbroken. Opnieuw aanmelden en één keer opnieuw vragen, anders
            // blijft de site wachten op een webhook die nooit meer komt.
            if (0 === $page && self::is_forbidden($changes) && self::connect()) {
                $changes = Content_Studio_API_Client::fetch_changes($cursor);
            }

            if (is_wp_error($changes)) {
                return 0 === $page ? $changes : $saved;
            }

            if ([] === $changes['data']) {
                break;
            }

            foreach ($changes['data'] as $article) {
                $action = is_array($article) ? self::save_and_acknowledge($article) : '';

                if (in_array($action, ['published', 'updated', 'stored'], true)) {
                    $saved++;
                }

                // Een artikel dat hier pas via de inhaalronde live gaat, had
                // een webhook moeten krijgen.
                if ('published' === $action) {
                    update_option(self::MISSED_WEBHOOKS_OPTION, absint(get_option(self::MISSED_WEBHOOKS_OPTION, 0)) + 1, false);
                }
            }

            if (null !== $changes['next_cursor']) {
                $cursor = $changes['next_cursor'];
                update_option(self::CURSOR_OPTION, $cursor, false);
            }

            if (!$changes['has_more']) {
                break;
            }
        }

        return $saved;
    }

    /**
     * Slaat een artikel op en vertelt de Engine wat ermee gebeurde. Een
     * artikel dat hier als concept eindigt, bijvoorbeeld in een taal die de
     * site niet publiceert, is overgeslagen en niet mislukt.
     *
     * @param array $article
     *
     * @return string De teruggemelde actie (published, updated of skipped),
     *                'stored' zonder Engine-ID, of '' als het mislukte.
     */
    private static function save_and_acknowledge($article)
    {
        $article_id = isset($article['id']) ? absint($article['id']) : 0;

        // Overgeslagen, niet mislukt: de Engine schrijft in de talen van het
        // project en deze site toont er soms minder. Net als in Laravel.
        if (!Content_Studio_Language::is_published_locale($article['locale'] ?? '')) {
            if ($article_id) {
                Content_Studio_API_Client::acknowledge($article_id, 'skipped');
            }

            return 'skipped';
        }

        self::$syncing = true;
        $post_id = Content_Studio_Storage::save_one($article);
        self::$syncing = false;

        if (!$article_id) {
            return $post_id ? 'stored' : '';
        }

        if (!$post_id) {
            Content_Studio_API_Client::acknowledge($article_id, 'failed', ['error' => 'The site could not process the article.']);

            return '';
        }

        if ('publish' !== get_post_status($post_id)) {
            Content_Studio_API_Client::acknowledge($article_id, 'skipped', ['external_ref' => (string) $post_id]);

            return 'skipped';
        }

        // Alleen de eerste keer live is 'published': daarop plaatst de Engine
        // het artikel op sociale kanalen. Een artikel dat hier al als concept
        // stond, gaat nu pas live.
        $url = Content_Studio_Publish_Confirmation::get_published_url($post_id);
        $action = Content_Studio_Publish_Confirmation::is_confirmed($post_id) ? 'updated' : 'published';
        $acknowledged = Content_Studio_API_Client::acknowledge(
            $article_id,
            $action,
            [
                'external_ref' => (string) $post_id,
                'url' => $url,
            ]
        );

        if (true === $acknowledged) {
            Content_Studio_Publish_Confirmation::record_confirmed($post_id, $url);
        }

        return $action;
    }

    private static function is_forbidden($response)
    {
        if (!is_wp_error($response)) {
            return false;
        }

        $data = $response->get_error_data();

        return is_array($data) && isset($data['status']) && 403 === (int) $data['status'];
    }
}
