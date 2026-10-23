<?php

class Content_Studio_API_Client
{
    const PROJECT_TRANSIENT = 'content_studio_project_info';

    /**
     * Every approved article, following the Engine's pagination links.
     *
     * @return array|WP_Error
     */
    public static function fetch_content()
    {
        $api_key = get_option('content_studio_api_key', '');
        $project_id = get_option('content_studio_project_id', '');

        if (empty($api_key)) {
            return new WP_Error('content_studio_missing_api_key', 'Content Studio API key is not configured.');
        }

        if (empty($project_id)) {
            return new WP_Error('content_studio_missing_project_id', 'Content Studio project ID is not configured.');
        }

        // Only content the engine has not seen confirmed as published, i.e. not
        // yet on this site. Once this plugin confirms a publish the engine flips
        // the status to 'published' and it drops out of this list. The filter
        // travels along in links.next, so later pages keep it.
        $url = add_query_arg(
            ['status' => 'approved'],
            trailingslashit(CONTENT_STUDIO_API_ROUTE) . 'projects/' . rawurlencode($project_id) . '/contents'
        );

        $articles = [];
        // A page count the Engine cannot realistically exceed, so a malformed
        // links.next pointing at itself cannot spin forever.
        $remaining_pages = 100;

        while ($url && $remaining_pages-- > 0) {
            $page = self::fetch_content_page($url, $api_key);

            if (is_wp_error($page)) {
                return $page;
            }

            foreach (self::page_articles($page) as $article) {
                $articles[] = $article;
            }

            $next = isset($page['links']['next']) ? $page['links']['next'] : null;
            $url = is_string($next) && '' !== $next ? self::normalize_engine_url($next) : null;
        }

        return ['data' => $articles];
    }

    /**
     * @param string $url
     * @param string $api_key
     *
     * @return array|WP_Error
     */
    private static function fetch_content_page($url, $api_key)
    {
        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error(
                'content_studio_api_error',
                sprintf('Content Studio API returned HTTP %d.', $status_code)
            );
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            return new WP_Error('content_studio_invalid_json', 'Content Studio API returned invalid JSON.');
        }

