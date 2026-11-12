<?php
defined( 'ABSPATH' ) || exit;

class WCGS_Admin {

    public static function register_menu() : void {
        add_menu_page(
            'WC Git Sync',
            'Git Sync',
            'manage_options',
            'wc-git-sync',
            [ __CLASS__, 'render' ],
            'dashicons-randomize',
            81
        );
    }

    public static function enqueue_assets( string $hook ) : void {
        if ( $hook !== 'toplevel_page_wc-git-sync' ) return;

        wp_enqueue_style(
            'wcgs-admin',
            WCGS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WCGS_VERSION
        );

        wp_enqueue_script(
            'wcgs-admin',
            WCGS_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            WCGS_VERSION,
            true
        );

        wp_localize_script( 'wcgs-admin', 'wcgsData', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wcgs_nonce' ),
            'intervals' => [
                'disabled'   => 'Desabilitado',
                'wcgs_5min'  => 'A cada 5 minutos',
                'wcgs_15min' => 'A cada 15 minutos',
                'wcgs_30min' => 'A cada 30 minutos',
                'hourly'     => 'A cada hora',
                'twicedaily' => 'Duas vezes ao dia',
                'daily'      => 'Diariamente',
            ],
        ] );
    }

    public static function render() : void {
        echo '<div id="wcgs-root"></div>';
    }
}
