<?php
/**
 * Ajax handler for Dokan
 *
 * @package Dokan
 */
class Dokan_Ajax {

    /**
     * Singleton object
     *
     * @staticvar boolean $instance
     * @return \self
     */
    public static function init() {

        static $instance = false;

        if ( !$instance ) {
            $instance = new self;
        }

        return $instance;
    }

    /**
     * Init ajax handlers
     *
     * @return void
     */
    function init_ajax() {
        //withdraw note
        $withdraw = Dokan_Admin_Withdraw::init();
        add_action( 'wp_ajax_note', array( $withdraw, 'note_update' ) );
        add_action( 'wp_ajax_withdraw_ajax_submission', array( $withdraw, 'withdraw_ajax' ) );

        //settings
        $settings = Dokan_Template_Settings::init();
        add_action( 'wp_ajax_dokan_settings', array( $settings, 'ajax_settings' ) );

        add_action( 'wp_ajax_dokan-mark-order-complete', array( $this, 'complete_order' ) );
        add_action( 'wp_ajax_dokan-mark-order-processing', array( $this, 'process_order' ) );
        add_action( 'wp_ajax_dokan_grant_access_to_download', array( $this, 'grant_access_to_download' ) );
        add_action( 'wp_ajax_dokan_add_order_note', array( $this, 'add_order_note' ) );
        add_action( 'wp_ajax_dokan_delete_order_note', array( $this, 'delete_order_note' ) );
        add_action( 'wp_ajax_dokan_change_status', array( $this, 'change_order_status' ) );
        add_action( 'wp_ajax_dokan_contact_seller', array( $this, 'contact_seller' ) );
        add_action( 'wp_ajax_nopriv_dokan_contact_seller', array( $this, 'contact_seller' ) );

        add_action( 'wp_ajax_dokan_add_shipping_tracking_info', array( $this, 'add_shipping_tracking_info' ) );

        add_action( 'wp_ajax_dokan_revoke_access_to_download', array( $this, 'revoke_access_to_download' ) );
        add_action( 'wp_ajax_nopriv_dokan_revoke_access_to_download', array( $this, 'revoke_access_to_download' ) );

        add_action( 'wp_ajax_dokan_toggle_seller', array( $this, 'toggle_seller_status' ) );

        add_action( 'wp_ajax_shop_url', array($this, 'shop_url_check') );
        add_action( 'wp_ajax_nopriv_shop_url', array($this, 'shop_url_check') );

        add_filter( 'woocommerce_cart_item_name', array($this, 'seller_info_checkout'), 10, 2 );

        add_action( 'wp_ajax_dokan_seller_listing_search', array($this, 'seller_listing_search') );
        add_action( 'wp_ajax_nopriv_dokan_seller_listing_search', array($this, 'seller_listing_search') );

        add_action( 'wp_ajax_dokan_create_new_product', array( $this, 'create_product' ) );

        add_action( 'wp_ajax_custom-header-crop', array( $this, 'crop_store_banner' ) );
    }

    function create_product() {
        check_ajax_referer( 'dokan_reviews' );

        parse_str( $_POST['postdata'], $postdata );

        $response = dokan_save_product( $postdata );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        if ( is_int( $response ) ) {
            wp_send_json_success( dokan_edit_product_url( $response ) );
        } else {
            wp_send_json_error( __( 'Something wrong, please try again later', 'dokan-lite' ) );
        }
    }

    /**
     * Injects seller name on checkout page
     *
     * @param array $item_data
     * @param array $cart_item
     * @return array
     */
    function seller_info_checkout( $item_data, $cart_item ) {
        $info   = dokan_get_store_info( $cart_item['data']->post->post_author );
        $seller = sprintf( __( '<br><strong> Vendor:</strong> %s', 'dokan-lite' ), $info['store_name'] );
        $data   = $item_data . $seller;

        return apply_filters( 'dokan_seller_info_checkout', $data, $info, $item_data, $cart_item );
    }

    /**
     * chop url check
     */
    function shop_url_check() {
        global $user_ID;

        if ( !wp_verify_nonce( $_POST['_nonce'], 'dokan_reviews' ) ) {
            wp_send_json_error( array(
                'type' => 'nonce',
                'message' => __( 'Are you cheating?', 'dokan-lite' )
            ) );
        }

        $url_slug = $_POST['url_slug'];
        $check    = true;
        $user     = get_user_by( 'slug', $url_slug );

        if ( $user != '' ) {
            $check = false;
        }

        // check if a customer wants to migrate, his username should be available
        if ( is_user_logged_in() && dokan_is_user_customer( $user_ID ) ) {
            $current_user = wp_get_current_user();

            if ( $current_user->user_nicename == $user->user_nicename ) {
                $check = true;
            }
        }

        echo $check;
    }

