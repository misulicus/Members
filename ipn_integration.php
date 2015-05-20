<?php
/**
 * Handles IPN responses from PayPal - adds recurring subscriptions to the database.  Also sends subsequent admin email.
 * Goes against DRY, but replicating the email here is perhaps the least buggy way to go.
 *
 * @global type $wpdb
 * @param integer $cart_item_id
 * @param object $merchant
 */

function wpec_members_pre_gateway_notification( $cart_item_id, $merchant ) {
  global $wpdb;

  $ipn = $merchant->paypal_ipn_values;
  $purchase_id = $wpdb->get_var( "SELECT purchaseid FROM `" . WPSC_TABLE_CART_CONTENTS . "` WHERE `id` = " . $cart_item_id );
  $transaction_id = isset( $ipn['subscr_id'] ) ? $ipn['subscr_id'] : $ipn['recurring_payment_id'];

  //Handle initial transaction - updates transaction ID with subscription ID

  if ( 'recurring_payment_profile_created' == $ipn['txn_type'] || 'subscr_signup' == $ipn['txn_type'] ) {
    $wpdb->query( "UPDATE `" . WPSC_TABLE_PURCHASE_LOGS . "` SET `transactid` ='{$transaction_id}'  WHERE `id` = " . absint( $purchase_id ) . " LIMIT 1" );
  }

  // Handle additional monthly transactions.  Checks for recency, as this will also get hit on the initial.
  // We basically check amount, first/last name, email and recency, then duplicate purchase log

  if( 'recurring_payment' == $ipn['txn_type'] || 'subscr_payment' == $ipn['txn_type'] ) {

    $original_transaction = $wpdb->get_row( "SELECT * FROM " . WPSC_TABLE_PURCHASE_LOGS . " WHERE transactid = '" . $transaction_id . "' ORDER BY id LIMIT 1" );

    //This should never happen.
    if( empty( $original_transaction ) )
      return;

    //Check to ensure time was not within one hour of original transaction
    //This should ensure we're dealing with subsequent transactions, not the original
    if( $original_transaction->date > ( time() - ( 3600 ) ) )
      return;

    //Duplicate Purchase Log
    $wpdb->insert( WPSC_TABLE_PURCHASE_LOGS, array(
      'totalprice' => $original_transaction->totalprice,
      'statusno' => $original_transaction->statusno,
      'user_ID' => $original_transaction->user_ID,
      'sessionid' => uniqid( 'monthly_', true ),
      'processed' => '3',
      'transactid' => $original_transaction->transactid,
      'date' => strtotime( current_time( 'mysql' ) ),
      'gateway' => $original_transaction->gateway,
      'billing_country' => $original_transaction->billing_country,
      'shipping_country' => $original_transaction->shipping_country,
      'billing_region' => $original_transaction->billing_region,
      'shipping_region' => $original_transaction->shipping_region,
      'base_shipping' => $original_transaction->base_shipping,
      'shipping_method' => $original_transaction->shipping_method,
      'shipping_option' => $original_transaction->shipping_option,
      'plugin_version' => WPSC_VERSION,
      'discount_value' => $original_transaction->discount_value,
      'discount_data' => $original_transaction->discount_data,
      'find_us' => $original_transaction->find_us,
      'wpec_taxes_total' => $original_transaction->wpec_taxes_total,
      'wpec_taxes_rate' => $original_transaction->wpec_taxes_rate
    ) );

    $new_id = $wpdb->insert_id;

    //Duplicate Submitted Form Data

    $old_cart_contents = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . WPSC_TABLE_SUBMITED_FORM_DATA . " WHERE log_id = %d", $original_transaction->id ) );

    foreach ( $old_cart_contents as $row ) {

      $wpdb->insert(
          WPSC_TABLE_SUBMITED_FORM_DATA,
          array(
            'log_id' => $new_id,
            'form_id' => $row->form_id,
            'value' => $row->value
          )
        );
    }

    //Duplicate cart contents

    $old_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . WPSC_TABLE_CART_CONTENTS . " WHERE purchaseid = %d", $original_transaction->id ) );
    $wpdb->insert(
        WPSC_TABLE_CART_CONTENTS,
        array(
          'prodid' => $old_row->prodid,
          'name' => $old_row->name,
          'purchaseid' => $new_id,
          'price' => $old_row->price,
          'quantity' => $old_row->quantity,
          'donation' => '1'
        )
      );

    //Email product list email to admin

    wpec_members_recurring_admin_report( $purchase_id );

  }


}

