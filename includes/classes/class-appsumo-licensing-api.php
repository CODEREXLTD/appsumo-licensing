<?php

namespace WPF\AppSupomo;

use Curl\Curl;

// exit if file is called directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// if class already defined, bail out
if ( class_exists( 'WPF\AppSupomo\AppSumoApi' ) ) {
	return;
}


/**
 * This class will handle Api request
 *
 * @package    WPF
 * @subpackage WPF\AppSupomo
 * @author     WPFunnels Team <admin@getwpfunnels.com>
 */
class AppSumoApi {

	protected $response = array();

	protected $response_code = 200;

	protected $prefix;

	protected $params;

	protected $action;

	protected $license_id;

	/*
    * Instance of the class License
    */
	protected $license;

	/*
	 * Instance of the class ApiResponse
	 */
	protected $api_response;

	protected $product;

	protected $woocommerce = null;

	protected $debug = false;

	/*
	 * @var WP_REST_Request
	 */
	protected $request;

	public function __construct() {
//		$this->license      = new License();
		$this->api_response = new ApiResponse();
		$this->prefix       = 'wpf';
	}

	/**
	 *
	 */
	public function register_rest_route_appsumo_notification() {

		register_rest_route( 'wpf/appsumo/v1/', 'notification', array(
			'methods'  => 'POST',
			'callback' => [ $this, 'rest_callback_appsumo_notification' ],
		) );

	}


	/**
	 *
	 */
	public function jwt_auth_token_before_dispatch_appsumo_fix( $jwt_res ) {

		return array(
			'access' => $jwt_res['token']
		);
	}


	/**
	 *
	 */
	public function rest_callback_appsumo_notification( \WP_REST_Request $request ) {

		$token_validation = $this->validate_jwt_token();

		if ( is_wp_error( $token_validation ) ) {
			return rest_ensure_response( $token_validation );
		}


		$action = sanitize_key( $request->get_param( 'action' ) );

		switch ( $action ):
			case( 'activate' ):
				$response = $this->api_action_activate( $request );
				break;
			case( 'enhance_tier' ):
			case( 'reduce_tier' ):
				$response = $this->api_action_update_plan( $request );
				break;
			case( 'refund' ):
				$response = $this->api_action_refund( $request );
				break;
			default:
				return $this->api_action_not_found( $request );

		endswitch;

		return apply_filters( 'wpf_appsumo_api_response', $response );
	}


	/**
	 * validate JWT token
	 */
	public function validate_jwt_token() {


		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		$plugin_file = trailingslashit( WP_PLUGIN_DIR ) . 'jwt-authentication-for-wp-rest-api/jwt-auth.php';

		$jwt_plugin_details = get_plugin_data( $plugin_file, false );

		$plugin_name = $jwt_plugin_details['TextDomain'];
		$version     = $jwt_plugin_details['Version'];


		$jwt_auth = new \Jwt_Auth_Public( $plugin_name, $version );

		return $jwt_auth->validate_token();

	}


	/**
	 * activate action
     *
	 */
	public function api_action_activate( \WP_REST_Request $request ) {

		// make sure a license is created and
		$order_created = $this->create_order_and_license( $request );

		if ( is_wp_error( $order_created ) ) {
			return $order_created;
		}

		$redirect_url = Globals::get_appsumo_redirect_link();
		$redirect_url = esc_url_raw( $redirect_url ) . '?email=' . $request->get_param( 'activation_email' );

		$data               = new \stdClass();
		$data->message      = esc_html__( 'User Account and License created', 'appsumo-licensing' );
		$data->redirect_url = $redirect_url;

		$response = new \WP_REST_Response();
		$response->set_status( 201 );
		$response->set_data( $data );

		return rest_ensure_response( $response );

	}


	/**
	 * create order and subscription
	 */
	public function create_order_and_license( \WP_REST_Request $request ) {

		$this->request = $request;
		$email         = $request->get_param( 'activation_email' );


		if ( empty( $email ) ) {
			return $this->get_error( 'invalid_request', esc_html__( 'API request is invalid.', 'appsumo-licensing' ) );
		}

		if ( empty( $request->get_param( 'uuid' ) ) ) {
			return $this->get_error( 'invalid_request', esc_html__( 'API request is invalid.', 'appsumo-licensing' ) );
		}

        $user_name   = strstr($email, '@', true);

		$address = array(
			'first_name' => ucfirst($user_name),
			'email'      => $email,
		);

		$user_exists = get_user_by_email( $email );

		if ( $user_exists ) {
			return $this->get_error( 'user_exists', esc_html__( 'User already exists.', 'appsumo-licensing' ) );

		}
        $plan_id                = sanitize_key( $request->get_param( 'plan_id' ) );
		$appsumo_woo_product    = (int) Globals::get_variation_id($plan_id);

		if ( ! $appsumo_woo_product ) {
			return $this->get_error( 'product_not_defined', esc_html__( 'Product not defined for Licensing.', 'appsumo-licensing' ) );

		}
		$customer_id = wc_create_new_customer( $email );

		if ( ! $customer_id ) {
			return $this->get_error( 'user_not_created', esc_html__( 'user could not be created.', 'appsumo-licensing' ) );

		}

		wp_set_current_user( $customer_id );

		$order_args = array(
			'status'        => 'completed',
			'customer_id'   => $customer_id,
			'customer_note' => esc_html__( 'AppSumo Special Deal', 'appsumo-licensing' ),
			'parent'        => null,
			'created_via'   => esc_html__( 'Created via AppSumo', 'appsumo-licensing' ),
			'cart_hash'     => null,
		);


		// Now we create the order
		$order = wc_create_order( $order_args );

		if ( is_wp_error( $order ) ) {
			return $this->get_error( 'order_not_created', esc_html__( 'Order could not be created.', 'appsumo-licensing' ) );
		}

		// The add_product() function below is located in /plugins/woocommerce/includes/abstracts/abstract_wc_order.php
		$order->add_product( wc_get_product( $appsumo_woo_product ), 1 );
		$order->set_address( $address, 'billing' );

		$order->calculate_totals();

		$result = $order->update_status( "completed", esc_html__( 'AppSumo order', 'appsumo-licensing' ), true );

		do_action( 'woocommerce_payment_complete', $order->get_id() );

		// now assign the license
        if( is_plugin_active( 'woocommerce-software-license/software-license.php' ) ){
            $license_obj = new \WOO_SL_functions;
            apply_filters('woo_sl/order_processed/product_sl', array( $this, 'filter_license_data' ), 10, 3);
            $this->create_license($order->get_id(), $license_obj);
            add_filter( 'woo_sl/generate_license_key', array( $this, 'filter_licence_key' ), 10, 4);
            $license_obj->generate_license_keys($order->get_id());
        }

		$this->grant_download_permissions( $order );
		return $result;
	}


