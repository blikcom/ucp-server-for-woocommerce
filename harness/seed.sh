#!/usr/bin/env bash
# Seed the throwaway instance with what the conformance suite expects.
#
# The dataset is the suite's flower shop: two products with fixed SKUs and
# prices, three coupons, USD, taxes off, and shipping rates that the plugin's
# fulfillment-option filter maps to the suite's stable option ids.
set -euo pipefail

wp() { docker compose -f "$(dirname "$0")/docker-compose.yml" exec -T cli wp --path=/var/www/html --allow-root "$@"; }

echo "==> WordPress core"
wp core install \
  --url="http://localhost:${CONFORMANCE_PORT:-8099}" \
  --title="UCP Conformance" \
  --admin_user=admin --admin_password=conformance --admin_email=admin@example.test \
  --skip-email >/dev/null

echo "==> WooCommerce"
wp plugin install woocommerce --version="${WC_VERSION:-10.4.0}" --activate >/dev/null
wp plugin activate ucp-server-for-woocommerce >/dev/null

echo "==> store settings (USD, no taxes - the suite's assumptions)"
wp option update woocommerce_currency USD >/dev/null
wp option update woocommerce_calc_taxes no >/dev/null
wp option update woocommerce_default_country US:CA >/dev/null
wp option update woocommerce_store_address "1 Test Street" >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null
wp rewrite flush --hard >/dev/null

echo "==> flower shop products"
wp eval '
$items = array(
    array( "bouquet_roses", "Bouquet of Roses", "35", "instock", 25 ),
    array( "gardenias", "Gardenias", "20", "outofstock", 0 ),
);
foreach ( $items as $item ) {
    list( $sku, $name, $price, $status, $stock ) = $item;
    if ( wc_get_product_id_by_sku( $sku ) ) { continue; }
    $product = new WC_Product_Simple();
    $product->set_name( $name );
    $product->set_sku( $sku );
    $product->set_regular_price( $price );
    $product->set_manage_stock( true );
    $product->set_stock_quantity( $stock );
    $product->set_stock_status( $status );
    $product->set_status( "publish" );
    $product->save();
}
echo "products: ", count( wc_get_products( array( "limit" => -1 ) ) ), "\n";
'

echo "==> coupons"
wp eval '
$coupons = array(
    array( "10OFF", "percent", 10 ),
    array( "WELCOME20", "percent", 20 ),
    array( "FIXED500", "fixed_cart", 5 ),
);
foreach ( $coupons as $c ) {
    list( $code, $type, $amount ) = $c;
    if ( wc_get_coupon_id_by_code( $code ) ) { continue; }
    $coupon = new WC_Coupon();
    $coupon->set_code( $code );
    $coupon->set_discount_type( $type );
    $coupon->set_amount( $amount );
    $coupon->save();
}
echo "coupons seeded\n";
'

echo "==> shipping zones (std-ship / exp-ship-us / exp-ship-intl)"
wp eval '
if ( ! class_exists( "WC_Shipping_Zone" ) ) { WC()->shipping(); }
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( "United States" );
$zone->add_location( "US", "country" );
$zone->save();
foreach ( array( array( "Standard Shipping", "5" ), array( "Express Shipping", "15" ) ) as $rate ) {
    $id = $zone->add_shipping_method( "flat_rate" );
    $method = WC_Shipping_Zones::get_shipping_method( $id );
    $method->instance_settings["title"] = $rate[0];
    $method->instance_settings["cost"]  = $rate[1];
    update_option( $method->get_instance_option_key(), $method->instance_settings );
}
$rest = new WC_Shipping_Zone( 0 );
$id = $rest->add_shipping_method( "flat_rate" );
$method = WC_Shipping_Zones::get_shipping_method( $id );
$method->instance_settings["title"] = "International Express";
$method->instance_settings["cost"]  = "25";
update_option( $method->get_instance_option_key(), $method->instance_settings );
echo "shipping seeded\n";
'

echo "==> posture (as the server resolves it)"
wp eval 'printf( "auth_mode=%s negotiation_mode=%s mock_handler=%s\n",
    \UCPWS\Support\Config::get( "auth_mode" ),
    \UCPWS\Support\Config::get( "negotiation_mode" ),
    \UCPWS\Support\Config::get_bool( "enable_mock_handler" ) ? "on" : "off" );'
