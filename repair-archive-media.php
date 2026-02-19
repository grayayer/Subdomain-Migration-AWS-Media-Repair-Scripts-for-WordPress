<?php
/**
 * UNIVERSAL ARCHIVE REPAIR SCRIPT
 * Usage: wp eval-file repair-archive-media.php --url=YOUR_SUBDOMAIN_HERE
 * usage example: wp eval-file repair-archive-media.php --url=2027.pdxwlf.com
 */

// 1. Get the URL from the WP-CLI context
$site_url = get_site_url();
$domain = parse_url($site_url, PHP_URL_HOST);

echo "Starting repair for: $domain\n";

global $wpdb;

// 2. Explicitly target all relevant post types including Gutenberg Patterns
$posts = get_posts([
	'post_type' => ['wp_block', 'post', 'page', 'pdxwlf_sponsor', 'wlf_experience', 'pdxwlf_event'],
	'posts_per_page' => -1,
	'post_status' => 'any'
]);

echo "Phase 1: Scanning " . count($posts) . " content items...\n";

foreach ($posts as $post) {
	$content = $post->post_content;
	$updated = false;

	// Pattern to find local upload URLs for the CURRENT domain
	$pattern = '/https:\/\/' . preg_quote($domain) . '\/wp-content\/uploads\/[^\s"\'>]+/';

	if (preg_match_all($pattern, $content, $matches)) {
		foreach (array_unique($matches[0]) as $local_url) {
			$filename = basename($local_url);
			$clean_name = preg_replace('/-\d+x\d+(?=\.(jpg|jpeg|png|gif|webp))/i', '', $filename);

			$id = $wpdb->get_var($wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
				'%' . $clean_name
			));

			if ($id) {
				$s3_url = wp_get_attachment_url($id);
				if ($s3_url && strpos($s3_url, 'amazonaws.com') !== false) {
					$content = str_replace($local_url, $s3_url, $content);
					$updated = true;
				}
			}
		}
	}

	if ($updated) {
		$wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post->ID]);
		echo "✓ Fixed Content: " . ($post->post_title ?: "ID " . $post->ID) . "\n";
	}
}

// 3. Phase 2: Deep scan of Metadata (ACF, Kadence backgrounds, etc)
echo "\nPhase 2: Scanning PostMeta for hidden links...\n";
$metas = $wpdb->get_results("SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE meta_value LIKE '%$domain/wp-content/uploads/%'");

foreach ($metas as $meta) {
	if (preg_match($pattern, $meta->meta_value, $match)) {
		$filename = basename($match[0]);
		$clean_name = preg_replace('/-\d+x\d+(?=\.(jpg|jpeg|png|gif|webp))/i', '', $filename);
		$id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1", '%' . $clean_name));

		if ($id) {
			$s3 = wp_get_attachment_url($id);
			if ($s3) {
				$wpdb->update($wpdb->postmeta, ['meta_value' => $s3], ['meta_id' => $meta->meta_id]);
				echo "✓ Fixed Meta ID: " . $meta->meta_id . "\n";
			}
		}
	}
}

echo "Repair for $domain Complete!\n";