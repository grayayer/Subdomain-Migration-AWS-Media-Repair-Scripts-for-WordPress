<?php
/**
 * Universal Archive Repair Script
 *
 * This script scans all posts, pages, custom post types, and metadata
 * for broken local media URLs and replaces them with the correct S3 URLs
 * from WP Offload Media.
 *
 * Usage:
 *   wp eval-file repair-archive-media.php --url=YOUR_SUBDOMAIN_HERE
 *
 * Example:
 *   wp eval-file repair-archive-media.php --url=2027.pdxwlf.com
 *
 * @package PDXWLF_Repair
 */

global $wpdb;

/**
 * Retrieve the current site URL from WP-CLI context.
 *
 * @var string $site_url The full site URL.
 * @var string $domain   The extracted domain host.
 */
$site_url = get_site_url();
$domain   = parse_url( $site_url, PHP_URL_HOST );

echo "Starting repair for: {$domain}\n";

/**
 * Retrieve all relevant post types for scanning.
 *
 * Includes:
 * - wp_block (Gutenberg patterns)
 * - post
 * - page
 * - pdxwlf_sponsor
 * - wlf_experience
 * - pdxwlf_event
 *
 * @var WP_Post[] $posts Array of post objects.
 */
$posts = get_posts(
	array(
		'post_type'      => array( 'wp_block', 'post', 'page', 'pdxwlf_sponsor', 'wlf_experience', 'pdxwlf_event' ),
		'posts_per_page' => -1,
		'post_status'    => 'any',
	)
);

echo 'Phase 1: Scanning ' . count( $posts ) . " content items...\n";

/**
 * Loop through each post and repair broken URLs in post_content.
 */
foreach ( $posts as $post ) {

	$content = $post->post_content;
	$updated = false;

	/**
	 * Regex pattern to match local upload URLs for the current domain.
	 *
	 * @var string $pattern The regex used to detect broken URLs.
	 */
	$pattern = '/https:\/\/' . preg_quote( $domain, '/' ) . '\/wp-content\/uploads\/[^\s"\'>]+/';

	/**
	 * Detect all matching URLs inside post content.
	 */
	if ( preg_match_all( $pattern, $content, $matches ) ) {

		foreach ( array_unique( $matches[0] ) as $local_url ) {

			/**
			 * Extract filename and remove WordPress thumbnail size suffixes.
			 *
			 * @var string $filename	The raw filename.
			 * @var string $clean_name  Filename without -300x200 style suffix.
			 */
			$filename   = basename( $local_url );
			$clean_name = preg_replace( '/-\d+x\d+(?=\.(jpg|jpeg|png|gif|webp))/i', '', $filename );

			/**
			 * Attempt to locate the attachment ID by matching filename.
			 *
			 * @var int|null $id The attachment ID or null if not found.
			 */
			$id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id
					FROM {$wpdb->postmeta}
					WHERE meta_key = '_wp_attached_file'
					  AND meta_value LIKE %s
					LIMIT 1",
					'%' . $clean_name
				)
			);

			if ( $id ) {

				/**
				 * Retrieve the correct S3 URL from WP Offload Media.
				 *
				 * @var string|false $s3_url The S3 URL or false if unavailable.
				 */
				$s3_url = wp_get_attachment_url( $id );

				if ( $s3_url && strpos( $s3_url, 'amazonaws.com' ) !== false ) {
					$content = str_replace( $local_url, $s3_url, $content );
					$updated = true;
				}
			}
		}
	}

	/**
	 * Update post content if changes were made.
	 */
	if ( $updated ) {

		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $content ),
			array( 'ID' => $post->ID )
		);

		$title = $post->post_title ? $post->post_title : 'ID ' . $post->ID;

		echo "✓ Fixed Content: {$title}\n";
	}
}

echo "\nPhase 2: Scanning PostMeta for hidden links...\n";

/**
 * Retrieve all postmeta entries containing broken URLs.
 *
 * @var array $metas Array of meta rows containing broken URLs.
 */
$metas = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT meta_id, meta_value
		FROM {$wpdb->postmeta}
		WHERE meta_value LIKE %s",
		"%{$domain}/wp-content/uploads/%"
	)
);

/**
 * Loop through metadata and repair URLs inside meta_value fields.
 */
foreach ( $metas as $meta ) {

	if ( preg_match( $pattern, $meta->meta_value, $match ) ) {

		$filename   = basename( $match[0] );
		$clean_name = preg_replace( '/-\d+x\d+(?=\.(jpg|jpeg|png|gif|webp))/i', '', $filename );

		/**
		 * Attempt to locate attachment ID.
		 *
		 * @var int|null $id The attachment ID.
		 */
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				  AND meta_value LIKE %s
				LIMIT 1",
				'%' . $clean_name
			)
		);

		if ( $id ) {

			/**
			 * Retrieve the correct S3 URL.
			 *
			 * @var string|false $s3 The S3 URL.
			 */
			$s3 = wp_get_attachment_url( $id );

			if ( $s3 ) {

				$wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $s3 ),
					array( 'meta_id' => $meta->meta_id )
				);

				echo "✓ Fixed Meta ID: {$meta->meta_id}\n";
			}
		}
	}
}

echo "Repair for {$domain} Complete!\n";
