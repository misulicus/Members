<?php
if ( !class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Subscriptions_List_Table extends WP_List_Table {

    function __construct(){
        global $status, $page;

        //Set parent defaults
        parent::__construct( array(
            'singular'  => 'subscription',     //singular name of the listed records
            'plural'    => 'subscriptions',    //plural name of the listed records
            'ajax'      => false        //does this table support ajax?
        ) );

    }

    function column_name( $item ) {
        //Build row actions
        $actions = array(
            'delete'    => sprintf( '<a href="admin.php?page=wpec_members&tab=wpec_manage_subscriptions&action=%s&subscription=%s">' . __('Delete', 'wpsc_members') . '</a>','delete', urlencode( $item['name'] ) ),
            'edit'      => sprintf( '<a href="admin.php?page=wpec_members&tab=wpec_edit_subscription&action=%s&subscription=%s">' . __('Edit', 'wpsc_members') . '</a>','edit', urlencode( $item['name'] ) ),
        );

        //Return the title contents
        return $item['name'] . $this->row_actions( $actions );
    }

    function column_message( $item ) {
        if ( empty( $item['message'] ) )
            return __('No custom message added', 'wpsc_members');

        return stripslashes( $item['message'] );
    }

   function column_default( $item, $column_name ) {
        return $item[ $column_name ];
   }

   function get_columns() {
        $columns = array(
            'name'              => __('Display Name', 'wpsc_members'),
            'internal_name'     => __('Internal Name', 'wpsc_members'),
            'message'           => __('Permission Message', 'wpsc_members'),
            'linked_products'   => __('Linked Products', 'wpsc_members')
        );

        return $columns;
   }

   function process_bulk_action() {
        $subscription_name = isset ( $_GET['subscription'] ) ? $_GET['subscription'] : '';

        global $wpsc_product_capability_list;

        //the $capability_name is the value['name'] find the key as this is the key in the global wpsc_prod..
        //@TODO Get rid of this mess and impliment some sort of ID's for capabilities
        $capability = '';

        if ( ! empty( $wpsc_product_capability_list ) ) {
            foreach ( $wpsc_product_capability_list as  $key => $value ) {
                if ( $value['name'] == $subscription_name ) {
                    $capability = $key;
                    continue;
                }
            }
        }

        //detect when the delete_all bulk action has been triggered
        //this will remove all the users caps however it currently does not refresh the page
        if( 'delete' === $this->current_action() )
            wpec_members_delete_subscription( $capability );

    }

    function prepare_items() {
        global $wpdb, $wpsc_product_capability_list;

        $subscriptions = $wpsc_product_capability_list;

        $per_page = 40;
        $total_items = count( $wpsc_product_capability_list );

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $this->process_bulk_action();

        $this->_column_headers = array( $columns, $hidden, $sortable );
        $current_page = $this->get_pagenum();

        $end_limit = $per_page;
        $start_limit = ( ( $current_page - 1 ) * $per_page );

        $data = array();
        $i=0;

        if ( ! empty( $subscriptions ) ) {
            foreach ( $subscriptions as $internal_name => $subscription ) {

                $data[ $i ]['internal_name'] = $internal_name;
                $data[ $i ]['name'] = $subscription['name'];
                $data[ $i ]['message'] = $subscription['message-details'];

                $query = 'SELECT p.ID FROM ' . $wpdb->postmeta . ' AS pm LEFT JOIN ' . $wpdb->posts . ' AS p ON pm.post_id = p.ID WHERE pm.meta_key = "_wpsc_product-capabilities" AND meta_value LIKE %s';
                $prepared_query = $wpdb->prepare( $query, '%"' . $internal_name . '"%' );
                $products = $wpdb->get_col( $prepared_query );

                $linked_products = array();

                foreach ( $products as $product_id ) {
                    $product_title = get_the_title( $product_id );
                    $link = get_edit_post_link( $product_id );
                    $linked_products[] = sprintf( '<a href="%1$s">%2$s</a>', $link, $product_title );
                }

                $data[ $i ]['linked_products'] = implode(', ', $linked_products);

                //$data[ $i ]['type'] = $subscription['capability-type'];
                $i++;
            }
        }

       /* set the sorted data items for the table display */
        $this->items = $data;

        /**
         * REQUIRED. We also have to register our pagination options & calculations.
         */
        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
            'total_pages' => ceil( $total_items/$per_page )   //WE have to calculate the total number of pages
        ) );
    }

}