add_action( 'wpsc_activated_subscription', 'wpec_members_pre_gateway_notification', 10, 2 );

/**
 * Filters subscription number and first_time text.  Because of the email_sent check at the end of transaction_results, this filter only ever applies to the first email
 * With that, we can easily replace the sub number with 1, and the first time text legitimately.  We'll use the activated_subscription hook for recurring payments to filter
 * the subsequent emails properly.
 *
 * @param string $message
 * @return string $message
 */
function wpec_members_filter_tags( $message ) {

  $first_time_text = __(get_option( 'wpec_members_first_time_text', 'Plus handle on first shipping date' ), 'wpsc_members');
  $first_time_text = '<h3 style="color:red">' . $first_time_text . '</h3>';

  $message = str_replace( '%subscription_number%', '1', $message );
  $message = str_replace( '%first_time_text%', $first_time_text, $message );

  return $message;

}

add_filter( 'wpsc_transaction_result_report', 'wpec_members_filter_tags' );

function wpec_members_add_first_time_text() {

  $first_time = __(get_option( 'wpec_members_first_time_text', 'Plus handle on first shipping date' ), 'wpsc_members');
    ?>
    <tr>
      <th scope="row">
      <?php _e('Text to be sent on initial subscription email', 'wpsc_members'); ?>:
      </th>
      <td>
        <input type='text' value='<?php echo esc_attr( $first_time ); ?>' name='wpsc_options[wpec_members_first_time_text]' id='wpec_members_first_time_text' />
      </td>
    </tr>
  <?php
}

add_action( 'wpsc_admin_settings_page', 'wpec_members_add_first_time_text' );


/**
 * Emails admin on subsequent recurring transactions
 * Anti-DRY code here - duplicating much of transaction_results and filter_tags.  Actually the least buggy way with the current architecture.
 * Lots of leftover cruft, but it shouldn't hurt anything.
 *
 * @param int $purchase_id
 * @return none
 */

