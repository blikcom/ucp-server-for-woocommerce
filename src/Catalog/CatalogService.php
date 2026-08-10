<?php
/**
 * Catalog capability: search + lookup over WooCommerce products.
 *
 * @package UCPWS
 */

namespace UCPWS\Catalog;

use UCPWS\Discovery\ProfileBuilder;
use UCPWS\Negotiation\NegotiationContext;
use UCPWS\Protocol\ErrorCodes;
use UCPWS\Protocol\UcpException;
use UCPWS\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Maps WooCommerce products (including variable products and their variations)
 * to UCP catalog entities.
 */
class CatalogService {

	private const MAX_PAGE_SIZE  = 50;
	private const MAX_LOOKUP_IDS = 50;

	/**
	 * Profile builder.
	 *
	 * @var ProfileBuilder
	 */
	private $profile_builder;

	/**
	 * Constructor.
	 *
	 * @param ProfileBuilder $profile_builder Profile builder.
	 */
	public function __construct( ProfileBuilder $profile_builder ) {
		$this->profile_builder = $profile_builder;
	}

	/**
	 * Free-text search with filters and cursor pagination.
	 *
	 * @param array<string, mixed> $body    Request body.
	 * @param NegotiationContext   $context Negotiation context.
	 * @return array<string, mixed>
	 */
	public function search( array $body, NegotiationContext $context ): array {
		$query_text = isset( $body['query'] ) && is_string( $body['query'] ) ? $body['query'] : '';
		$pagination = isset( $body['pagination'] ) && is_array( $body['pagination'] ) ? $body['pagination'] : array();
		$filters    = isset( $body['filters'] ) && is_array( $body['filters'] ) ? $body['filters'] : array();

		$limit = isset( $pagination['limit'] ) ? (int) $pagination['limit'] : 10;
		$limit = max( 1, min( self::MAX_PAGE_SIZE, $limit ) );
		$page  = $this->decode_cursor( isset( $pagination['cursor'] ) ? (string) $pagination['cursor'] : '' );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'fields'         => 'ids',
			'orderby'        => '' !== $query_text ? 'relevance' : 'date',
		);

		if ( '' !== $query_text ) {
			$args['s'] = $query_text;
		}

