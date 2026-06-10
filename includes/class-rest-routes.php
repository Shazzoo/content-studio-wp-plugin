<?php

class Content_Studio_REST_Routes
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route(
            'content-studio/v1',
            '/sync',
            [
                'methods' => 'POST',
                'callback' => [$this, 'sync_content'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]
        );

        register_rest_route(
            'content-studio/v1',
            '/content',
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_content'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function sync_content()
    {
        $content = Content_Studio_API_Client::fetch_content();

        if (is_wp_error($content)) {
            return $content;
        }

        $saved = Content_Studio_Storage::save($content);

        return rest_ensure_response([
            'success' => true,
            'saved' => $saved,
        ]);
    }

    public function get_content()
    {
        return rest_ensure_response(Content_Studio_Storage::get());
    }
}
