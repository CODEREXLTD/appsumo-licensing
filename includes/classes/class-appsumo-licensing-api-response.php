<?php


namespace WPF\AppSupomo;

// exit if file is called directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// if class already defined, bail out
if ( class_exists( 'Ulicense\ApiResponse' ) ) {
	return;
}


/**
 * This class will handle Api response
 *
 * @package    WPF
 * @subpackage WPF\AppSupomo
 * @author     WPFunnels Team <admin@getwpfunnels.com>
 */
class ApiResponse {

	private $logged_errors = array();

	private $logged_success = array();


	public function __construct() {
	}

	/**
	 * @param $code
	 */
	public function log_success( $code ) {
		$this->logged_success[ $code ] = $this->get_success_message( $code );
	}

	/**
	 * @param $code
	 *
	 * @return array
	 */
	public function get_success_message( $code ) {
		$api_success = array(
			'domain_activated' => array(
				'code'    => 200,
				'message' => __( 'Domain has been successfully activated against this license.', 'ulicense' )
			),

			'domain_reactivated' => array(
				'code'    => 200,
				'message' => __( 'Domain reactivated for this license.', 'ulicense' )
			),

			'domain_deactivated' => array(
				'code'    => 200,
				'message' => __( 'Domain has been successfully blocked against this license.', 'ulicense' )
			),
			'domain_deleted'     => array(
				'code'    => 200,
				'message' => __( 'Domain has been successfully deleted against this license.', 'ulicense' )
			),

			'computer_deleted' => array(
				'code'    => 200,
				'message' => __( 'Computer has been successfully deleted against this license.', 'ulicense' )
			),

			'license_deactivated' => array(
				'code'    => 200,
				'message' => __( 'Licenseblocked Successfully.', 'ulicense' )
			),


			'vendor_purchase_code_validated' => array(
				'code'    => 200,
				'message' => __( 'Vendor purchase code successfully validated.', 'ulicense' )
			),
			'envato_purchase_code_validated' => array(
				'code'    => 200,
				'message' => __( 'Envato purchase code successfully validated.', 'ulicense' )
			),

			'license_generated_vendor' => array(
				'code'    => 200,
				'message' => __( 'Purchase code verified and license successfully generated.', 'ulicense' )
			),

			'unknown' => array(
				'code'    => 200,
				'message' => __( 'Your request was successfully processed.', 'ulicense' )
			),

			'computer_activated'   => array(
				'code'    => 200,
				'message' => __( 'Computer has been successfully activated against this license.', 'ulicense' )
			),
			'computer_reactivated' => array(
				'code'    => 200,
				'message' => __( 'Computer reactivated for this license.', 'ulicense' )
			),
			'computer_deactivated' => array(
				'code'    => 200,
				'message' => __( 'Computer has been successfully blocked against this license.', 'ulicense' )
			),

			'computer_is_active' => array(
				'code'    => 200,
				'message' => __( 'Computer is active', 'ulicense' )
			),

			'credits_reduce_success' => array(
				'code'    => 200,
				'message' => __( 'Credits successfully reduced.', 'ulicense' )
			),

		);

		return isset( $api_success[ $code ] ) ? $api_success[ $code ] : $api_success['unknown'];
	}

	/**
	 * @param $error_code
	 */
	public function log_error( $error_code ) {
		$this->logged_errors[ $error_code ] = $this->get_error_message( $error_code );
	}

