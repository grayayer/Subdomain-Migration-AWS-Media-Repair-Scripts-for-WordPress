# WordPress Subdomain Migration AWS Media Repair Scripts

This toolkit was designed to fix broken media paths during WordPress subdomain migrations for the Portland Winter Light festival, specifically when using [WP Offload Media](<https://wordpress.org/plugins/amazon-s3-and-cloudfront/>) with [Object Versioning](https://deliciousbrains.com/wp-offload-media/doc/object-versioning-instead-of-cache-invalidation/) enabled. Feel free to adapt for your own purposes.

## **Overview**

When migrating a site to a subdomain (e.g., pdxwlf.com → 2026.pdxwlf.com), standard search-and-replace tools often break the connection to AWS S3. This happens because the database loses the "handshake" with S3 metadata, and standard WordPress functions fail to map new URLs to old Media IDs.

These scripts bypass URL logic and use **Filename-to-ID mapping** to restore the correct S3 paths.

## **How it Works**

1. **Regex Identification:** The scripts scan your content for any URL matching the new subdomain's upload path.
2. **Filename Extraction:** It pulls the filename (e.g., logo.png) and strips any thumbnail dimensions (e.g., \-825x250).
3. **Database Lookup:** It queries the wp\_postmeta table to find the unique **Attachment ID** that owns that filename.
4. **S3 Relinking:** It uses wp\_get\_attachment\_url(ID), which triggers WP Offload Media to return the full, versioned S3 path (including those random numbers like /15152023/).
5. **Injection:** It overwrites the broken local URL with the working S3 URL.

## **How to Use**

1. Upload the desired script to your WordPress root directory.
2. Run the script via **WP-CLI** to ensure it has the correct site context:
   Bash
   wp eval-file \[script-name\].php \--url=2026.pdxwlf.com

3. **Post-Process:**
   * Go to **Kadence \> Settings** and click **Regenerate Local Fonts/CSS**.
   * Go to **LiteSpeed Cache** and click **Purge All**.

## **How to Know it's Successful**

* **CLI Output:** You will see ✓ Fixed: filename.jpg (ID: 12345\) for successful repairs.
* **Backend Editor:** Open a page that was previouslyhaving problems; images that were broken icons should now render perfectly.
* **Page Source:** Inspect an image on the frontend. The src should point to <https://pdxwlf.s3.us-west-2.amazonaws.com/>... and include a versioning subfolder (if you have that enabled).
