<?php
//error_reporting( 1 );

if (!class_exists('WC_Wuunder_Create')) {

    class WC_Wuunder_Create
    {
        public $order_id;
        private $version_obj;

        public function __construct()
        {
            $this->version_obj = array(
                'product' => 'Woocommerce extension',
                'version' => array(
                    'build' => '2.7.21',
                    'plugin' => '2.0'),
                'platform' => array(
                    'name' => 'Woocommerce',
                    'build' => WC()->version
                ));
            add_action('load-edit.php', array(&$this, 'wcwp_generateBookingUrl'));
            add_action('woocommerce_admin_order_actions_end', array(&$this, 'wcwp_add_listing_actions'));
            add_action('add_meta_boxes_shop_order', array(&$this, 'wcwp_add_meta_boxes'));
            add_action('admin_notices', array(&$this, 'wcwp_sample_admin_notice__error'));
            wp_enqueue_style('wuunder-admin', (dirname(plugin_dir_url(__FILE__)) . '/assets/css/wuunder-admin.css'));
        }

        /**
         * Creates an error message for the admin order page
         */
        public function wcwp_sample_admin_notice__error()
        {

            if ('error' == isset($_GET['notice']) && $_GET['notice']) {

                $class = 'notice notice-error';
                $message = __('<b>Het aanmaken van het label voor #' . sanitize_text_field($_GET['id']) . ' is mislukt</b>', 'woocommerce-wuunder');
                $errors = sanitize_text_field($_GET['error_melding']);
                $message .= '<ul style="margin:0 0 0 20px; padding:0; list-style:inherit;">';
                foreach ($errors as $error) {
                    $message .= '<li>' . $error . '</li>';
                }
                $message .= '</ul>';

                printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);

            } elseif ('success' == isset($_GET['notice']) && $_GET['notice']) {

                $class = 'notice notice-success';
                $message = __('Het verzendlabel voor #' . sanitize_text_field($_GET['id']) . ' is aangemaakt', 'woocommerce-wuunder');
                printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);

            }

        }

        /**
         * Sets the address and package data for the booking request
         *
         * @param $orderId
         * @return $bookingConfig
         */
        private function wcwp_setBookingConfig($orderId)
        {
            wcwp_log('info', 'Filling the booking config');

            $orderItems = $this->wcwp_get_order_items($orderId);

            $order = new WC_Order($orderId);
            $orderPicture = null;
            $base64String = get_option('wc_wuunder_default_image_base64');
            if (!empty($base64String)) {
                $maxImageSize = 2000000; //2mb
                $maxBase64StringSize = $maxImageSize * 1.37;
                if (strlen($base64String) <= $maxBase64StringSize) {
                    $orderPicture = $base64String;
                }
            }

            if (is_null($orderPicture)) {
                foreach ($orderItems['images'] as $image) {
                    if (!is_null($image)) {
                        $orderPicture = $this->wcwp_get_base64_image($image);
                        break;
                    }
                }
            }

            // Get WooCommerce Wuunder Address from options page
            $company = $this->wcwp_get_company_address();
            $customer = $this->wcwp_get_customer_address($orderId);

            $totalWeight = 0;
            $dimensions = null;
            $description = '';

            foreach ($order->get_items() as $item_id => $item_product) {
                $product = $item_product->get_product();
                if ($dimensions === null) {
                    $dimensions = array($product->get_length(), $product->get_width(), $product->get_height());
                }
            }

            foreach ($orderItems['products'] as $item) {
                $totalWeight += $item['total_weight'];
                $description .= '- ' . $item['quantity'] . 'x ' . $item['name'] . "\r\n";
            }

            if (3 !== count($dimensions)) {
                $dimensions = array(null, null, null);
            }

            $value = intval(($order->get_total() + $order->get_total_discount() - $order->get_shipping_total() - $order->get_shipping_tax()) * 100);
            $bookingTokenData = get_post_meta($orderId, '_wuunder_label_booking_token');

            if (count($bookingTokenData)) {
                $bookingToken = $bookingTokenData[0];
            } else {
                $bookingToken = uniqid();
                update_post_meta($orderId, '_wuunder_label_booking_token', $bookingToken);
            }

            $redirectUrl = get_site_url(null, '/wp-admin/edit.php?post_type=shop_order');
            $webhookUrl = get_site_url(null, 'index.php/wuunder/webhook?order=' . $orderId . '&token=' . $bookingToken);

            $bookingConfig = new Wuunder\Api\Config\BookingConfig();
            $bookingConfig->setWebhookUrl($webhookUrl);
            $bookingConfig->setRedirectUrl($redirectUrl);

            $bookingConfig->setDescription($description);
            $bookingConfig->setPicture($orderPicture);
            $bookingConfig->setKind($totalWeight > 23000 ? 'pallet' : 'package');
            $bookingConfig->setValue($value ? $value : null);
            $bookingConfig->setLength($this->wcwp_roundButNull($dimensions[0]));
            $bookingConfig->setWidth($this->wcwp_roundButNull($dimensions[1]));
            $bookingConfig->setHeight($this->wcwp_roundButNull($dimensions[2]));
            $bookingConfig->setWeight($totalWeight ? $totalWeight : null);
            $bookingConfig->setCustomerReference($orderId);

            $order_items = $order->get_items('shipping');
            $bookingConfig->setPreferredServiceLevel((count($order->get_items('shipping')) > 0) ? $this->wcwp_get_filter_from_shippingmethod(reset($order_items)->get_method_id()) : '');
            $bookingConfig->setSource($this->version_obj);

            $orderMeta = get_post_meta($orderId);
            if (isset($orderMeta['parcelshop_id'])) {
                $bookingConfig->setParcelshopId($orderMeta['parcelshop_id'][0]);
            }

            $bookingConfig->setDeliveryAddress($customer);
            $bookingConfig->setPickupAddress($company);

            return $bookingConfig;
        }

        /**
         * Generates the booking url that takes the user to Wuunder.
         * Returns the user to the original order page with the redirect.
         */
        public function wcwp_generateBookingUrl()
        {
            if (isset($_REQUEST['order']) && sanitize_text_field($_REQUEST['action']) === "bookorder") {
                wcwp_log('info', 'Generating the booking url');
                $order_id = sanitize_text_field($_REQUEST['order']);

                $status = get_option('wc_wuunder_api_status');
                $apiKey = ('productie' == $status ? get_option('wc_wuunder_api') : get_option('wc_wuunder_test_api'));


                $connector = new Wuunder\Connector($apiKey, 'productie' !== $status);
                $booking = $connector->createBooking();
                $bookingConfig = $this->wcwp_setBookingConfig($order_id);

                if ($bookingConfig->validate()) {
                    $booking->setConfig($bookingConfig);
                    wcwp_log('info', 'Going to fire for bookingurl');
                    if ($booking->fire()) {
                        $url = $booking->getBookingResponse()->getBookingUrl();
                    } else {
                        wcwp_log('error', $booking->getBookingResponse()->getError());
                    }
                } else {
                    wcwp_log('error', 'Bookingconfig not complete');
                }

                wcwp_log('info', 'Handling response');

                if (isset($url)) {
                    update_post_meta($order_id, '_wuunder_label_booking_url', $url);
                    wp_redirect($url);
                } else {
                    wp_redirect(get_admin_url(null, 'edit.php?post_type=shop_order'));
                }
                exit;
            }
        }

        /**
         * Returns rounded value, or null
         *
         * @param $val
         * @return float|null
         */
        private function wcwp_roundButNull($val)
        {
            if (empty($val)) {
                return null;
            }

            return round($val);
        }

        /**
         * Returns the filter (preferred service level) that is set in the Wuunder config
         *
         * @param $shipping_method
         * @return
         */
        private function wcwp_get_filter_from_shippingmethod($shipping_method)
        {
            if (false !== strpos($shipping_method, ':')) {
                $shipping_method = explode(':', $shipping_method)[0];
            }
            if ($shipping_method === get_option('wc_wuunder_mapping_method_1')) {
                return get_option('wc_wuunder_mapping_filter_1');
            } elseif ($shipping_method === get_option('wc_wuunder_mapping_method_2')) {
                return get_option('wc_wuunder_mapping_filter_2');
            } elseif ($shipping_method === get_option('wc_wuunder_mapping_method_3')) {
                return get_option('wc_wuunder_mapping_filter_3');
            } elseif ($shipping_method === get_option('wc_wuunder_mapping_method_4')) {
                return get_option('wc_wuunder_mapping_filter_4');
            } else {
                return '';
            }
        }

        /**
         * Gets the company address set in the Wuunder config
         *
         * @return $pickupAddress
         */
        public function wcwp_get_company_address()
        {
            $pickupAddress = new \Wuunder\Api\Config\AddressConfig();
            $pickupAddress->setEmailAddress(get_option('wc_wuunder_company_email'));
            $pickupAddress->setFamilyName(get_option('wc_wuunder_company_lastname'));
            $pickupAddress->setGivenName(get_option('wc_wuunder_company_firstname'));
            $pickupAddress->setLocality(get_option('wc_wuunder_company_city'));
            $pickupAddress->setStreetName(get_option('wc_wuunder_company_street'));
            $pickupAddress->setHouseNumber(get_option('wc_wuunder_company_housenumber'));
            $pickupAddress->setZipCode(get_option('wc_wuunder_company_postode'));
            $pickupAddress->setPhoneNumber(get_option('wc_wuunder_company_phone'));
            $pickupAddress->setCountry(get_option('wc_wuunder_company_country'));
            $pickupAddress->setBusiness(get_option('wc_wuunder_company_name'));
            if ($pickupAddress->validate()) {
                return $pickupAddress;
            } else {
                wcwp_log('error', 'Invalid pickup address. There are mistakes or missing fields.');
                return $pickupAddress;
            }
        }

        /**
         * Retrieves part of the customer address
         *
         * @param $order_meta , $suffix
         * @return $order_meta
         */
        private function wcwp_get_customer_address_part($order_meta, $suffix, $prefix = null)
        {
            if (!is_null($prefix)){
                if (isset($order_meta[$prefix . $suffix]) && !empty($order_meta[$prefix . $suffix][0])) {
                    return $order_meta[$prefix . $suffix][0];
                }
                return null;
            }

            if (isset($order_meta['_shipping' . $suffix]) && !empty($order_meta['_shipping' . $suffix][0])) {
                return $order_meta['_shipping' . $suffix][0];
            } else if (isset($order_meta['_billing' . $suffix]) && !empty($order_meta['_billing' . $suffix][0])) {
                return $order_meta['_billing' . $suffix][0];
            } else {
                return null;
            }
        }


        /**
         * Fills the delivery address with the customers data
         *
         * @param $orderid
         * @return $deliveryAddress
         */
        public function wcwp_get_customer_address($orderid)
        {
            // Get customer address from order
            $order_meta = get_post_meta($orderid);
            $deliveryAddress = new \Wuunder\Api\Config\AddressConfig();

            $prefix = "_shipping";
            if (!isset($order_meta['_shipping_address_1']) || empty($order_meta['_shipping_address_1'])) {
                $prefix = "_billing";
            }

            $address_line_1 = $this->wcwp_get_customer_address_part($order_meta, '_address_1', $prefix);
            $address_line_2 = $this->wcwp_get_customer_address_part($order_meta, '_address_2', $prefix);

            $deliveryAddress->setEmailAddress($this->wcwp_get_customer_address_part($order_meta, '_email'));
            $deliveryAddress->setFamilyName($this->wcwp_get_customer_address_part($order_meta, '_last_name', $prefix));
            $deliveryAddress->setGivenName($this->wcwp_get_customer_address_part($order_meta, '_first_name', $prefix));
            $deliveryAddress->setLocality($this->wcwp_get_customer_address_part($order_meta, '_city', $prefix));
            $deliveryAddress->setStreetName($address_line_1);
            $deliveryAddress->setAddress2($address_line_2);
            $deliveryAddress->setZipCode(str_replace(' ', '', $this->wcwp_get_customer_address_part($order_meta, '_postcode', $prefix)));
            $deliveryAddress->setPhoneNumber($order_meta['_billing_phone'][0]);
            $deliveryAddress->setCountry($this->wcwp_get_customer_address_part($order_meta, '_country', $prefix));
            $deliveryAddress->setBusiness($this->wcwp_get_customer_address_part($order_meta, '_company', $prefix));

            if ($deliveryAddress->validate()) {
                return $deliveryAddress;
            } else {
                wcwp_log('error', 'Invalid delivery address. There are mistakes or missing fields.');
                return $deliveryAddress;
            }
        }

        /**
         * Checks if the image is smaller than 2MB and base 64 encodes if it is
         *
         * @param $imagepath
         * @return $image
         */
        public function wcwp_get_base64_image($imagepath)
        {
            try {
                $fileSize = ('http' === substr($imagepath, 0, 4)) ? $this->wcwp_remote_filesize($imagepath) : filesize($imagepath);
                wcwp_log('info', 'Handling a image of size: ' . $fileSize);
                if ($fileSize > 0 && $fileSize <= 2097152) { //smaller or equal to 2MB
                    wcwp_log('info', 'Base64 encoding image');
                    $imagedata = file_get_contents($imagepath);
                    $image = base64_encode($imagedata);
                } else {
                    $image = '';
                }
                return $image;
            } catch (Exception $e) {
                wcwp_log('error', $e);
                return '';
            }
        }

        function wcwp_curl_get_file_size($url)
        {
            // Assume failure.
            $result = -1;

            $response = wp_remote_get($url);
            $content_length = intval(wp_remote_retrieve_header($response, 'content-length'));
            $status = intval(wp_remote_retrieve_response_code($response));

            if ($response) {
                // http://en.wikipedia.org/wiki/List_of_HTTP_status_codes
                if (200 == $status || ($status > 300 && $status <= 308)) {
                    $result = $content_length;
                }
            }

            return $result;
        }

        /**
         * Employed in get_base64_image to get the correct path
         *
         * @param $url
         * @return string
         */
        private function wcwp_remote_filesize($url)
        {
            $remoteFilesize = $this->wcwp_curl_get_file_size($url);
            return $remoteFilesize;
        }

        /**
         *
         * @param $order
         */
        public function wcwp_add_listing_actions($order)
        {
            // do not show buttons for trashed orders
            if ('trash' == $order->get_status()) {
                return;
            }

            if (!empty(get_post_meta($order->get_id(), '_wuunder_label_id', true))) {
                $listing_actions = array(
                    'shipping_label' => array(
                        'url' => get_post_meta($order->get_id(), '_wuunder_label_url', true),
                        'img' => Woocommerce_Wuunder::$plugin_url . 'assets/images/print-label.png',
                        'title' => __('Download label', 'woocommerce-wuunder'),
                    ),
                    'track_trace' => array(
                        'url' => get_post_meta($order->get_id(), '_wuunder_track_and_trace_url', true),
                        'img' => Woocommerce_Wuunder::$plugin_url . 'assets/images/in-transit.png',
                        'title' => __('Track & Trace', 'woocommerce-wuunder'),
                    )
                );

                echo '<div style="clear:both;">';
                foreach ($listing_actions as $action => $data) {
                    $target = ' target="_blank" ';
                    ?>
                    <a
                        <?php
                        echo $target; ?>href=" <?php echo $data['url']; ?>"
                        class="<?php echo $action; ?> button tips <?php echo $action; ?>"
                        style="background:#8dcc00; height:2em; width:2em; padding:3px;"
                        alt="<?php echo $data['title']; ?>" data-tip="<?php echo $data['title']; ?>">
                        <img src="<?php echo $data['img']; ?>" style="width:18px; margin: 4px 3px;"
                             alt="<?php echo $data['title']; ?>">
                    </a>
                    <?php
                }
                echo '</div>';
            } else {
                $listing_actions = array(
                    'create_label' => array(
                        'url' => (get_post_meta($order->get_id(), '_wuunder_label_booking_url', true) ? get_post_meta($order->get_id(), '_wuunder_label_booking_url', true) : wp_nonce_url(admin_url('edit.php?&action=bookorder&order=' . $order->get_id()), 'wcwuunder')),
                        'img' => Woocommerce_Wuunder::$plugin_url . 'assets/images/create-label.png',
                        'alt' => __('Verzendlabel aanmaken', 'woocommerce-wuunder'),
                    ),
                );

                foreach ($listing_actions as $action => $data) {
                    ?>
                    <a href="<?php echo $data['url']; ?>" class="button tips <?php echo $action; ?>"
                       style="background:#8dcc00; height:2em; width:2em; padding:3px;" alt="<?php echo $data['alt']; ?>"
                       data-tip="<?php echo $data['alt']; ?>">
                        <img src="<?php echo $data['img']; ?>" style="width:18px; margin: 4px 3px;"
                             alt="<?php echo $data['alt']; ?>">
                    </a>
                    <?php
                }
            }

        }

        /**
         * Add the meta box on the single order page
         */
        public function wcwp_add_meta_boxes()
        {
            // create PDF buttons
            add_meta_box(
                'wpo_wcpdf-box',
                __('Wuunder', 'woocommerce-wuunder'),
                array($this, 'wcwp_sidebar_box_content'),
                'shop_order',
                'side',
                'default'
            );
        }

        /**
         * Create the meta box content on the single order page
         */
        public function wcwp_sidebar_box_content($post)
        {
            global $post_id;
            $order = new WC_Order($post_id);
            $this->wcwp_add_listing_actions($order);
        }

        /**
         *
         *
         */
        public function wcwp_get_order_items($order_id)
        {

            global $woocommerce;
            $order = new WC_Order($order_id);
            //global $_product;
            $items = $order->get_items();
            $data_list = array();
            $images = array();

            if (sizeof($items) > 0) {
                foreach ($items as $item) {
                    // Array with data for the printing template
                    $data = array();

                    $image = wp_get_attachment_image_src(get_post_thumbnail_id($item['product_id']), 'thumbnail');
                    $images[] = $image[0];

                    // Create the product
                    $product = $order->get_product_from_item($item);

                    // Set the variation
                    if (isset($item['variation_id']) && $item['variation_id'] > 0) {
                        $data['variation'] = woocommerce_get_formatted_variation($product->get_variation_attributes());
                    } else {
                        $data['variation'] = null;
                    }

                    // Set item name
                    $data['name'] = $item['name'];

                    // Set item quantity
                    $data['quantity'] = $item['qty'];

                    // Set item SKU
                    $data['sku'] = $product->get_sku();


                    // Set item weight
                    $weight = $product->get_weight();
                    $weight_unit = get_option('woocommerce_weight_unit');
                    switch ($weight_unit) {
                        case 'kg':
                            $data['weight'] = $weight * 1000;
                            break;
                        case 'g':
                            $data['weight'] = $weight;
                            break;
                        case 'lbs':
                            $data['weight'] = $weight * 0.45359237;
                            break;
                        case 'oz':
                            $data['weight'] = $weight * 0.0283495231;
                            break;
                        default:
                            $data['weight'] = $weight;
                            break;
                    }

                    $data['total_weight'] = $data['quantity'] * $data['weight'];

                    // Set item dimensions
                    $data['dimensions'] = wc_format_dimensions($product->get_dimensions(false));

                    $data_list['products'][] = $data;
                }
            }

            $data_list['images'] = $images;
            return $data_list;
        }

    }

}

new WC_Wuunder_Create();
