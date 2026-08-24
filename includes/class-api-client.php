<?php

class Content_Studio_API_Client
{
    const PROJECT_TRANSIENT = 'content_studio_project_info';

    public static function fetch_content()
    {
        $api_key = get_option('content_studio_api_key', '');
        $project_id = get_option('content_studio_project_id', '');
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];

        if (empty($api_key)) {
            return new WP_Error('content_studio_missing_api_key', 'Content Studio API key is not configured.');
        }

        if (empty($project_id)) {
            return new WP_Error('content_studio_missing_project_id', 'Content Studio project ID is not configured.');
        }

        $url = trailingslashit(CONTENT_STUDIO_API_ROUTE) . 'projects/' . rawurlencode($project_id) . '/contents';

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
                'headers' => $headers,
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

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            return new WP_Error('content_studio_invalid_json', 'Content Studio API returned invalid JSON.');
        }

        return $data;
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

        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error(
                'content_studio_api_error',
                sprintf('Content Studio API returned HTTP %d when confirming publication.', $status_code)
            );
        }

        return true;
    }
}