function wpec_members_recurring_admin_report( $purchase_id ) {
  global $wpdb;

    $purchase_id = absint( $purchase_id );

    $purchase_log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `id`= %s LIMIT 1", $purchase_id ), ARRAY_A );
    $cart = $wpdb->get_results( "SELECT * FROM `" . WPSC_TABLE_CART_CONTENTS . "` WHERE `purchaseid` = '{$purchase_id}'" , ARRAY_A );
    $transactid = $purchase_log['transactid'];
    $total_logs = $wpdb->get_var( "SELECT COUNT(*) FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `transactid` = '{$transactid}'" );

    $wpec_taxes_controller = new wpec_taxes_controller();

    $total_shipping = '';
    foreach ( $cart as $row ) {

      do_action( 'wpsc_transaction_result_cart_item', array( "purchase_id" => $purchase_log['id'], "cart_item" => $row, "purchase_log" => $purchase_log ) );
      do_action( 'wpsc_confirm_checkout', $purchase_log['id'] );

      $total = 0;
      $shipping = $row['pnp'];
      $total_shipping += $shipping;

      $total += ( $row['price'] * $row['quantity'] );
      $message_price = wpsc_currency_display( $total, array( 'display_as_html' => false ) );
      $shipping_price = wpsc_currency_display( $shipping, array( 'display_as_html' => false ) );

      $variation_list = '';


        // RK Mods : Make subscription plan price message more friendly

      $product_list .= "" . $row['quantity'] . " x " . $row['name'] . " subscription @ " . $message_price . " per month.\n\r";
      if ( $shipping > 0 )
        $product_list .= sprintf(__( ' - Shipping: %s ', 'wpsc_members' ), $shipping_price);

        // RK Mods : Make subscription plan price message more friendly

      $product_list_html .= "\n\r" . $row['quantity'] . " x " . $row['name'] . " subscription @ " . $message_price_html . " per month.\n\r";
      if ( $shipping > 0 )
          $product_list_html .=  sprintf(__( ' &nbsp; Shipping: %s ', 'wpsc_members' ), $shipping_price);

      //add tax if included
      if ( $wpec_taxes_controller->wpec_taxes_isenabled() && $wpec_taxes_controller->wpec_taxes_isincluded() ) {
        $taxes_text = ' - - ' . __('Tax Included', 'wpsc_members') . ': ' . wpsc_currency_display( $row['tax_charged'], array( 'display_as_html' => false ) ) . "\n\r";
        $taxes_text_html = ' - - ' . __('Tax Included', 'wpsc_members') . ': ' . wpsc_currency_display( $row['tax_charged'] );
        $product_list .= $taxes_text;
        $product_list_html .= $taxes_text_html;
      }// if

      $report = get_option( 'wpsc_email_admin' );

        // RK Mods : Make subscription plan price message more friendly

      $report_product_list .= "" . $row['quantity'] . " x " . $row['name'] . " subscription @ " . $message_price . " per month.\n\r";
    } // closes foreach cart as row

    $total_shipping += $purchase_log['base_shipping'];

    $total = $purchase_log['totalprice'];

    $total_price_email = '';
    $total_price_html = '';
    $total_tax_html = '';
    $total_tax = '';
    $total_shipping_html = '';
    $total_shipping_email = '';
    if ( wpsc_uses_shipping() )
      $total_shipping_email .= sprintf(__( 'Total Shipping: %s ', 'wpsc_members' ), wpsc_currency_display( $total_shipping, array( 'display_as_html' => false ) ) );
    $total_price_email .= sprintf(__( 'Total: %s ', 'wpsc_members' ), wpsc_currency_display( $total, array( 'display_as_html' => false ) ));
    if ( $purchase_log['discount_value'] > 0 ) {
      $discount_email = __( 'Discount', 'wpsc_members' ) . "\n\r: ";
      $discount_email .= $purchase_log['discount_data'] . ' : ' . wpsc_currency_display( $purchase_log['discount_value'], array( 'display_as_html' => false ) ) . "\n\r";

      $report .= $discount_email . "\n\r";
      $total_shipping_email .= $discount_email;
      $total_shipping_html .= __( 'Discount', 'wpsc_members' ) . ": " . wpsc_currency_display( $purchase_log['discount_value'] ) . "\n\r";
    }

    //only show total tax if tax is not included
    if ( $wpec_taxes_controller->wpec_taxes_isenabled() && ! $wpec_taxes_controller->wpec_taxes_isincluded() ) {
      $total_tax_html .= __('Total Tax', 'wpsc_members') . ': ' . wpsc_currency_display( $purchase_log['wpec_taxes_total'] ) . "\n\r";
      $total_tax .= __('Total Tax', 'wpsc_members') . ': ' . wpsc_currency_display( $purchase_log['wpec_taxes_total'] , array( 'display_as_html' => false ) )."\n\r";
    }
    if ( wpsc_uses_shipping() )
      $total_shipping_html .= '<hr>' . sprintf(__( 'Total Shipping: %s ', 'wpsc_members' ), wpsc_currency_display( $total_shipping ));
    $total_price_html .= sprintf( __( 'Total: %s ', 'wpsc_members' ), wpsc_currency_display( $total ) );
    $report_id = sprintf( __("Purchase # %s ", 'wpsc_members'), $purchase_log['id'] );

    // remember to document these on Notifications page
    $report = str_replace( '%purchase_id%', $report_id, $report );
    $report = str_replace( '%product_list%', $report_product_list, $report );
    $report = str_replace( '%total_tax%', $total_tax, $report );
    $report = str_replace( '%total_shipping%', $total_shipping_email, $report );
    $report = str_replace( '%total_price%', $total_price_email, $report );
    $report = str_replace( '%shop_name%', get_option( 'blogname' ), $report );
    $report = str_replace( '%find_us%', $purchase_log['find_us'], $report );
    $report = str_replace( '%subscription_number%', $total_logs, $report );
    $report = str_replace( '%first_time_text%', '', $report );

    $form_sql = "SELECT * FROM `" . WPSC_TABLE_SUBMITED_FORM_DATA . "` WHERE `log_id` = '" . $purchase_log['id'] . "'";
    $form_data = $wpdb->get_results( $form_sql, ARRAY_A );

    if ( $form_data != null ) {
      foreach ( $form_data as $form_field ) {
        $form_data = $wpdb->get_row( "SELECT * FROM `" . WPSC_TABLE_CHECKOUT_FORMS . "` WHERE `id` = '" . $form_field['form_id'] . "' LIMIT 1", ARRAY_A );

        switch ( $form_data['type'] ) {
          case "country":
            $country_code = $form_field['value'];
            $billing_country = wpsc_get_country( $country_code ) . "\n";

            $country_data = wpsc_country_has_state( $country_code );
            if ( ( $country_data['has_regions'] == 1 ) )
              $billing_state .=  wpsc_get_region( $purchase_log['billing_region'] ) . "\n";
            break;

          case "delivery_country":
            $delivery_country = wpsc_get_country( $form_field['value'] ) . "\n";
            break;
          default:

            if ( ( $form_data['unique_name'] == 'shippingstate' ||  $form_data['unique_name'] == 'billingstate' ) && is_numeric( $form_field['value'] ) ) {
              $report_user .= wpsc_get_state_by_id( $form_field['value'], 'name' ) . "\n";
            } else {

              if ( $form_data['unique_name'] == 'billingfirstname' )
                $report_name = "\n" . __('=== Billing Address ===', 'wpsc_members') . "\n\n" . $form_field['value'];
              else if ( $form_data['unique_name'] == 'billinglastname' )
                $report_name .= ' ' . $form_field['value'] . "\n";
              else if ( stristr( $form_data['unique_name'], 'billing' ) && $form_data['unique_name'] != 'billingemail' && $form_data['unique_name'] != 'billingphone' )
                $report_billing .= $form_field['value'] . "\n";
              else if ( $form_data['unique_name'] == 'billingemail' )
                $report_contact = $form_field['value'] . "\n";
              else if ( $form_data['unique_name'] == 'billingphone' )
                $report_contact .= $form_field['value'] . "\n\n" . __('=== Shipping Address ===', 'wpsc_members') . "\n\n";
              else if ( $form_data['unique_name'] == 'shippingfirstname' )
                $report_sname .= $form_field['value'];
              else if ( $form_data['unique_name'] == 'shippinglastname' )
                $report_sname .= ' ' . $form_field['value'] . "\n";
              else if ( $form_data['unique_name'] == 'shippingpostcode' )
                $report_user .= $form_field['value'];
              else
                $report_user .= $form_field['value'] . "\n";

            }

          break;
        }
      }
    }

    $report_user .= "\n\r";
    $report = $report_id . $report_name . $report_billing . $billing_state . $billing_country . $report_contact . $report_sname .  $report_user . $delivery_country . $report;

    wp_mail( get_option( 'purch_log_email' ), __( 'Purchase Report', 'wpsc_members' ), $report );

}

/*
 * Revalidates is_valid_ipn_response() assuming its a subscription request
 * Not all post fields are sent back in a subscription
 * @return bool
 */
 
function wpec_members_subscr_ipn_validation( $valid, $ipn ) {
	
	if ( ! $valid ) {
		switch ( $ipn->paypal_ipn_values['txn_type'] ) {
			case 'subscr_cancel':
				$valid = true;
				break;
				
			case 'subscr_signup':
				$valid = true;
				break;			
			default:
				break;
		}
	}
	
	return $valid;
}
add_filter( 'wpsc_paypal_standard_is_valid_ipn_response', 'wpec_members_subscr_ipn_validation', 10, 2 );
?>