		if ( isset( $filters['categories'] ) && is_array( $filters['categories'] ) && array() !== $filters['categories'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'name',
					'terms'    => array_map( 'sanitize_text_field', array_map( 'strval', $filters['categories'] ) ),
				),
			);
		}

		$price_filter = isset( $filters['price'] ) && is_array( $filters['price'] ) ? $filters['price'] : null;
		if ( null !== $price_filter ) {
			$currency   = get_woocommerce_currency();
			$meta_query = array();
			if ( isset( $price_filter['min'] ) && is_numeric( $price_filter['min'] ) ) {
				$meta_query[] = array(
					'key'     => '_price',
					'value'   => Money::to_decimal( (int) $price_filter['min'], $currency ),
					'compare' => '>=',
					'type'    => 'DECIMAL(10,4)',
				);
			}
			if ( isset( $price_filter['max'] ) && is_numeric( $price_filter['max'] ) ) {
				$meta_query[] = array(
					'key'     => '_price',
					'value'   => Money::to_decimal( (int) $price_filter['max'], $currency ),
					'compare' => '<=',
					'type'    => 'DECIMAL(10,4)',
				);
			}
			if ( array() !== $meta_query ) {
				$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}
		}//end if

		$query = new \WP_Query( $args );

		$products = array();
		foreach ( $query->posts as $post_id ) {
			$product = wc_get_product( (int) $post_id );
			if ( $product instanceof \WC_Product ) {
				$mapped = $this->map_product( $product );
				if ( null !== $mapped ) {
					$products[] = $mapped;
				}
			}
		}

		$has_next = $page < (int) $query->max_num_pages;

		$response = array(
			'ucp'      => $this->profile_builder->catalog_response_block( $context, ProfileBuilder::CAP_CATALOG_SEARCH ),
			'products' => $products,
		);

		$pagination_out = array(
			'has_next_page' => $has_next,
			'total_count'   => (int) $query->found_posts,
		);
		if ( $has_next ) {
			$pagination_out['cursor'] = $this->encode_cursor( $page + 1 );
		}
		$response['pagination'] = $pagination_out;

		return $response;
	}

	/**
	 * Batch lookup by product/variant id or SKU.
	 *
	 * @param array<string, mixed> $body    Request body.
	 * @param NegotiationContext   $context Negotiation context.
	 * @return array<string, mixed>
	 * @throws UcpException 400 when `ids` is missing/oversized.
	 */
	public function lookup( array $body, NegotiationContext $context ): array {
		$ids = isset( $body['ids'] ) && is_array( $body['ids'] ) ? array_values( array_filter( array_map( 'strval', $body['ids'] ) ) ) : array();

		if ( array() === $ids ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'ids is required and must contain at least one identifier.', 400 );
		}
		if ( count( $ids ) > self::MAX_LOOKUP_IDS ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, sprintf( 'Batch size exceeds the maximum of %d identifiers.', self::MAX_LOOKUP_IDS ), 400 );
		}

		$grouped   = array();
		$not_found = array();

		foreach ( $ids as $requested ) {
			$resolution = $this->resolve_id( $requested );
			if ( null === $resolution ) {
				$not_found[] = $requested;
				continue;
			}

			$parent_id = $resolution['parent']->get_id();
			if ( ! isset( $grouped[ $parent_id ] ) ) {
				$grouped[ $parent_id ] = array(
					'parent' => $resolution['parent'],
					'inputs' => array(),
				);
			}
			$grouped[ $parent_id ]['inputs'][] = array(
				'id'         => $requested,
				'match'      => $resolution['match'],
				'variant_id' => $resolution['variant_id'],
			);
		}

		$products = array();
		foreach ( $grouped as $group ) {
			$mapped = $this->map_product( $group['parent'], $group['inputs'] );
			if ( null !== $mapped ) {
				$products[] = $mapped;
			}
		}

		$response = array(
			'ucp'      => $this->profile_builder->catalog_response_block( $context, ProfileBuilder::CAP_CATALOG_LOOKUP ),
			'products' => $products,
		);

		if ( array() !== $not_found ) {
			$messages = array();
			foreach ( $not_found as $missing ) {
				$messages[] = array(
					'type'    => 'info',
					'code'    => ErrorCodes::NOT_FOUND,
					'content' => $missing,
				);
			}
			$response['messages'] = $messages;
		}

		return $response;
	}

	/**
	 * Single-product detail (`get_product`).
	 *
	 * @param array<string, mixed> $body    Request body.
	 * @param NegotiationContext   $context Negotiation context.
	 * @return array<string, mixed>
	 * @throws UcpException 400 for missing id.
	 */
	public function get_product( array $body, NegotiationContext $context ): array {
		$id = isset( $body['id'] ) && is_string( $body['id'] ) ? $body['id'] : '';
		if ( '' === $id ) {
			throw UcpException::transport( ErrorCodes::INVALID_REQUEST, 'id is required.', 400 );
		}

		$resolution = $this->resolve_id( $id );

		if ( null === $resolution ) {
			return array(
				'ucp'          => array_merge(
					$this->profile_builder->catalog_response_block( $context, ProfileBuilder::CAP_CATALOG_LOOKUP ),
					array( 'status' => 'error' )
				),
				'messages'     => array(
					array(
						'type'     => 'error',
						'code'     => ErrorCodes::NOT_FOUND,
						'content'  => sprintf( 'Product %s not found', $id ),
						'severity' => 'recoverable',
					),
				),
				'continue_url' => $this->storefront_url(),
			);
		}

		$selected = isset( $body['selected'] ) && is_array( $body['selected'] ) ? $body['selected'] : array();
		$product  = $this->map_product( $resolution['parent'], array(), $selected );

		if ( null === $product ) {
			throw UcpException::transport( 'internal_error', 'Unable to map the product.', 500 );
		}

		return array(
			'ucp'     => $this->profile_builder->catalog_response_block( $context, ProfileBuilder::CAP_CATALOG_LOOKUP ),
			'product' => $product,
		);
	}

	/**
	 * Resolve an identifier to (parent product, matched variant, match type).
	 *
	 * @param string $id Product/variation id or SKU.
	 * @return array{parent: \WC_Product, variant_id: int|null, match: string}|null
	 */
	private function resolve_id( string $id ): ?array {
		$product = null;

		if ( ctype_digit( $id ) ) {
			$candidate = wc_get_product( (int) $id );
			if ( $candidate instanceof \WC_Product ) {
				$product = $candidate;
			}
		}

		if ( null === $product ) {
			$product_id = wc_get_product_id_by_sku( $id );
			if ( $product_id > 0 ) {
				$candidate = wc_get_product( $product_id );
				if ( $candidate instanceof \WC_Product ) {
					$product = $candidate;
				}
			}
		}

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		if ( $product instanceof \WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof \WC_Product ) {
				return null;
			}
			if ( 'publish' !== $parent->get_status() ) {
				return null;
			}
			return array(
				'parent'     => $parent,
				'variant_id' => $product->get_id(),
				'match'      => 'exact',
			);
		}

		if ( 'publish' !== $product->get_status() ) {
			return null;
		}

		return array(
			'parent'     => $product,
			'variant_id' => null,
			'match'      => $product->is_type( 'variable' ) ? 'featured' : 'exact',
		);
	}

	/**
	 * Map a WooCommerce product to the UCP product entity.
	 *
	 * @param \WC_Product                      $product Parent product.
	 * @param array<int, array<string, mixed>> $inputs  Lookup input correlations.
	 * @param array<int, array<string, mixed>> $selected Selected options (get_product narrowing).
	 * @return array<string, mixed>|null
	 */
	private function map_product( \WC_Product $product, array $inputs = array(), array $selected = array() ): ?array {
		$currency = get_woocommerce_currency();

		$variations = array();
		if ( $product->is_type( 'variable' ) && $product instanceof \WC_Product_Variable ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( (int) $child_id );
				if ( $variation instanceof \WC_Product_Variation && $variation->variation_is_visible() ) {
					$variations[] = $variation;
				}
			}
		}

		if ( array() !== $selected && array() !== $variations ) {
			$variations = $this->filter_by_selected_options( $variations, $selected );
		}

		$variant_sources = array() !== $variations ? $variations : array( $product );

		$variants = array();
		$min      = null;
		$max      = null;

		foreach ( $variant_sources as $source ) {
			$variant = $this->map_variant( $source, $product, $currency, $inputs );
			if ( null === $variant ) {
				continue;
			}
			$amount = $variant['price']['amount'];
			$min    = null === $min ? $amount : min( $min, $amount );
			$max    = null === $max ? $amount : max( $max, $amount );

			$variants[] = $variant;
		}

		if ( array() === $variants ) {
			return null;
		}

		$description = $this->description_of( $product );

		$mapped = array(
			'id'          => '' !== $product->get_sku() ? $product->get_sku() : (string) $product->get_id(),
			'title'       => $product->get_name(),
			'description' => $description,
			'url'         => (string) get_permalink( $product->get_id() ),
			'price_range' => array(
				'min' => array(
					'amount'   => (int) $min,
					'currency' => $currency,
				),
				'max' => array(
					'amount'   => (int) $max,
					'currency' => $currency,
				),
			),
			'variants'    => $variants,
		);

		$media = $this->media_of( $product );
		if ( array() !== $media ) {
			$mapped['media'] = $media;
		}

		$options = $this->options_of( $product );
		if ( array() !== $options ) {
			$mapped['options'] = $options;
		}

		$categories = array();
		foreach ( wc_get_product_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ) as $term_name ) {
			$categories[] = array(
				'value'    => (string) $term_name,
				'taxonomy' => 'merchant',
			);
		}
		if ( array() !== $categories ) {
			$mapped['categories'] = $categories;
		}

		if ( $product->get_review_count() > 0 ) {
			$mapped['rating'] = array(
				'value'     => (float) $product->get_average_rating(),
				'scale_min' => 0,
				'scale_max' => 5,
				'count'     => (int) $product->get_review_count(),
			);
		}

		/**
		 * Filters the UCP catalog product payload.
		 *
		 * @param array       $mapped  Payload.
		 * @param \WC_Product $product Product.
		 */
		return apply_filters( 'ucpws_catalog_product', $mapped, $product );
	}

	/**
	 * Map a purchasable product/variation to the UCP variant entity.
	 *
	 * @param \WC_Product                      $source   Variation or simple product.
	 * @param \WC_Product                      $parent_product Parent product.
	 * @param string                           $currency Currency.
	 * @param array<int, array<string, mixed>> $inputs   Lookup input correlations.
	 * @return array<string, mixed>|null
	 */
	private function map_variant( \WC_Product $source, \WC_Product $parent_product, string $currency, array $inputs ): ?array {
		$price = $source->get_price();
		if ( '' === $price ) {
			return null;
		}

		$variant = array(
			'id'          => '' !== $source->get_sku() ? $source->get_sku() : (string) $source->get_id(),
			'title'       => $source instanceof \WC_Product_Variation ? wc_get_formatted_variation( $source, true, false ) : $source->get_name(),
			'description' => $this->description_of( $source, $parent_product ),
			'price'       => array(
				'amount'   => Money::to_minor( $price, $currency ),
				'currency' => $currency,
			),
		);

		if ( '' === trim( (string) $variant['title'] ) ) {
			$variant['title'] = $source->get_name();
		}

		if ( '' !== $source->get_sku() ) {
			$variant['sku'] = $source->get_sku();
		}

		if ( $source->is_on_sale() && '' !== $source->get_regular_price() ) {
			$variant['list_price'] = array(
				'amount'   => Money::to_minor( $source->get_regular_price(), $currency ),
				'currency' => $currency,
			);
		}

		$available = $source->is_in_stock() && $source->is_purchasable();
		$status    = 'in_stock';
		if ( ! $source->is_in_stock() ) {
			$status = 'out_of_stock';
		} elseif ( $source->is_on_backorder() ) {
			$status = 'backorder';
		}
		$variant['availability'] = array(
			'available' => $available,
			'status'    => $status,
		);

		if ( $source instanceof \WC_Product_Variation ) {
			$selected_options = array();
			foreach ( $source->get_variation_attributes( false ) as $attribute => $value ) {
				$selected_options[] = array(
					'name'  => wc_attribute_label( $attribute, $parent_product ),
					'label' => (string) $value,
				);
			}
			if ( array() !== $selected_options ) {
				$variant['selected_options'] = $selected_options;
			}

			$image_id = $source->get_image_id();
			if ( $image_id ) {
				$url = wp_get_attachment_image_url( (int) $image_id, 'woocommerce_single' );
				if ( is_string( $url ) && '' !== $url ) {
					$variant['media'] = array(
						array(
							'type' => 'image',
							'url'  => $url,
						),
					);
				}
			}
		}//end if

		// Lookup correlation: attach the request ids that resolved here.
		$correlations = array();
		foreach ( $inputs as $input ) {
			$variant_specific = null !== $input['variant_id'];
			$matches_variant  = $variant_specific && (int) $input['variant_id'] === $source->get_id();
			$is_first_variant = ! $variant_specific;

			if ( $matches_variant ) {
				$correlations[] = array(
					'id'    => (string) $input['id'],
					'match' => 'exact',
				);
			} elseif ( $is_first_variant ) {
				$correlations[] = array(
					'id'    => (string) $input['id'],
					'match' => (string) $input['match'],
				);
			}
		}
		if ( array() !== $correlations ) {
			$variant['inputs'] = $correlations;
			// Only the first (featured) variant claims product-level inputs.
			foreach ( $inputs as &$input ) {
				if ( null === $input['variant_id'] ) {
					$input['variant_id'] = -1;
				}
			}
			unset( $input );
		}

		return $variant;
	}

	/**
	 * Filter variations by selected option name/label pairs.
	 *
	 * @param \WC_Product_Variation[]          $variations Variations.
	 * @param array<int, array<string, mixed>> $selected   Selected options.
	 * @return \WC_Product_Variation[]
	 */
	private function filter_by_selected_options( array $variations, array $selected ): array {
		$filtered = array();

		foreach ( $variations as $variation ) {
			$attributes = array();
			foreach ( $variation->get_variation_attributes( false ) as $attribute => $value ) {
				$attributes[ strtolower( wc_attribute_label( $attribute ) ) ] = strtolower( (string) $value );
			}

			$matches = true;
			foreach ( $selected as $selection ) {
				if ( ! is_array( $selection ) ) {
					continue;
				}
				$name  = strtolower( (string) ( $selection['name'] ?? '' ) );
				$label = strtolower( (string) ( $selection['label'] ?? '' ) );
				if ( '' === $name || '' === $label ) {
					continue;
				}
				if ( ! isset( $attributes[ $name ] ) || $attributes[ $name ] !== $label ) {
					$matches = false;
					break;
				}
			}

			if ( $matches ) {
				$filtered[] = $variation;
			}
		}//end foreach

		return array() !== $filtered ? $filtered : $variations;
	}

	/**
	 * Description object (`{plain, html?}`), never empty.
	 *
	 * @param \WC_Product      $product Product or variation.
	 * @param \WC_Product|null $parent_product Parent fallback.
	 * @return array<string, string>
	 */
	private function description_of( \WC_Product $product, ?\WC_Product $parent_product = null ): array {
		$html = $product->get_description();
		if ( '' === $html ) {
			$html = $product->get_short_description();
		}
		if ( '' === $html && null !== $parent_product ) {
			$html = $parent_product->get_description();
			if ( '' === $html ) {
				$html = $parent_product->get_short_description();
			}
		}

		$plain = trim( wp_strip_all_tags( $html ) );
		if ( '' === $plain ) {
			$plain = $product->get_name();
		}

		$description = array( 'plain' => $plain );
		if ( '' !== trim( $html ) && trim( $html ) !== $plain ) {
			$description['html'] = $html;
		}

		return $description;
	}

	/**
	 * Media entries (featured image + gallery).
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int, array<string, string>>
	 */
	private function media_of( \WC_Product $product ): array {
		$media = array();

		$ids = array();
		if ( $product->get_image_id() ) {
			$ids[] = (int) $product->get_image_id();
		}
		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$ids[] = (int) $gallery_id;
		}

		foreach ( array_unique( $ids ) as $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'woocommerce_single' );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$entry = array(
				'type' => 'image',
				'url'  => $url,
			);
			$alt   = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( is_string( $alt ) && '' !== $alt ) {
				$entry['alt_text'] = $alt;
			}
			$media[] = $entry;
		}

		return $media;
	}

	/**
	 * Product options from variable product attributes.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	private function options_of( \WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) || ! $product instanceof \WC_Product_Variable ) {
			return array();
		}

		$options = array();
		foreach ( $product->get_variation_attributes() as $attribute_name => $values ) {
			$mapped_values = array();
			foreach ( (array) $values as $value ) {
				$mapped_values[] = array( 'label' => (string) $value );
			}
			if ( array() === $mapped_values ) {
				continue;
			}
			$options[] = array(
				'name'   => wc_attribute_label( $attribute_name, $product ),
				'values' => $mapped_values,
			);
		}

		return $options;
	}

	/**
	 * Storefront URL for continue_url fallbacks.
	 *
	 * @return string
	 */
	public function storefront_url(): string {
		$shop = get_permalink( wc_get_page_id( 'shop' ) );
		return is_string( $shop ) && '' !== $shop ? $shop : home_url( '/' );
	}

	/**
	 * Encode a page number as an opaque cursor.
	 *
	 * @param int $page Page number.
	 * @return string
	 */
	private function encode_cursor( int $page ): string {
		return rtrim( strtr( base64_encode( 'page:' . $page ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- opaque cursor.
	}

	/**
	 * Decode a cursor back to a page number.
	 *
	 * @param string $cursor Cursor.
	 * @return int
	 */
	private function decode_cursor( string $cursor ): int {
		if ( '' === $cursor ) {
			return 1;
		}
		$decoded = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- opaque cursor.
		if ( is_string( $decoded ) && preg_match( '/^page:(\d+)$/', $decoded, $matches ) ) {
			return max( 1, (int) $matches[1] );
		}
		return 1;
	}
}