        return is_array($data) ? $data : [];
    }

    /**
     * The articles on one response page, whichever shape the Engine sends.
     *
     * @param array $page
     *
     * @return array
     */
    private static function page_articles($page)
    {
        if (isset($page['data']) && is_array($page['data'])) {
            return $page['data'];
        }

        if (isset($page['articles']) && is_array($page['articles'])) {
            return $page['articles'];
        }

        return array_values($page) === $page ? $page : [];
    }

    /**
     * A links.next may arrive relative to the API root.
     *
     * @param string $url
     *
     * @return string
     */
    private static function normalize_engine_url($url)
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return trailingslashit(CONTENT_STUDIO_API_ROUTE) . ltrim($url, '/');
    }

    /**
     * Project metadata: primary language, the languages in use, and how much
     * content is waiting to sync.
     *
     * Cached, because the settings screen and the front end both ask for it on
     * ordinary page loads.
     *
     * @return array|WP_Error
     */
    public static function fetch_project($force_refresh = false)
    {
        $cached = get_transient(self::PROJECT_TRANSIENT);

        if (!$force_refresh && is_array($cached)) {
            return $cached;
        }

        $api_key = get_option('content_studio_api_key', '');
        $project_id = get_option('content_studio_project_id', '');

        if (empty($api_key)) {
            return new WP_Error('content_studio_missing_api_key', 'Content Studio API key is not configured.');
        }

        if (empty($project_id)) {
            return new WP_Error('content_studio_missing_project_id', 'Content Studio project ID is not configured.');
        }

        $url = trailingslashit(CONTENT_STUDIO_API_ROUTE) . 'projects/' . rawurlencode($project_id);

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error(
                'content_studio_api_error',
                sprintf('Content Studio API returned HTTP %d for the project.', $status_code)
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (JSON_ERROR_NONE !== json_last_error() || !isset($body['data']) || !is_array($body['data'])) {
            return new WP_Error('content_studio_invalid_json', 'Content Studio API returned invalid project JSON.');
        }

        set_transient(self::PROJECT_TRANSIENT, $body['data'], HOUR_IN_SECONDS);

        return $body['data'];
    }

    /**
     * Tells Content Studio Engine that an article is actually live on this
     * site. This is the explicit confirmation - without it the Engine only
     * learns a page is published passively, once real traffic comes in.
     *
     * @param int|string $content_id    The Content Studio Engine content ID.
     * @param string     $published_url The public URL of the article.
     *
     * @return true|WP_Error
     */
    public static function confirm_published($content_id, $published_url = '')
    {
        $api_key = get_option('content_studio_api_key', '');
        $project_id = get_option('content_studio_project_id', '');

        if (empty($api_key)) {
            return new WP_Error('content_studio_missing_api_key', 'Content Studio API key is not configured.');
        }

        if (empty($project_id)) {
            return new WP_Error('content_studio_missing_project_id', 'Content Studio project ID is not configured.');
        }

        if (empty($content_id)) {
            return new WP_Error('content_studio_missing_content_id', 'Content Studio content ID is missing.');
        }

        $url = trailingslashit(CONTENT_STUDIO_API_ROUTE)
            . 'projects/' . rawurlencode($project_id)
            . '/contents/' . rawurlencode((string) $content_id)
            . '/confirm-published';

        $body = [];

        if (!empty($published_url)) {
            $body['published_url'] = $published_url;
        }

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // The Engine only confirms content that is still 'approved'. An
        // article it already has as published - e.g. confirmed again because
        // its URL changed - comes back as a 422 saying so. That is not a
        // failure; the Engine just does not take URL updates yet.
        if (422 === $status_code) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $message = is_array($body) && isset($body['message']) ? (string) $body['message'] : '';

            if (preg_match("/status is 'published'/", $message)) {
                return new WP_Error('content_studio_already_published', $message);
            }
        }

        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error(
                'content_studio_api_error',
                sprintf('Content Studio API returned HTTP %d when confirming publication.', $status_code)
            );
        }

        return true;
    }

    /**
     * Meldt deze site aan bij de Engine, met het adres waarop de Engine hem
     * mag bellen. Het geheim dat terugkomt staat er maar één keer in; daarna
     * is opnieuw aanmelden de enige weg.
     *
     * @param string $webhook_url
     *
     * @return string|WP_Error Het geheim voor de webhook-handtekening.
     */
    public static function register_connector($webhook_url)
    {
        $response = self::engine_request(
            'POST',
            trailingslashit(CONTENT_STUDIO_API_ROUTE) . 'projects/' . rawurlencode(get_option('content_studio_project_id', '')) . '/connector',
            [
                'cms' => 'wordpress',
                'webhook_url' => $webhook_url,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['secret']) || !is_string($response['secret'])) {
            return new WP_Error('content_studio_no_secret', 'Content Studio did not return a webhook secret.');
        }

        return $response['secret'];
    }

    /**
     * Eén artikel, zoals de Engine het na een webhook geeft.
     *
     * @param int $article_id
     *
     * @return array|WP_Error
     */
    public static function fetch_article($article_id)
    {
        $response = self::engine_request('GET', self::v2_project_url('articles/' . absint($article_id)));

        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * De wijzigingen sinds de cursor van deze site.
     *
     * @param string $cursor Leeg voor vanaf het begin.
     * @param int    $limit
     *
     * @return array|WP_Error ['data' => [...], 'next_cursor' => ?string, 'has_more' => bool]
     */
    public static function fetch_changes($cursor, $limit = 50)
    {
        $url = add_query_arg(
            array_filter(['cursor' => $cursor, 'limit' => $limit]),
            self::v2_project_url('sync/changes')
        );

        $response = self::engine_request('GET', $url);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'data' => isset($response['data']) && is_array($response['data']) ? $response['data'] : [],
            'next_cursor' => isset($response['next_cursor']) && is_string($response['next_cursor']) ? $response['next_cursor'] : null,
            'has_more' => !empty($response['has_more']),
        ];
    }

    /**
     * Vertelt de Engine wat er met een artikel gebeurd is: published, updated,
     * skipped of failed. Vervangt confirm-published zodra de site is
     * aangemeld.
     *
     * @param int    $article_id
     * @param string $action
     * @param array  $details external_ref, url en/of error.
     *
     * @return true|WP_Error
     */
    public static function acknowledge($article_id, $action, $details = [])
    {
        $body = array_merge(
            [
                'article_id' => absint($article_id),
                'action' => $action,
            ],
            array_filter($details, static function ($value) {
                return null !== $value && '' !== $value;
            })
        );

        $response = self::engine_request('POST', self::v2_project_url('sync/acknowledgements'), $body);

        return is_wp_error($response) ? $response : true;
    }

    private static function v2_project_url($path)
    {
        return trailingslashit(CONTENT_STUDIO_API_V2_ROUTE)
            . 'projects/' . rawurlencode(get_option('content_studio_project_id', ''))
            . '/' . $path;
    }

    /**
     * Een aanroep naar de Engine. Bij een fout draagt de WP_Error de
     * HTTP-status als data en de reden van de Engine als bericht, want dat is
     * wat iemand in de instellingen wil lezen.
     *
     * @param string     $method
     * @param string     $url
     * @param array|null $body
     *
     * @return array|WP_Error
     */
    private static function engine_request($method, $url, $body = null)
    {
        $api_key = get_option('content_studio_api_key', '');

        if (empty($api_key)) {
            return new WP_Error('content_studio_missing_api_key', 'Content Studio API key is not configured.');
        }

        if (empty(get_option('content_studio_project_id', ''))) {
            return new WP_Error('content_studio_missing_project_id', 'Content Studio project ID is not configured.');
        }

        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ];

        if (null !== $body) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code < 200 || $status_code >= 300) {
            $message = is_array($data) && !empty($data['message']) && is_string($data['message'])
                ? $data['message']
                : sprintf('Content Studio API returned HTTP %d.', $status_code);

            return new WP_Error('content_studio_api_error', $message, ['status' => $status_code]);
        }

        return is_array($data) ? $data : [];
    }
}
