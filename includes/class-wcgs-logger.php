<?php
defined( 'ABSPATH' ) || exit;

class WCGS_Logger {

    private static $instance = null;
    const OPTION_KEY  = 'wcgs_log_entries';
    const MAX_ENTRIES = 1000;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log( $level, $message, $project_id = '', $context = array() ) {
        $entries   = $this->load();
        $entries[] = array(
            'ts'         => current_time( 'mysql' ),
            'level'      => $level,
            'message'    => $message,
            'project_id' => $project_id,
            'context'    => $context,
        );

        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $entries, false );
    }

    public function get_entries( $limit = 200, $project_id = null ) {
        $entries = $this->load();

        if ( $project_id !== null ) {
            $filtered = array();
            foreach ( $entries as $e ) {
                if ( isset( $e['project_id'] ) && $e['project_id'] === $project_id ) {
                    $filtered[] = $e;
                }
            }
            $entries = array_values( $filtered );
        }

        return array_slice( array_reverse( $entries ), 0, $limit );
    }

    public function clear( $project_id = null ) {
        if ( $project_id === null ) {
            update_option( self::OPTION_KEY, array(), false );
            return;
        }

        $entries  = $this->load();
        $filtered = array();
        foreach ( $entries as $e ) {
            if ( ! isset( $e['project_id'] ) || $e['project_id'] !== $project_id ) {
                $filtered[] = $e;
            }
        }
        update_option( self::OPTION_KEY, array_values( $filtered ), false );
    }

    private function load() {
        return (array) get_option( self::OPTION_KEY, array() );
    }
}
