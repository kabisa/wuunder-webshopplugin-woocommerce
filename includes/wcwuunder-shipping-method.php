<?php

if (!defined('WPINC')) {
    die;
}

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) || (is_multisite() && is_plugin_active_for_network('woocommerce/woocommerce.php'))) {
    
    function wc_wuunder_parcelshop_method()
    {
        if (!class_exists('WC_wuunder_parcelshop')) {
            class WC_wuunder_parcelshop extends WC_Shipping_Method
            {
                /**
                 * Constructor for your shipping class
                 *
                 * @access public
                 * @return void
                 */
                public function __construct($instance_id = 0)
                {
                    $this->id = 'wuunder_parcelshop';
                    $this->instance_id = absint($instance_id);
                    $this->method_title = __('Wuunder Parcelshop');
                    $this->method_description = __('Laat klanten zelf een locatie kiezen om hun pakketje op te halen.');
                    // $this->enabled            = ( 'yes' === $this->get_option( 'enabled') ) ? $this->get_option( 'enabled') : 'no';
                    $this->enabled = 'yes';
                    $this->title = 'Wuunder Parcelshop Locator';
                    $this->supports = array(
                        'shipping-zones',
                        'settings',
                        'instance-settings',
                        'instance-settings-modal'
                    );
                    $this->defaultCarriers = array(
                        'dhl' => __("DHL"),
                        'dpd' => __("DPD"),
                        'postnl' => __("PostNL")
                    );

                    $this->init();
                }

                /**
                 * Init your settings
                 *
                 * @access public
                 * @return void
                 */
                function init()
                {
                    // Load the settings API
                    $this->wcwp_init_form_fields(); // This is part of the settings API. Override the method to add your own settings
                    $this->init_settings(); // This is part of the settings API. Loads settings you previously init.

                    // These are the options set by the user
                    $this->cost = $this->get_option('cost');
                    $this->carriers = $this->get_option('select_carriers');
                    $this->tax_status = $this->get_option('tax_status');

                    // Save settings in admin if you have any defined
                    add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
                    update_option('default_carrier_list', $this->defaultCarriers);
                }

                function wcwp_init_form_fields()
                {
                    $this->form_fields = array(
                        'select_carriers' => array(
                            'title' => __('Welke Carriers', 'woocommerce'),
                            'type' => 'multiselect',
                            'description' => __('Geef aan uit welke carriers de klant kan kiezen (cmd/ctrl + muis om meerdere te kiezen). Als geen selectie wordt gemaakt, dan zijn alle carriers geselecteerd.', 'woocommerce'),
                            'options' => $this->defaultCarriers
                        )
                    );

                    $this->instance_form_fields = array(
                        'cost' => array(
                            'title' => __('Kosten', 'woocommerce'),
                            'type' => 'number',
                            'description' => __('Kosten voor gebruik Parcelshop pick-up', 'woocommerce'),
                            'default' => 3.5,
                            'desc_tip' => true
                        ),
                        'free_from' => array(
                            'title' => __('Gratis verzending vanaf', 'woocommerce'),
                            'type' => 'number',
                            'description' => __('Vanaf welk bestelbedrag is de verzending gratis. Stel 0 in voor nooit.', 'woocommerce'),
                            'default' => 50.00,
                            'desc_tip' => true
                        ),
                        'tax_status' => array(
                            'title' => __('Tax status', 'woocommerce'),
                            'type' => 'select',
                            'class' => 'wc-enhanced-select',
                            'default' => 'taxable',
                            'options' => array(
                                'taxable' => __('Taxable', 'woocommerce'),
                                'none' => _x('None', 'Tax status', 'woocommerce'),
                            ),
                        ),
                    );
                }

                /**
                 * calculate_shipping function.
                 *
                 * @access public
                 * @param mixed $package
                 * @return void
                 */
                public function calculate_shipping($package = array())
                {
                    $cost = $this->get_option('cost');
                    if ($this->get_option('free_from') > 0 && $package['contents_cost'] >= $this->get_option('free_from')) {
                        $cost = 0;
                    }

                    if (!isset($this->tax_status) || $this->tax_status == 'none') {
                        $tax = false;
                    } else {
                        $tax = '';
                    }

                    $rate = array(
                        'id' => $this->id,
                        'label' => $this->title,
                        'cost' => $cost,
                        'taxes' => $tax,
                        'calc_tax' => 'per_order'
                    );

                    // Register the rate
                    $this->add_rate($rate);
                }

            }
        }
    }

    add_action('woocommerce_shipping_init', 'wc_wuunder_parcelshop_method');

    function wuunder_parcelshop_locator($methods)
    {
        $methods['wuunder_parcelshop'] = 'WC_wuunder_parcelshop';
        return $methods;
    }

    add_filter('woocommerce_shipping_methods', 'wuunder_parcelshop_locator');
}
