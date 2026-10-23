<?php

/**
 * Alles wat deze site de engine vraagt over de V2-weg, op één plek.
 *
 * De API-sleutel en de project-ID staan al in de instellingen; hier worden ze
 * gebruikt. Mislukt een aanroep, dan is dat geen fatale fout: een site die
 * even niet kan praten moet blijven werken, en de ophaalronde haalt later op
 * wat bleef liggen. Van de laatste mislukking blijven de status en het
 * antwoord bewaard, want dat is wat iemand op de instellingenpagina wil
 * lezen - niet "het lukte niet", maar waarom niet.
 */
class Content_Studio_Engine_Client
{
    const SECRET_OPTION = 'content_studio_webhook_secret';

    const CURSOR_OPTION = 'content_studio_sync_cursor';

    /** Waarom het laatste aanmelden mislukte, voor de statuskaart. */
    const CONNECT_ERROR_OPTION = 'content_studio_connect_error';

    /** @var int|null */
    private static $last_status = null;

    /** @var string */
    private static $last_message = '';

    public static function is_configured()
    {
        return '' !== (string) get_option('content_studio_api_key', '')
            && '' !== (string) get_option('content_studio_project_id', '');
    }

    /** Of deze site al bij de engine staat ingeschreven voor V2. */
    public static function is_connected()
    {
        return self::is_configured() && '' !== self::secret();
    }

    public static function secret()
    {
        return (string) get_option(self::SECRET_OPTION, '');
    }

    public static function cursor()
    {
        $cursor = (string) get_option(self::CURSOR_OPTION, '');

        return '' === $cursor ? null : $cursor;
    }

    public static function remember_cursor($cursor)
    {
        if (is_string($cursor) && '' !== $cursor) {
            update_option(self::CURSOR_OPTION, $cursor);
        }
    }

    /**
     * Laat de inhaalronde weer vooraan beginnen: na een ander project, of
     * nadat een taal live ging waarvan de artikelen eerder zijn overgeslagen.
     */
    public static function reset_cursor()
    {
        delete_option(self::CURSOR_OPTION);
    }

    public static function last_status()
    {
        return self::$last_status;
    }

    public static function last_message()
    {
        return self::$last_message;
    }

    /**
     * Meldt deze site aan en bewaart het geheim dat de engine teruggeeft. Dat
     * geheim komt één keer; daarna is opnieuw aanmelden de enige weg.
     *
     * Het oude geheim blijft staan als dit mislukt. De engine kan dan nog met
     * het vorige geheim bellen, en die aanroepen moeten hier herkend blijven
     * worden.
     *
     * Alleen in productie, net als de publicatiebevestiging. De engine koppelt
     * een project aan één website; een lokale of staging-kopie met dezelfde
     * sleutels zou die koppeling anders inpikken, en dan kan de echte site
     * zich niet meer aanmelden.
     *
     * @param string $webhook_url
     *
     * @return bool
     */
    public static function register($webhook_url)
    {
        if (!self::is_configured() || !Content_Studio_Publish_Confirmation::is_production()) {
            return false;
        }

        $response = self::request(
            'POST',
            self::v1_url('projects/' . rawurlencode(self::project_id()) . '/connector'),
            [
                'cms' => 'wordpress',
                'webhook_url' => $webhook_url,
            ]
        );

        if (!is_array($response) || empty($response['secret'])) {
            update_option(self::CONNECT_ERROR_OPTION, '' !== self::$last_message ? self::$last_message : 'De engine gaf geen geheim terug.');

            return false;
        }

        update_option(self::SECRET_OPTION, (string) $response['secret']);
        update_option(self::CONNECT_ERROR_OPTION, '');
        // Een nieuwe koppeling begint met een schone lei.
        update_option(Content_Studio_Engine_Sync::MISSED_WEBHOOKS_OPTION, 0, false);

        return true;
    }

    /**
     * Eén artikel, zoals de engine het nu geeft.
     *
     * @param int|string $article_id
     *
     * @return array|null
     */
    public static function article($article_id)
    {
        $response = self::request(
            'GET',
            self::v2_url('projects/' . rawurlencode(self::project_id()) . '/articles/' . rawurlencode((string) $article_id))
        );

        return is_array($response) && isset($response['data']) && is_array($response['data'])
            ? $response['data']
            : null;
    }

    /**
     * Wat deze site sinds zijn cursor heeft gemist.
     *
     * @param string|null $cursor
     * @param int         $limit
     *
     * @return array|null {data: array, next_cursor: string|null, has_more: bool}
     */
    public static function changes($cursor, $limit = 50)
    {
        $query = ['limit' => $limit];

        if (is_string($cursor) && '' !== $cursor) {
            $query['cursor'] = $cursor;
        }

        $response = self::request(
            'GET',
            add_query_arg($query, self::v2_url('projects/' . rawurlencode(self::project_id()) . '/sync/changes'))
        );

        if (!is_array($response)) {
            return null;
        }

        return [
            'data' => isset($response['data']) && is_array($response['data']) ? $response['data'] : [],
            'next_cursor' => isset($response['next_cursor']) && is_string($response['next_cursor'])
                ? $response['next_cursor']
                : null,
            'has_more' => !empty($response['has_more']),
        ];
    }

    /**
     * Wat deze site met het artikel deed. Ook een mislukking wordt gemeld,
     * want anders blijft bij de engine staan dat het is afgeleverd.
     *
     * @param int|string  $article_id
     * @param string      $action       published, updated, skipped of failed
     * @param string|null $external_ref
     * @param string|null $url
     * @param string|null $error
     *
     * @return bool
     */
    public static function acknowledge($article_id, $action, $external_ref = null, $url = null, $error = null)
    {
        $body = [
            'article_id' => (int) $article_id,
            'action' => $action,
        ];

        foreach (['external_ref' => $external_ref, 'url' => $url, 'error' => $error] as $key => $value) {
            if (null !== $value && '' !== $value) {
                $body[$key] = $value;
            }
        }

        $response = self::request(
            'POST',
            self::v2_url('projects/' . rawurlencode(self::project_id()) . '/sync/acknowledgements'),
            $body
        );

        return is_array($response);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array  $body
     *
     * @return array|null
     */
    private static function request($method, $url, $body = [])
    {
        self::$last_status = null;
        self::$last_message = '';

        $args = [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . (string) get_option('content_studio_api_key', ''),
            ],
        ];

        if ('POST' === $method) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);

            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            self::$last_message = $response->get_error_message();

            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            self::$last_status = $status;
            self::$last_message = is_array($decoded) && !empty($decoded['message'])
                ? (string) $decoded['message']
                : sprintf('De engine antwoordde met HTTP %d.', $status);

            return null;
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function project_id()
    {
        return (string) get_option('content_studio_project_id', '');
    }

    private static function v1_url($path)
    {
        return trailingslashit(CONTENT_STUDIO_API_ROUTE) . $path;
    }

    private static function v2_url($path)
    {
        return trailingslashit(CONTENT_STUDIO_API_V2_ROUTE) . $path;
    }
}
