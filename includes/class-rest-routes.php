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

        // Waar de Engine aanklopt. Open voor iedereen: de handtekening in
        // de headers bepaalt of een aanroep echt van de Engine komt.
        register_rest_route(
            'content-studio/v1',
            '/webhook',
            [
                'methods' => 'POST',
                'callback' => ['Content_Studio_Engine_Sync', 'handle_webhook'],
                'permission_callback' => '__return_true',
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