    /**
     * Mark a order as complete
     *
     * Fires from seller dashboard in frontend
     */
    function complete_order() {
        if ( !is_admin() ) {
            die();
        }

        if ( !current_user_can( 'dokandar' ) || dokan_get_option( 'order_status_change', 'dokan_selling', 'on' ) != 'on' ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'dokan-lite' ) );
        }

        if ( !check_admin_referer( 'dokan-mark-order-complete' ) ) {
            wp_die( __( 'You have taken too long. Please go back and retry.', 'dokan-lite' ) );
        }

        $order_id = isset($_GET['order_id']) && (int) $_GET['order_id'] ? (int) $_GET['order_id'] : '';
        if ( !$order_id ) {
            die();
        }

        if ( !dokan_is_seller_has_order( get_current_user_id(), $order_id ) ) {
            wp_die( __( 'You do not have permission to change this order', 'dokan-lite' ) );
        }

        $order = new WC_Order( $order_id );
        $order->update_status( 'completed' );

        wp_safe_redirect( wp_get_referer() );
        die();
    }

    /**
     * Mark a order as processing
     *
     * Fires from frontend seller dashboard
     */
    function process_order() {
        if ( !is_admin() ) {
            die();
        }

        if ( !current_user_can( 'dokandar' ) && dokan_get_option( 'order_status_change', 'dokan_selling', 'on' ) != 'on' ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'dokan-lite' ) );
        }

        if ( !check_admin_referer( 'dokan-mark-order-processing' ) ) {
            wp_die( __( 'You have taken too long. Please go back and retry.', 'dokan-lite' ) );
        }

        $order_id = isset( $_GET['order_id'] ) && (int) $_GET['order_id'] ? (int) $_GET['order_id'] : '';
        if ( !$order_id ) {
            die();
        }

        if ( !dokan_is_seller_has_order( get_current_user_id(), $order_id ) ) {
            wp_die( __( 'You do not have permission to change this order', 'dokan-lite' ) );
        }

        $order = new WC_Order( $order_id );
        $order->update_status( 'processing' );

        wp_safe_redirect( wp_get_referer() );
    }

    /**
     * Grant download permissions via ajax function
     *
     * @access public
     * @return void
     */
    function grant_access_to_download() {

        check_ajax_referer( 'grant-access', 'security' );

        global $wpdb;

        $order_id       = intval( $_POST['order_id'] );
        $product_ids    = $_POST['product_ids'];
        $loop           = intval( $_POST['loop'] );
        $file_counter   = 0;
        $order          = new WC_Order( $order_id );

        if ( ! is_array( $product_ids ) ) {
            $product_ids = array( $product_ids );
        }

        foreach ( $product_ids as $product_id ) {
            $product    = get_product( $product_id );
            $files      = $product->get_files();

            if ( ! $order->billing_email )
                die();

            if ( $files ) {
                foreach ( $files as $download_id => $file ) {
                    if ( $inserted_id = wc_downloadable_file_permission( $download_id, $product_id, $order ) ) {

                        // insert complete - get inserted data
                        $download = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE permission_id = %d", $inserted_id ) );

                        $loop ++;
                        $file_counter ++;

                        if ( isset( $file['name'] ) ) {
                            $file_count = $file['name'];
                        } else {
                            $file_count = sprintf( __( 'File %d', 'dokan-lite' ), $file_counter );
                        }

                        include dirname( dirname( __FILE__ ) ) . '/templates/orders/order-download-permission-html.php';
                    }
                }
            }
        }

        die();
    }

    /**
     * Update a order status
     *
     * @return void
     */
    function change_order_status() {

        check_ajax_referer( 'dokan_change_status' );

        $order_id     = intval( $_POST['order_id'] );
        $order_status = $_POST['order_status'];

        $order = new WC_Order( $order_id );
        $order->update_status( $order_status );

        $statuses     = wc_get_order_statuses();
        $status_label = isset( $statuses[$order_status] ) ? $statuses[$order_status] : $order_status;
        $status_class = dokan_get_order_status_class( $order_status );

        echo '<label class="dokan-label dokan-label-' . $status_class . '">' . $status_label . '</label>';
        exit;
    }

    /**
     * Seller store page email contact form handler
     *
     * Catches the form submission from store page
     */
    function contact_seller() {
        $posted = $_POST;

        check_ajax_referer( 'dokan_contact_seller' );

        $contact_name    = sanitize_text_field( $posted['name'] );
        $contact_email   = sanitize_text_field( $posted['email'] );
        $contact_message = strip_tags( $posted['message'] );
        $error_template  = '<div class="alert alert-danger">%s</div>';

        if ( empty( $contact_name ) ) {
            $message = sprintf( $error_template, __( 'Please provide your name.', 'dokan-lite' ) );
            wp_send_json_error( $message );
        }

        if ( empty( $contact_name ) ) {
            $message = sprintf( $error_template, __( 'Please provide your name.', 'dokan-lite' ) );
            wp_send_json_error( $message );
        }

        $seller = get_user_by( 'id', (int) $posted['seller_id'] );

        if ( !$seller ) {
            $message = sprintf( $error_template, __( 'Something went wrong!', 'dokan-lite' ) );
            wp_send_json_error( $message );
        }

        Dokan_Email::init()->contact_seller( $seller->user_email, $contact_name, $contact_email, $contact_message );

        $success = sprintf( '<div class="alert alert-success">%s</div>', __( 'Email sent successfully!', 'dokan-lite' ) );
        wp_send_json_success( $success );
        exit;
    }

    function revoke_access_to_download() {
        check_ajax_referer( 'revoke-access', 'security' );

        if ( ! current_user_can( 'dokandar' ) ) {
            die(-1);
        }

        global $wpdb;

        $download_id = $_POST['download_id'];
        $product_id  = intval( $_POST['product_id'] );
        $order_id    = intval( $_POST['order_id'] );

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d AND product_id = %d AND download_id = %s;", $order_id, $product_id, $download_id ) );

        do_action( 'woocommerce_ajax_revoke_access_to_product_download', $download_id, $product_id, $order_id );

        die();
    }

    /**
     * Add order note via ajax
     */
    public function add_order_note() {

        check_ajax_referer( 'add-order-note', 'security' );

        if ( !is_user_logged_in() ) {
            die(-1);
        }
        if ( ! current_user_can( 'dokandar' ) ) {
            die(-1);
        }

        $post_id   = absint( $_POST['post_id'] );
        $note      = wp_kses_post( trim( stripslashes( $_POST['note'] ) ) );
        $note_type = $_POST['note_type'];

        $is_customer_note = $note_type == 'customer' ? 1 : 0;

        if ( $post_id > 0 ) {
            $order      = wc_get_order( $post_id );
            $comment_id = $order->add_order_note( $note, $is_customer_note );

            echo '<li rel="' . esc_attr( $comment_id ) . '" class="note ';
            if ( $is_customer_note ) {
                echo 'customer-note';
            }
            echo '"><div class="note_content">';
            echo wpautop( wptexturize( $note ) );
            echo '</div><p class="meta"><a href="#" class="delete_note">'.__( 'Delete note', 'dokan-lite' ).'</a></p>';
            echo '</li>';
        }

        // Quit out
        die();
    }

    /**
     * Add shipping tracking info via ajax
     */
    public function add_shipping_tracking_info() {

        check_ajax_referer( 'add-shipping-tracking-info', 'security' );

        if ( !is_user_logged_in() ) {
            die(-1);
        }
        if ( ! current_user_can( 'dokandar' ) ) {
            die(-1);
        }

        $post_id           = absint( $_POST['post_id'] );
        $shipping_provider = $_POST['shipping_provider'];
        $shipping_number   = ( trim( stripslashes( $_POST['shipping_number'] ) ) );
        $shipped_date      = ( trim( $_POST['shipped_date'] ) );

        $ship_info = 'Shipping provider: ' . $shipping_provider . '<br />' . 'Shipping number: ' . $shipping_number . '<br />' . 'Shipped date: ' . $shipped_date;

        if ( $shipping_number == '' ){
            die();
        }

        if ( $post_id > 0 ) {
            $order      = wc_get_order( $post_id );
            //$comment_id = $order->add_order_note( $note, $is_customer_note );

            $time = current_time('mysql');

            $data = array(
                'comment_post_ID'      => $post_id,
                'comment_author'       => 'WooCommerce',
                'comment_author_email' => '',
                'comment_author_url'   => '',
                'comment_content'      => $ship_info,
                'comment_type'         => 'order_note',
                'comment_parent'       => 0,
                'user_id'              => get_current_user_id(),
                'comment_author_IP'    => $_SERVER['REMOTE_ADDR'],
                'comment_agent'        => $_SERVER['HTTP_USER_AGENT'],
                'comment_date'         => $time,
                'comment_approved'     => 1,
            );

            $comment_id = wp_insert_comment($data);

            update_comment_meta($comment_id, 'is_customer_note', true);

            do_action( 'woocommerce_new_customer_note', array( 'order_id' => $order->id, 'customer_note' => $ship_info ) );

            echo '<li rel="' . esc_attr( $comment_id ) . '" class="note ';
            //if ( $is_customer_note ) {
                echo 'customer-note';
            //}
            echo '"><div class="note_content">';
            echo wpautop( wptexturize( $ship_info ) );
            echo '</div><p class="meta"><a href="#" class="delete_note">'.__( 'Delete', 'dokan-lite' ).'</a></p>';
            echo '</li>';
        }

        // Quit out
        die();
    }

    /**
     * Delete order note via ajax
     */
    public function delete_order_note() {

        check_ajax_referer( 'delete-order-note', 'security' );

        if ( !is_user_logged_in() ) {
            die(-1);
        }

        if ( ! current_user_can( 'dokandar' ) ) {
            die(-1);
        }

        $note_id = (int) $_POST['note_id'];

        if ( $note_id > 0 ) {
            wp_delete_comment( $note_id );
        }

        // Quit out
        die();
    }

    /**
     * Enable/disable seller selling capability from admin seller listing page
     *
     * @return type
     */
    function toggle_seller_status() {
        if ( !current_user_can( 'manage_options' ) ) {
            return;
        }

        $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0;
        $status = sanitize_text_field( $_POST['type'] );

        if ( $user_id && in_array( $status, array( 'yes', 'no' ) ) ) {
            update_user_meta( $user_id, 'dokan_enable_selling', $status );

            if ( $status == 'no' ) {
                $this->make_products_pending( $user_id );
            }
        }
        exit;
    }

    /**
     * Make all the products to pending once a seller is deactivated for selling
     *
     * @param int $seller_id
     */
    function make_products_pending( $seller_id ) {
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'author' => $seller_id,
            'orderby' => 'post_date',
            'order' => 'DESC'
        );

        $product_query = new WP_Query( $args );
        $products = $product_query->get_posts();

        if ( $products ) {
            foreach ($products as $pro) {
                wp_update_post( array( 'ID' => $pro->ID, 'post_status' => 'pending' ) );
            }
        }
    }

    /**
     * Search seller listing
     *
     * @return void
     */
    public function seller_listing_search() {
        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'dokan-seller-listing-search' ) ) {
            wp_send_json_error( __( 'Error: Nonce verification failed', 'dokan-lite' ) );
        }

        $paged  = 1;
        $limit  = 10;
        $offset = ( $paged - 1 ) * $limit;

        $seller_args = array(
            'number' => $limit,
            'offset' => $offset
        );

        $search_term = isset( $_REQUEST['search_term'] ) ? sanitize_text_field( $_REQUEST['search_term'] ) : '';
        $pagination_base = isset( $_REQUEST['pagination_base'] ) ? sanitize_text_field( $_REQUEST['pagination_base'] ) : '';

        if ( '' != $search_term ) {

            $seller_args['meta_query'] = array(

                array(
                    'key'     => 'dokan_enable_selling',
                    'value'   => 'yes',
                    'compare' => '='
                ),

                array(
                    'key'     => 'dokan_store_name',
                    'value'   => $search_term,
                    'compare' => 'LIKE'
                )

            );
        }

        $sellers = dokan_get_sellers( $seller_args );

        $template_args = apply_filters( 'dokan_store_list_args', array(
            'sellers'         => $sellers,
            'limit'           => $limit,
            'paged'           => $paged,
            'image_size'      => 'medium',
            'search'          => 'yes',
            'pagination_base' => $pagination_base,
            'search_query'    => $search_term,
        ) );

        ob_start();
        dokan_get_template_part( 'store-lists-loop', false, $template_args );
        $content = ob_get_clean();

        wp_send_json_success( $content );
    }

    /**
     * Gets attachment uploaded by Media Manager, crops it, then saves it as a
     * new object. Returns JSON-encoded object details.
     *
     * @since 2.5
     *
     * @return void
     */
    public function crop_store_banner() {
        check_ajax_referer( 'image_editor-' . $_POST['id'], 'nonce' );

        if ( !current_user_can( 'edit_post', $_POST['id'] ) ) {
            wp_send_json_error();
        }

        $crop_details = $_POST['cropDetails'];

        $dimensions = $this->get_header_dimensions( array(
            'height' => $crop_details['height'],
            'width'  => $crop_details['width'],
        ) );

        $attachment_id = absint( $_POST['id'] );

        $cropped = wp_crop_image(
            $attachment_id,
            (int) $crop_details['x1'],
            (int) $crop_details['y1'],
            (int) $crop_details['width'],
            (int) $crop_details['height'],
            (int) $dimensions['dst_width'],
            (int) $dimensions['dst_height']
        );

        if ( ! $cropped || is_wp_error( $cropped ) ) {
            wp_send_json_error( array( 'message' => __( 'Image could not be processed. Please go back and try again.' ) ) );
        }

        /** This filter is documented in wp-admin/custom-header.php */
        $cropped = apply_filters( 'wp_create_file_in_uploads', $cropped, $attachment_id ); // For replication

        $object = $this->create_attachment_object( $cropped, $attachment_id );

        unset( $object['ID'] );

        $new_attachment_id = $this->insert_attachment( $object, $cropped );

        $object['attachment_id'] = $new_attachment_id;
        $object['url']           = wp_get_attachment_url( $new_attachment_id );;
        $object['width']         = $dimensions['dst_width'];
        $object['height']        = $dimensions['dst_height'];

        wp_send_json_success( $object );
    }

     /**
     * Calculate width and height based on what the currently selected theme supports.
     *
     * @since 2.5
     *
     * @param array $dimensions
     *
     * @return array dst_height and dst_width of header image.
     */
    final public function get_header_dimensions( $dimensions ) {
        $general_settings = get_option( 'dokan_general', [] );

        $max_width = 0;
        $width = absint( $dimensions['width'] );
        $height = absint( $dimensions['height'] );
        $theme_width = ! empty( $general_settings['store_banner_width'] ) ? $general_settings['store_banner_width'] : 625;
        $theme_height = ! empty( $general_settings['store_banner_height'] ) ? $general_settings['store_banner_height'] : 300;
        $has_flex_width = ! empty( $general_settings['store_banner_flex_width'] ) ? $general_settings['store_banner_flex_width'] : true;
        $has_flex_height = ! empty( $general_settings['store_banner_flex_height'] ) ? $general_settings['store_banner_flex_height'] : true;
        $has_max_width = ! empty( $general_settings['store_banner_max_width'] ) ? $general_settings['store_banner_max_width'] : false;
        $dst = array( 'dst_height' => null, 'dst_width' => null );

        // For flex, limit size of image displayed to 1500px unless theme says otherwise
        if ( $has_flex_width ) {
            $max_width = 625;
        }

        if ( $has_max_width ) {
            $max_width = max( $max_width, get_theme_support( 'custom-header', 'max-width' ) );
        }
        $max_width = max( $max_width, $theme_width );

        if ( $has_flex_height && ( ! $has_flex_width || $width > $max_width ) ) {
            $dst['dst_height'] = absint( $height * ( $max_width / $width ) );
        }
        elseif ( $has_flex_height && $has_flex_width ) {
            $dst['dst_height'] = $height;
        }
        else {
            $dst['dst_height'] = $theme_height;
        }

        if ( $has_flex_width && ( ! $has_flex_height || $width > $max_width ) ) {
            $dst['dst_width'] = absint( $width * ( $max_width / $width ) );
        }
        elseif ( $has_flex_width && $has_flex_height ) {
            $dst['dst_width'] = $width;
        }
        else {
            $dst['dst_width'] = $theme_width;
        }

        return $dst;
    }

    /**
     * Create an attachment 'object'.
     *
     * @since 2.5
     *
     * @param string $cropped              Cropped image URL.
     * @param int    $parent_attachment_id Attachment ID of parent image.
     *
     * @return array Attachment object.
     */
    final public function create_attachment_object( $cropped, $parent_attachment_id ) {
        $parent = get_post( $parent_attachment_id );
        $parent_url = wp_get_attachment_url( $parent->ID );
        $url = str_replace( basename( $parent_url ), basename( $cropped ), $parent_url );

        $size = @getimagesize( $cropped );
        $image_type = ( $size ) ? $size['mime'] : 'image/jpeg';

        $object = array(
            'ID' => $parent_attachment_id,
            'post_title' => basename($cropped),
            'post_mime_type' => $image_type,
            'guid' => $url,
            'context' => 'custom-header'
        );

        return $object;
    }


    /**
     * Insert an attachment and its metadata.
     *
     * @since 2.5
     *
     * @param array  $object  Attachment object.
     * @param string $cropped Cropped image URL.
     *
     * @return int Attachment ID.
     */
    final public function insert_attachment( $object, $cropped ) {
        $attachment_id = wp_insert_attachment( $object, $cropped );
        $metadata = wp_generate_attachment_metadata( $attachment_id, $cropped );

        $metadata = apply_filters( 'wp_header_image_attachment_metadata', $metadata );
        wp_update_attachment_metadata( $attachment_id, $metadata );
        return $attachment_id;
    }

}

