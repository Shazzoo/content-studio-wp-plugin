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
    }

    public function sync_content()
    {
        $saved = Content_Studio_Admin_Settings::run_sync();

        if (is_wp_error($saved)) {
            return $saved;
        }

        return rest_ensure_response([
            'success' => true,
            'saved' => $saved,
        ]);
    }
}
