<?php

class Content_Studio_API_Client
{
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
}
