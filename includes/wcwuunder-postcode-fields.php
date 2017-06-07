<?php
if (!class_exists('WC_Wuunder_Postcode_Fields')) {
    class WC_Wuunder_Postcode_Fields
    {

        public function __construct()
        {
            // Load styles & scripts
            add_action('wp_enqueue_scripts', array(&$this, 'add_styles_scripts'));

            // Add street name & house number checkout fields.
            if (version_compare(WOOCOMMERCE_VERSION, '2.0') >= 0) {
                // WC 2.0 or newer is used, the filter got a $coutry parameter, yay!
                add_filter('woocommerce_billing_fields', array(&$this, 'billing_fields'), 10, 2);
                add_filter('woocommerce_shipping_fields', array(&$this, 'shipping_fields'), 10, 2);
            } else {
                // Backwards compatibility
                add_filter('woocommerce_billing_fields', array(&$this, 'billing_fields'));
                add_filter('woocommerce_shipping_fields', array(&$this, 'shipping_fields'));
            }


            // Hide state field for countries without states (backwards compatible fix for bug #4223)
            add_filter('woocommerce_countries_allowed_country_states', array(&$this, 'hide_states'));

            // Localize checkout fields (limit custom checkout fields to NL)
            add_filter('woocommerce_default_address_fields', array(&$this, 'default_address_fields'));

            // Load custom order data.
            add_filter('woocommerce_load_order_data', array(&$this, 'load_order_data'));

            // Custom shop_order details.
            add_filter('woocommerce_admin_billing_fields', array(&$this, 'admin_billing_fields'));
            add_filter('woocommerce_admin_shipping_fields', array(&$this, 'admin_shipping_fields'));
            add_filter('woocommerce_found_customer_details', array($this, 'customer_details_ajax'));
            add_action('save_post', array(&$this, 'save_custom_fields'));

            // Processing checkout
            add_action('woocommerce_checkout_update_order_meta', array(&$this, 'merge_street_number_suffix'), 20, 2);
            add_filter('woocommerce_process_checkout_field_billing_postcode', array(&$this, 'clean_billing_postcode'));
            add_filter('woocommerce_process_checkout_field_shipping_postcode', array(&$this, 'clean_shipping_postcode'));

            // Save the order data in WooCommerce 2.2 or later.
            if (version_compare(WOOCOMMERCE_VERSION, '2.2') >= 0) {
                add_action('woocommerce_checkout_update_order_meta', array(&$this, 'save_order_data'), 10, 2);
            }

            // Remove placeholder values (IE8 & 9)
            add_action('woocommerce_checkout_update_order_meta', array(&$this, 'remove_placeholders'), 10, 2);

            $this->load_woocommerce_filters();
        }

        public function load_woocommerce_filters()
        {
            // Custom address format.
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.6', '>=')) {
                add_filter('woocommerce_localisation_address_formats', array($this, 'localisation_address_formats'));
                add_filter('woocommerce_formatted_address_replacements', array($this, 'formatted_address_replacements'), 1, 2);
                add_filter('woocommerce_order_formatted_billing_address', array($this, 'order_formatted_billing_address'), 1, 2);
                add_filter('woocommerce_order_formatted_shipping_address', array($this, 'order_formatted_shipping_address'), 1, 2);
                add_filter('woocommerce_user_column_billing_address', array($this, 'user_column_billing_address'), 1, 2);
                add_filter('woocommerce_user_column_shipping_address', array($this, 'user_column_shipping_address'), 1, 2);
                add_filter('woocommerce_my_account_my_address_formatted_address', array($this, 'my_account_my_address_formatted_address'), 1, 3);
            }

        }

        /**
         * Load styles & scripts.
         */
        public function add_styles_scripts()
        {
            if (is_checkout() || is_account_page()) {
                if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<=')) {
                    // Backwards compatibility for https://github.com/woothemes/woocommerce/issues/4239
                    wp_register_script('wuunder-checkout', (dirname(plugin_dir_url(__FILE__)) . '/assets/js/nwuunder-checkout.js'), array('wc-checkout'));
                    wp_enqueue_script('wuunder-checkout');
                }

                wp_register_script('wuunder-checkout', (dirname(plugin_dir_url(__FILE__)) . '/assets/js/wuunder-checkout-wuunder.js'), array('wc-checkout'));
                wp_enqueue_script('wuunder-checkout');

                if (is_account_page()) {
                    // Disable regular address fields for NL on account page - Fixed in WC 2.1 but not on init...
                    wp_register_script('wuunder-account-page', (dirname(plugin_dir_url(__FILE__)) . '/assets/js/wuunder-account-page.js'), array('jquery'));
                    wp_enqueue_script('wuunder-account-page');
                }

                wp_enqueue_style('wuunder-checkout', (dirname(plugin_dir_url(__FILE__)) . '/assets/css/wuunder-checkout.css'));
            }

        }

        public function billing_fields($fields, $country = '')
        {
            return $this->checkout_fields($fields, $country, 'billing');
        }

        public function shipping_fields($fields, $country = '')
        {
            return $this->checkout_fields($fields, $country, 'shipping');
        }

        public function checkout_fields($fields, $country, $form)
        {
            if (isset($fields['_country'])) {
                // some weird bug on the my account page
                $form = '';
            }

            $fields[$form . '_address_1'] = array(
                'required' => false,
                'hidden' => true,
            );

            $fields[$form . '_address_2'] = array(
                'hidden' => true,
            );

            $fields[$form . '_state'] = array(
                'hidden' => true,
                'required' => false,
            );

            // Add Street name
            $fields[$form . '_street_name'] = array(
                'label' => __('Straat', 'woocommerce-wuunder'),
                'placeholder' => __('Straat', 'woocommerce-wuunder'),
                'class' => array('form-row-first'),
                'required' => true,
                'hidden' => false,
            );

            // Add house number
            $fields[$form . '_house_number'] = array(
                'label' => __('Nr.', 'woocommerce-wuunder'),
                'class' => array('form-row-quart-first'),
                'required' => true,
                'hidden' => false,
            );

            // Add house number Suffix
            $fields[$form . '_house_number_suffix'] = array(
                'label' => __('Suffix', 'woocommerce-wuunder'),
                'class' => array('form-row-quart'),
                'required' => false,
                'hidden' => false,
            );

            // Create new ordering for checkout fields
            $order_keys = array(
                $form . '_country',
                $form . '_first_name',
                $form . '_last_name',
                $form . '_company',
                $form . '_address_1',
                $form . '_address_2',
                $form . '_street_name',
                $form . '_house_number',
                $form . '_house_number_suffix',
                $form . '_postcode',
                $form . '_city',
                $form . '_state',
            );

            if ($form == 'billing') {
                array_push($order_keys,
                    $form . '_email',
                    $form . '_phone'
                );
            }

            $new_order = array();

            // Create reordered array and fill with old array values
            foreach ($order_keys as $key) {
                $new_order[$key] = $fields[$key];
            }

            // Merge (&overwrite) field array
            $fields = array_merge($new_order, $fields);

            return $fields;
        }

        /**
         * Hide state field for countries without states (backwards compatible fix for WooCommerce bug #4223)
         * @param  array $allowed_states states per country
         * @return array
         */
        public function hide_states($allowed_states)
        {

            $hidden_states = array(
                'AF' => array(),
                'AT' => array(),
                'BE' => array(),
                'BI' => array(),
                'CZ' => array(),
                'DE' => array(),
                'DK' => array(),
                'FI' => array(),
                'FR' => array(),
                'HU' => array(),
                'IS' => array(),
                'IL' => array(),
                'KR' => array(),
                'NL' => array(),
                'NO' => array(),
                'PL' => array(),
                'PT' => array(),
                'SG' => array(),
                'SK' => array(),
                'SI' => array(),
                'LK' => array(),
                'SE' => array(),
                'VN' => array(),
            );
            $states = $hidden_states + $allowed_states;

            return $states;
        }

        /**
         * Localize checkout fields live
         * @param  array $locale_fields list of fields filtered by locale
         * @return array $locale_fields with custom fields added
         */
        public function country_locale_field_selectors($locale_fields)
        {
            $custom_locale_fields = array(
                'street_name' => '#billing_street_name_field, #shipping_street_name_field',
                'house_number' => '#billing_house_number_field, #shipping_house_number_field',
                'house_number_suffix' => '#billing_house_number_suffix_field, #shipping_house_number_suffix_field',
            );

            $locale_fields = array_merge($locale_fields, $custom_locale_fields);

            return $locale_fields;
        }

        /**
         * Make NL checkout fields hidden by default
         * @param  array $fields default checkout fields
         * @return array $fields default + custom checkoud fields
         */
        public function default_address_fields($fields)
        {
            $custom_fields = array(
                'address_1' => array(
                    'hidden' => true,
                    'required' => false,
                ),
                'address_2' => array(
                    'hidden' => true,
                    'required' => false,
                ),
                'state' => array(
                    'hidden' => true,
                    'required' => false,
                ),
                'house_number_suffix' => array(
                    'hidden' => false,
                    'required' => false,
                ),
                'street_name' => array(
                    'hidden' => false,
                    'required' => false,
                ),
                'house_number' => array(
                    'hidden' => false,
                    'required' => false,
                ),
                'house_number_suffix' => array(
                    'hidden' => false,
                    'required' => false,
                ),

            );

            $fields = array_merge($fields, $custom_fields);

            return $fields;
        }

        /**
         * Load order custom data.
         *
         * @param  array $data Default WC_Order data.
         * @return array       Custom WC_Order data.
         */
        public function load_order_data($data)
        {

            // Billing
            $data['billing_street_name'] = '';
            $data['billing_house_number'] = '';
            $data['billing_house_number_suffix'] = '';

            // Shipping
            $data['shipping_street_name'] = '';
            $data['shipping_house_number'] = '';
            $data['shipping_house_number_suffix'] = '';

            return $data;
        }

        /**
         * Custom billing admin edit fields.
         *
         * @param  array $fields Default WC_Order data.
         * @return array         Custom WC_Order data.
         */
        public function admin_billing_fields($fields)
        {

            $fields['street_name'] = array(
                'label' => __('Street name', 'woocommerce-wuunder'),
                'show' => true
            );

            $fields['house_number'] = array(
                'label' => __('Number', 'woocommerce-wuunder'),
                'show' => true
            );

            $fields['house_number_suffix'] = array(
                'label' => __('Suffix', 'woocommerce-wuunder'),
                'show' => true
            );

            return $fields;
        }

        /**
         * Custom shipping admin edit fields.
         *
         * @param  array $fields Default WC_Order data.
         * @return array         Custom WC_Order data.
         */
        public function admin_shipping_fields($fields)
        {

            $fields['street_name'] = array(
                'label' => __('Street name', 'woocommerce-wuunder'),
                'show' => true
            );

            $fields['house_number'] = array(
                'label' => __('Number', 'woocommerce-wuunder'),
                'show' => true
            );

            $fields['house_number_suffix'] = array(
                'label' => __('Suffix', 'woocommerce-wuunder'),
                'show' => true
            );

            return $fields;
        }

        /**
         * Add custom fields in customer details ajax.
         * called when clicking the "Load billing/shipping address" button on Edit Order view
         *
         * @return void
         */
        public function customer_details_ajax($customer_data)
        {
            $user_id = (int)trim(stripslashes($_POST['user_id']));
            $type_to_load = esc_attr(trim(stripslashes($_POST['type_to_load'])));

            $custom_data = array(
                $type_to_load . '_street_name' => get_user_meta($user_id, $type_to_load . '_street_name', true),
                $type_to_load . '_house_number' => get_user_meta($user_id, $type_to_load . '_house_number', true),
                $type_to_load . '_house_number_suffix' => get_user_meta($user_id, $type_to_load . '_house_number_suffix', true),
            );

            return array_merge($customer_data, $custom_data);
        }

        /**
         * Save custom fields from admin.
         */
        public function save_custom_fields($post_id)
        {
            global $post_type;
            if ($post_type == 'shop_order' && !empty($_POST)) {
                update_post_meta($post_id, '_billing_street_name', stripslashes($_POST['_billing_street_name']));
                update_post_meta($post_id, '_billing_house_number', stripslashes($_POST['_billing_house_number']));
                update_post_meta($post_id, '_billing_house_number_suffix', stripslashes($_POST['_billing_house_number_suffix']));

                update_post_meta($post_id, '_shipping_street_name', stripslashes($_POST['_shipping_street_name']));
                update_post_meta($post_id, '_shipping_house_number', stripslashes($_POST['_shipping_house_number']));
                update_post_meta($post_id, '_shipping_house_number_suffix', stripslashes($_POST['_shipping_house_number_suffix']));
            }
            return;
        }

        /**
         * Merge streetname, street number and street suffix into the default 'address_1' field
         *
         * @param  string $order_id Order ID of checkout order.
         * @return void
         */
        public function merge_street_number_suffix($order_id)
        {
            // file_put_contents('postdata.txt', print_r($_POST,true)); // for debugging
            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<=')) {
                // old versions use 'shiptobilling'
                $ship_to_different_address = isset($_POST['shiptobilling']) ? false : true;
            } else {
                // WC2.1
                $ship_to_different_address = isset($_POST['ship_to_different_address']) ? true : false;
            }

            // check if country is NL
            if ($_POST['billing_country'] == 'NL') {
                // concatenate street & house number & copy to 'billing_address_1'
                $billing_house_number = $_POST['billing_house_number'] . (!empty($_POST['billing_house_number_suffix']) ? '-' . $_POST['billing_house_number_suffix'] : '');
                $billing_address_1 = $_POST['billing_street_name'] . ' ' . $billing_house_number;
                update_post_meta($order_id, '_billing_address_1', $billing_address_1);

                // check if 'ship to billing address' is checked
                if ($ship_to_different_address == false) {
                    // use billing address
                    update_post_meta($order_id, '_shipping_address_1', $billing_address_1);
                }
            }

            if ($_POST['shipping_country'] == 'NL' && $ship_to_different_address == true) {
                // concatenate street & house number & copy to 'shipping_address_1'
                $shipping_house_number = $_POST['shipping_house_number'] . (!empty($_POST['shipping_house_number_suffix']) ? '-' . $_POST['shipping_house_number_suffix'] : '');
                $shipping_address_1 = $_POST['shipping_street_name'] . ' ' . $shipping_house_number;
                update_post_meta($order_id, '_shipping_address_1', $shipping_address_1);
            }
            return;
        }

        /**
         * Clean postcodes : remove space, dashes (& other non alfanumeric characters)
         *
         * @return $billing_postcode
         * @return $shipping_postcode
         */
        public function clean_billing_postcode()
        {
            $billing_postcode = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['billing_postcode']);
            return $billing_postcode;
        }

        public function clean_shipping_postcode()
        {
            $shipping_postcode = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['shipping_postcode']);
            return $shipping_postcode;
        }

        /**
         * Remove placeholders from posted checkout data
         * @param  string $order_id order_id of the new order
         * @param  array $posted Array of posted form data
         * @return void
         */
        public function remove_placeholders($order_id, $posted)
        {
            // get default address fields with their placeholders
            $countries = new WC_Countries;
            $fields = $countries->get_default_address_fields();

            // define order_comments placeholder
            $order_comments_placeholder = _x('Notes about your order, e.g. special notes for delivery.', 'placeholder', 'woocommerce');

            // check if ship to billing is set
            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<=')) {
                // old versions use 'shiptobilling'
                $ship_to_different_address = isset($_POST['shiptobilling']) ? false : true;
            } else {
                // WC2.1
                $ship_to_different_address = isset($_POST['ship_to_different_address']) ? true : false;
            }

            // check the billing & shipping fields
            $field_types = array('billing', 'shipping');
            $check_fields = array('address_1', 'address_2', 'city', 'state', 'postcode');
            foreach ($field_types as $field_type) {
                foreach ($check_fields as $check_field) {
                    // file_put_contents(ABSPATH.'field_check.txt', $posted[$field_type.'_'.$check_field] .' || '. $fields[$check_field]['placeholder']."\n",FILE_APPEND);
                    if (isset($posted[$field_type . '_' . $check_field]) && $posted[$field_type . '_' . $check_field] == $fields[$check_field]['placeholder']) {
                        update_post_meta($order_id, '_' . $field_type . '_' . $check_field, '');

                        // also clear shipping field when ship_to_different_address is false
                        if ($ship_to_different_address == false && $field_type == 'billing') {
                            update_post_meta($order_id, '_shipping_' . $check_field, '');
                        }
                    }
                }
            }

            // check the order comments field
            if ($posted['order_comments'] == $order_comments_placeholder) {
                wp_update_post(array(
                        'ID' => $order_id,
                        'post_excerpt' => '',
                    )
                );
            }

            return;
        }

        /**
         * Custom country address formats.
         *
         * @param  array $formats Defaul formats.
         *
         * @return array          New NL format.
         */
        public function localisation_address_formats($formats)
        {
            // default = $postcode_before_city = "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}";
            $formats['NL'] = "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}";
            return $formats;
        }

        /**
         * Custom country address format.
         *
         * @param  array $replacements Default replacements.
         * @param  array $args Arguments to replace.
         *
         * @return array               New replacements.
         */
        public function formatted_address_replacements($replacements, $args)
        {
            extract($args);

            if (!empty($street_name)) {
                $replacements['{address_1}'] = $street_name . ' ' . $house_number . $house_number_suffix;
            }

            return $replacements;
        }

        /**
         * Custom order formatted billing address.
         *
         * @param  array $address Default address.
         * @param  object $order Order data.
         *
         * @return array          New address format.
         */
        public function order_formatted_billing_address($address, $order)
        {
            $address['street_name'] = $order->billing_street_name;
            $address['house_number'] = $order->billing_house_number;
            $address['house_number_suffix'] = !empty($order->billing_house_number_suffix) ? '-' . $order->billing_house_number_suffix : '';

            return $address;
        }

        /**
         * Custom order formatted shipping address.
         *
         * @param  array $address Default address.
         * @param  object $order Order data.
         *
         * @return array          New address format.
         */
        public function order_formatted_shipping_address($address, $order)
        {
            $address['street_name'] = $order->shipping_street_name;
            $address['house_number'] = $order->shipping_house_number;
            $address['house_number_suffix'] = !empty($order->shipping_house_number_suffix) ? '-' . $order->shipping_house_number_suffix : '';

            return $address;
        }

        /**
         * Custom user column billing address information.
         *
         * @param  array $address Default address.
         * @param  int $user_id User id.
         *
         * @return array          New address format.
         */
        public function user_column_billing_address($address, $user_id)
        {
            $address['street_name'] = get_user_meta($user_id, 'billing_street_name', true);
            $address['house_number'] = get_user_meta($user_id, 'billing_house_number', true);
            $address['house_number_suffix'] = (get_user_meta($user_id, 'billing_house_number_suffix', true)) ? '-' . get_user_meta($user_id, 'billing_house_number_suffix', true) : '';

            return $address;
        }

        /**
         * Custom user column shipping address information.
         *
         * @param  array $address Default address.
         * @param  int $user_id User id.
         *
         * @return array          New address format.
         */
        public function user_column_shipping_address($address, $user_id)
        {
            $address['street_name'] = get_user_meta($user_id, 'shipping_street_name', true);
            $address['house_number'] = get_user_meta($user_id, 'shipping_house_number', true);
            $address['house_number_suffix'] = (get_user_meta($user_id, 'shipping_house_number_suffix', true)) ? '-' . get_user_meta($user_id, 'shipping_house_number_suffix', true) : '';

            return $address;
        }

        /**
         * Custom my address formatted address.
         *
         * @param  array $address Default address.
         * @param  int $customer_id Customer ID.
         * @param  string $name Field name (billing or shipping).
         *
         * @return array            New address format.
         */
        public function my_account_my_address_formatted_address($address, $customer_id, $name)
        {
            $address['street_name'] = get_user_meta($customer_id, $name . '_street_name', true);
            $address['house_number'] = get_user_meta($customer_id, $name . '_house_number', true);
            $address['house_number_suffix'] = (get_user_meta($customer_id, $name . '_house_number_suffix', true)) ? '-' . get_user_meta($customer_id, $name . '_house_number_suffix', true) : '';

            return $address;
        }

        /**
         * Get a posted address field after sanitization and validation.
         *
         * @param  string $key
         * @param  string $type billing for shipping
         *
         * @return string
         */
        public function get_posted_address_data($key, $posted, $type = 'billing')
        {
            if ('billing' === $type || false === $posted['ship_to_different_address']) {
                $return = isset($posted['billing_' . $key]) ? $posted['billing_' . $key] : '';
            } else {
                $return = isset($posted['shipping_' . $key]) ? $posted['shipping_' . $key] : '';
            }

            return $return;
        }

        /**
         * Save order data.
         *
         * @param  int $order_id
         * @param  array $posted
         *
         * @return void
         */
        public function save_order_data($order_id, $posted)
        {
            // Billing.
            update_post_meta($order_id, '_billing_street_name', $this->get_posted_address_data('street_name', $posted));
            update_post_meta($order_id, '_billing_house_number', $this->get_posted_address_data('house_number', $posted));
            update_post_meta($order_id, '_billing_house_number_suffix', $this->get_posted_address_data('house_number_suffix', $posted));

            // Shipping.
            update_post_meta($order_id, '_shipping_street_name', $this->get_posted_address_data('street_name', $posted, 'shipping'));
            update_post_meta($order_id, '_shipping_house_number', $this->get_posted_address_data('house_number', $posted, 'shipping'));
            update_post_meta($order_id, '_shipping_house_number_suffix', $this->get_posted_address_data('house_number_suffix', $posted, 'shipping'));
        }

    }
}

new WC_Wuunder_Postcode_Fields();