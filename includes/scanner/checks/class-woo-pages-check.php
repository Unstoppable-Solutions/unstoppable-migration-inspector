<?php
/**
 * WooCommerce page assignments.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * The shop, cart, checkout and account pages must exist, be published and
 * (for cart and checkout) contain WooCommerce's block or shortcode.
 */
final class Woo_Pages_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'woo_pages';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'woocommerce';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Store pages', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		$action = __( 'Go to WooCommerce > Settings > Advanced > Page setup and choose the correct page for each role. If a page is missing, restore it from the old site or create it with the matching WooCommerce block.', 'unstoppable-migration-inspector' );

		return array(
			'checkout_page' => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'The cart or checkout page is missing or not set up', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WooCommerce\'s cart or checkout page is not assigned, does not exist, is not published, or does not contain the cart or checkout. Customers cannot complete an order. Page IDs often change when content is imported instead of copied.', 'unstoppable-migration-inspector' ),
				'action'      => $action,
			),
			'store_page'    => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'The shop or account page is missing or not published', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WooCommerce\'s shop or My Account page is not assigned, does not exist or is not published. Customers may not find products or be able to log in to see their orders.', 'unstoppable-migration-inspector' ),
				'action'      => $action,
			),
		);
	}

	/**
	 * Applicability.
	 *
	 * @param Scan_Context $context Context.
	 * @return true|string
	 */
	public function applicable( Scan_Context $context ) {
		unset( $context );

		return class_exists( 'WooCommerce' ) ? true : __( 'WooCommerce is not active.', 'unstoppable-migration-inspector' );
	}

	/**
	 * Run.
	 *
	 * @param Scan_Context $context Context.
	 * @return Finding[]
	 */
	public function run( Scan_Context $context ) {
		unset( $context );

		$pages = array(
			'cart'      => array( 'checkout_page', array( 'wp:woocommerce/cart', '[woocommerce_cart' ) ),
			'checkout'  => array( 'checkout_page', array( 'wp:woocommerce/checkout', '[woocommerce_checkout' ) ),
			'shop'      => array( 'store_page', array() ),
			'myaccount' => array( 'store_page', array() ),
		);

		$findings = array();

		foreach ( $pages as $role => $rule ) {
			list( $kind, $markers ) = $rule;

			$option  = 'woocommerce_' . $role . '_page_id';
			$page_id = (int) get_option( $option );
			$page    = $page_id > 0 ? get_post( $page_id ) : null;

			if ( null === $page || 'page' !== $page->post_type ) {
				$findings[] = ( new Finding( $kind, $role ) )->on( 'option', $option )->with( array( 'problem' => $page_id > 0 ? 'missing' : 'unassigned' ) );
				continue;
			}

			if ( 'publish' !== $page->post_status ) {
				$findings[] = ( new Finding( $kind, $role ) )->on( 'post', $page_id )->with( array( 'problem' => 'not_published' ) );
				continue;
			}

			if ( ! empty( $markers ) && ! self::contains_any( (string) $page->post_content, $markers ) ) {
				$findings[] = ( new Finding( $kind, $role ) )->on( 'post', $page_id )->with( array( 'problem' => 'no_block' ) );
			}
		}

		return $findings;
	}

	/**
	 * Whether content contains any marker.
	 *
	 * @param string   $content Content.
	 * @param string[] $markers Markers.
	 * @return bool
	 */
	private static function contains_any( $content, array $markers ) {
		foreach ( $markers as $marker ) {
			if ( false !== stripos( $content, $marker ) ) {
				return true;
			}
		}

		return false;
	}
}
