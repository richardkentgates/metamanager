<?php
/**
 * MM_Schema_Types — central registry for schema type field definitions.
 *
 * Provides:
 *   - The narrowed list of schema types relevant to GCM clients
 *   - Per-type field definitions for the admin UI (metabox + settings tab)
 *   - A builder that converts flat stored overrides into nested JSON-LD properties
 */

defined( 'ABSPATH' ) || exit;

class MM_Schema_Types {

	// -------------------------------------------------------------------------
	// Schema type list
	// -------------------------------------------------------------------------

	/**
	 * Returns the narrowed set of schema types relevant to GCM client sites.
	 * Used by both the post metabox and the Schema settings tab.
	 *
	 * @param bool $include_empty Whether to prepend the "use default" empty option.
	 * @return array<string, string>
	 */
	public static function get_schema_types( bool $include_empty = false ): array {
		$types = [
			// ── Page types ────────────────────────────────────────────────────
			'WebPage'           => 'WebPage — Generic page',
			'AboutPage'         => 'AboutPage',
			'ContactPage'       => 'ContactPage',
			'ProfilePage'       => 'ProfilePage',
			// ── Articles ─────────────────────────────────────────────────────
			'Article'           => 'Article',
			'BlogPosting'       => 'BlogPosting',
			'FAQPage'           => 'FAQPage',
			'HowTo'             => 'HowTo',
			// ── Products & services ───────────────────────────────────────────
			'Event'             => 'Event',
			'Product'           => 'Product',
			'Service'           => 'Service',
			// ── Tourism ──────────────────────────────────────────────────────
			'TouristAttraction' => 'TouristAttraction',
			'TouristTrip'       => 'TouristTrip',
			// ── Real estate ──────────────────────────────────────────────────
			'RealEstateListing' => 'RealEstateListing',
			// ── Business & people ────────────────────────────────────────────
			'LocalBusiness'     => 'LocalBusiness',
			'Organization'      => 'Organization',
			'Person'            => 'Person',
		];

		if ( $include_empty ) {
			return array_merge( [ '' => '— Use post type default —' ], $types );
		}

		return $types;
	}

	// -------------------------------------------------------------------------
	// Field definitions
	// -------------------------------------------------------------------------

