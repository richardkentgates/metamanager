<?php
/**
 * MM_Metadata_Help — contextual help tab content for every Metamanager admin screen.
 *
 * All methods are static; no instance state is required.
 */

defined( 'ABSPATH' ) || exit;

class MM_Metadata_Help {

	/**
	 * Return the HTML string for the Overview tab on a given page.
	 *
	 * @param string $page Page key (e.g. 'business', 'titles', 'social' …).
	 * @return string
	 */
	public static function overview( string $page ): string {
		$texts = [
			'business' => '<p>' . esc_html__( 'The Business page stores your local business profile: name, type, address, phone, logo, geo-coordinates, and opening hours. This data feeds the LocalBusiness JSON-LD node that Google uses to build your Knowledge Panel.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Select the most specific Schema.org business subtype that describes your organisation — the grouped dropdown maps to the full LocalBusiness hierarchy. Fill in every field you can; more complete data produces richer results.', 'metamanager' ) . '</p>',

			'titles' => '<p>' . esc_html__( 'The Titles page controls every SEO title and meta description template across the site. Titles are assembled using token variables so you can build dynamic patterns without writing code.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Tokens available: %%sitetitle%%, %%tagline%%, %%sep%%, %%post_title%%, %%term_title%%, %%author_name%%, %%page%%, %%current_year%%, %%post_type_label%%, %%search_query%%.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Individual posts, terms, and users can override any template from their own SEO metabox or profile fields.', 'metamanager' ) . '</p>',

			'social' => '<p>' . esc_html__( 'The Social page configures Open Graph (Facebook, LinkedIn, Pinterest) and Twitter/X Card meta tags. These tags control how your pages appear when shared on social networks.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Set a default OG image that is used as a fallback when no featured image is available. For best results use a 1200×630 px image (1.91:1 ratio).', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Social profile URLs entered here are also added to the sameAs property of your LocalBusiness / Organization schema node.', 'metamanager' ) . '</p>',

			'schema' => '<p>' . esc_html__( 'The Schema page configures site-wide JSON-LD defaults. Metamanager always emits a WebSite node and a per-page WebPage node. You control which content-type node is attached to each post type.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'The Knowledge Entity setting controls the top-level organisation node (WebSite isPartOf). Breadcrumbs and SearchAction are toggled here globally and can be fine-tuned per-post.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'The Custom JSON-LD field is a power-user escape hatch: any valid JSON-LD object pasted there is merged verbatim into the @graph.', 'metamanager' ) . '</p>',

			'sitemaps' => '<p>' . esc_html__( 'Metamanager generates a sitemap index at /sitemap.xml that references per-post-type, per-taxonomy, and video sub-sitemaps. The WordPress 5.5+ built-in sitemap is disabled automatically.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'When ping is enabled, Google and Bing are notified within 60 seconds whenever you publish or update a post. Each engine is pinged at most once per publish event via WP-Cron.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Password-protected and noindex posts are excluded from the sitemap by default. Adjust "Records per file" if you have a very large site (default: 1 000).', 'metamanager' ) . '</p>',

			'robots' => '<p>' . esc_html__( 'The Robots page replaces the WordPress-generated robots.txt with a fully customisable version. The output is served live — no static file is written to disk.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'By default /wp-admin/ is disallowed and /wp-admin/admin-ajax.php is explicitly allowed. A Sitemap: directive pointing to /sitemap.xml is appended automatically.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Use the Custom directives textarea for any directive not covered by the form fields (e.g. per-bot rules). One directive per line, no blank lines required between sections.', 'metamanager' ) . '</p>',

			'authors' => '<p>' . esc_html__( 'The Authors page controls SEO settings for author archive pages. You can set a global title and description template, toggle author archives to noindex by default, and control whether Person schema nodes are emitted.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Individual authors can override these defaults from their WordPress profile page using the Metamanager fields added there. Social profile links entered on each profile are included in that author\'s Person schema sameAs array.', 'metamanager' ) . '</p>',

			'hygiene' => '<p>' . esc_html__( 'The Hygiene page has two sections: head cleanup and content audits. Head cleanup removes WordPress-generated tags that add noise, expose version information, or are unsupported by modern search engines.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Content audits scan published posts for SEO issues: orphan pages (no internal links pointing to them), thin content (below the configured word-count floor), and duplicate titles (multiple posts sharing the same title template output).', 'metamanager' ) . '</p>',

			'links' => '<p>' . esc_html__( 'The Links page shows all internal and external links extracted from your post content and their last-checked HTTP status code. Broken links (4xx/5xx) are highlighted in red.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Links are extracted automatically when a post is saved. The WP-Cron checker runs every 6 hours by default, checking a configurable batch size to avoid server timeouts.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'You can ignore individual links or entire domains. The "Re-check" button forces an immediate HEAD request for a single URL. Use WP-CLI (wp metamanager check-links --all) for a full one-shot scan.', 'metamanager' ) . '</p>',

			'tools' => '<p>' . esc_html__( 'The Tools page provides one-click maintenance actions. Actions run via AJAX and report success or failure inline — no page reload required.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Flush Rewrites re-registers all sitemap URL patterns in the WordPress rewrite table — run this after activating the plugin on a new server or after changing permalink settings.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Reset Settings wipes all Metamanager options back to factory defaults. This cannot be undone. Export your settings first if you need a backup.', 'metamanager' ) . '</p>',

			'contact' => '<p>' . esc_html__( 'The Contact Card page controls the Business Contact Card displayed via the GCM Business Contact Card widget, the Business Contact Card Gutenberg block, or the [gcm_business_contact] shortcode.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Visible Actions determine which buttons appear on every card. Any action whose corresponding data is missing from your Business Profile (e.g. no phone number set) is automatically hidden regardless of this setting.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Card and Button Appearance controls are sitewide — they apply to every widget, block, and shortcode instance at once. Each card also emits an inline schema.org JSON-LD node scoped to that placement.', 'metamanager' ) . '</p>' .
				'<p>' . esc_html__( 'Download endpoints (vCard, JSON, CSV) are served from /gcm-biz-export/{format}/ and can be linked to directly. If these URLs return 404 after activation, go to SEO → Tools and click Flush Rewrite Rules.', 'metamanager' ) . '</p>',
		];

		return $texts[ $page ] ?? '';
	}

	/**
	 * Return additional help tabs (beyond Overview) for a given page.
	 *
	 * @param string $page Page key.
	 * @return array Array of tab definition arrays for WP_Screen::add_help_tab().
	 */
	public static function extra_tabs( string $page ): array {
		$tabs = [];

		if ( 'titles' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_title_tokens',
				'title'   => __( 'Token Reference', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'Wrap tokens in double percent signs: %%token_name%%.', 'metamanager' ) . '</p>' .
					'<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Token', 'metamanager' ) . '</th><th>' . esc_html__( 'Resolves to', 'metamanager' ) . '</th></tr></thead><tbody>' .
					'<tr><td><code>%%sitetitle%%</code></td><td>' . esc_html__( 'Site name (Settings → General)', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%tagline%%</code></td><td>' . esc_html__( 'Site tagline (Settings → General)', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%sep%%</code></td><td>' . esc_html__( 'Title separator character selected above', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%post_title%%</code></td><td>' . esc_html__( 'The post or page title', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%term_title%%</code></td><td>' . esc_html__( 'Category, tag, or taxonomy term name', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%term_description%%</code></td><td>' . esc_html__( 'Term description text', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%author_name%%</code></td><td>' . esc_html__( 'Author display name', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%author_bio%%</code></td><td>' . esc_html__( 'Author biographical info field', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%page%%</code></td><td>' . esc_html__( 'Pagination number (omitted on page 1)', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%current_year%%</code></td><td>' . esc_html__( 'Four-digit current year', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%current_month%%</code></td><td>' . esc_html__( 'Full month name', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%post_type_label%%</code></td><td>' . esc_html__( 'Post type plural label (e.g. "Posts")', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%search_query%%</code></td><td>' . esc_html__( 'The search term (search pages only)', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>%%category%%</code></td><td>' . esc_html__( 'Primary category of post', 'metamanager' ) . '</td></tr>' .
					'</tbody></table>',
			];
		}

		if ( 'schema' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_schema_types',
				'title'   => __( 'Schema Types', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'Metamanager emits a structured @graph on every page. Common node types:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong>WebSite</strong> — ' . esc_html__( 'Once per site; includes a SearchAction for sitelinks search box eligibility.', 'metamanager' ) . '</li>' .
					'<li><strong>WebPage / subtypes</strong> — ' . esc_html__( 'One node per page (AboutPage, ContactPage, FAQPage, SearchResultsPage, ProfilePage…).', 'metamanager' ) . '</li>' .
					'<li><strong>BreadcrumbList</strong> — ' . esc_html__( 'Automatic breadcrumb trail using post ancestors and primary category.', 'metamanager' ) . '</li>' .
					'<li><strong>BlogPosting / Article / NewsArticle</strong> — ' . esc_html__( 'Assigned per post type; linked to the Author Person node.', 'metamanager' ) . '</li>' .
					'<li><strong>LocalBusiness subtypes</strong> — ' . esc_html__( 'Added to every page via the Business profile; includes OpeningHoursSpecification and GeoCoordinates.', 'metamanager' ) . '</li>' .
					'<li><strong>Person</strong> — ' . esc_html__( 'On author archives and singular posts; includes sameAs social links.', 'metamanager' ) . '</li>' .
					'<li><strong>ImageObject</strong> — ' . esc_html__( 'Emitted automatically when an OG image is present.', 'metamanager' ) . '</li>' .
					'<li><strong>ItemList</strong> — ' . esc_html__( 'On taxonomy archive pages listing posts.', 'metamanager' ) . '</li>' .
					'</ul>',
			];
		}

		if ( 'sitemaps' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_sitemap_urls',
				'title'   => __( 'Sitemap URLs', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'The following sitemap endpoints are registered:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><code>/sitemap.xml</code> — ' . esc_html__( 'Index listing all sub-sitemaps.', 'metamanager' ) . '</li>' .
					'<li><code>/sitemap-post-{type}.xml</code> — ' . esc_html__( 'One file per enabled post type (e.g. /sitemap-post-post.xml).', 'metamanager' ) . '</li>' .
					'<li><code>/sitemap-tax-{taxonomy}.xml</code> — ' . esc_html__( 'One file per enabled taxonomy (e.g. /sitemap-tax-category.xml).', 'metamanager' ) . '</li>' .
					'</ul>' .
					'<p>' . esc_html__( 'If any sitemap URL returns 404, go to Settings → Tools and click "Flush Rewrite Rules".', 'metamanager' ) . '</p>',
			];
		}

		if ( 'links' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_links_status',
				'title'   => __( 'Status Codes', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'HTTP status codes returned by the link checker:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong>0</strong> — ' . esc_html__( 'Not yet checked or connection timed out.', 'metamanager' ) . '</li>' .
					'<li><strong>200</strong> — ' . esc_html__( 'OK — link is healthy.', 'metamanager' ) . '</li>' .
					'<li><strong>301 / 302</strong> — ' . esc_html__( 'Redirect — link works but consider updating the URL.', 'metamanager' ) . '</li>' .
					'<li><strong>403</strong> — ' . esc_html__( 'Forbidden — may be a false positive (server blocks bots). Ignore if expected.', 'metamanager' ) . '</li>' .
					'<li><strong>404</strong> — ' . esc_html__( 'Not Found — the target page no longer exists. Fix or remove the link.', 'metamanager' ) . '</li>' .
					'<li><strong>410</strong> — ' . esc_html__( 'Gone — page was permanently removed.', 'metamanager' ) . '</li>' .
					'<li><strong>5xx</strong> — ' . esc_html__( 'Server error on the target site — check again later.', 'metamanager' ) . '</li>' .
					'</ul>',
			];

			$tabs[] = [
				'id'      => 'mm_meta_links_cli',
				'title'   => __( 'WP-CLI', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'Run a full link scan from the command line (bypasses batch size limits):', 'metamanager' ) . '</p>' .
					'<pre><code>wp metamanager check-links --all</code></pre>' .
					'<p>' . esc_html__( 'Purge the link table and start fresh:', 'metamanager' ) . '</p>' .
					'<pre><code>wp metamanager purge-links</code></pre>',
			];
		}

		if ( 'tools' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_tools_cli',
				'title'   => __( 'WP-CLI Commands', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'All tools are also available as WP-CLI subcommands under wp metamanager:', 'metamanager' ) . '</p>' .
					'<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Command', 'metamanager' ) . '</th><th>' . esc_html__( 'What it does', 'metamanager' ) . '</th></tr></thead><tbody>' .
					'<tr><td><code>wp metamanager export</code></td><td>' . esc_html__( 'Dump all settings as JSON to stdout.', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>wp metamanager export --format=pretty</code></td><td>' . esc_html__( 'Pretty-print JSON for readability.', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>wp metamanager reset</code></td><td>' . esc_html__( 'Reset all settings to factory defaults (with confirmation prompt).', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>wp metamanager check-links</code></td><td>' . esc_html__( 'Run one batch of the link checker immediately.', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>wp metamanager check-links --all</code></td><td>' . esc_html__( 'Run all batches until every link is checked.', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>wp metamanager ping</code></td><td>' . esc_html__( 'Ping Google and Bing with the sitemap URL immediately.', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>wp metamanager flush-rewrites</code></td><td>' . esc_html__( 'Flush WordPress rewrite rules.', 'metamanager' ) . '</td></tr>' .
					'<tr><td><code>wp metamanager schema-test &lt;url&gt;</code></td><td>' . esc_html__( 'Fetch a page URL and print its JSON-LD schema.', 'metamanager' ) . '</td></tr>' .
					'</tbody></table>',
			];
		}

		if ( 'hygiene' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_hygiene_head',
				'title'   => __( 'Head Cleanup Reference', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'Toggles and what they remove from wp_head:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . esc_html__( 'Generator tag', 'metamanager' ) . '</strong> — <code>&lt;meta name="generator" content="WordPress …"&gt;</code> ' . esc_html__( '(exposes WP version)', 'metamanager' ) . '</li>' .
					'<li><strong>' . esc_html__( 'oEmbed links', 'metamanager' ) . '</strong> — ' . esc_html__( 'Discovery and JavaScript endpoints for oEmbed (unused by most themes).', 'metamanager' ) . '</li>' .
					'<li><strong>' . esc_html__( 'Shortlink', 'metamanager' ) . '</strong> — <code>&lt;link rel="shortlink"&gt;</code> ' . esc_html__( '(legacy, not used by search engines).', 'metamanager' ) . '</li>' .
					'<li><strong>' . esc_html__( 'WLW Manifest', 'metamanager' ) . '</strong> — <code>&lt;link rel="wlwmanifest"&gt;</code> ' . esc_html__( '(Windows Live Writer, long discontinued).', 'metamanager' ) . '</li>' .
					'<li><strong>' . esc_html__( 'RSD Link', 'metamanager' ) . '</strong> — <code>&lt;link rel="EditURI"&gt;</code> ' . esc_html__( '(Really Simple Discovery, blog-era legacy).', 'metamanager' ) . '</li>' .
					'<li><strong>' . esc_html__( 'Pingback header', 'metamanager' ) . '</strong> — ' . esc_html__( 'Removes X-Pingback HTTP header and wp_really_simple_discovery from head.', 'metamanager' ) . '</li>' .
					'<li><strong>' . esc_html__( 'X-Powered-By header', 'metamanager' ) . '</strong> — ' . esc_html__( 'Removes the PHP version disclosure header.', 'metamanager' ) . '</li>' .
					'<li><strong>' . esc_html__( 'DNS prefetch', 'metamanager' ) . '</strong> — ' . esc_html__( 'Removes wp_resource_hints which adds DNS prefetch links for WordPress.com CDN etc.', 'metamanager' ) . '</li>' .
					'</ul>',
			];
		}

		if ( 'contact' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_contact_endpoints',
				'title'   => __( 'Download Endpoints', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'Three server-side download URLs are registered automatically:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><code>/gcm-biz-export/vcard/</code> — ' . esc_html__( 'vCard 3.0 (.vcf) — importable by iOS, Android, Outlook, Google Contacts.', 'metamanager' ) . '</li>' .
					'<li><code>/gcm-biz-export/json/</code> — ' . esc_html__( 'JSON file with name, phone, email, address, and coordinates.', 'metamanager' ) . '</li>' .
					'<li><code>/gcm-biz-export/csv/</code> — ' . esc_html__( 'CSV file importable by spreadsheet applications and CRMs.', 'metamanager' ) . '</li>' .
					'</ul>' .
					'<p>' . esc_html__( 'If any endpoint returns 404, go to SEO → Tools → Flush Rewrite Rules.', 'metamanager' ) . '</p>',
			];
		}

		if ( 'business' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_business_hours',
				'title'   => __( 'Business Hours', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'The business hours table uses drag-to-reorder rows (jQuery UI Sortable). Click and drag the ≡ handle on the left of any row.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Each row maps to one OpeningHoursSpecification node in JSON-LD. You can assign multiple days to one time slot (e.g. Mon–Fri 9–17) by checking more than one day checkbox per row.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Check the "Closed" checkbox for days your business is not open. Closed rows are excluded from the schema output.', 'metamanager' ) . '</p>',
			];
		}

		if ( 'social' === $page ) {
			$tabs[] = [
				'id'      => 'mm_meta_og_images',
				'title'   => __( 'OG Image Guidelines', 'metamanager' ),
				'content' =>
					'<p>' . esc_html__( 'Recommended default Open Graph image specifications:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li>' . esc_html__( 'Size: 1200 × 630 px (1.91:1 ratio)', 'metamanager' ) . '</li>' .
					'<li>' . esc_html__( 'Format: JPEG or PNG', 'metamanager' ) . '</li>' .
					'<li>' . esc_html__( 'Max file size: 8 MB (Facebook limit)', 'metamanager' ) . '</li>' .
					'<li>' . esc_html__( 'Keep important content away from the edges — some platforms crop to square.', 'metamanager' ) . '</li>' .
					'</ul>' .
					'<p>' . esc_html__( 'Per-post OG images are set in the Metamanager metabox on the post editor. The default image here is used only as a fallback when no featured image or per-post image is set.', 'metamanager' ) . '</p>',
			];
		}

		return $tabs;
	}
}
