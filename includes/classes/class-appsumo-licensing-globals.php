<?php
namespace WPF\AppSupomo;

// exit if file is called directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// if class already defined, bail out
if ( class_exists( 'WPF\AppSupomo' ) ) {
    return;
}


/**
 * This class will handle Api response
 *
 * @package    WPF
 * @subpackage WPF\AppSupomo
 * @author     WPFunnels Team <admin@getwpfunnels.com>
 */
class Globals {

    /**
     * get appsumo product id
     *
     * @return int
     */
    public static function get_appsumo_parent_product_id() {
        return 247;
    }


    /**
     * get variation id
     *
     * @param $tier_name
     * @return int|mixed
     */
    public static function get_variation_id( $tier_name ) {
        $variations = array(
            'wpfunnels_tier1' => 248,
            'wpfunnels_tier2' => 249,
            'wpfunnels_tier3' => 250,
        );
        return isset($variations[$tier_name]) ? $variations[$tier_name] : 0;
    }

    /**
     * @param $key
     * @param string $default
     * @return mixed|string|void
     */
    public static function get_options( $key, $default = '' ) {
        if(!$key) {
            return '';
        }

        return get_option( $key, $default );
    }


    /**
     * AppSumo redirect link
     *
     * @return string|void
     */
    public static function get_appsumo_redirect_link() {
        return home_url();
    }
}