	/**
	 *
	 * code is:
	 * 200 : good request
	 * 400: bad request
	 *
	 * so, missing license is bad request, but expired license is good request
	 *
	 * @param $error_code
	 *
	 * @return array
	 */
	public function get_error_message( $error_code ) {
		$api_errors = array(
			'license_key_missing' => array(
				'code'    => 200,
				'message' => __( 'License key is missing.', 'ulicense' )
			),
			'missing_key'         => array(
				'code'    => 400,
				'message' => __( 'License key is missing.', 'ulicense' )
			),

			'missing_name' => array(
				'code'    => 400,
				'message' => __( 'Computer Name is missing.', 'ulicense' )
			),

			'license_suspended' => array(
				'code'    => 200,
				'message' => __( 'License is suspended.', 'ulicense' )
			),

			'license_expired' => array(
				'code'    => 200,
				'message' => __( 'License is expired.', 'ulicense' )
			),

			'license_pending'        => array(
				'code'    => 200,
				'message' => __( 'License is pending activation.', 'ulicense' )
			),

			// no other license status is found, its fallback
			'license_status_invalid' => array(
				'code'    => 200,
				'message' => __( 'License status is invalid', 'ulicense' )
			),

			'license_asset_mismatch' => array(
				'code'    => 200,
				'message' => __( 'Digital asset associated with this license mismatch with the requested digital asset.', 'ulicense' )
			),

			'license_cpt_generate_error' => array(
				'code'    => 200,
				'message' => __( 'There was an error generating new License', 'ulicense' )
			),

			'vendor_code_verified_same_domain' => array(
				'code'    => 200,
				'message' => __( 'Domain already active for this purchase code.', 'ulicense' )
			),

			'vendor_code_already_utilized' => array(
				'code'    => 200,
				'message' => __( 'The Purchase code you have provided is already utilized.', 'ulicense' )
			),

			'missing_private_key' => array(
				'code'    => 400,
				'message' => __( 'Private Secret Key is required for this action.', 'ulicense' ),
			),

			'invalid_private_key' => array(
				'code'    => 400,
				'message' => __( 'Private Secret Key invalid.', 'ulicense' )
			),

			'server_not_authorized' => array(
				'code'    => 200,
				'message' => __( 'Requesting server is not authorized.', 'ulicense' )
			),

			'missing_public' => array(
				'code'    => 400,
				'message' => __( 'Public Key is required for this action.', 'ulicense' )
			),

			'missing_domain' => array(
				'code'    => 400,
				'message' => __( 'Domain is required for this action.', 'ulicense' )
			),

			'missing_asset' => array(
				'code'    => 400,
				'message' => __( 'Digital Asset identifier is missing.', 'ulicense' )
			),

			'missing_license_key' => array(
				'code'    => 400,
				'message' => __( 'License key is required for this action.', 'ulicense' )
			),
			'invalid_asset'       => array(
				'code'    => 200,
				'message' => __( 'We could not find any Digital Asset with provided identifier.', 'ulicense' )
			),

			'invalid_public_key' => array(
				'code'    => 400,
				'message' => __( 'Public Key invalid.', 'ulicense' )
			),

			'invalid_api_action' => array(
				'code'    => 400,
				'message' => __( 'Invalid API action.', 'ulicense' )
			),

			'invalid_license_key' => array(
				'code'    => 200,
				'message' => __( 'License Key invalid. No record found.', 'ulicense' )
			),

			'domain_already_active' => array(
				'code'    => 200,
				'message' => __( 'Domain Already Active.', 'ulicense' )
			),

			'domain_not_found' => array(
				'code'    => 200,
				'message' => __( 'Domain Not found for this license.', 'ulicense' )
			),

			'max_allowed_active' => array(
				'code'    => 200,
				'message' => __( 'Max allowed entries already active, could not activate any new entry.', 'ulicense' )
			),

			'domain_is_marked_inactive' => [
				'code'    => 200,
				'message' => __( 'Domain is marked inactive.', 'ulicense' )
			],

			'domain_activation_error' => [
				'code'    => 200,
				'message' => __( 'Domain Could not be activated.', 'ulicense' )
			],

			'validation_key_missing' => array(
				'code'    => 400,
				'message' => __( 'Activation key is missing for which license to be validated.', 'ulicense' )
			),

			'validation_vendor_missing' => array(
				'code'    => 400,
				'message' => __( 'Vendor for which key is to be validated is missing.', 'ulicense' )
			),

			'envato_purchase_code_invalid' => array(
				'code'    => 200,
				'message' => __( 'Envato Purchase code invalid, could not be verified.', 'ulicense' )
			),

			'vendor_purchase_not_validated' => array(
				'code'    => 200,
				'message' => __( 'Purchase code provided is invalid.', 'ulicense' )
			),

			'vendor_asset_not_found' => array(
				'code'    => 200,
				'message' => __( 'No Digital Asset is configured for the purchase code provided.', 'ulicense' )
			),

			'error_deactivating_domain' => array(
				'code'    => 200,
				'message' => __( 'Error Deactivating Domain. Could not update license details', 'ulicense' )
			),

			'error_deleting_domain' => array(
				'code'    => 200,
				'message' => __( 'Error Deleting Domain. Could not update license details', 'ulicense' )
			),

			'missing_computer' => array(
				'code'    => 400,
				'message' => __( 'Computer key is required for this action.', 'ulicense' )
			),

			'computer_not_found' => array(
				'code'    => 200,
				'message' => __( 'Computer Not found for this license.', 'ulicense' )
			),

			'computer_is_marked_inactive' => [
				'code'    => 200,
				'message' => __( 'Computer is marked as blocked in the server', 'ulicense' )
			],

			'error_deactivating_computer' => array(
				'code'    => 200,
				'message' => __( 'Error Deactivating Computer. Could not update license details', 'ulicense' )
			),

			'security_token_invalid' => array(
				'code'    => 200,
				'message' => __( 'Security token invalid', 'ulicense' )
			),

			'user_authorization_invalid' => array(
				'code'    => 200,
				'message' => __( 'user Authorization invalid. This user is not allowed to modify this license.', 'ulicense' )
			),

			'not_enough_credits' => array(
				'code'    => 200,
				'message' => __( 'Credits are not enough to process this request.', 'ulicense' )
			),

			'credits_reduce_error' => array(
				'code'    => 200,
				'message' => __( 'Credits could not be reduced.', 'ulicense' )
			),

			'unknown' => array(
				'code'    => 500,
				'message' => __( 'Unknown Error Occurred', 'ulicense' )
			),


		);

		return isset( $api_errors[ $error_code ] ) ? $api_errors[ $error_code ] : $api_errors['unknown'];
	}

	public function set_error( $error_code, $error_message ) {
		$this->logged_errors[ $error_code ] = esc_html( $error_message );
	}

	/**
	 *
	 */
	public function get_logged_errors() {
		return $this->logged_errors;
	}

	/**
	 *
	 */
	public function get_logged_success() {
		return $this->logged_success;
	}

	/**
	 *
	 */
	public function get_error( $error_code ) {

		$error = $this->get_error_message( $error_code );

		return new \WP_Error(
			$error_code,
			$error['message'],
			[
				'status' => $error['code']
			]
		);

	}
}
