<?php
/**
 * MM_Metadata_Loader — wires every module and admin class to WordPress hooks.
 */

defined( 'ABSPATH' ) || exit;

class MM_Metadata_Loader {

	private MM_Site_Settings $settings;
	private MM_Page_Context  $context;
	private MM_Head_Emitter $document;

	public function run(): void {
		$this->settings = MM_Site_Settings::get_instance();
		$this->context  = new MM_Page_Context();
		$this->document = new MM_Head_Emitter( $this->context, $this->settings );

		// --- Head modules (order matters — hygiene first, schema last) ----------
		// Hygiene hooks must be registered standalone; populate() is a no-op.
		( new MM_Mod_Hygiene( $this->settings ) )->register_hooks();
		$this->document->add_module( new MM_Mod_Head_Meta( $this->settings ) );
		$this->document->add_module( new MM_Mod_Social( $this->settings ) );
		$this->document->add_module( new MM_Mod_Schema( $this->settings ) );
		$this->document->add_module( new MM_Mod_Local( $this->settings ) );
		$this->document->add_module( new MM_Mod_Author( $this->settings ) );

		// Emit everything in one wp_head block at priority 2.
		// Title is handled via pre_get_document_title filter (priority 1).
		add_action( 'wp_head', [ $this->document, 'render' ], 2 );

		// Title filter — works for themes with add_theme_support('title-tag').
		add_filter( 'pre_get_document_title', [ $this, 'filter_title' ], 10 );

		// --- Standalone modules (own hooks) ------------------------------------
		( new MM_Mod_Sitemap_Web( $this->settings ) )->register_hooks();
		( new MM_Mod_Robots( $this->settings ) )->register_hooks();
		( new MM_Mod_Links( $this->settings ) )->register_hooks();
		( new MM_Mod_Html_Sitemap( $this->settings ) )->register_hooks();
		( new MM_Mod_Business_Contact( $this->settings ) )->register_hooks();

		// --- Admin ---------------------------------------------------------------
		if ( is_admin() ) {
			( new MM_Metadata_Admin( $this->settings ) )->register_hooks();
			( new MM_Post_Meta_Panel( $this->settings ) )->register_hooks();
			( new MM_Term_Meta_Panel( $this->settings ) )->register_hooks();
			( new MM_User_Meta_Panel( $this->settings ) )->register_hooks();
		}
	}

	// -------------------------------------------------------------------------
	// Title filter
	// -------------------------------------------------------------------------

	public function filter_title( string $title ): string {
		// Build a throw-away $data array just to run the Meta module's title logic.
		$data = [ 'title' => '', 'meta' => [], 'links' => [], 'schema' => [] ];
		( new MM_Mod_Head_Meta( $this->settings ) )->populate( $data, $this->context, $this->settings );
		return $data['title'] ?: $title;
	}
}