	/**
	 * Returns field definitions for all types that have expandable fields.
	 * Types not in this map (WebPage, Article, BlogPosting, etc.) are fully
	 * auto-populated from WP data and need no extra fields.
	 *
	 * Each field definition:
	 *   key         string   flat meta key stored under schema_fields
	 *   label       string   UI label
	 *   type        string   input type: text|email|tel|url|number|datetime-local|select
	 *   required    bool     marks as required for valid schema
	 *   auto_label  string|null  shown as "Auto: …" when value comes from WP data
	 *   placeholder string   input placeholder
	 *   description string   help text shown beneath input
	 *   options     array    key=>label pairs, for 'select' type only
	 *
	 * @return array<string, array>
	 */
	public static function get_fields_by_type(): array {
		return [

			// ── Event ─────────────────────────────────────────────────────────
			'Event' => [
				[
					'key'         => 'event_start_date',
					'label'       => 'Start Date & Time',
					'type'        => 'datetime-local',
					'required'    => true,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => 'Required for Google rich results. Format: YYYY-MM-DDTHH:MM.',
				],
				[
					'key'         => 'event_end_date',
					'label'       => 'End Date & Time',
					'type'        => 'datetime-local',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
				],
				[
					'key'         => 'event_location_name',
					'label'       => 'Venue / Location Name',
					'type'        => 'text',
					'required'    => true,
					'auto_label'  => null,
					'placeholder' => 'e.g. Crab Island, Destin Harbor',
					'description' => '',
				],
				[
					'key'         => 'event_location_address',
					'label'       => 'Location Address',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Destin, FL 32541',
					'description' => '',
				],
				[
					'key'         => 'event_price',
					'label'       => 'Price',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 150 or 75-200',
					'description' => 'Numeric or range. Leave blank to omit offers from schema.',
				],
				[
					'key'         => 'event_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217 currency code. Defaults to USD.',
				],
			],

			// ── Service ───────────────────────────────────────────────────────
			'Service' => [
				[
					'key'         => 'service_type',
					'label'       => 'Service Type',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Pontoon Charter, Canvas Repair',
					'description' => '',
				],
				[
					'key'         => 'service_area',
					'label'       => 'Area Served',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Destin, Fort Walton Beach, FL',
					'description' => '',
				],
				[
					'key'         => 'service_price',
					'label'       => 'Price / Range',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 150 or Starting at $75',
					'description' => '',
				],
				[
					'key'         => 'service_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217. Used only when Price is a number.',
				],
			],

			// ── Product ───────────────────────────────────────────────────────
			'Product' => [
				[
					'key'         => 'product_brand',
					'label'       => 'Brand',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
				],
				[
					'key'         => 'product_price',
					'label'       => 'Price',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 49.99',
					'description' => 'Numeric price.',
				],
				[
					'key'         => 'product_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217 currency code.',
				],
				[
					'key'         => 'product_availability',
					'label'       => 'Availability',
					'type'        => 'select',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
					'options'     => [
						''           => '— Not specified —',
						'InStock'    => 'In Stock',
						'OutOfStock' => 'Out of Stock',
						'PreOrder'   => 'Pre-Order',
					],
				],
			],

			// ── TouristAttraction ─────────────────────────────────────────────
			'TouristAttraction' => [
				[
					'key'         => 'attraction_city',
					'label'       => 'City',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Destin',
					'description' => '',
				],
				[
					'key'         => 'attraction_region',
					'label'       => 'State / Region',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'FL',
					'description' => '',
				],
				[
					'key'         => 'attraction_phone',
					'label'       => 'Phone',
					'type'        => 'tel',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '+18505552849',
					'description' => '',
				],
				[
					'key'         => 'attraction_lat',
					'label'       => 'Latitude',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 30.3935',
					'description' => 'Decimal degrees. Used to emit geo coordinates.',
				],
				[
					'key'         => 'attraction_lng',
					'label'       => 'Longitude',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. -86.4958',
					'description' => '',
				],
			],

			// ── TouristTrip ───────────────────────────────────────────────────
			'TouristTrip' => [
				[
					'key'         => 'trip_departure',
					'label'       => 'Departure Location',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Destin Harbor, Slip 12',
					'description' => 'Where guests board / depart from.',
				],
				[
					'key'         => 'trip_duration',
					'label'       => 'Duration',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 4 hours',
					'description' => '',
				],
				[
					'key'         => 'trip_price',
					'label'       => 'Price',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 600',
					'description' => '',
				],
				[
					'key'         => 'trip_currency',
					'label'       => 'Currency',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'USD',
					'description' => 'ISO 4217. Used only when Price is a number.',
				],
				[
					'key'         => 'trip_provider',
					'label'       => 'Provider / Operator',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => 'Business name (from Business settings)',
					'placeholder' => '',
					'description' => 'Leave blank to use the business name from Settings → Business.',
				],
			],

			// ── RealEstateListing ─────────────────────────────────────────────
			'RealEstateListing' => [
				[
					'key'         => 'listing_street',
					'label'       => 'Street Address',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 123 Harbor Blvd',
					'description' => '',
				],
				[
					'key'         => 'listing_city',
					'label'       => 'City',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Destin',
					'description' => '',
				],
				[
					'key'         => 'listing_region',
					'label'       => 'State',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'FL',
					'description' => '',
				],
				[
					'key'         => 'listing_rooms',
					'label'       => 'Number of Rooms / Bedrooms',
					'type'        => 'number',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
				],
				[
					'key'         => 'listing_sqft',
					'label'       => 'Square Footage',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 1450',
					'description' => '',
				],
				[
					'key'         => 'listing_price',
					'label'       => 'Listing Price',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. 485000',
					'description' => 'Numeric. Currency defaults to USD.',
				],
			],

			// ── LocalBusiness ─────────────────────────────────────────────────
			'LocalBusiness' => [
				[
					'key'         => 'business_hours',
					'label'       => 'Opening Hours',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Mo-Fr 09:00-17:00',
					'description' => 'Schema.org opening hours specification format. Separate multiple ranges with commas.',
				],
				[
					'key'         => 'business_price_range',
					'label'       => 'Price Range',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. $$ or $150–$600',
					'description' => 'Free-form price range indicator.',
				],
			],

			// ── Person ────────────────────────────────────────────────────────
			'Person' => [
				[
					'key'         => 'person_job_title',
					'label'       => 'Job Title',
					'type'        => 'text',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'e.g. Captain, Realtor, Owner',
					'description' => '',
				],
				[
					'key'         => 'person_email',
					'label'       => 'Email',
					'type'        => 'email',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '',
					'description' => '',
				],
				[
					'key'         => 'person_phone',
					'label'       => 'Phone',
					'type'        => 'tel',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => '+18505552849',
					'description' => '',
				],
				[
					'key'         => 'person_same_as',
					'label'       => 'Profile URL (LinkedIn, etc.)',
					'type'        => 'url',
					'required'    => false,
					'auto_label'  => null,
					'placeholder' => 'https://linkedin.com/in/…',
					'description' => 'Social or professional profile URL to link this person to.',
				],
			],

		];
	}

