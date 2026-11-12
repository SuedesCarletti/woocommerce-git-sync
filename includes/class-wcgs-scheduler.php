<?php
defined( 'ABSPATH' ) || exit;

class WCGS_Scheduler {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setup() {
        add_filter( 'cron_schedules', array( $this, 'add_intervals' ) );
        add_action( 'init', array( $this, 'sync_all_schedules' ), 20 );
    }

    public function add_intervals( $schedules ) {
        $schedules['wcgs_5min']  = array( 'interval' => 300,  'display' => 'A cada 5 minutos'  );
        $schedules['wcgs_15min'] = array( 'interval' => 900,  'display' => 'A cada 15 minutos' );
        $schedules['wcgs_30min'] = array( 'interval' => 1800, 'display' => 'A cada 30 minutos' );
        return $schedules;
    }

    public function sync_all_schedules() {
        foreach ( WCGS_Project::all() as $p ) {
            $this->reschedule( $p );
        }
    }

    public function reschedule( $p ) {
        $this->unschedule( $p );
        if ( $p->sync_interval !== 'disabled' ) {
            wp_schedule_event( time(), $p->sync_interval, 'wcgs_project_sync', array( $p->id ) );
        }
    }

    public function unschedule( $p ) {
        $ts = wp_next_scheduled( 'wcgs_project_sync', array( $p->id ) );
        if ( $ts ) {
            wp_unschedule_event( $ts, 'wcgs_project_sync', array( $p->id ) );
        }
    }
}
