<?php
/**
 * Script to repair broken media URLs on a specific post by matching filenames
 * to attachment IDs and replacing local URLs with the correct S3 Offload URLs.
 *
 * This script:
 * - Scans post content for hardcoded 2026-domain upload URLs.
 * - Extracts filenames and attempts to locate matching attachment IDs.
 * - Retrieves the correct S3 URL via WP Offload Media.
 * - Replaces incorrect URLs and updates the post.
 *
 * run in terminal with: "$: wp eval-file repair-single-page.php --url=2026.pdxwlf.com"
 *
 * @package CustomMediaRepair
 */

$target_post_id = 11969;

/**
 * Retrieve the post object for the targeted post ID.
 *
 * @var WP_Post|false $post The post object or false if not found.
 */
$post = get_post( $target_post_id );

if ( ! $post ) {
	die( "Post ID {$target_post_id} not found.\n" );
}

/**
 * Store original and working content for comparison.
 *
 * @var string $content		  The working post content.
 * @var string $original_content The original post content before modifications.
 */
$content		  = $post->post_content;
$original_content = $content;

echo 'Processing page: ' . esc_html( $post->post_title ) . "\n";

/**
 * Regex pattern to match any uploads URL on the 2026 domain.
 *
 * @var string $pattern The regex used to detect broken URLs.
 */
$pattern = '/https:\/\/2026\.pdxwlf\.com\/wp-content\/uploads\/[^\s"\'>]+/';

/**
 * Search for all matching URLs in the content.
 */
if ( preg_match_all( $pattern, $content, $matches ) ) {

	/**
	 * Loop through each unique matched URL.
	 *
	 * @var array $matches Array of regex matches.
	 */
	foreach ( array_unique( $matches[0] ) as $local_url ) {

		/**
		 * Extract the filename from the matched URL.
		 *
		 * @var string $filename The basename of the file.
		 */
		$filename = basename( $local_url );

		// Access the global database object.
		global $wpdb;

		/**
		 * Attempt to locate the attachment ID by matching the filename
		 * against the `_wp_attached_file` meta value.
		 *
		 * @var int|null $attachment_id The found attachment ID or null.
		 */
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				  AND meta_value LIKE %s
				LIMIT 1",
				'%' . $wpdb->esc_like( $filename )
			)
		);

		if ( $attachment_id ) {

			/**
			 * Retrieve the correct S3 URL from WP Offload Media.
			 *
			 * @var string|false $s3_url The S3 URL or false if unavailable.
			 */
			$s3_url = wp_get_attachment_url( $attachment_id );

			if ( $s3_url && strpos( $s3_url, 'amazonaws.com' ) !== false ) {

				// Replace the incorrect URL with the correct S3 URL.
				$content = str_replace( $local_url, $s3_url, $content );

				echo "✓ Fixed: {$filename} (ID: {$attachment_id})\n";

			} else {

				echo "Found ID {$attachment_id} for {$filename}, but no S3 URL returned.\n";

			}
		} else {

			echo "✗ Still could not find ID for filename: {$filename}\n";

		}
	}
}

/**
 * If changes were made, update the post content.
 */
if ( $content !== $original_content ) {

	wp_update_post(
		array(
			'ID'		   => $target_post_id,
			'post_content' => $content,
		)
	);

	echo "DONE: Page updated. Refresh your editor.\n";

} else {

	echo "No changes were made.\n";

}