	// -------------------------------------------------------------------------
	// JSON-LD node builder
	// -------------------------------------------------------------------------

	/**
	 * Converts flat stored schema_fields overrides into nested JSON-LD properties
	 * ready to merge into the schema node.
	 *
	 * @param array  $fields Flat key→value array from post meta schema_fields.
	 * @param string $type   The resolved schema @type for this post.
	 * @return array JSON-LD properties to merge.
	 */
	public static function build_node_additions( array $fields, string $type ): array {
		/** @var array<string, mixed> $out */
		$out = [];

		// ── Helpers ──────────────────────────────────────────────────────────

		$str = static function ( string $key ) use ( $fields ): string {
			return trim( $fields[ $key ] ?? '' );
		};

		// Build an Offer node from price/currency fields (key prefix passed in).
		$make_offer = function ( string $price_key, string $currency_key ) use ( $str ): ?array {
			$price = $str( $price_key );
			if ( $price === '' ) {
				return null;
			}
			$currency = $str( $currency_key ) ?: 'USD';
			return [
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => $currency,
			];
		};

		// ── Event ─────────────────────────────────────────────────────────────
		if ( 'Event' === $type ) {
			if ( $str( 'event_start_date' ) ) {
				$out['startDate'] = $str( 'event_start_date' );
			}
			if ( $str( 'event_end_date' ) ) {
				$out['endDate'] = $str( 'event_end_date' );
			}
			$loc_name = $str( 'event_location_name' );
			$loc_addr = $str( 'event_location_address' );
			if ( $loc_name || $loc_addr ) {
				$place = [ '@type' => 'Place' ];
				if ( $loc_name ) {
					$place['name'] = $loc_name;
				}
				if ( $loc_addr ) {
					$place['address'] = $loc_addr;
				}
				$out['location'] = $place;
			}
			$offer = $make_offer( 'event_price', 'event_currency' );
			if ( $offer ) {
				$out['offers'] = $offer;
			}
		}

		// ── Service ───────────────────────────────────────────────────────────
		if ( 'Service' === $type ) {
			if ( $str( 'service_type' ) ) {
				$out['serviceType'] = $str( 'service_type' );
			}
			if ( $str( 'service_area' ) ) {
				$out['areaServed'] = $str( 'service_area' );
			}
			$offer = $make_offer( 'service_price', 'service_currency' );
			if ( $offer ) {
				$out['offers'] = $offer;
			}
		}

		// ── Product ───────────────────────────────────────────────────────────
		if ( 'Product' === $type ) {
			if ( $str( 'product_brand' ) ) {
				$out['brand'] = [ '@type' => 'Brand', 'name' => $str( 'product_brand' ) ];
			}
			$offer = $make_offer( 'product_price', 'product_currency' );
			if ( $offer ) {
				$avail = $str( 'product_availability' );
				if ( in_array( $avail, [ 'InStock', 'OutOfStock', 'PreOrder' ], true ) ) {
					$offer['availability'] = 'https://schema.org/' . $avail;
				}
				$out['offers'] = $offer;
			}
		}

		// ── TouristAttraction ─────────────────────────────────────────────────
		if ( 'TouristAttraction' === $type ) {
			$city   = $str( 'attraction_city' );
			$region = $str( 'attraction_region' );
			if ( $city || $region ) {
				$addr = [ '@type' => 'PostalAddress' ];
				if ( $city )   { $addr['addressLocality'] = $city; }
				if ( $region ) { $addr['addressRegion']   = $region; }
				$out['address'] = $addr;
			}
			if ( $str( 'attraction_phone' ) ) {
				$out['telephone'] = $str( 'attraction_phone' );
			}
			$lat = $str( 'attraction_lat' );
			$lng = $str( 'attraction_lng' );
			if ( $lat !== '' && $lng !== '' ) {
				$out['geo'] = [
					'@type'     => 'GeoCoordinates',
					'latitude'  => (float) $lat,
					'longitude' => (float) $lng,
				];
			}
		}

		// ── TouristTrip ───────────────────────────────────────────────────────
		if ( 'TouristTrip' === $type ) {
			if ( $str( 'trip_departure' ) ) {
				$out['itinerary'] = [ '@type' => 'Place', 'name' => $str( 'trip_departure' ) ];
			}
			if ( $str( 'trip_duration' ) ) {
				$existing_desc       = isset( $out['description'] ) ? (string) $out['description'] : '';
				$out['description']  = ltrim( $existing_desc . ' Duration: ' . $str( 'trip_duration' ) );
			}
			$offer = $make_offer( 'trip_price', 'trip_currency' );
			if ( $offer ) {
				$out['offers'] = $offer;
			}
			if ( $str( 'trip_provider' ) ) {
				$out['provider'] = [ '@type' => 'Organization', 'name' => $str( 'trip_provider' ) ];
			}
		}

		// ── RealEstateListing ─────────────────────────────────────────────────
		if ( 'RealEstateListing' === $type ) {
			$street = $str( 'listing_street' );
			$city   = $str( 'listing_city' );
			$region = $str( 'listing_region' );
			if ( $street || $city || $region ) {
				$addr = [ '@type' => 'PostalAddress' ];
				if ( $street ) { $addr['streetAddress']  = $street; }
				if ( $city )   { $addr['addressLocality'] = $city; }
				if ( $region ) { $addr['addressRegion']   = $region; }
				$out['address'] = $addr;
			}
			if ( $str( 'listing_rooms' ) !== '' ) {
				$out['numberOfRooms'] = (int) $str( 'listing_rooms' );
			}
			if ( $str( 'listing_sqft' ) !== '' ) {
				$out['floorSize'] = [
					'@type'    => 'QuantitativeValue',
					'value'    => (float) $str( 'listing_sqft' ),
					'unitCode' => 'FTK', // square feet ISO unit code
				];
			}
			$offer = $make_offer( 'listing_price', 'listing_currency' );
			if ( $offer ) {
				$out['offers'] = $offer;
			}
		}

		// ── LocalBusiness ─────────────────────────────────────────────────────
		if ( 'LocalBusiness' === $type ) {
			if ( $str( 'business_hours' ) ) {
				// Support comma-separated hours specs → array.
				$hours_raw = array_map( 'trim', explode( ',', $str( 'business_hours' ) ) );
				$hours     = array_filter( $hours_raw );
				$out['openingHours'] = count( $hours ) === 1 ? reset( $hours ) : array_values( $hours );
			}
			if ( $str( 'business_price_range' ) ) {
				$out['priceRange'] = $str( 'business_price_range' );
			}
		}

		// ── Person ────────────────────────────────────────────────────────────
		if ( 'Person' === $type ) {
			if ( $str( 'person_job_title' ) ) {
				$out['jobTitle'] = $str( 'person_job_title' );
			}
			if ( $str( 'person_email' ) ) {
				$out['email'] = sanitize_email( $str( 'person_email' ) );
			}
			if ( $str( 'person_phone' ) ) {
				$out['telephone'] = $str( 'person_phone' );
			}
			if ( $str( 'person_same_as' ) ) {
				$out['sameAs'] = esc_url_raw( $str( 'person_same_as' ) );
			}
		}

		return $out;
	}
}
