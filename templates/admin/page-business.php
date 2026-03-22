<?php
/**
 * Admin tab — Business Profile.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$b   = $settings->get_business();
$opt = MM_META_OPT_BUSINESS;

$business_types = MM_Mod_Local::get_business_types();

// Opening hours skeleton (7 rows if none saved)
$hours = $b['hours'] ?? [];
$hour_defaults = [
	['days'=>['Monday'],'open'=>'09:00','close'=>'17:00','closed'=>false],
	['days'=>['Tuesday'],'open'=>'09:00','close'=>'17:00','closed'=>false],
	['days'=>['Wednesday'],'open'=>'09:00','close'=>'17:00','closed'=>false],
	['days'=>['Thursday'],'open'=>'09:00','close'=>'17:00','closed'=>false],
	['days'=>['Friday'],'open'=>'09:00','close'=>'17:00','closed'=>false],
	['days'=>['Saturday'],'open'=>'','close'=>'','closed'=>true],
	['days'=>['Sunday'],'open'=>'','close'=>'','closed'=>true],
];
if ( empty($hours) ) { $hours = $hour_defaults; }
?>

<div class="mm-meta-panel" id="page-business">

	<h2>Business Identity</h2>
	<p class="description">Used for LocalBusiness schema, Open Graph <code>business.business</code> tags, and site-wide rich results.</p>

	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="gcm_biz_name">Business Name</label></th>
			<td>
				<input type="text" id="gcm_biz_name" name="<?php echo esc_attr($opt); ?>[name]"
					value="<?php echo esc_attr($b['name']); ?>" class="regular-text">
			</td>
		</tr>
		<tr>
			<th><label for="gcm_biz_type">Business Type</label></th>
			<td>
				<select id="gcm_biz_type" name="<?php echo esc_attr($opt); ?>[type]">
					<option value="Organization" <?php selected($b['type'],'Organization'); ?>>Organization (generic)</option>
					<option value="LocalBusiness" <?php selected($b['type'],'LocalBusiness'); ?>>LocalBusiness (generic)</option>
					<?php foreach ( $business_types as $group_label => $subtypes ) : ?>
						<optgroup label="<?php echo esc_attr($group_label); ?>">
							<?php foreach ( $subtypes as $slug => $label ) : ?>
								<option value="<?php echo esc_attr($slug); ?>" <?php selected($b['type'],$slug); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_biz_logo">Logo</label></th>
			<td>
				<div class="gcm-media-picker" data-target="gcm_biz_logo_id" data-preview="gcm_biz_logo_preview">
					<input type="hidden" id="gcm_biz_logo_id" name="<?php echo esc_attr($opt); ?>[logo_id]" value="<?php echo (int)($b['logo_id']??0); ?>">
					<input type="text"   id="gcm_biz_logo_url" name="<?php echo esc_attr($opt); ?>[logo_url]" value="<?php echo esc_attr($b['logo_url']??''); ?>" class="large-text" placeholder="URL or pick from media library">
					<button type="button" class="button gcm-btn-pick-media">Choose Image</button>
					<?php if ( ! empty($b['logo_url']) ) : ?>
						<img id="gcm_biz_logo_preview" src="<?php echo esc_url($b['logo_url']); ?>" class="gcm-image-preview" style="max-height:60px;display:block;margin-top:8px;">
					<?php else : ?>
						<img id="gcm_biz_logo_preview" src="" class="gcm-image-preview" style="max-height:60px;display:none;margin-top:8px;">
					<?php endif; ?>
				</div>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_biz_phone">Phone</label></th>
			<td>
				<input type="text" id="gcm_biz_phone" name="<?php echo esc_attr($opt); ?>[phone]"
					value="<?php echo esc_attr($b['phone']??''); ?>" class="regular-text" placeholder="+1 (555) 000-0000">
			</td>
		</tr>
		<tr>
			<th><label for="gcm_biz_email">Email</label></th>
			<td>
				<input type="email" id="gcm_biz_email" name="<?php echo esc_attr($opt); ?>[email]"
					value="<?php echo esc_attr($b['email']??''); ?>" class="regular-text">
			</td>
		</tr>
		<tr>
			<th><label for="gcm_biz_price">Price Range</label></th>
			<td>
				<select id="gcm_biz_price" name="<?php echo esc_attr($opt); ?>[price_range]">
					<?php foreach ( ['', '$', '$$', '$$$', '$$$$'] as $p ) : ?>
						<option value="<?php echo esc_attr($p); ?>" <?php selected($b['price_range']??'',$p); ?>><?php echo $p ? esc_html($p) : '— not set —'; ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>

	<h2>Address &amp; Location</h2>
	<table class="form-table gcm-form-table">
		<?php $a = $b['address'] ?? []; ?>
		<tr>
			<th><label for="gcm_addr_street">Street Address</label></th>
			<td><input type="text" id="gcm_addr_street" name="<?php echo esc_attr($opt); ?>[address][street]" value="<?php echo esc_attr($a['street']??''); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th><label for="gcm_addr_city">City</label></th>
			<td><input type="text" id="gcm_addr_city" name="<?php echo esc_attr($opt); ?>[address][city]" value="<?php echo esc_attr($a['city']??''); ?>" class="regular-text"></td>
		</tr>
		<tr>
			<th><label for="gcm_addr_state">State / Province</label></th>
			<td><input type="text" id="gcm_addr_state" name="<?php echo esc_attr($opt); ?>[address][state]" value="<?php echo esc_attr($a['state']??''); ?>" class="regular-text" placeholder="e.g. NC"></td>
		</tr>
		<tr>
			<th><label for="gcm_addr_zip">ZIP / Postal Code</label></th>
			<td><input type="text" id="gcm_addr_zip" name="<?php echo esc_attr($opt); ?>[address][zip]" value="<?php echo esc_attr($a['zip']??''); ?>" class="small-text"></td>
		</tr>
		<tr>
			<th><label for="gcm_addr_country">Country Code</label></th>
			<td><input type="text" id="gcm_addr_country" name="<?php echo esc_attr($opt); ?>[address][country]" value="<?php echo esc_attr($a['country']??'US'); ?>" class="small-text" placeholder="US"></td>
		</tr>
		<tr>
			<th><label>Geo Coordinates</label></th>
			<td>
				<input type="text" name="<?php echo esc_attr($opt); ?>[lat]" value="<?php echo esc_attr($b['lat']??''); ?>" class="small-text" placeholder="Latitude">
				<input type="text" name="<?php echo esc_attr($opt); ?>[lng]" value="<?php echo esc_attr($b['lng']??''); ?>" class="small-text" placeholder="Longitude" style="margin-left:8px">
				<p class="description">Optional. Used in <code>GeoCoordinates</code> schema node.</p>
			</td>
		</tr>
	</table>

	<h2>Opening Hours</h2>
	<p class="description">Rows with no open/close time will be emitted as closed. Drag to reorder.</p>
	<div class="gcm-repeater" id="gcm-hours-repeater">
		<table class="gcm-repeater-table widefat striped">
			<thead>
				<tr>
					<th class="gcm-drag-handle-cell"></th>
					<th>Days</th>
					<th>Opens</th>
					<th>Closes</th>
					<th>Closed?</th>
					<th></th>
				</tr>
			</thead>
		<tbody class="gcm-repeater-body" id="gcm-hours-rows"
				data-sortable="true"
				data-name-base="<?php echo esc_attr($opt); ?>[hours]">
				<?php
				$days_all = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
				foreach ( $hours as $ri => $row ) :
					$row_days = is_array($row['days']) ? $row['days'] : [];
				?>
				<tr class="gcm-repeater-row">
					<td class="gcm-drag-handle-cell"><span class="gcm-drag-handle dashicons dashicons-move" title="Drag to reorder"></span></td>
					<td class="gcm-days-cell">
						<?php foreach ( $days_all as $d ) : ?>
							<label class="gcm-day-label">
								<input type="checkbox"
									name="<?php echo esc_attr($opt); ?>[hours][<?php echo $ri; ?>][days][]"
									value="<?php echo esc_attr($d); ?>"
									<?php checked( in_array($d, $row_days, true) ); ?>>
								<?php echo esc_html( substr($d,0,3) ); ?>
							</label>
						<?php endforeach; ?>
					</td>
					<td><input type="time" name="<?php echo esc_attr($opt); ?>[hours][<?php echo $ri; ?>][open]" value="<?php echo esc_attr($row['open']??''); ?>" class="gcm-time-input"></td>
					<td><input type="time" name="<?php echo esc_attr($opt); ?>[hours][<?php echo $ri; ?>][close]" value="<?php echo esc_attr($row['close']??''); ?>" class="gcm-time-input"></td>
					<td style="text-align:center"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[hours][<?php echo $ri; ?>][closed]" value="1" <?php checked(!empty($row['closed'])); ?>></td>
					<td><button type="button" class="button-link gcm-repeater-remove">&times;</button></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<button type="button" class="button gcm-repeater-add" data-target="gcm-hours-rows" data-template="hours">+ Add Row</button>
	</div>

	<h2>Service Areas</h2>
	<p class="description">Cities or regions you serve. Each becomes a <code>City</code> node in <code>areaServed</code>.</p>
	<div class="gcm-repeater" id="gcm-areas-repeater">
		<ul class="gcm-simple-list" id="gcm-areas-list" data-name-base="<?php echo esc_attr($opt); ?>[service_areas]">
			<?php foreach ( (array)($b['service_areas']??[]) as $ai => $area ) : ?>
				<li class="gcm-simple-list-item">
					<input type="text" name="<?php echo esc_attr($opt); ?>[service_areas][]"
						value="<?php echo esc_attr($area); ?>" class="regular-text" placeholder="City name">
					<button type="button" class="button-link gcm-repeater-remove">✕</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<button type="button" class="button gcm-repeater-add" data-target="gcm-areas-list" data-template="area">+ Add Area</button>
	</div>

	<h2>Social Profiles (sameAs)</h2>
	<table class="form-table gcm-form-table">
		<?php
		$social_fields = [
			'facebook'  => 'Facebook',
			'instagram' => 'Instagram',
			'linkedin'  => 'LinkedIn',
			'youtube'   => 'YouTube',
			'twitter'   => 'Twitter / X',
			'bluesky'   => 'BlueSky',
			'pinterest' => 'Pinterest',
		];
		$accounts = $b['accounts'] ?? [];
		foreach ( $social_fields as $field => $label ) : ?>
		<tr>
			<th><label><?php echo esc_html($label); ?></label></th>
			<td>
				<input type="url" name="<?php echo esc_attr($opt); ?>[accounts][<?php echo esc_attr($field); ?>]"
					value="<?php echo esc_attr($accounts[$field]??''); ?>"
					class="large-text" placeholder="Full URL">
			</td>
		</tr>
		<?php endforeach; ?>
	</table>

</div>
