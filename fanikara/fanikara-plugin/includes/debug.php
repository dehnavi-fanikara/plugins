<?php

    if (!defined('ABSPATH')) { exit; }

    add_action('init', 'fanikara_full_check_v2');

    function fanikara_full_check_v2() {

        if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['full_check_v2'] ) ) {
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $output = '<div style="direction:ltr; text-align:left; background:#f0f0f0; padding:20px; font-family:monospace; max-width:900px; margin:20px auto;">';
        $output .= '<h1 style="color:#d32f2f;">Fanikara Plugin - System Check V2</h1>';

        // ========== 1. بررسی JetEngine ==========
        $output .= '<h2 style="color:#1976d2;">1. JetEngine Status</h2>';
        $output .= '<p style="color:green;">✅ Jet_Engine class exists</p>';
        $output .= '<p style="color:green;">✅ jet_engine() function exists</p>';

        // ========== 2. بررسی جدول‌های درست JetEngine ==========
        $output .= '<h2 style="color:#1976d2;">2. Database Tables Check (Correct Tables)</h2>';

        $correct_tables = array(
            $prefix . 'jet_rel_default',
            $prefix . 'jet_post_types',
            $prefix . 'jet_taxonomies',
        );

        foreach ( $correct_tables as $table ) {
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) == $table ) {
                $output .= '<p style="color:green;">✅ Table exists: ' . $table . '</p>';
            } else {
                $output .= '<p style="color:red;">❌ Table does NOT exist: ' . $table . '</p>';
            }
        }

        // ========== 3. بررسی جدول jet_rel_default (مهمترین) ==========
        $output .= '<h2 style="color:#1976d2;">3. JetEngine Relations Data (jet_rel_default)</h2>';

        $rel_table = $prefix . 'jet_rel_default';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$rel_table'" ) == $rel_table ) {

            // تعداد کل رکوردها
            $total_records = $wpdb->get_var( "SELECT COUNT(*) FROM {$rel_table}" );
            $output .= '<p>📊 Total records in jet_rel_default: ' . $total_records . '</p>';

            // رکوردهای با rel_id = 9 (city_releated_users)
            $rel_9 = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$rel_table} WHERE rel_id = %s LIMIT 10",
                '9'
            ) );

            if ( ! empty( $rel_9 ) ) {
                $output .= '<p style="color:green;">✅ Found ' . count( $rel_9 ) . ' records for rel_id=9 (city_releated_users)</p>';
            } else {
                $output .= '<p style="color:orange;">⚠️ No records found for rel_id=9</p>';
            }

            // رکوردهای با rel_id = 11 (city_related_content)
            $rel_11 = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$rel_table} WHERE rel_id = %s LIMIT 10",
                '11'
            ) );

            if ( ! empty( $rel_11 ) ) {
                $output .= '<p style="color:green;">✅ Found ' . count( $rel_11 ) . ' records for rel_id=11 (city_related_content)</p>';
            } else {
                $output .= '<p style="color:orange;">⚠️ No records found for rel_id=11</p>';
            }

            // نمایش نمونه دیتا برای محتوای خاص (ID=935)
            $content_id = 935;
            $city_for_content = $wpdb->get_var( $wpdb->prepare(
                "SELECT parent_object_id FROM {$rel_table} WHERE rel_id = %s AND child_object_id = %d LIMIT 1",
                '11', $content_id
            ) );

            if ( $city_for_content ) {
                $city_title = get_the_title( $city_for_content );
                $city_slug = get_post_field( 'post_name', $city_for_content );
                $output .= '<p style="color:green;">✅ Content ID ' . $content_id . ' is connected to City ID ' . $city_for_content . ' ("' . $city_title . '", slug: ' . $city_slug . ')</p>';
            } else {
                $output .= '<p style="color:red;">❌ Content ID ' . $content_id . ' has NO city connected (check rel_id=11)</p>';
            }

        } else {
            $output .= '<p style="color:red;">❌ jet_rel_default table does not exist!</p>';
        }

        // ========== 4. لیست همه Relation‌های ثبت شده ==========
        $output .= '<h2 style="color:#1976d2;">4. Relations Registered in Options</h2>';

        // Relation‌ها در option_value ذخیره می‌شوند
        $options_data = $wpdb->get_var( "SELECT option_value FROM {$prefix}options WHERE option_name = 'jet_engine_relations'" );

        if ( $options_data ) {
            $relations = maybe_unserialize( $options_data );
            if ( is_array( $relations ) && ! empty( $relations ) ) {
                foreach ( $relations as $rel_id => $rel_data ) {
                    $output .= '<div style="border:1px solid #ccc; margin:10px 0; padding:10px;">';
                    $output .= '<strong>Relation ID:</strong> ' . $rel_id . '<br>';
                    $output .= '<strong>Name:</strong> ' . ( isset( $rel_data['name'] ) ? $rel_data['name'] : 'N/A' ) . '<br>';
                    $output .= '<strong>Type:</strong> ' . ( isset( $rel_data['type'] ) ? $rel_data['type'] : 'N/A' ) . '<br>';
                    $output .= '<strong>Parent:</strong> ' . ( isset( $rel_data['parent_object'] ) ? $rel_data['parent_object'] : 'N/A' ) . '<br>';
                    $output .= '<strong>Child:</strong> ' . ( isset( $rel_data['child_object'] ) ? $rel_data['child_object'] : 'N/A' ) . '<br>';
                    $output .= '</div>';
                }
            } else {
                $output .= '<p>No relations found in jet_engine_relations option</p>';
            }
        } else {
            $output .= '<p>⚠️ jet_engine_relations option not found (relations might be stored elsewhere)</p>';
        }

        $output .= '<hr>';
        $output .= '<p>🔍 URL parameter used: <code>?full_check_v2=1</code></p>';
        $output .= '<p>📅 Check time: ' . current_time( 'mysql' ) . '</p>';
        $output .= '</div>';

        wp_die( $output );
    }