    /**
     * create license
     *
     * @param $order_id
     * @param $license_obj
     * @throws \Exception
     */
    public function create_license( $order_id, $license_obj )
    {
        //check if order contain any licensed product
        $order_data     = new \WC_Order($order_id);
        $order_products =   $order_data->get_items();
        $found_licensed_product =   false;
        foreach($order_products as  $key    =>  $order_product)
        {
            if (\WOO_SL_functions::is_product_licensed( $order_product->get_product_id() ) )
            {
                $found_licensed_product =   TRUE;
                break;
            }
        }
        if(!$found_licensed_product)
            return;
        $_woo_sl    =   array();
        //get the order items
        foreach ( $order_products as $key   =>  $order_product )
        {
            if(! $license_obj->is_product_licensed( $order_product->get_product_id() ))
                continue;

            $is_licence_extend  =   FALSE;
            $_woo_sl_extend     =   wc_get_order_item_meta($key, '_woo_sl_extend', TRUE);

            if(!empty($_woo_sl_extend))
                $is_licence_extend  =   TRUE;

            //no need to process if is an licence extend
            if (    $is_licence_extend  )
                continue;

            //check against the variation, if assigned a licence group
            if($order_product->get_variation_id()   > 0)
            {
                $variation_license_group_id =   get_post_meta($order_product->get_variation_id(), '_sl_license_group_id', TRUE);

                if( $variation_license_group_id == '')
                    continue;
            }

            //get product licensing details
            $product_sl_groups     =   \WOO_SL_functions::get_product_licensing_groups( $order_product->get_product_id() );

            //if variation, filter out the licence groups
            if($order_product->get_variation_id()   >   0)
            {
                if(isset($product_sl_groups[$variation_license_group_id]))
                {
                    $_product_sl_groups  =   $product_sl_groups;
                    $product_sl_groups  =   array();
                    $product_sl_groups[$variation_license_group_id]  =   $_product_sl_groups[$variation_license_group_id];
                }
                else
                    $product_sl_groups  =   array();
            }

            $_group_title                       =   array();
            $_licence_prefix                    =   array();
            $_max_keys                          =   array();
            $_max_instances_per_key             =   array();
            $_use_predefined_keys               =   array();
            $_product_use_expire                =   array();
            $_product_expire_renew_price        =   array();
            $_product_expire_units              =   array();
            $_product_expire_time               =   array();
            $_product_expire_starts_on_activate =   array();
            $_product_expire_disable_update_link=   array();
            $_product_expire_limit_api_usage    =   array();
            $_product_expire_notice             =   array();

            foreach($product_sl_groups  as  $product_sl_group)
            {
                $_group_title[]                     =   $product_sl_group['group_title'];
                $_licence_prefix[]                  =   $product_sl_group['licence_prefix'];
                $_max_keys[]                        =   $product_sl_group['max_keys'];
                $_max_instances_per_key[]           =   $product_sl_group['max_instances_per_key'];
                $_use_predefined_keys[]             =   $product_sl_group['use_predefined_keys'];

                $_product_use_expire[]                =   $product_sl_group['product_use_expire'];
                $_product_expire_renew_price[]        =   $product_sl_group['product_expire_renew_price'];
                $_product_expire_units[]              =   $product_sl_group['product_expire_units'];
                $_product_expire_time[]               =   $product_sl_group['product_expire_time'];
                $_product_expire_starts_on_activate[] =   $product_sl_group['product_expire_starts_on_activate'];
                $_product_expire_disable_update_link[]=   $product_sl_group['product_expire_disable_update_link'];
                $_product_expire_limit_api_usage[]    =   $product_sl_group['product_expire_limit_api_usage'];
                $_product_expire_notice[]             =   $product_sl_group['product_expire_notice'];
            }

            $data['group_title']                            =   $_group_title;
            $data['licence_prefix']                         =   $_licence_prefix;
            $data['max_keys']                               =   $_max_keys;
            $data['max_instances_per_key']                  =   $_max_instances_per_key;
            $data['use_predefined_keys']                    =   $_use_predefined_keys;
            $data['product_use_expire']                     =   $_product_use_expire;
            $data['product_expire_renew_price']             =   $_product_expire_renew_price;
            $data['product_expire_units']                   =   $_product_expire_units;
            $data['product_expire_time']                    =   $_product_expire_time;
            $data['product_expire_starts_on_activate']      =   $_product_expire_starts_on_activate;
            $data['product_expire_disable_update_link']     =   $_product_expire_disable_update_link;
            $data['product_expire_limit_api_usage']         =   $_product_expire_limit_api_usage;
            $data['product_expire_notice']                  =   $_product_expire_notice;

            $data   =   apply_filters('woo_sl/order_processed/product_sl', $data, $order_product, $order_id);

            wc_update_order_item_meta($key, '_woo_sl', $data);

            //set currently as inactive
            wc_update_order_item_meta($key, '_woo_sl_licensing_status', 'inactive');

            foreach ( $data['product_use_expire']    as  $data_key    =>  $data_block_value )
            {
                if ( $data_block_value    !=  'no' )
                {
                    wc_update_order_item_meta($key, '_woo_sl_licensing_using_expire', $data_block_value );

                    //continue only if expire_starts_on_activate is not set to yes
                    $expire_starts_on_activate  =   $data['product_expire_starts_on_activate'][$data_key];
                    if ( $expire_starts_on_activate ==  'yes' )
                    {
                        //set currently as not-activated
                        wc_update_order_item_meta($key, '_woo_sl_licensing_status', 'not-activated');
                        continue;
                    }

                    if ( $data_block_value    ==  'yes' )
                    {
                        $today      =   date("Y-m-d", current_time( 'timestamp' ));
                        $start_at   =   strtotime($today);
                        wc_update_order_item_meta($key, '_woo_sl_licensing_start', $start_at);

                        $_sl_product_expire_units   =   $data['product_expire_units'][$data_key];
                        $_sl_product_expire_time    =   $data['product_expire_time'][$data_key];
                        $expire_at  =   strtotime( " + " . $_sl_product_expire_units . " " . $_sl_product_expire_time,  $start_at);
                        wc_update_order_item_meta($key, '_woo_sl_licensing_expire_at', $expire_at);
                    }
                }
            }
        }
    }


    /**
	 *
	 */
	public function get_error( $code, $message, $status_code = 403 ) {

		return new \WP_Error(
			$code,
			$message,
			array(
				'status' => $status_code,
			)
		);

	}

	/**
	 * code taken from https://github.com/woocommerce/woocommerce/blob/3.2.6/includes/wc-order-functions.php#L379
	 */
	public function grant_download_permissions( $order ) {

		if ( ! $order ) {
			return;
		}
		if ( sizeof( $order->get_items() ) > 0 ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();

				if ( $product && $product->exists() && $product->is_downloadable() ) {
					$downloads = $product->get_downloads();

					foreach ( array_keys( $downloads ) as $download_id ) {
						wc_downloadable_file_permission( $download_id, $product, $order, $item->get_quantity() );
					}
				}
			}
		}

