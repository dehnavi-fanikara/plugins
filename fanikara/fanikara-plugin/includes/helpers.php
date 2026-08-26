<?php
/**
 * Fanikara Relation Helper Class
 *
 * A singleton class to manage JetEngine relations queries.
 * Provides helper methods to get parent/children post IDs with caching.
 *
 * @package Fanikara
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Fanikara_Relation_Helper {

    /**
     * Cache group name
     */
    const CACHE_GROUP = 'fanikara';

    /**
     * Cache duration in seconds (1 hour)
     */
    const CACHE_DURATION = HOUR_IN_SECONDS;

    /**
     * Singleton instance
     *
     * @var Fanikara_Relation_Helper|null
     */
    private static $instance = null;

    /**
     * Relation table name (without prefix)
     *
     * @var string
     */
    private $table_name;

    /**
     * Flag to track if table exists
     *
     * @var bool|null
     */
    private $table_exists = null;

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'jet_rel_default';
    }

    /**
     * Get singleton instance
     *
     * @return Fanikara_Relation_Helper
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if relation table exists
     *
     * @return bool
     */
    private function table_exists() {
        if ( null === $this->table_exists ) {
            global $wpdb;
            $this->table_exists = ( $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_name}'" ) === $this->table_name );
        }
        return $this->table_exists;
    }

    /**
     * Get related post IDs from JetEngine relations
     *
     * @param int    $post_id       The current post ID
     * @param int    $rel_id        The relation ID from JetEngine
     * @param string $direction     'parent' to get parent IDs, 'children' to get child IDs
     * @param bool   $return_single If true, returns first ID only (string). If false, returns array
     * @return string|array         Single ID as string or array of IDs
     */
    public function get_related_ids( $post_id, $rel_id, $direction = 'parent', $return_single = true ) {

        // Validate inputs
        if ( ! $post_id || ! $rel_id ) {
            return $return_single ? '' : array();
        }

        // Check if relation table exists
        if ( ! $this->table_exists() ) {
            return $return_single ? '' : array();
        }

        // Validate direction parameter
        if ( ! in_array( $direction, array( 'parent', 'children' ), true ) ) {
            return $return_single ? '' : array();
        }

        // Create cache key based on parameters
        $cache_key     = "fanikara_rel_{$direction}_{$rel_id}_{$post_id}";
        $cached_result = wp_cache_get( $cache_key, self::CACHE_GROUP );

        // Return cached result if exists
        if ( false !== $cached_result ) {
            return $return_single ? ( ! empty( $cached_result ) ? $cached_result[0] : '' ) : $cached_result;
        }

        // Build query based on direction
        global $wpdb;

        if ( 'parent' === $direction ) {
            $query = $wpdb->prepare(
                "SELECT parent_object_id FROM {$this->table_name} 
             WHERE rel_id = %d AND child_object_id = %d",
                (int) $rel_id,
                (int) $post_id
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT child_object_id FROM {$this->table_name} 
             WHERE rel_id = %d AND parent_object_id = %d",
                (int) $rel_id,
                (int) $post_id
            );
        }

        // Execute query and get all results
        $results = $wpdb->get_col( $query );

        // Convert to integers
        $results = array_map( 'intval', $results );

        // Cache the results
        wp_cache_set( $cache_key, $results, self::CACHE_GROUP, self::CACHE_DURATION );

        // Return based on $return_single parameter
        if ( $return_single ) {
            return ! empty( $results ) ? (string) $results[0] : '';
        }

        return $results;
    }

    /**
     * Get single parent ID (convenience method)
     *
     * @param int $post_id The child post ID
     * @param int $rel_id  The relation ID
     * @return string      Parent ID or empty string
     */
    public function get_parent_id( $post_id, $rel_id ) {
        return $this->get_related_ids( $post_id, $rel_id, 'parent', true );
    }

    /**
     * Get all parent IDs (convenience method)
     *
     * @param int $post_id The child post ID
     * @param int $rel_id  The relation ID
     * @return array       Array of parent IDs
     */
    public function get_parent_ids( $post_id, $rel_id ) {
        return $this->get_related_ids( $post_id, $rel_id, 'parent', false );
    }

    /**
     * Get single child ID (convenience method)
     *
     * @param int $post_id The parent post ID
     * @param int $rel_id  The relation ID
     * @return string      Child ID or empty string
     */
    public function get_child_id( $post_id, $rel_id ) {
        return $this->get_related_ids( $post_id, $rel_id, 'children', true );
    }

    /**
     * Get all children IDs (convenience method)
     *
     * @param int $post_id The parent post ID
     * @param int $rel_id  The relation ID
     * @return array       Array of children IDs
     */
    public function get_children_ids( $post_id, $rel_id ) {
        return $this->get_related_ids( $post_id, $rel_id, 'children', false );
    }

    /**
     * Clear cache for a specific relation query
     *
     * @param int    $post_id   The post ID
     * @param int    $rel_id    The relation ID
     * @param string $direction 'parent' or 'children'
     * @return bool             True on success, false on failure
     */
    public function clear_cache( $post_id, $rel_id, $direction = 'parent' ) {
        $cache_key = "fanikara_rel_{$direction}_{$rel_id}_{$post_id}";
        return wp_cache_delete( $cache_key, self::CACHE_GROUP );
    }

    /**
     * Clear all cached relations for a specific post
     *
     * @param int $post_id The post ID
     * @return void
     */
    public function clear_post_cache( $post_id ) {
        // Note: WordPress object cache doesn't support wildcard deletion
        // This is a simplified version - for production, you may need to track cache keys
        wp_cache_flush_group( self::CACHE_GROUP );
    }
}

/**
 * Backward compatibility wrapper function
 * Allows existing code using fanikara_get_related_ids() to continue working
 *
 * @param int    $post_id       The current post ID
 * @param int    $rel_id        The relation ID from JetEngine
 * @param string $direction     'parent' or 'children'
 * @param bool   $return_single If true, returns first ID only
 * @return string|array
 */
if ( ! function_exists( 'fanikara_get_related_ids' ) ) {
    function fanikara_get_related_ids( $post_id, $rel_id, $direction = 'parent', $return_single = true ) {
        return Fanikara_Relation_Helper::get_instance()->get_related_ids( $post_id, $rel_id, $direction, $return_single );
    }
}