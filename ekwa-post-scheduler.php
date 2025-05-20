<?php
/*
Plugin Name: Ekwa Post Scheduler
Description: Schedule posts to be published at a future date and time.
Version: 1.0
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Add scheduling meta box to post editor
function ekwa_add_scheduler_metabox() {
    add_meta_box(
        'ekwa_post_scheduler',
        'Ekwa Post Scheduler',
        'ekwa_scheduler_metabox_callback',
        'post',
        'side',
        'high'
    );
}
add_action( 'add_meta_boxes', 'ekwa_add_scheduler_metabox' );

function ekwa_scheduler_metabox_callback( $post ) {
    wp_nonce_field( 'ekwa_save_scheduler', 'ekwa_scheduler_nonce' );
    $interval_value = get_post_meta( $post->ID, '_ekwa_schedule_interval_value', true );
    $interval_type = get_post_meta( $post->ID, '_ekwa_schedule_interval_type', true );
    $is_smart = get_post_meta( $post->ID, '_ekwa_smart_scheduled', true );
    ?>
    <label for="ekwa_schedule_interval_value">Schedule Interval:</label>
    <input type="number" min="1" id="ekwa_schedule_interval_value" name="ekwa_schedule_interval_value" value="<?php echo esc_attr( $interval_value ); ?>" style="width:60px;" />
    <select id="ekwa_schedule_interval_type" name="ekwa_schedule_interval_type">
        <?php
        $types = array(
            'minutes' => 'Minutes',
            'hours'   => 'Hours',
            'days'    => 'Days',
            'weeks'   => 'Weeks',
            'months'  => 'Months',
        );
        foreach ( $types as $key => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $key ),
                selected( $interval_type, $key, false ),
                esc_html( $label )
            );
        }
        ?>
    </select>
    <br><br>
    <label>
        <input type="checkbox" name="ekwa_smart_scheduled" value="1" <?php checked( $is_smart, '1' ); ?> />
        Smart Scheduled
    </label>
    <?php
}

// Save scheduled interval and smart scheduled meta
function ekwa_save_scheduler_meta( $post_id ) {
    if ( ! isset( $_POST['ekwa_scheduler_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ekwa_scheduler_nonce'], 'ekwa_save_scheduler' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // Save interval value and type
    if ( isset( $_POST['ekwa_schedule_interval_value'] ) ) {
        update_post_meta( $post_id, '_ekwa_schedule_interval_value', intval( $_POST['ekwa_schedule_interval_value'] ) );
    }
    if ( isset( $_POST['ekwa_schedule_interval_type'] ) ) {
        update_post_meta( $post_id, '_ekwa_schedule_interval_type', sanitize_text_field( $_POST['ekwa_schedule_interval_type'] ) );
    }

    // Save smart scheduled
    $is_smart = isset( $_POST['ekwa_smart_scheduled'] ) ? '1' : '';
    update_post_meta( $post_id, '_ekwa_smart_scheduled', $is_smart );

    if ( isset( $_POST['ekwa_scheduled_time'] ) && ! empty( $_POST['ekwa_scheduled_time'] ) ) {
        update_post_meta( $post_id, '_ekwa_scheduled_time', sanitize_text_field( $_POST['ekwa_scheduled_time'] ) );
        // Set post status to 'future' and update post_date
        $scheduled_time = date( 'Y-m-d H:i:s', strtotime( $_POST['ekwa_scheduled_time'] ) );
        $post_data = array(
            'ID'            => $post_id,
            'post_status'   => 'future',
            'post_date'     => $scheduled_time,
            'post_date_gmt' => get_gmt_from_date( $scheduled_time ),
        );
        remove_action( 'save_post', 'ekwa_save_scheduler_meta' );
        wp_update_post( $post_data );
        add_action( 'save_post', 'ekwa_save_scheduler_meta' );
    }
}
add_action( 'save_post', 'ekwa_save_scheduler_meta' );

// Register custom post status for Smart Scheduled
function ekwa_register_smart_scheduled_status() {
    register_post_status( 'smart_scheduled', array(
        'label'                     => _x( 'Smart Scheduled', 'post' ),
        'public'                    => true,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Smart Scheduled <span class="count">(%s)</span>', 'Smart Scheduled <span class="count">(%s)</span>' ),
    ) );
}
add_action( 'init', 'ekwa_register_smart_scheduled_status' );

// Show Smart Scheduled in post status dropdown and admin list
function ekwa_display_smart_scheduled_status( $statuses ) {
    $statuses['smart_scheduled'] = _x( 'Smart Scheduled', 'post' );
    return $statuses;
}
add_filter( 'display_post_states', function( $states, $post ) {
    if ( get_post_status( $post->ID ) === 'smart_scheduled' ) {
        $states[] = __( 'Smart Scheduled' );
    }
    return $states;
}, 10, 2 );
add_filter( 'post_status_list', 'ekwa_display_smart_scheduled_status' );

// Set post status to smart_scheduled if Smart Scheduled is checked
function ekwa_set_smart_scheduled_status( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['ekwa_scheduler_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ekwa_scheduler_nonce'], 'ekwa_save_scheduler' ) ) return;

    $is_smart = isset( $_POST['ekwa_smart_scheduled'] ) ? '1' : '';
    if ( $is_smart === '1' ) {
        $post = get_post( $post_id );
        if ( $post && $post->post_status !== 'smart_scheduled' ) {
            $post_data = array(
                'ID' => $post_id,
                'post_status' => 'smart_scheduled',
            );
            remove_action( 'save_post', 'ekwa_set_smart_scheduled_status' );
            wp_update_post( $post_data );
            add_action( 'save_post', 'ekwa_set_smart_scheduled_status' );
        }
    }
}
add_action( 'save_post', 'ekwa_set_smart_scheduled_status' );

// Add noindex meta tag for smart scheduled posts
function ekwa_noindex_smart_scheduled() {
    if ( is_singular('post') ) {
        global $post;
        if ( get_post_status( $post ) === 'smart_scheduled' ) {
            echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
        }
    }
}
add_action( 'wp_head', 'ekwa_noindex_smart_scheduled' );

// Exclude smart scheduled posts from main queries (archives, categories, etc.)
function ekwa_exclude_smart_scheduled_from_query( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if (
        $query->is_home() ||
        $query->is_archive() ||
        $query->is_category() ||
        $query->is_tag() ||
        $query->is_search()
    ) {
        $query->set( 'post_status', array( 'publish' ) );
    }
}
add_action( 'pre_get_posts', 'ekwa_exclude_smart_scheduled_from_query' );

// Exclude smart scheduled posts from Yoast SEO XML sitemap
function ekwa_yoast_exclude_smart_scheduled( $excluded, $post ) {
    if ( get_post_status( $post->ID ) === 'smart_scheduled' ) {
        return true;
    }
    return $excluded;
}
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', function( $excluded, $post_type ) {
    $args = array(
        'post_type'   => $post_type,
        'post_status' => 'smart_scheduled',
        'fields'      => 'ids',
        'nopaging'    => true,
    );
    $smart_posts = get_posts( $args );
    return array_merge( $excluded, $smart_posts );
}, 10, 2 );

// Schedule cron event on plugin activation
function ekwa_activate_scheduler() {
    if ( ! wp_next_scheduled( 'ekwa_check_smart_scheduled_posts' ) ) {
        wp_schedule_event( time(), 'minute', 'ekwa_check_smart_scheduled_posts' );
    }
}
register_activation_hook( __FILE__, 'ekwa_activate_scheduler' );

// Clear cron event on plugin deactivation
function ekwa_deactivate_scheduler() {
    wp_clear_scheduled_hook( 'ekwa_check_smart_scheduled_posts' );
}
register_deactivation_hook( __FILE__, 'ekwa_deactivate_scheduler' );

// Add custom interval for every minute
add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['minute'] = array(
        'interval' => 60,
        'display'  => __( 'Every Minute' )
    );
    return $schedules;
});

// Cron job: check and publish smart scheduled posts
add_action( 'ekwa_check_smart_scheduled_posts', function() {
    $args = array(
        'post_type'   => 'post',
        'post_status' => 'smart_scheduled',
        'meta_query'  => array(
            array(
                'key'     => '_ekwa_schedule_interval_value',
                'compare' => 'EXISTS',
            ),
            array(
                'key'     => '_ekwa_schedule_interval_type',
                'compare' => 'EXISTS',
            ),
        ),
        'posts_per_page' => -1,
    );
    $posts = get_posts( $args );
    foreach ( $posts as $post ) {
        $interval_value = intval( get_post_meta( $post->ID, '_ekwa_schedule_interval_value', true ) );
        $interval_type  = get_post_meta( $post->ID, '_ekwa_schedule_interval_type', true );
        $scheduled_time = strtotime( $post->post_date_gmt );
        $now = current_time( 'timestamp', true );

        // Calculate the unlock time
        $unlock_time = strtotime( "+$interval_value $interval_type", $scheduled_time );

        if ( $now >= $unlock_time ) {
            // Publish the post
            $update = array(
                'ID'          => $post->ID,
                'post_status' => 'publish',
            );
            wp_update_post( $update );
            // Optionally, clean up meta
            delete_post_meta( $post->ID, '_ekwa_smart_scheduled' );
        }
    }
});

// Remove Yoast article published_time and modified_time meta tags for smart scheduled posts
function ekwa_filter_yoast_metadata($presentation) {
    // We don't need to modify the presentation object directly
    // Just return it unchanged to avoid errors
    return $presentation;
}
add_filter('wpseo_frontend_presentation', 'ekwa_filter_yoast_metadata');

// Use a more reliable approach to remove Yoast meta tags
function ekwa_remove_yoast_meta_tags() {
    if (is_singular('post')) {
        global $post;
        if (get_post_status($post) === 'smart_scheduled') {
            // Remove Yoast article published/modified time meta tags
            add_filter('wpseo_frontend_presentation_output', function($output) {
                // Remove the meta tags using regex
                $output = preg_replace('/<meta property="article:published_time".*?\/>/i', '', $output);
                $output = preg_replace('/<meta property="article:modified_time".*?\/>/i', '', $output);
                return $output;
            });

            // Alternative method: Remove specific opengraph tags
            add_filter('wpseo_opengraph_type', '__return_false');
            add_filter('wpseo_add_opengraph_article_publisher', '__return_false');
            add_filter('wpseo_opengraph_author_facebook', '__return_false');

            // Disable all article tags
            add_filter('wpseo_opengraph_show_article_author_facebook', '__return_false');
            add_filter('wpseo_opengraph_show_article_section', '__return_false');
            add_filter('wpseo_opengraph_show_publish_date', '__return_false');
        }
    }
}
add_action('template_redirect', 'ekwa_remove_yoast_meta_tags', 5);

// Add a buffer to remove any remaining meta tags that might slip through
add_action('wp_head', function() {
    if (is_singular('post')) {
        global $post;
        if (get_post_status($post) === 'smart_scheduled') {
            ob_start(function($output) {
                // Remove the published_time and modified_time meta tags
                $output = preg_replace('/<meta property="article:published_time".*?\/>/i', '', $output);
                $output = preg_replace('/<meta property="article:modified_time".*?\/>/i', '', $output);
                return $output;
            });

            // Make sure to end the output buffer in footer
            add_action('wp_footer', function() {
                if (ob_get_level()) {
                    ob_end_flush();
                }
            }, 999);
        }
    }
}, 1);