		$order->get_data_store()->set_download_permissions_granted( $order, true );
	}


    /**
     * plan update with API action
     *
     * @param \WP_REST_Request $request
     * @return \WP_Error|\WP_REST_Response
     */
	public function api_action_update_plan( \WP_REST_Request $request ) {

		// make sure a license is created and
		$upgrade_plan = $this->update_license_for_plan( $request );

		if ( is_wp_error( $upgrade_plan ) || !$upgrade_plan ) {
			return $upgrade_plan;
		}

		$data          = new \stdClass();
		$data->message = esc_html__( 'WPFunnels License Updated.', 'appsumo-licensing' );

		$response = new \WP_REST_Response();
		$response->set_status( 200 );
		$response->set_data( $data );

		return rest_ensure_response( $response );

	}


    /**
     * update license
     *
     * @param \WP_REST_Request $request
     * @return \WP_Error
     */
	public function update_license_for_plan( \WP_REST_Request $request ) {

		$this->request = $request;
		$email         = $request->get_param( 'activation_email' );

		$user = get_user_by_email( $email );

		if ( ! $user ) {
			return $this->get_error( 'user_not_found', esc_html__( 'User Not Found.', 'appsumo-licensing' ) );
		}

		$plan_id     = sanitize_key( $request->get_param( 'plan_id' ) );
		$max_allowed = $this->get_max_allowed_for_plan( $plan_id );

		if ( 1 === $max_allowed ) {
			return $this->get_error( 'invalid_plan_id', esc_html__( 'Plan Id provided is not valid.', 'appsumo-licensing' ) );
		}

		$order_id               = $this->get_order_id_from_key( $request->get_param( 'uuid' ) );
        if ( ! $order_id ) {
            return $this->get_error( 'invalid_uuid', esc_html__( 'Invalid UUID provide, no license found.', 'appsumo-licensing' ) );
        }
		$order                  = wc_get_order($order_id);
		$items                  = $order->get_items('line_item');
        $appsumo_woo_product    = (int) Globals::get_variation_id($plan_id);
        foreach ($items as $item_key => $item) {
            $order->remove_item($item_key);
        }

        $order->add_product( wc_get_product( $appsumo_woo_product ), 1 );
        $order->save();
        $this->update_license( $order_id, $appsumo_woo_product );
        return true;
	}


    /**
     * @param $order_id
     * @param $product_id
     * @throws \Exception
     */
	private function update_license($order_id, $product_id) {
        $order                  = new \WC_Order( $order_id );
        $order_products         = $order->get_items();
        $found_licensed_product = false;
        foreach($order_products as $key => $order_product ) {
            if ( \WOO_SL_functions::is_product_licensed($order_product['product_id']) ) {
                $found_licensed_product = TRUE;
                break;
            }
        }

        if ( !$found_licensed_product ) {
            die();
        }

        foreach( $order_products as $order_item_key => $order_product ) {
            if ( ! \WOO_SL_functions::is_product_licensed( $order_product['product_id']) ) {
                continue;
            }

            $product_sl_groups = array();
            $variation_license_group_id = 0;
            if($order_product->get_variation_id()   > 0)
            {
                $variation_license_group_id =   get_post_meta( $order_product->get_variation_id(), '_sl_license_group_id', TRUE);
                if( $variation_license_group_id == '') continue;
                $product_sl_groups =  \WOO_SL_functions::get_product_licensing_groups( $order_product->get_product_id() );
                if(isset($product_sl_groups[$variation_license_group_id])) {
                    $_product_sl_groups                             =   $product_sl_groups;
                    $product_sl_groups                              =   array();
                    $product_sl_groups[$variation_license_group_id] =   $_product_sl_groups[$variation_license_group_id];
                }
            }


            if( $order_product->get_variation_id() != $product_id ) {
                continue;
            }

            /** update the existing licenses */
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix.'woocommerce_software_licence',
                array(
                    'order_item_id' => $order_item_key,
                    'group_id'      => 0,
                ),
                array(
                    'order_id' => $order_id
                )
            );

            /**
             * Generate  keys
             * @var {WOO_SL_functions|WOO_SL_functions}
             */
            $_group_title                       =   array();
            $_licence_prefix                    =   array();
            $_max_keys                          =   array();
            $_max_instances_per_key             =   array();
            $_use_predefined_keys               =   array();
            $_product_use_expire                =   array();
            $_product_expire_renew_price        =   array();
            $_product_expire_units              =   array();
            $_product_expire_time               =   array();
            $_product_expire_starts_on_activate =   array();
            $_product_expire_disable_update_link=   array();
            $_product_expire_limit_api_usage    =   array();
            $_product_expire_notice             =   array();

            foreach( $product_sl_groups as  $key => $product_sl_group ) {
                $_group_title[]                     =   $product_sl_group['group_title'];
                $_licence_prefix[]                  =   $product_sl_group['licence_prefix'];
                $_max_keys[]                        =   $product_sl_group['max_keys'];
                $_max_instances_per_key[]           =   $product_sl_group['max_instances_per_key'];
                $_use_predefined_keys[]             =   $product_sl_group['use_predefined_keys'];
                $_product_use_expire[]                =   $product_sl_group['product_use_expire'];
                $_product_expire_renew_price[]        =   $product_sl_group['product_expire_renew_price'];
                $_product_expire_units[]              =   $product_sl_group['product_expire_units'];
                $_product_expire_time[]               =   $product_sl_group['product_expire_time'];
                $_product_expire_starts_on_activate[] =   $product_sl_group['product_expire_starts_on_activate'];
                $_product_expire_disable_update_link[]=   $product_sl_group['product_expire_disable_update_link'];
                $_product_expire_limit_api_usage[]    =   $product_sl_group['product_expire_limit_api_usage'];
                $_product_expire_notice[]             =   $product_sl_group['product_expire_notice'];
            }
            $data['group_title']                            =   $_group_title;
            $data['licence_prefix']                         =   $_licence_prefix;
            $data['max_keys']                               =   $_max_keys;
            $data['max_instances_per_key']                  =   $_max_instances_per_key;
            $data['use_predefined_keys']                    =   $_use_predefined_keys;
            $data['product_use_expire']                     =   $_product_use_expire;
            $data['product_expire_renew_price']             =   $_product_expire_renew_price;
            $data['product_expire_units']                   =   $_product_expire_units;
            $data['product_expire_time']                    =   $_product_expire_time;
            $data['product_expire_starts_on_activate']      =   $_product_expire_starts_on_activate;
            $data['product_expire_disable_update_link']     =   $_product_expire_disable_update_link;
            $data['product_expire_limit_api_usage']         =   $_product_expire_limit_api_usage;
            $data['product_expire_notice']                  =   $_product_expire_notice;
            $data                                           =   apply_filters('woo_sl/order_processed/product_sl', $data, $order_product, $order_id);

            wc_update_order_item_meta($order_item_key, '_woo_sl', $data);
            foreach ( $data['product_use_expire']    as  $data_key    =>  $data_block_value ) {
                if ( $data_block_value    !=  'no' ) {
                    wc_update_order_item_meta($order_item_key, '_woo_sl_licensing_using_expire', $data_block_value );

                    //continue only if expire_starts_on_activate is not set to yes
                    $expire_starts_on_activate  =   $data['product_expire_starts_on_activate'][$data_key];
                    if ( $expire_starts_on_activate ==  'yes' ) {
                        //set currently as not-activated
                        wc_update_order_item_meta($order_item_key, '_woo_sl_licensing_status', 'not-activated');
                        continue;
                    }

                    if ( $data_block_value    ==  'yes' ) {
                        $today      =   date("Y-m-d", current_time( 'timestamp' ));
                        $start_at   =   strtotime($today);
                        wc_update_order_item_meta($order_item_key, '_woo_sl_licensing_start', $start_at);
                        $_sl_product_expire_units   =   $data['product_expire_units'][$data_key];
                        $_sl_product_expire_time    =   $data['product_expire_time'][$data_key];
                        $expire_at  =   strtotime( " + " . $_sl_product_expire_units . " " . $_sl_product_expire_time,  $start_at);
                        wc_update_order_item_meta($order_item_key, '_woo_sl_licensing_expire_at', $expire_at);
                    }
                }
            }
        }
    }


	/**
	 *
	 */
	public function get_max_allowed_for_plan( $plan_id ) {

		switch ( $plan_id ):
			case( 'wpfunnels_tier1' ):
				$max_allowed = 5;
				break;
			case( 'wpfunnels_tier2' ):
				$max_allowed = 15;
				break;
			default:
				$max_allowed = 1000; // unlimited
		endswitch;

		return $max_allowed;

	}


    /**
     * api action for refund
     *
     * @param \WP_REST_Request $request
     * @return bool|int|\WP_Error|\WP_REST_Response
     */
	public function api_action_refund( \WP_REST_Request $request ) {


		// make sure a license is created and
		$deactivate_license = $this->deactivate_license( $request );

		if ( is_wp_error( $deactivate_license ) ) {
			return $deactivate_license;
		}

		$remove_user = $this->remove_user( $request );

		if ( is_wp_error( $remove_user ) ) {
			return $remove_user;
		}

		$data          = new \stdClass();
		$data->message = esc_html__( 'Product refunded. User Account and License removed', 'appsumo-licensing' );

		$response = new \WP_REST_Response();
		$response->set_status( 200 );
		$response->set_data( $data );

		return rest_ensure_response( $response );

	}


    /**
     * license deactivate
     *
     * @param \WP_REST_Request $request
     * @return int|\WP_Error
     */
	public function deactivate_license( \WP_REST_Request $request ) {

		$license_key = sanitize_key( $request->get_param( 'uuid' ) );

		if ( empty( $license_key ) ) {
			return $this->get_error( 'invalid_request', esc_html__( 'API request is invalid.', 'appsumo-licensing' ) );
		}

//		$license_id = $this->license->get_post_id_from_key( $license_key );
        $order_id   = $this->get_order_id_from_key( $license_key );
		if ( ! $order_id ) {
			return $this->get_error( 'license_not_found', esc_html__( 'License could not be found for the key', 'appsumo-licensing' ) );
		}

		global $wpdb;
		$order = wc_get_order($order_id);
		$order->update_status('refunded');
        $items                  = $order->get_items('line_item');
        foreach ($items as $item_key => $item) {
            $wpdb->delete(
                $wpdb->prefix.'woocommerce_software_licence',
                array(
                    'order_id' => $order_id
                )
            );
        }
        return true;
	}


    /**
     * remove user
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
	public function remove_user( \WP_REST_Request $request ) {

		$user_email = $request->get_param( 'activation_email' );

		if ( empty( $user_email ) ) {
			return $this->get_error( 'invalid_request', esc_html__( 'API request is invalid.', 'appsumo-licensing' ) );
		}


		$user = get_user_by_email( $user_email );

		if ( ! $user ) {
			return $this->get_error( 'user_not_found', esc_html__( 'User could not be found.', 'appsumo-licensing' ) );
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			include_once trailingslashit( ABSPATH ) . 'wp-admin/includes/user.php';
		}

		$user_deleted = wp_delete_user( $user->ID );

		if ( ! $user_deleted ) {
			return $this->get_error( 'user_not_deleted', esc_html__( 'User could not be deleted.', 'appsumo-licensing' ) );
		}

		return $user_deleted;

	}


    /**
     * api callback not found exception
     *
     * @param $request
     * @return \WP_Error|\WP_REST_Response
     */
	public function api_action_not_found( $request ) {

		$error = $this->get_error( 'api_action_not_found', esc_html__( 'No such API action found.', 'appsumo-licensing' ) );

		return rest_ensure_response( $error );

	}


	/**
	 *
	 */
	public function set_error( $code, $message, $status_code = 403 ) {

		$this->error = $this->get_error( $code, $message, $status_code );

	}


	/**
	 *
	 */
	public function send_api_error() {

		$response = new \WP_REST_Response( $this->error );

		return $response;
//		exit();


	}


    /**
     * @param $data
     * @param $order_product
     * @param $order_id
     */
	public function filter_license_data( $data, $order_product, $order_id ) {
        $data['vendor_purchase_code'] = sanitize_key( $this->request->get_param( 'invoice_item_uuid' ) ) ?? '';
        $data['licensee_company']     = esc_html__( 'AppSumo', 'appsumo-licensing' );
        $data['notes']                = esc_html__( 'License Generated programmatically for AppSumo purchase', 'appsumo-licensing' );
    }

    /**
     * @param $license_key
     * @param $order_id
     * @param $order_item_id
     * @param $license_group_id
     * @return string
     */
	public function filter_licence_key( $license_key, $order_id, $order_item_id, $license_group_id ) {
        $license_key    = sanitize_key( $this->request->get_param( 'uuid' ) );
        return $license_key;
    }


	/**
	 *
	 */
	public function filter_license_meta_with_request( $license_meta ) {

		$plan_id = sanitize_key( $this->request->get_param( 'plan_id' ) );

		$max_allowed = $this->get_max_allowed_for_plan( $plan_id );

		$license_meta['max_allowed']          = $max_allowed;
		$license_meta['license_key']          = sanitize_key( $this->request->get_param( 'uuid' ) ) ?? '';
		$license_meta['vendor_purchase_code'] = sanitize_key( $this->request->get_param( 'invoice_item_uuid' ) ) ?? '';
		$license_meta['licensee_company']     = esc_html__( 'AppSumo', 'appsumo-licensing' );
		$license_meta['notes']                = esc_html__( 'License Generated programmatically for AppSumo purchase', 'appsumo-licensing' );

		return $license_meta;
	}


	/**
	 * WP REST api endpoint introduction
	 * @hooked 'init'
	 */
	public function rest_api_init() {
		add_action( 'rest_api_init', function () {
			$api_end_point = $this->api_endpoint();
			register_rest_route( $api_end_point, '(?P<action>[^?&]*)', array(
				'methods'  => 'GET',
				'callback' => array( $this, 'handle_request' )
			) );
		} );
	}


	/**
	 * Helper method to get api_endpoint
	 *
	 * @param string $action
	 *
	 * @return string
	 */
	public function api_endpoint( $action = '' ) {
		$endpoint = trailingslashit( Globals::get_options( 'api_endpoint' ) );

		// Remove leading '/' if added by user.
		$endpoint = ltrim( $endpoint, '/' );
		if ( ! empty( $action ) ) {
			$endpoint = trailingslashit( $endpoint . (string) $action );
		}

		return $endpoint;
	}


	/**
	 * The handler function that receives the API calls and passes them on to the
	 * proper handlers.
	 *
	 * @called rest_api_init
	 *
	 * @param $request
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_request( $request ) {
		$this->action = isset( $request['action'] ) ? sanitize_key( $request['action'] ) : '';

		if ( ! $this->is_valid_action( $this->action ) ) {
			$this->log_error( 'invalid_api_action' );

			return $this->send_response();
		}

		$dirty_params = $_GET;

		$checks_passed = $this->process_params( $dirty_params );

		if ( $checks_passed ) {
			$this->process_request();
		}

		return $this->send_response();
	}


	/**
	 * @param $action
	 *
	 * @return bool
	 */
	private function is_valid_action( $action ) {
		return ( in_array( $action, $this->get_allowed_actions() ) );
	}


	/**
	 *
	 */
	public function get_allowed_actions() {

		/**
		 * info => used to get license info
		 * get => for getting asset and/or license info
		 * validate => for validating vendor keys and creating license against that
		 * deactivate => to deactivate a license for a domain . if single domain, then delete, if multi domain, then delete just domain
		 */

		return array(
			'info',
			'get',
			'validate',
			'deactivate',
			'activate'
//			'create',
		);
	}


	/**
	 * Log error
	 *
	 * @param $code
	 */
	public function log_error( $code ) {
		$this->api_response->log_error( $code );
	}


	/**
	 * Prints out the JSON response for an API call.
	 *
	 * @return \WP_REST_Response
	 */
	private function send_response() {
		$logged_success = array();

		/*
		 * First, check for errors
		 */
		$logged_errors = $this->api_response->get_logged_errors();
		if ( ! empty( $logged_errors ) ) {
			$this->build_response( 'error', $logged_errors );
		} else {
			$logged_success = $this->api_response->get_logged_success();
			$this->build_response( 'success', $logged_success );
		}


		if ( $this->debug ) {
			$this->response['debug']['params']  = $this->params;
			$this->response['debug']['request'] = $_REQUEST;
		}

		$this->response['log']['errors']  = $logged_errors;
		$this->response['log']['success'] = $logged_success;

		$response = new \WP_REST_Response( $this->response );
		$response->set_status( 200 );

		$this->log_response( $this->response );

		return $response;
	}


	/**
	 * @param $nature
	 * @param $logs
	 */
	public function build_response( $nature, $logs ) {
		$keys         = array_keys( $logs );
		$message_code = array_pop( $keys );
		$logs         = array_pop( $logs );

		$response                 = $this->get_default_response( $nature );
		$response['message']      = $logs['message'];
		$response['message_code'] = $message_code;
		$this->response_code      = $logs['code'];

		$this->response = array_merge( $response, $this->response );
	}


	private function get_default_response( $type = 'success' ) {
		$success_response = array(
			'success' => true,
			'error'   => false,
		);

		$error_response = array(
			'error'   => true,
			'success' => false,
		);

		return ( 'error' === $type ) ? $error_response : $success_response;
	}


	/**
	 *
	 */
	public function log_response( $response ) {

		global $wpdb;

		$table_name = $wpdb->prefix . ULICENSE_TABLE_NAME_API_REQUEST_LOG;

		$wpdb->insert(
			$table_name,
			array(
				'time'         => current_time( 'mysql' ),
				'license'      => esc_sql( $this->params->license ),
				'domain'       => esc_sql( $this->params->domain ),
				'asset'        => esc_sql( $this->params->asset ),
				'email'        => esc_sql( $this->params->email ),
				'vendor'       => esc_sql( $this->params->vendor ),
				'key'          => esc_sql( $this->params->key ),
				'message_code' => $this->response['message_code'],
				'is_error'     => $this->response['error']
			)
		);

	}


	/**
	 * Process the dirty request parameters and sanitizes to be used later
	 *
	 * @param $params
	 *
	 * @return bool
	 */
	public function process_params( $params ) {
		// Filter Request for non-standard api call
		/*
		 * Get and Check Private Key.
		 */
		$this->params->private = isset( $params['private'] ) ? sanitize_text_field( $params['private'] ) : '';
		if ( 'create' === $this->action ) {
			if ( empty( $this->params->private ) ) {
				$this->log_error( 'missing_private_key' );

				return false;
			}

			$saved_private_key = Globals::get_options( 'private_key' );

			if ( $saved_private_key != $this->params->private ) {
				$this->log_error( 'invalid_private_key' );

				return false;
			}
		}

		/*
		 * Get and Check Public Key.
		 */
		$this->params->public = isset( $params['public'] ) ? sanitize_text_field( $params['public'] ) : '';
		$is_required          = Globals::get_options( 'api_require_public' );
		if ( 'yes' === $is_required ) {
			if ( empty( $this->params->public ) ) {
				$this->log_error( 'missing_public' );

				return false;
			}

			$saved_public_key = Globals::get_options( 'public_key' );

			if ( $saved_public_key !== $this->params->public ) {
				$this->log_error( 'invalid_public_key' );

				return false;
			}
		}


		/*
		 * Get and set other param.
		 */
		$this->params->license = isset( $params['license'] ) ? sanitize_text_field( $params['license'] ) : '';
		$this->params->asset   = isset( $params['asset'] ) ? sanitize_key( $params['asset'] ) : '';
		$this->params->email   = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$this->params->source  = isset( $params['source'] ) ? sanitize_key( $params['source'] ) : '';
		$this->params->id      = isset( $params['id'] ) ? sanitize_key( $params['id'] ) : '';
		/**
		 * This will be the type of license
		 */
		$this->params->type = isset( $params['type'] ) ? sanitize_key( $params['type'] ) : 'plugin';

		// key is purchased key as from envato
		$this->params->key = isset( $params['key'] ) ? sanitize_text_field( $params['key'] ) : '';

		// vendor is like envato
		$this->params->vendor = isset( $params['vendor'] ) ? sanitize_key( $params['vendor'] ) : 'author';

		// for plugin type
		$this->params->domain = isset( $params['domain'] ) ? parse_domain_from_url( $params['domain'] ) : '';
		// for software type
		$this->params->computer = isset( $params['computer'] ) ? sanitize_key( $params['computer'] ) : '';
		$this->params->name     = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		$this->params->force    = isset( $params['force'] ) && boolval( $params['force'] ) ? 1 : 0;

		return true;
	}


	/**
	 * The handler function that receives the API calls and passes them on to the
	 * proper handlers.
	 *
	 */
	public function process_request() {
		switch ( $this->action ):

			case ( 'info' ):
				$this->send_license_info();

				break;

			case ( 'get' ):
				$this->send_license_info();

				if ( $this->is_type_plugin() || $this->is_type_software() ) {
					$this->send_asset_info();
				}

				break;


			case ( 'validate' ):

				$this->handle_action_validate();

				break;

			case ( 'activate' ):

				$this->handle_action_activate();

				break;

			case ( 'deactivate' ):

				$this->handle_action_deactivate();

				break;

			default:
				$this->log_error( 'invalid_api_action' );
				break;
		endswitch;
	}


	/**
	 * This will send license info to response
	 */
	public function send_license_info() {
		$license_info = $this->fetch_license_info();

		if ( $license_info ) {
			$this->response( $license_info, 'license' );
		}
	}


	/**
	 * Fetch Licence info from server
	 */
	public function fetch_license_info() {
		$license_id = $this->get_license_id();

		if ( $license_id ) {
			return $this->get_license_meta( $license_id );
		}

		return null;
	}


	/**
	 * Get license id or log error
	 */
	public function get_license_id() {
		if ( empty( $this->license_id ) ) {
			if ( empty( $this->params->license ) ) {

				// try to get from the key provided
				$license_key = $this->get_license_key_for_vendor();
				if ( ! $license_key ) {
					$this->log_error( 'license_key_missing' );

					return false;
				}

				$license_id = $this->license->get_post_id_from_key( $license_key );
				if ( $license_id ) {
					$this->license_id = $license_id;

					return $this->license_id;
				}
			} else {
				$license_id = $this->license->get_post_id_from_key( $this->params->license );

				if ( $license_id <= 0 ) {
					$this->log_error( 'invalid_license_key' );

					return false;
				} else {
					$this->license_id = $license_id;
				}
			}
		}

		return $this->license_id;
	}


	/**
	 * Get License key for vendor specific cases
	 */
	public function get_license_key_for_vendor() {
		switch ( $this->params->vendor ):

			case( 'envato' ):
				return $this->license->get_license_key_for_purchase_code( $this->params->key );

				break;
			case( 'author' ):

				return $this->params->key;
				break;

			default:

				return false;
		endswitch;
	}


	/**
	 * @param string $license_id
	 *
	 * @return mixed|void
	 */
	public function get_license_meta( $license_id = '' ) {
		$license_id = ! empty( $this->get_license_id() ) ? $this->get_license_id() : $license_id;

		$license_meta = array();

		$license_meta['id'] = $license_id;


		$license_post_meta = get_post_meta( $license_id );

		$license_post_meta = array_map( 'array_pop', $license_post_meta );

		$license_meta['license_key']      = esc_html( $license_post_meta[ $this->prefix . 'license_key' ] );
		$license_meta['max_allowed']      = absint( $license_post_meta[ $this->prefix . 'max_allowed' ] );
		$license_meta['status']           = sanitize_key( $license_post_meta[ $this->prefix . 'status' ] );
		$license_meta['date_activated']   = sanitize_key( $license_post_meta[ $this->prefix . 'date_activated' ] );
		$license_meta['date_renewed']     = sanitize_key( $license_post_meta[ $this->prefix . 'date_renewed' ] );
		$license_meta['date_expiry']      = sanitize_key( $license_post_meta[ $this->prefix . 'date_expiry' ] );
		$license_meta['licensee_name']    = esc_html( $license_post_meta[ $this->prefix . 'licensee_name' ] );
		$license_meta['licensee_email']   = sanitize_email( $license_post_meta[ $this->prefix . 'licensee_email' ] );
		$license_meta['licensee_company'] = esc_html( $license_post_meta[ $this->prefix . 'licensee_company' ] );
		if ( $this->is_type_plugin() ) {
			$license_meta['active_domains'] = count( wp_list_filter( unserialize( $license_post_meta[ $this->prefix . 'active_domains' ] ), [ 'active' => true ] ) );
		}


		$license_meta = apply_filters( 'ulicense_api_license_meta', $license_meta, $license_id );

		return $license_meta;
	}


    /**
     * get order id by key
     *
     * @return bool|integer
     */
	public function get_order_id_from_key( $key ) {
        global $wpdb;
        $table  = $wpdb->prefix.'woocommerce_software_licence';
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE licence = %s", $key ) );

        if($result) {
            return (int)$result->order_id;
        }
        return false;
    }

	/**
	 *
	 */
	public function is_type_plugin() {

		return $this->params->type === 'plugin';
	}


	/**
	 * @param $message
	 * @param string $key
	 */
	public function response( $message, $key = '' ) {
		$this->response[ $key ] = $message;
	}


	/**
	 *
	 */
	public function is_type_software() {

		return $this->params->type === 'software';
	}


	public function send_asset_info() {
		$asset_info = $this->fetch_asset_info();

		if ( $this->license_id ) {
			$verify_license = $this->verify_license();
		}

		// Fetch Download Link
		if ( $this->is_type_plugin() ):
			//Update Digital Asset Meta with package Url
			if ( $verify_license ) {
				$asset_info['download_url'] = $this->get_package_url( $this->product->id );
			}

			if ( ! isset( $asset_info['download_url'] ) ) {
				$asset_info['download_url'] = '';
			}

			if ( $asset_info ) {
				$this->response( $asset_info, 'digital_asset' );
			}
		endif;
	}


	/**
	 * Fetch Licence info from server
	 */
	public function fetch_asset_info() {

		// Try to Get the product id from api request
		$param_product_id = $this->get_product_id_for_asset_identifier( $this->params->asset );

		// Get the product from Licence ID
		$license_id = $this->get_license_id();

		$license_product_id = get_post_meta( $license_id, $this->prefix . 'woo_product_id', true );

		if ( $param_product_id ) {
			$this->product->id = $product_id = $param_product_id;
		} elseif ( $license_product_id ) {
			$this->product->id = $product_id = $license_product_id;
		} else {
			// no product found
			return null;
		}


		// 2. Get the License info from this download file
		if ( $this->is_type_plugin() ) {
			$this->product->parsed_data = $this->get_woocommerce_digital_asset_parsed_readme( $product_id );

			if ( ! empty( $this->product->parsed_data ) ) {
				return $this->product->parsed_data;
			}
		}


		return null;
	}


	/**
	 * Get Woocommerce product id from slug
	 *
	 * @param $asset_identifier
	 *
	 * @return int
	 */
	public function get_product_id_for_asset_identifier( $asset_identifier ) {
		// Try to Get the product id from api request
		//		$param_product = get_page_by_path( $asset_identifier, OBJECT, 'product' );
//
		//		if ( $param_product ) {
		//			$param_product_id = $param_product->ID;
		//		} else {
		//			$param_product_id = 0;
		//		}
//
		//		return $param_product_id;
//
		$product_id = null;

		if ( empty( $asset_identifier ) ) {
			return absint( $product_id );
		}

		$args          = array(
			'posts_per_page' => 1,
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_' . $this->prefix . 'asset_identifier',
					'value' => $asset_identifier
				)
			)
		);
		$product_query = new \WP_Query( $args );

		if ( $product_query->have_posts() ) {
			foreach ( $product_query->get_posts() as $product ) {
				$product_id = $product->ID;
				break;
			} // end while
		} // end if
		wp_reset_postdata();

		return absint( $product_id );
	}


	public function get_woocommerce_digital_asset_parsed_readme( $product_id ) {
		$product_id             = absint( $product_id );
		$digital_asset_metadata = array();

		$product = wc_get_product( $product_id );


		$downloads = $product->get_downloads();

		// instantiate the variable
		$file_path_array = array();

		if ( is_array( $downloads ) && ! empty( $downloads ) ) {
			foreach ( $downloads as $download_id => $download_object ) {
				$download_path = $product->get_file_download_path( $download_id );

				$this->product->download_path[] = $download_path;
				// Remove trailing slashes
				$path          = untrailingslashit( ABSPATH );
				$relative_link = wp_make_link_relative( $download_path );

				$file_path = $path . $relative_link;

				$file_path_array[] = $file_path;
			}
		}

		$this->product->file_path_array = $file_path_array;

		if ( ! empty( $file_path_array ) ) {
			foreach ( $file_path_array as $index => $zip_file_path ) {

				/*
				 * Details: https://github.com/tutv/wp-package-parser
				 */
				$package                = new \Max_WP_Package( $zip_file_path );
				$digital_asset_metadata = $package->get_metadata();
			}
		}


		return $digital_asset_metadata;
	}


	/**
	 * verify the license for:
	 *
	 * 1. validity
	 * 2. against domain
	 *
	 * @param null $license_id
	 *
	 * @return bool
	 */
	public function verify_license( $license_id = null ) {
		$license_id = is_null( $license_id ) ? $this->license_id : $license_id;

		// is license Active
		$is_license_active = $this->is_license_active( $license_id );
		if ( ! $is_license_active ) {
			/*
				errors are already handled for license inactive, so, only return false
			*/
			return false;
		}

		// is license valid
		$is_license_valid = $this->is_license_valid( $license_id );
		if ( ! $is_license_valid ) {
			/*
				errors are already handled for license inactive, so, only return false
			*/
			return false;
		}

		// is domain valid
		if ( $this->is_type_plugin() && ! $this->verify_domain( $this->params->domain ) ) {
			/**
			 * Error already handled
			 */

			return false;
		}

		// is computer valid
		if ( $this->is_type_software() && ! $this->verify_computer( $this->params->computer ) ) {
			/**
			 * Error already handled
			 */

			return false;
		}

		// is license configured asset is same as requested asset
		if ( ( $this->is_type_plugin() || $this->is_type_software() ) && ! $this->is_requested_asset_match_with_license_asset() ) {
			// error already handled
			return false;
		}


		return true;
	}


	/**
	 * verify the license for license status
	 *
	 * @param null $license_id
	 *
	 * @return bool
	 */
	public function is_license_active( $license_id = null ) {
		$license_id = is_null( $license_id ) ? $this->license_id : $license_id;

		// is license Active
		$license_status = get_post_meta( $license_id, $this->prefix . 'status', true );

		if ( 'active' === $license_status ) {
			return true;
		}

		// handle the cases for license status not 'active'
		switch ( $license_status ):
			case( 'suspended' ):
				$this->log_error( 'license_suspended' );
				break;
			case( 'pending' ):
				$this->log_error( 'license_pending' );
				break;
			case( 'expired' ):
				$this->log_error( 'license_expired' );
				break;
			default:
				$this->log_error( 'license_status_invalid' );
		endswitch;


		return false;
	}


	/**
	 * verify the license for license validity against expiry date
	 *
	 * @param null $license_id
	 *
	 * @return bool
	 */
	public function is_license_valid( $license_id = null ) {
		$license_id = is_null( $license_id ) ? $this->license_id : $license_id;

		// is license Active
		$date_expiry = get_post_meta( $license_id, $this->prefix . 'date_expiry', true );

		$date_expiry_formatted = strtotime( $date_expiry ) + 60 * 60 * 24; // grace period of 1 day to accommodate different time zones

		// Assuming that no expiry means unlimited validity, so returning valid license true
		if ( empty( $date_expiry ) || $date_expiry_formatted > time() ) {
			return true;
		}

		$this->log_error( 'license_expired' );

		return false;
	}

	/**
	 * Verify if domain is allowed in license
	 *
	 * @param $license_id
	 * @param $domain
	 *
	 * @return bool
	 */
	public function verify_domain( $domain, $license_id = null ) {

		$license_id = is_null( $license_id ) ? $this->license_id : $license_id;

		$domain_from_license = $this->license->get_domain( $domain, $license_id );

		if ( empty( $domain_from_license ) ) {
			$this->log_error( 'domain_not_found' );
			$this->license->log( 'domain_not_found', $domain );

			return false;
		}

		// if the array just have domain, then its not active, we need to throw error in api
		if ( isset( $domain_from_license['domain'] ) && ! isset( $domain_from_license['active'] ) ) {
			$this->log_error( 'domain_is_marked_inactive' );
			$this->license->log( 'domain_is_marked_inactive', $domain );

			return false;
		}

		// if the array have 'active' it means we have the domain and need to reactivate
		if ( isset( $domain_from_license['active'] ) && $domain_from_license['active'] ) {

			return true;
		}

		return false;


	}


	/**
	 * Verify if computer is allowed in license
	 *
	 * @param $license_id
	 * @param $computer
	 *
	 * @return bool
	 */
	public function verify_computer( $computer, $license_id = null ) {
		$license_id = is_null( $license_id ) ? $this->license_id : $license_id;

		$computer_from_license = $this->license->get_computer( $computer, $license_id );

		if ( empty( $computer_from_license ) ) {
			$this->log_error( 'computer_not_found' );
			$this->license->log( 'computer_not_found', $computer );

			return false;
		}

		// if the array just have domain, then its not active, we need to throw error in api
		if ( isset( $computer_from_license['computer'] ) && ! $computer_from_license['active'] ) {
			$this->log_error( 'computer_is_marked_inactive' );
			$this->license->log( 'computer_is_marked_inactive', $computer );

			return false;
		}

		// if the array have 'active' it means we have the domain and need to reactivate
		if ( isset( $computer_from_license['active'] ) && $computer_from_license['active'] ) {
			$this->log_success( 'computer_is_active' );

			return true;
		}

		return false;


	}


	/**
	 * Log error
	 *
	 * @param $code
	 */
	public function log_success( $code ) {
		$this->api_response->log_success( $code );
	}

	/**
	 *
	 */
	public function is_requested_asset_match_with_license_asset() {
		$license_id         = $this->get_license_id();
		$param_product_id   = $this->get_product_id_for_asset_identifier( $this->params->asset );
		$license_product_id = get_post_meta( $license_id, $this->prefix . 'woo_product_id', true );

		if (
			! empty( $this->params->asset )
			//            && ! empty($this->params->license)
			&& (int) $param_product_id !== (int) $license_product_id
		) {
			$this->log_error( 'license_asset_mismatch' );

			return false;
		} else {
			return true;
		}
	}

	/**
	 * @param $product_id
	 *
	 * @return bool|string
	 */
	public function get_package_url( $product_id ) {
		$method = get_post_meta( $product_id, '_ulicense_asset_hosted_at', true );


		switch ( $method ) {
			case( 'aws' ):

				return $this->get_package_url_aws( $product_id );
				break;

			default:
//				TODO: Add more Methods to get digital asset package url for downloading zip
//				return get_post_meta( $product_id, '_asset_banner_high', true );
				return false;


		}
	}

	protected function get_package_url_aws( $product_id ) {
		$aws_key       = Globals::get_options( 'aws_key' );
		$aws_secret    = Globals::get_options( 'aws_secret' );
		$bucket_region = get_post_meta( $product_id, '_ulicense_aws_region', true );
		$bucket        = get_post_meta( $product_id, '_ulicense_aws_bucket', true );
		$file_name     = get_post_meta( $product_id, '_ulicense_aws_file', true );


		if ( $aws_key && $aws_secret && $bucket_region && $bucket && $file_name ) {
			$presigned_url = $this->get_s3_url( $aws_key, $aws_secret, $bucket_region, $bucket, $file_name );

			return esc_url_raw( $presigned_url );
		} else {
			return false;
		}
	}

	protected function get_s3_url( $aws_key, $aws_secret, $bucket_region, $bucket, $file_name, $version = 'latest' ) {
		if ( ! class_exists( '\Aws\S3\S3Client' ) ) {
			return false;
		}

		$credentials = new \Aws\Credentials\Credentials( $aws_key, $aws_secret );

		/** @noinspection PhpUndefinedClassInspection */
		$s3_client = new \Aws\S3\S3Client(
			array(
				'region'      => $bucket_region,
				'version'     => $version,
				'credentials' => $credentials
			)
		);

		$command = $s3_client->getCommand( 'GetObject', [
			'Bucket' => $bucket,
			'Key'    => $file_name
		] );

		/** @noinspection PhpParamsInspection */
		$req = $s3_client->createPresignedRequest( $command, '+1440 minutes' );

		return $presignedUrl = (string) $req->getUri();
	}

	/**
	 * function to handle vendor key validation and returning responses to the client
	 */
	public function handle_action_validate() {

		/**
		 * Check if all the required params are provided
		 *
		 * validate the vendor key
		 *
		 * create license against the vendor key if verified
		 *
		 * try to link license to the product
		 *
		 * send license info
		 *
		 */


		/**
		 * Check if all the required params are provided
		 */
		if ( empty( $this->params->key ) ) {
			$this->log_error( 'validation_key_missing' );

			return false;
		}

		if ( ( $this->is_type_plugin() || $this->is_type_software() ) && empty( $this->params->vendor ) ) {
			$this->log_error( 'validation_vendor_missing' );

			return false;
		}

		if ( $this->is_type_plugin() && empty( $this->params->domain ) ) {
			$this->log_error( 'missing_domain' );

			return false;
		}

		if ( $this->is_type_software() && empty( $this->params->computer ) ) {
			$this->log_error( 'missing_computer' );

			return false;
		}

		if ( ( $this->is_type_plugin() || $this->is_type_software() ) && empty( $this->params->asset ) ) {
			$this->log_error( 'missing_asset' );

			return false;
		}


		/**
		 * validate the vendor key
		 */
		$vendor_key_validated = $this->validate_vendor_key();
		if ( ! $vendor_key_validated ) {
			// error codes already handled
			$this->license->log( 'vendor_key_not_validated', $this->params->domain );

			return false;
		} else {
			$this->log_success( 'vendor_purchase_code_validated' );
			$this->license->log( 'vendor_purchase_code_validated', $this->params->domain );
		}

		// validation of codes are done so far,

		/*
		 * Check there is no existing license with this verification code
		 */
		$license_key = $this->get_license_key_for_vendor();

		// If we get the license key: then we check if we can activate the existing License
		// If we do not get the existing license key for the vendor, then we generate new one.

		// If a license is found.
		if ( ! empty( $license_key ) ) {
			// check if we can activate the existing License
			/**
			 * validate if we are going to activate license for same digital asset
			 */
			if ( ! $this->is_requested_asset_match_with_license_asset() ) {
				// Error already handled
				return false;
			}

			// Get license id to check validity and active status
			$this->license_id = $this->license->get_post_id_from_key( $license_key );

			if ( ! $this->is_license_active( $this->license_id ) ) {
				// Error codes taken care of
				return false;
			}

			if ( ! $this->is_license_valid( $this->license_id ) ) {
				// Error codes taken care of
				return false;
			}

			// set license key
			$this->params->license = $license_key;
			// send license info
			$this->send_license_info();

			if ( $this->is_type_plugin() ) {
				return $this->process_domain_validation_tasks();
			}
			if ( $this->is_type_software() ) {
				return $this->process_software_validation_tasks();
			}

		} else {
			/**
			 * create license against the vendor key if verified
			 */
			$generated_license_post_id = $this->generate_license_for_vendor();
			if ( ! $generated_license_post_id ) {
				// Error codes already logged
				return false;
			}
			// Configure the license params
			$this->license_id = $generated_license_post_id;
			$this->send_license_info();
			$this->log_success( 'license_generated_vendor' );
			$this->license->log( 'license_generated_vendor', $this->params->domain );

			return true;
		}
	}

	/**
	 * Validate Vendor key
	 *
	 * Log the relevant errors
	 *
	 */
	protected function validate_vendor_key() {
		switch ( $this->params->vendor ):

			case( 'envato' ):
				// 1. get envato credentials to make api call
				$envato_api_token = Globals::get_options( 'envato_api_token' );

				if ( ! empty( $envato_api_token ) ) {
					$verified = $this->verify_envato_sale( $envato_api_token, $this->params->key );


					if ( ! $verified ) {
						$this->log_error( 'envato_purchase_code_invalid' );

						return false;
					} else {
						$this->log_success( 'envato_purchase_code_validated' );

						return true;
					}
				}
				break;
			case( 'author' ):

				if ( $this->license->get_post_id_from_key( $this->params->key ) > 0 ) {
					$this->log_success( 'vendor_purchase_code_validated' );

					return true;
				} else {
					$this->log_error( 'vendor_purchase_not_validated' );

					return false;
				}

				break;

			default:
				$this->log_error( 'vendor_purchase_not_validated' );

				return false;
		endswitch;

		return false;
	}

	protected function verify_envato_sale( $envato_api_token, $purchaseCode ) {
		if ( empty( $envato_api_token ) || empty( $purchaseCode ) ) {
			return false;
		}

		$enable_cache = Globals::get_options( 'envato_cache_response' );

		// instantiate variable as true
		$curl_error = true;

		if ( 'yes' === $enable_cache ) {
			$curl_response = get_transient(
				'ulicense_curl_' . $this->params->vendor . '_' . $purchaseCode
			);
			$curl_error    = false;
		} else {
			$curl_response = null;
		}

		if ( ! $curl_response ) {
			// make a new curl request
			$curl = new Curl();

			$curl->setHeader( 'Authorization', 'Bearer ' . $envato_api_token );

			$curl->get( 'https://api.envato.com/v3/market/author/sale', array(
				'code' => $purchaseCode
			) );

			$curl->close();
			$curl_error    = $curl->error;
			$curl_response = $curl->response;

			set_transient(
				'ulicense_curl_' . $this->params->vendor . '_' . $purchaseCode,
				$curl_response,
				60 * 60 * 24
			);
		}

		if ( $curl_error ) {
			return false;
		} else {
			$this->params->vendor_response = $curl_response;

			return true;
		}
	}

	/**
	 *
	 */
	public function process_domain_validation_tasks() {
		$domain_from_license = $this->license->get_domain( $this->params->domain );
		// if the array is empty, then we do not have the domain
		if ( empty( $domain_from_license ) ) {
			// check if the existing active domains has the room to add more
			$active_domains = $this->license->get_active_domains();
			if ( count( $active_domains ) >= intval( $this->license->get_max_allowed() ) ) {
				$this->log_error( 'max_allowed_active' );
				$this->license->log( 'max_allowed_active', $this->params->domain );

				return false;
			}

			$this->license->activate_domain( $this->params->domain );
			$this->license->update_status( 'active' );
			$this->log_success( 'domain_activated' );
			$this->license->log( 'domain_activated', $this->params->domain );

			return true;
		}

		// if the array just have domain, then its not active, we need to throw error in api
		if ( isset( $domain_from_license['domain'] ) && ! isset( $domain_from_license['active'] ) ) {
			$this->log_error( 'domain_is_marked_inactive' );
			$this->license->log( 'domain_is_marked_inactive', $this->params->domain );

			return false;
		}
		// if the array have 'active' it means we have the domain and need to reactivate
		if ( isset( $domain_from_license['active'] ) && $domain_from_license['active'] ) {
			$this->license->update_status( 'active' );
			$this->log_success( 'domain_reactivated' );
			$this->license->log( 'domain_reactivated', $this->params->domain );

			return true;
		}

	}

	/**
	 *
	 */
	public function process_software_validation_tasks() {

		$computer_from_license = $this->license->get_computer( $this->params->computer );
		// if the array is empty, then we do not have the computer
		if ( empty( $computer_from_license ) ) {
			// check if the existing active domains has the room to add more
			$active_computers = $this->license->get_active_computers();
			if ( count( $active_computers ) >= intval( $this->license->get_max_allowed() ) ) {
				$this->log_error( 'max_allowed_active' );
				$this->license->log( 'max_allowed_active', $this->params->computer );

				return false;
			}
			$computer_name = $this->params->name ?? esc_html__( 'Anonymous', 'appsumo-licensing' );
			$this->license->activate_computer( $this->params->computer, $computer_name );
			$this->license->update_status( 'active' );
			$this->log_success( 'computer_activated' );
			$this->license->log( 'computer_activated', $this->params->computer );

			return true;
		}

		// if the array just have domain, then its not active, we need to throw error in api
		if ( isset( $computer_from_license['computer'] ) && ! $computer_from_license['active'] ) {
			$this->log_error( 'computer_is_marked_inactive' );
			$this->license->log( 'computer_is_marked_inactive', $this->params->computer );

			return false;
		}
		// if the array have 'active' it means we have the computer and need to reactivate
		if ( isset( $computer_from_license['active'] ) && $computer_from_license['active'] ) {

			$computer_name = ( $this->params->name === $computer_from_license['name'] ) || empty( $this->params->name )
				? $computer_from_license['name']
				: $this->params->name;

			$this->license->activate_computer( $this->params->computer, $computer_name );
			$this->license->update_status( 'active' );
			$this->log_success( 'computer_reactivated' );
			$this->license->log( 'computer_reactivated', $this->params->computer );

			return true;
		}

	}

	/**
	 * generate license CPT with the data
	 *
	 * return post_id or false
	 */
	public function generate_license_for_vendor() {

		/*
		 * 1. Check there is no existing license with this verification code
		 * 2. Create a License for this vendor code
		 */

		//		TODO: Accommodate if a single license key can be used for multiple domains


		//		Details : https://developer.wordpress.org/reference/functions/wp_insert_post/
		$postarr = array(
			'post_title' => $this->params->vendor . ' ' . $this->params->key,
		);

		$meta_input = array();

		$meta_input[ $this->prefix . 'vendor' ]               = $this->params->vendor;
		$meta_input[ $this->prefix . 'vendor_purchase_code' ] = $this->params->key;
		$meta_input[ $this->prefix . 'status' ]               = 'active';
		$meta_input[ $this->prefix . 'date_activated' ]       = date_i18n( 'Y-m-d' );
		$meta_input[ $this->prefix . 'active_domains' ][]     = $this->params->domain;
		$meta_input[ $this->prefix . 'max_allowed' ]          = 1;


		// Try to add vendor related info like date purchased, support valid till
		if ( 'envato' === $this->params->vendor ) {

			// Try to attach product
			$product_id = $this->get_product_id_to_attach_for_vendor( 'envato' );

			if ( intval( $product_id ) > 0 ) {
				$meta_input[ $this->prefix . 'woo_product_id' ] = $product_id;
			} else {
				// attaching product  is required as we shall not be send any asset info if no product attached
				$this->log_error( 'vendor_asset_not_found' );

				return false;
			}

			/**
			 * Update Sold at, supported until and buyer id
			 */
			$sold_at = strtotime( $this->params->vendor_response->sold_at );
			if ( $sold_at ) {
				$meta_input[ $this->prefix . 'vendor_sold_at' ] = date( 'Y-m-d', $sold_at );
			}

			$supported_until = strtotime( $this->params->vendor_response->supported_until );

			if ( $supported_until ) {
				$meta_input[ $this->prefix . 'vendor_supported_until' ] = date( 'Y-m-d', $supported_until );
			}

			$meta_input[ $this->prefix . 'vendor_buyer_id' ] = $this->params->vendor_response->buyer;

			$meta_input[ $this->prefix . 'notes' ] = esc_html__( 'License generated programmatically using the vendor purchase code.', 'appsumo-licensing' );
		}

		$postarr['meta_input'] = $meta_input;

		return $this->license->generate( $postarr );
	}

	/**
	 * @param $vendor
	 *
	 * @return bool|int
	 */
	public function get_product_id_to_attach_for_vendor( $vendor ) {
		switch ( $vendor ):
			case( 'envato' ):
				// Try to attach product
				$product_id = $this->get_product_from_envato_item_id( $this->params->vendor_response->item->id );
				if ( $product_id ) {
					return $product_id;
				} else {
					// try to get the asset info from the params
					$product_id = $this->get_product_id_for_asset_identifier( $this->params->asset );
					if ( $product_id ) {
						return $product_id;
					}
				}
				break;
			default:
				return false;

		endswitch;

		return false;
	}

	/**
	 * Fetch the WooCommerce product id fro  provided envato item id
	 *
	 * return int $product_id or 0
	 *
	 * @param $envato_item_id
	 *
	 * @return int
	 */
	public function get_product_from_envato_item_id( $envato_item_id ) {
		$product_id = null;

		if ( ! $envato_item_id ) {
			return absint( $product_id );
		}

		$args          = array(
			'posts_per_page' => 1,
			'post_type'      => 'product',
			'meta_query'     => array(
				array(
					'key'   => '_' . $this->prefix . 'envato_item_id',
					'value' => $envato_item_id
				)
			)
		);
		$product_query = new \WP_Query( $args );

		if ( $product_query->have_posts() ) {
			foreach ( $product_query->get_posts() as $product ) {
				$product_id = $product->ID;

				break;
			} // end while
		} // end if
		wp_reset_postdata();


		return absint( $product_id );
	}

	/**
	 * function to handle vendor key validation and returning responses to the client
	 */
	public function handle_action_activate() {
		/**
		 * Check if all the required params are provided
		 */
		if ( empty( $this->params->license ) ) {
			$this->log_error( 'license_key_missing' );

			return false;
		}

		if ( $this->is_type_plugin() && empty( $this->params->domain ) ) {
			$this->log_error( 'missing_domain' );

			return false;
		}

		if ( $this->is_type_software() && empty( $this->params->computer ) ) {
			$this->log_error( 'missing_computer' );

			return false;
		}

		/*
		 * if we are activating, we need to verify token
		 */
		if ( ! isset( $_REQUEST['token'] ) || md5( 'token-' . $this->params->license ) !== $_REQUEST['token'] ) {
			$this->log_error( 'security_token_invalid' );

			return false;
		}

		/**
		 * try to get the license from the license key
		 */
		$license_id = $this->license->get_post_id_from_key( $this->params->license );

		if ( ! $license_id ) {
			$this->log_error( 'invalid_license_key' );

			return false;
		}

		$this->send_license_info();

		if ( $this->is_type_plugin() ) {
			return $this->process_domain_activation_tasks();
		}

		if ( $this->is_type_software() ) {
			return $this->process_software_activation_tasks();
		}

		return false;

	}

	/**
	 *
	 */
	public function process_domain_activation_tasks() {

		$domain_from_license = $this->license->get_domain( $this->params->domain );
		// if the array is empty, then we do not have the domain

		if ( empty( $domain_from_license ) ) {
			$this->log_success( 'domain_not_found' );
			$this->license->log( 'domain_not_found', $this->params->domain );

			return true;
		}

		// check if the existing active domains has the room to add more
		$active_domains = $this->license->get_active_domains();
		if ( count( $active_domains ) >= intval( $this->license->get_max_allowed() ) ) {
			$this->log_error( 'max_allowed_active' );
			$this->license->log( 'max_allowed_active', $this->params->domain );

			return false;
		}


		$this->license->activate_domain( $this->params->domain );
		$this->log_success( 'domain_activated' );
		$this->license->log( 'domain_activated', $this->params->domain );

		return true;


	}

	/**
	 *
	 */
	public function process_software_activation_tasks() {


		$active_computers = $this->license->get_active_computers();

		// check if the existing active domains has the room to add more
		if ( count( $active_computers ) >= intval( $this->license->get_max_allowed() ) ) {
			$this->log_error( 'max_allowed_active' );
			$this->license->log( 'max_allowed_active', $this->params->computer );

			return false;
		}

		$computer_name = $this->params->name ?? esc_html__( 'Anonymous', 'appsumo-licensing' );
		$this->license->activate_computer( $this->params->computer, $computer_name );
		$this->license->update_status( 'active' );
		$this->log_success( 'computer_activated' );
		$this->license->log( 'computer_activated', $this->params->computer );

		return true;

	}

	/**
	 * function to handle vendor key validation and returning responses to the client
	 */
	public function handle_action_deactivate() {

		/**
		 * Check if all the required params are provided
		 *
		 * try to get the license from the license key
		 *
		 * Remove the domain from active domain list
		 *
		 * Check if there are still some active domains (Multi Domain Case)
		 *
		 * if no other domain active, deactivate license
		 *
		 */


		/**
		 * Check if all the required params are provided
		 */
		if ( empty( $this->params->license ) ) {
			$this->log_error( 'missing_license_key' );

			return false;
		}

		if ( $this->is_type_plugin() && empty( $this->params->domain ) ) {
			$this->log_error( 'missing_domain' );

			return false;
		}

		if ( $this->is_type_software() && empty( $this->params->computer ) ) {
			$this->log_error( 'missing_computer' );

			return false;
		}

		/*
		 * if we are forcing deactivations, we need to verify nonce
		 */

		if ( $this->params->force && ( ! isset( $_REQUEST['token'] ) || md5( 'token-' . $this->params->license ) !== $_REQUEST['token'] ) ) {
			$this->log_error( 'security_token_invalid' );

			return false;
		}


		/**
		 * try to get the license from the license key
		 */
		$license_id = $this->license->get_post_id_from_key( $this->params->license );

		if ( ! $license_id ) {
			$this->log_error( 'invalid_license_key' );

			return false;
		}


		if ( $this->is_type_plugin() ) {
			/**
			 * Errors already handled
			 */
			return $this->process_domain_deactivation_tasks();
		}

		if ( $this->is_type_software() ) {
			/**
			 * Errors already handled
			 */
			return $this->process_software_deactivation_tasks();
		}

	}

	/**
	 *
	 */
	public function process_domain_deactivation_tasks() {
		/**
		 * Try to Remove the domain from active domain list
		 */
		$domain_detail_from_license = $this->license->get_domain( $this->params->domain );

		$domain_to_delete = isset( $domain_detail_from_license['domain'] ) ?? '';

		if ( ! $domain_to_delete ) {
			$this->log_error( 'domain_not_found' );
			$this->license->log( 'domain_not_found', $this->params->domain );

			return false;
		}

		if ( $this->params->force ) {
			$is_domains_deleted = $this->license->deactivate_domain( $this->params->domain );
		} else {
			$is_domains_deleted = $this->license->delete_domain( $this->params->domain );
		}


		if ( ! $is_domains_deleted ) {
			$this->log_error( 'error_deactivating_domain' );
			$this->license->log( 'error_deactivating_domain', $this->params->domain );

			return false;
		} else {
			$this->log_success( 'domain_deactivated' );
			$this->license->log( 'domain_deactivated', $this->params->domain );

			return true;
		}

	}

	/**
	 *
	 */
	public function process_software_deactivation_tasks() {
		/**
		 * Try to Remove the domain from active domain list
		 */
		$computer_detail_from_license = $this->license->get_computer( $this->params->computer );

		$computer_to_delete = isset( $computer_detail_from_license['computer'] ) ?? '';

		if ( ! $computer_to_delete ) {
			$this->log_error( 'computer_not_found' );
			$this->license->log( 'computer_not_found', $this->params->computer );

			return false;
		}

		if ( $this->params->force ) {
			$is_computer_deleted = $this->license->deactivate_computer( $this->params->computer );
		} else {
			$is_computer_deleted = $this->license->delete_computer( $this->params->computer );
		}


		if ( ! $is_computer_deleted ) {
			$this->log_error( 'error_deactivating_computer' );
			$this->license->log( 'error_deactivating_computer', $this->params->computer );

			return false;
		} else {
			$this->log_success( 'computer_deactivated' );
			$this->license->log( 'computer_deactivated', $this->params->computer );

			return true;
		}

	}

	/**
	 *
	 */
	public function is_type_credits() {

		return $this->params->type === 'credits';
	}

	/**
	 * Activate Licence with domain
	 */
	public function activate_domain() {
		$license_id = $this->get_license_id();

		if ( ! $license_id ) {
			return null;
		}

		$max_allowed = $this->license->get_max_allowed( $license_id );

		$active_domains = $this->license->get_active_domains( $license_id );


		if ( in_array( $this->params->domain, $active_domains, true ) ) {
			$this->log_error( 'domain_already_active' );

			return false;
		}


		if ( count( $active_domains ) >= $max_allowed ) {
			$this->log_error( 'max_allowed_active' );

			return false;
		}

		// Now, try to activate it as existing_active_domains < max_allowed
		$active_domains[] = $this->params->domain;
		//		$active_domains = array_merge( $existing_active_domains, array( $this->params->domain ) );

		$this->update_activation_date( $license_id );

		return update_post_meta( $license_id, $this->prefix . 'active_domains', $active_domains );
	}

	/**
	 * @param $license_id
	 *
	 * @return bool|int
	 */
	public function update_activation_date( $license_id ) {

		// Update license activation date
		$is_already_active = get_post_meta( $license_id, $this->prefix . 'date_activated', true );
		if ( ! $is_already_active ) {
			return update_post_meta( $license_id, $this->prefix . 'date_activated', date( 'Y-m-d' ) );
		}

		return true;
	}

	/**
	 * @param $envato_api_token
	 * @param $purchaseCode
	 *
	 * @return bool|null
	 */
	protected function verify_purchase( $envato_api_token, $purchaseCode ) {
		if ( empty( $envato_api_token ) || empty( $purchaseCode ) ) {
			return false;
		}

		$curl = new Curl();

		$curl->setHeader( 'Authorization', 'Bearer ' . $envato_api_token );

		$curl->get( 'https://api.envato.com/v3/market/buyer/purchase', array(
			'code' => $purchaseCode
		) );

		$curl->close();

		if ( $curl->error ) {
			return false;
		} else {
			return $curl->response;
		}
	}

	/** @noinspection PhpUnusedPrivateMethodInspection */
	private function debug( $var ) {
		echo wp_send_json( $var );
		die();
	}
}
