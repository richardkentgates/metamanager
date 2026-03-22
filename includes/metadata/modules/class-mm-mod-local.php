<?php
/**
 * MM_Mod_Local — LocalBusiness / Organization schema node.
 *
 * Adds the knowledge-graph entity node to the @graph on every page.
 * On the homepage also emits og:type="business.business" and
 * business:contact_data:* tags for Facebook's business card format.
 *
 * Supports 45 schema.org LocalBusiness subtypes grouped by industry.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Local extends MM_Mod_Base {

	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		$biz = $settings->all_business();

		if ( empty( $biz['name'] ) ) {
			// No business profile configured yet — add minimal Organization node.
			$this->add_node( $data, [
				'@type' => 'Organization',
				'@id'   => $this->site_id( 'organization' ),
				'name'  => get_bloginfo( 'name' ),
				'url'   => $this->site_url(),
			] );
			return;
		}

		$type = $biz['type'] ?: 'LocalBusiness';
		$node = [
			'@type'  => $type,
			'@id'    => $this->site_id( 'organization' ),
			'name'   => $biz['name'],
			'url'    => $this->site_url(),
		];

		// Logo.
		if ( ! empty( $biz['logo_url'] ) || ! empty( $biz['logo_id'] ) ) {
			$logo = $this->image_data( (int) ( $biz['logo_id'] ?? 0 ), $biz['logo_url'] ?? '' );
			if ( $logo['url'] ) {
				$node['logo'] = [
					'@type'  => 'ImageObject',
					'url'    => $logo['url'],
					'width'  => $logo['width'],
					'height' => $logo['height'],
				];
				$node['image'] = $node['logo'];
			}
		}

		// Contact.
		if ( ! empty( $biz['phone'] ) ) {
			$node['telephone'] = sanitize_text_field( $biz['phone'] );
		}
		if ( ! empty( $biz['email'] ) ) {
			$node['email'] = sanitize_email( $biz['email'] );
		}

		// Address.
		$addr = $biz['address'] ?? [];
		if ( ! empty( $addr['street'] ) ) {
			$node['address'] = array_filter( [
				'@type'           => 'PostalAddress',
				'streetAddress'   => $addr['street'] ?? '',
				'addressLocality' => $addr['city']    ?? '',
				'addressRegion'   => $addr['state']   ?? '',
				'postalCode'      => $addr['zip']     ?? '',
				'addressCountry'  => $addr['country'] ?? 'US',
			] );
		}

		// Geo coordinates.
		if ( ! empty( $biz['lat'] ) && ! empty( $biz['lng'] ) ) {
			$node['geo'] = [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $biz['lat'],
				'longitude' => (float) $biz['lng'],
			];
		}

		// Price range.
		if ( ! empty( $biz['price_range'] ) ) {
			$node['priceRange'] = sanitize_text_field( $biz['price_range'] );
		}

		// Payment accepted.
		if ( ! empty( $biz['payment_accepted'] ) ) {
			$node['paymentAccepted'] = implode( ', ', array_map( 'sanitize_text_field', $biz['payment_accepted'] ) );
		}

		// Opening hours.
		$hours = $this->build_opening_hours( $biz['hours'] ?? [] );
		if ( $hours ) {
			$node['openingHoursSpecification'] = $hours;
		}

		// Service areas (areaServed).
		if ( ! empty( $biz['service_areas'] ) ) {
			$service_areas = array_map( function ( $area ) {
				return [ '@type' => 'City', 'name' => sanitize_text_field( $area ) ];
			}, $biz['service_areas'] );
			$node['areaServed'] = $service_areas;
		}

		// Social profiles → sameAs.
		$accounts = $settings->get( 'social.accounts', [] );
		$same_as  = array_values( array_filter( array_map( 'esc_url_raw', $accounts ) ) );
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}

		$this->add_node( $data, $node );

		// Multiple locations — each as a separate node.
		if ( ! empty( $biz['locations'] ) ) {
			foreach ( $biz['locations'] as $index => $loc ) {
				$this->add_node( $data, $this->build_location_node( $loc, $node, $index ) );
			}
		}

		// Homepage: OG business.business type + contact tags.
		if ( $context->is_front_page() && $settings->get( 'social.og_enabled', true ) ) {
			$this->emit_og_business( $data, $biz, $node );
		}
	}

	// -------------------------------------------------------------------------
	// Opening hours
	// -------------------------------------------------------------------------

	private function build_opening_hours( array $hours ): array {
		$specs = [];
		$day_map = [
			'monday'    => 'Monday',
			'tuesday'   => 'Tuesday',
			'wednesday' => 'Wednesday',
			'thursday'  => 'Thursday',
			'friday'    => 'Friday',
			'saturday'  => 'Saturday',
			'sunday'    => 'Sunday',
		];

		foreach ( $hours as $entry ) {
			if ( empty( $entry['days'] ) || empty( $entry['open'] ) || empty( $entry['close'] ) ) {
				continue;
			}
			foreach ( $entry['days'] as $day ) {
				$day_name = $day_map[ strtolower( $day ) ] ?? null;
				if ( $day_name ) {
					$specs[] = [
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => 'https://schema.org/' . $day_name,
						'opens'     => sanitize_text_field( $entry['open'] ),
						'closes'    => sanitize_text_field( $entry['close'] ),
					];
				}
			}
		}
		return $specs;
	}

	// -------------------------------------------------------------------------
	// Multiple locations
	// -------------------------------------------------------------------------

	private function build_location_node( array $loc, array $parent, int $index ): array {
		$node = [
			'@type'  => $parent['@type'],
			'@id'    => $this->site_id( 'location-' . ( $index + 1 ) ),
			'name'   => sanitize_text_field( $loc['name'] ?? $parent['name'] ),
			'url'    => $parent['url'],
			'parentOrganization' => [ '@id' => $parent['@id'] ],
		];
		$addr = $loc['address'] ?? [];
		if ( ! empty( $addr['street'] ) ) {
			$node['address'] = array_filter( [
				'@type'           => 'PostalAddress',
				'streetAddress'   => $addr['street']  ?? '',
				'addressLocality' => $addr['city']     ?? '',
				'addressRegion'   => $addr['state']    ?? '',
				'postalCode'      => $addr['zip']      ?? '',
				'addressCountry'  => $addr['country']  ?? 'US',
			] );
		}
		if ( ! empty( $loc['phone'] ) ) {
			$node['telephone'] = sanitize_text_field( $loc['phone'] );
		}
		$hours = $this->build_opening_hours( $loc['hours'] ?? [] );
		if ( $hours ) {
			$node['openingHoursSpecification'] = $hours;
		}
		return $node;
	}

	// -------------------------------------------------------------------------
	// OG business.business (homepage Facebook card)
	// -------------------------------------------------------------------------

	private function emit_og_business( array &$data, array $biz, array $node ): void {
		// Override og:type to business.business.
		foreach ( $data['meta'] as &$mt ) {
			if ( ( $mt['property'] ?? '' ) === 'og:type' ) {
				$mt['content'] = 'business.business';
				break;
			}
		}
		unset( $mt );

		$addr = $biz['address'] ?? [];
		$contact_fields = array_filter( [
			'business:contact_data:street_address' => $addr['street']  ?? '',
			'business:contact_data:locality'       => $addr['city']    ?? '',
			'business:contact_data:region'         => $addr['state']   ?? '',
			'business:contact_data:postal_code'    => $addr['zip']     ?? '',
			'business:contact_data:country_name'   => $addr['country'] ?? '',
			'business:contact_data:phone_number'   => $biz['phone']    ?? '',
			'business:contact_data:email'          => $biz['email']    ?? '',
		] );

		foreach ( $contact_fields as $prop => $val ) {
			$data['meta'][] = [ 'property' => $prop, 'content' => esc_attr( sanitize_text_field( $val ) ) ];
		}
	}

	// -------------------------------------------------------------------------
	// Static: known LocalBusiness subtypes grouped by industry.
	// Used by admin settings UI.
	// -------------------------------------------------------------------------

	public static function get_business_types(): array {
		return [
			'General' => [
				'LocalBusiness'    => 'Local Business (generic)',
				'Organization'     => 'Organization',
				'Corporation'      => 'Corporation',
				'NGO'              => 'Non-Profit / NGO',
			],
			'Professional Services' => [
				'LegalService'           => 'Legal Service',
				'Attorney'               => 'Attorney / Law Firm',
				'AccountingService'      => 'Accounting Service / CPA',
				'FinancialService'       => 'Financial Services',
				'InsuranceAgency'        => 'Insurance Agency',
				'RealEstateAgent'        => 'Real Estate Agent',
				'NotaryService'          => 'Notary Service',
				'EmploymentAgency'       => 'Employment / Staffing Agency',
				'ITService'              => 'IT Service / Tech Consulting',
				'MarketingAgency'        => 'Marketing Agency',
			],
			'Medical & Wellness' => [
				'MedicalBusiness'      => 'Medical Business (generic)',
				'Dentist'              => 'Dentist',
				'Physician'            => 'Physician / Doctor',
				'Optician'             => 'Optician',
				'Optometrist'          => 'Optometrist',
				'Pharmacy'             => 'Pharmacy',
				'AnimalShelter'        => 'Veterinarian / Animal Hospital',
				'Chiropractor'         => 'Chiropractor',
				'MassageTherapist'     => 'Massage Therapist',
				'Therapist'            => 'Therapist / Counselor',
			],
			'Home & Construction' => [
				'HomeAndConstructionBusiness' => 'Home & Construction (generic)',
				'Electrician'                 => 'Electrician',
				'GeneralContractor'           => 'General Contractor',
				'HousePainter'                => 'Painter',
				'HVACBusiness'                => 'HVAC',
				'Locksmith'                   => 'Locksmith',
				'MovingCompany'               => 'Moving Company',
				'Plumber'                     => 'Plumber',
				'RoofingContractor'           => 'Roofing Contractor',
				'LandscapingBusiness'         => 'Landscaping',
				'HouseCleaning'               => 'House Cleaning / Maid Service',
			],
			'Food & Dining' => [
				'FoodEstablishment'   => 'Food Establishment (generic)',
				'Bakery'              => 'Bakery',
				'BarOrPub'            => 'Bar / Pub',
				'Brewery'             => 'Brewery / Winery',
				'CafeOrCoffeeShop'    => 'Café / Coffee Shop',
				'FastFoodRestaurant'  => 'Fast Food',
				'IceCreamShop'        => 'Ice Cream Shop',
				'Restaurant'          => 'Restaurant',
				'FoodTruck'           => 'Food Truck',
			],
			'Retail' => [
				'Store'              => 'Retail Store (generic)',
				'AutoPartsStore'     => 'Auto Parts Store',
				'BookStore'          => 'Book Store',
				'ClothingStore'      => 'Clothing Store',
				'ConvenienceStore'   => 'Convenience Store',
				'FlowerShop'         => 'Florist',
				'FurnitureStore'     => 'Furniture Store',
				'GroceryStore'       => 'Grocery Store',
				'HardwareStore'      => 'Hardware Store',
				'JewelryStore'       => 'Jewelry Store',
				'PetStore'           => 'Pet Store',
			],
			'Automotive' => [
				'AutoDealer'          => 'Auto Dealer',
				'AutoBodyShop'        => 'Auto Body Shop',
				'AutoRepair'          => 'Auto Repair',
				'AutoWash'            => 'Car Wash',
			],
			'Beauty & Personal Care' => [
				'BeautySalon'     => 'Beauty Salon',
				'HairSalon'       => 'Hair Salon',
				'NailSalon'       => 'Nail Salon',
				'TattooParlor'    => 'Tattoo Parlor',
				'SpaService'      => 'Spa',
			],
			'Education & Fitness' => [
				'EducationalOrganization' => 'School / Educational Org',
				'SportsActivityLocation'  => 'Gym / Fitness Center',
				'DanceSchool'             => 'Dance School',
				'MusicSchool'             => 'Music School',
				'TutoringService'         => 'Tutoring Service',
			],
			'Lodging & Travel' => [
				'Hotel'           => 'Hotel',
				'BedAndBreakfast' => 'Bed & Breakfast',
				'TouristAttraction' => 'Tourist Attraction',
				'TravelAgency'    => 'Travel Agency',
			],
		];
	}
}
