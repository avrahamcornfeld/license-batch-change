<?php
/**
 * Mugrabi Font Upgrade Script - Final Production Release
 *
 * Usage: wp eval-file mugrabi-upgrade.php [--dry-run] [--rollback] [--order=ID] [--limit=N] [--yes]
 *
 * Upgrades previous Mugrabi font purchasers by adding new products to completed orders.
 * Uses WooCommerce CRUD API exclusively. No direct SQL. HPOS compatible.
 *
 * Production Features:
 * - Proper pagination for unlimited orders
 * - Per-order error handling with continue strategy
 * - Official WooCommerce download permission API
 * - Variation caching to prevent thousands of lookups
 * - Duplicate detection by variation ID (handles multiple licenses per order)
 * - Rollback with official API (no orphaned permissions)
 * - Comprehensive validation (existence, downloadable, files attached)
 * - Explicit permission failure detection (no orphaned products)
 * - Detailed validation diagnostics with WP_CLI output
 * - Order note format: "Product, Weight, License"
 *
 * @author Mugrabi
 * @version 4.4.0
 */

// ============================================================================
// Configuration
// ============================================================================

const SOURCE_PRODUCT_ID = 23279;
const TARGET_DISPLAY_ID = 148870;
const TARGET_TEXT_ID = 149461;
const UPGRADE_META_KEY = '_aaa_upgrade';
const UPGRADE_META_VALUE = 'mugrabi_2026';
const LICENSE_ATTR = 'pa_license';
const WEIGHT_ATTR = 'pa_weight';
const ORDERS_PER_PAGE = 100;

/**
 * Weight mapping rules - exact attribute values
 *
 * Source weight format: "NNN-category" (e.g., "300-light", "400-regular")
 * Maps to target products with specific weights
 */
const WEIGHT_RULES = [
    '300-light' => [
        'display' => '700-bold',
        'text' => null,
    ],
    '400-regular' => [
        'display' => '700-bold',
        'text' => null,
    ],
    '700-bold' => [
        'display' => null,
        'text' => '700-bold',
    ],
    '000-family' => [
        'display' => '700-bold',
        'text' => '700-bold',
    ],
    '000-familybasic' => [
        'display' => '700-bold',
        'text' => '700-bold',
    ],
];

// ============================================================================
// CLI Arguments Parser
// ============================================================================

class MugrabiUpgradeArgs {
    public bool $dry_run = false;
    public bool $rollback = false;
    public bool $yes = false;
    public ?int $order_id = null;
    public ?int $limit = null;

    public function __construct() {
        global $argv;

        foreach ($argv as $arg) {
            if ($arg === '--dry-run') {
                $this->dry_run = true;
            } elseif ($arg === '--rollback') {
                $this->rollback = true;
            } elseif ($arg === '--yes') {
                $this->yes = true;
            } elseif (strpos($arg, '--order=') === 0) {
                $this->order_id = (int) substr($arg, 8);
            } elseif (strpos($arg, '--limit=') === 0) {
                $this->limit = (int) substr($arg, 8);
            }
        }
    }
}

// ============================================================================
// Statistics Tracker
// ============================================================================

class MugrabiStats {
    public int $orders_scanned = 0;
    public int $orders_updated = 0;
    public int $items_added = 0;
    public int $permissions_created = 0;
    public int $skipped = 0;
    public int $errors = 0;
    public float $start_time = 0;

    public function __construct() {
        $this->start_time = microtime(true);
    }

    public function elapsed(): string {
        $elapsed = microtime(true) - $this->start_time;
        $mins = floor($elapsed / 60);
        $secs = $elapsed % 60;
        return sprintf('%d:%05.2f', $mins, $secs);
    }

    public function printSummary(): void {
        WP_CLI::line('');
        WP_CLI::line('=== SUMMARY ===');
        WP_CLI::line("Orders scanned:      {$this->orders_scanned}");
        WP_CLI::line("Orders updated:      {$this->orders_updated}");
        WP_CLI::line("Items added:         {$this->items_added}");
        WP_CLI::line("Permissions created: {$this->permissions_created}");
        WP_CLI::line("Skipped:             {$this->skipped}");
        WP_CLI::line("Errors:              {$this->errors}");
        WP_CLI::line("Elapsed time:        {$this->elapsed()}");
        WP_CLI::line('');
    }
}

// ============================================================================
// Variation Finder with Caching
// ============================================================================

class MugrabiVariationFinder {
    private array $target_products = [
        'display' => TARGET_DISPLAY_ID,
        'text' => TARGET_TEXT_ID,
    ];

    /**
     * Cache: [product_id][weight][license] => variation_id
     */
    private array $cache = [];

    /**
     * Find variation by product ID, weight, and license with caching
     *
     * @param int    $parent_product_id Parent variable product ID
     * @param string $weight           Exact weight value (e.g. "700-bold")
     * @param string $license          Exact license value
     *
     * @return int|null Variation ID or null if not found
     */
    public function findVariation(int $parent_product_id, string $weight, string $license): ?int {
        // Check cache first
        $cache_key = "{$parent_product_id}_{$weight}_{$license}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $product = wc_get_product($parent_product_id);

        if (!$product || !$product->is_type('variable')) {
            $this->cache[$cache_key] = null;
            return null;
        }

        $variations = $product->get_children();

        if (empty($variations)) {
            $this->cache[$cache_key] = null;
            return null;
        }

        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);

            if (!$variation || !$variation->is_type('variation')) {
                continue;
            }

            $attributes = $variation->get_attributes();

            $var_weight = $attributes[WEIGHT_ATTR] ?? '';
            $var_license = $attributes[LICENSE_ATTR] ?? '';

            if ($var_weight === $weight && $var_license === $license) {
                $this->cache[$cache_key] = (int) $variation_id;
                return (int) $variation_id;
            }
        }

        $this->cache[$cache_key] = null;
        return null;
    }

    /**
     * Validate that all required variations exist with full checks
     *
     * Verifies:
     * - Variation exists
     * - Variation is downloadable
     * - Variation has at least one downloadable file attached
     *
     * Prints detailed diagnostics for each missing/invalid variation.
     *
     * @return array Empty array if valid, array of error messages if invalid
     */
    public function validateAllVariationsExist(): array {
        $errors = [];

        // Get source product
        $source = wc_get_product(SOURCE_PRODUCT_ID);

        if (!$source || !$source->is_type('variable')) {
            $errors[] = 'Source product is not a variable product';
            return $errors;
        }

        // Collect all licenses from source product
        $source_licenses = $this->getProductLicenses(SOURCE_PRODUCT_ID);

        if (empty($source_licenses)) {
            $errors[] = 'No licenses found in source product variations';
            return $errors;
        }

        // Verify target product variations exist with full validation
        foreach ($source_licenses as $license) {
            // Display: weight 700-bold
            $display_var = $this->findVariation(TARGET_DISPLAY_ID, '700-bold', $license);
            $display_errors = $this->validateVariation($display_var, 'Display', '700-bold', $license);
            $errors = array_merge($errors, $display_errors);

            // Text: weight 700-bold
            $text_var = $this->findVariation(TARGET_TEXT_ID, '700-bold', $license);
            $text_errors = $this->validateVariation($text_var, 'Text', '700-bold', $license);
            $errors = array_merge($errors, $text_errors);
        }

        return $errors;
    }

    /**
     * Validate a single variation for all required conditions
     *
     * Checks:
     * 1. Variation exists
     * 2. Variation is downloadable
     * 3. Variation has at least one downloadable file
     *
     * Prints detailed diagnostics to WP_CLI for each failure.
     *
     * @param int|null $variation_id Variation ID or null
     * @param string   $product_type Product type for output (Display/Text)
     * @param string   $weight       Weight attribute for error messages
     * @param string   $license      License attribute for error messages
     *
     * @return array Array of error messages (empty if all checks pass)
     */
    private function validateVariation(?int $variation_id, string $product_type, string $weight, string $license): array {
        $errors = [];

        // Check 1: Variation exists
        if (!$variation_id) {
            WP_CLI::log('');
            WP_CLI::log($product_type);
            WP_CLI::log("weight: {$weight}");
            WP_CLI::log("license: {$license}");
            WP_CLI::error('ERROR: variation not found', false);
            $errors[] = "{$product_type} (weight={$weight}, license={$license}): Variation does not exist";
            return $errors; // Can't check further without valid ID
        }

        $variation = wc_get_product($variation_id);

        if (!$variation) {
            WP_CLI::log('');
            WP_CLI::log($product_type);
            WP_CLI::log("weight: {$weight}");
            WP_CLI::log("license: {$license}");
            WP_CLI::error("ERROR: product ID {$variation_id} not found", false);
            $errors[] = "{$product_type} (weight={$weight}, license={$license}) ID {$variation_id}: Product not found";
            return $errors;
        }

        // Check 2: Is downloadable
        if (!$variation->is_downloadable()) {
            WP_CLI::log('');
            WP_CLI::log($product_type);
            WP_CLI::log("weight: {$weight}");
            WP_CLI::log("license: {$license}");
            WP_CLI::error('ERROR: product is not set as downloadable', false);
            $errors[] = "{$product_type} (weight={$weight}, license={$license}) ID {$variation_id}: Product is not set as downloadable";
        }

        // Check 3: Has downloadable files
        $downloads = $variation->get_downloads();

        if (empty($downloads)) {
            WP_CLI::log('');
            WP_CLI::log($product_type);
            WP_CLI::log("weight: {$weight}");
            WP_CLI::log("license: {$license}");
            WP_CLI::error('ERROR: no downloadable files attached', false);
            $errors[] = "{$product_type} (weight={$weight}, license={$license}) ID {$variation_id}: No downloadable files attached";
        }

        return $errors;
    }

    /**
     * Get all unique licenses from a product's variations
     *
     * @param int $product_id
     *
     * @return array Array of unique license values
     */
    private function getProductLicenses(int $product_id): array {
        $product = wc_get_product($product_id);

        if (!$product || !$product->is_type('variable')) {
            return [];
        }

        $licenses = [];
        $variations = $product->get_children();

        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);

            if (!$variation) {
                continue;
            }

            $attributes = $variation->get_attributes();
            $license = $attributes[LICENSE_ATTR] ?? '';

            if ($license !== '' && !in_array($license, $licenses, true)) {
                $licenses[] = $license;
            }   
        }

        return $licenses;
    }
}

// ============================================================================
// Order Item Manager - WooCommerce CRUD API
// ============================================================================

class MugrabiOrderItemManager {
    private MugrabiVariationFinder $finder;
    private MugrabiStats $stats;

    public function __construct(MugrabiVariationFinder $finder, MugrabiStats $stats) {
        $this->finder = $finder;
        $this->stats = $stats;
    }

    /**
     * Get upgrade rules for source weight
     *
     * Matches exact source weight value (e.g., "300-light") to upgrade rules
     *
     * @param string $weight Source weight attribute value
     *
     * @return array|null Rules array or null if no rule matches
     */
    private function getUpgradeRules(string $weight): ?array {
        if (!isset(WEIGHT_RULES[$weight])) {
            return null;
        }

        return WEIGHT_RULES[$weight];
    }

    /**
     * Check if order item has upgrade meta
     *
     * @param \WC_Order_Item $item
     *
     * @return bool
     */
    private function hasUpgradeMeta(\WC_Order_Item $item): bool {
        $meta = $item->get_meta(UPGRADE_META_KEY);
        return $meta === UPGRADE_META_VALUE;
    }

    /**
     * Determine what items to add for a source product item
     *
     * Returns array keyed by variation_id to handle multiple licenses per order.
     * For orders with multiple licenses (e.g., otf-2 AND web-100k), both are added.
     *
     * Key format: "variation_{$variation_id}" ensures uniqueness per license per product type.
     *
     * @param \WC_Order_Item_Product $item Source item from order
     * @param array                   $existing_variation_ids Variation IDs already in order
     *
     * @return array Array of [key => variation_id] pairs to add
     */
    public function getItemsToAdd(\WC_Order_Item_Product $item, array $existing_variation_ids = []): array {
        $to_add = [];

        $product = $item->get_product();
        if (!$product) {
            return $to_add;
        }

// Get attribute slugs from source variation
$attributes = $product->get_attributes();

$license = $attributes[LICENSE_ATTR] ?? '';
$weight = $attributes[WEIGHT_ATTR] ?? '';

if ($weight === '' || $license === '') {
    return $to_add;
}

        // Check if this item already has upgrade meta (skip if already processed)
        if ($this->hasUpgradeMeta($item)) {
            return $to_add;
        }

        // Get rules for this weight
        $rules = $this->getUpgradeRules($weight);
        if (!$rules) {
            return $to_add;
        }

        // Find display variation - keyed by variation_id to allow multiple licenses
        if ($rules['display']) {
            $var_id = $this->finder->findVariation(
                TARGET_DISPLAY_ID,
                $rules['display'],
                $license
            );

            if ($var_id && !isset($existing_variation_ids[$var_id])) {
                $key = "display_{$license}";
                $to_add[$key] = $var_id;
            }
        }

        // Find text variation - keyed by variation_id to allow multiple licenses
        if ($rules['text']) {
            $var_id = $this->finder->findVariation(
                TARGET_TEXT_ID,
                $rules['text'],
                $license
            );

            if ($var_id && !isset($existing_variation_ids[$var_id])) {
                $key = "text_{$license}";
                $to_add[$key] = $var_id;
            }
        }

        return $to_add;
    }

    /**
     * Get variations already in order by variation_id
     *
     * Returns map: variation_id => item_id for all items in order
     *
     * @param \WC_Order $order
     *
     * @return array
     */
    public function getOrderVariationsByVariationId(\WC_Order $order): array {
        $variations = [];

        foreach ($order->get_items() as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if ($product) {
                $var_id = $product->get_id();
                $variations[$var_id] = $item_id;
            }
        }

        return $variations;
    }

    /**
     * Get items marked with upgrade meta
     *
     * @param \WC_Order $order
     *
     * @return array Array of item_id => \WC_Order_Item_Product
     */
    public function getUpgradeItems(\WC_Order $order): array {
        $items = [];

        foreach ($order->get_items() as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            if ($this->hasUpgradeMeta($item)) {
                $items[$item_id] = $item;
            }
        }

        return $items;
    }

    /**
     * Add product variation to order with zero cost
     *
     * Uses WooCommerce CRUD API to add item to order.
     * Sets subtotal and total to 0 for free upgrade items.
     *
     * @param \WC_Order $order
     * @param int       $variation_id
     *
     * @return \WC_Order_Item_Product|null Item added or null if failed
     */
    public function addItemToOrder(\WC_Order $order, int $variation_id): ?\WC_Order_Item_Product {
        try {
            $product = wc_get_product($variation_id);

            if (!$product) {
                WP_CLI::warning("Product variation {$variation_id} not found");
                $this->stats->errors++;
                return null;
            }

            if (!$product->is_type('variation')) {
                WP_CLI::warning("Product {$variation_id} is not a variation");
                $this->stats->errors++;
                return null;
            }

            // Create order item
            $item = new \WC_Order_Item_Product();
            $item->set_product($product);
            $item->set_quantity(1);

            // Set zero cost
            $item->set_subtotal(0);
            $item->set_total(0);
            $item->set_tax_class('');

            // Add upgrade meta using proper CRUD API
            $item->add_meta_data(UPGRADE_META_KEY, UPGRADE_META_VALUE, true);

            // Add item to order
            $order->add_item($item);

            $this->stats->items_added++;

            return $item;
        } catch (\Exception $e) {
            WP_CLI::warning("Failed to add item {$variation_id}: " . $e->getMessage());
            $this->stats->errors++;
            return null;
        }
    }

    /**
     * Grant download permissions using WooCommerce official API
     *
     * Uses wc_downloadable_file_permission() which creates WC_Customer_Download records
     * - same mechanism used by WooCommerce when order completes (wc_downloadable_product_permissions)
     *
     * CRITICAL: If wc_downloadable_file_permission() returns false for any file,
     * treat as error and fail. Customer would receive product without access.
     *
     * @param \WC_Order              $order
     * @param \WC_Order_Item_Product $item
     *
     * @return bool True on success, false if any permission creation fails
     */
    public function grantDownloadPermissions(\WC_Order $order, \WC_Order_Item_Product $item): bool {
        try {
            $product = $item->get_product();

            if (!$product || !$product->is_downloadable()) {
                return true; // Not downloadable, skip
            }

            $downloads = $product->get_downloads();

            if (empty($downloads)) {
                return true; // No downloads, skip
            }

            // Use WooCommerce official API: wc_downloadable_file_permission()
            // This is called by WooCommerce core in: wc_downloadable_product_permissions()
            // See: plugins/woocommerce/includes/wc-order-functions.php line 485
            foreach ($downloads as $download) {
                $permission_id = wc_downloadable_file_permission(
                    $download->get_id(),
                    $product->get_id(),
                    $order,
                    $item->get_quantity(),
                    $item
                );

                // CRITICAL: Explicit failure detection
                // If permission creation returns false, treat as error
                if ($permission_id === false) {
                    $download_name = $download->get_name();
                    WP_CLI::warning("Failed to create permission for download: {$download_name}");
                    $this->stats->errors++;
                    return false; // Fail order processing
                }

                if ($permission_id) {
                    $this->stats->permissions_created++;
                }
            }

            return true;
        } catch (\Exception $e) {
            WP_CLI::warning("Failed to grant permissions: " . $e->getMessage());
            $this->stats->errors++;
            return false;
        }
    }

    /**
     * Format product info for order note
     *
     * Format: "Product Name, Weight, License" (e.g., "Mugrabi Display, Bold, otf-2")
     * NOTE: Product name already includes variation details (weight/license)
     * We extract just the base product name.
     *
     * @param \WC_Order_Item_Product $item
     *
     * @return string
     */
    public function formatItemForNote(\WC_Order_Item_Product $item): string {
        $product = $item->get_product();

        if (!$product) {
            return 'Unknown product';
        }

        $weight = $product->get_attribute(WEIGHT_ATTR);
        $license = $product->get_attribute(LICENSE_ATTR);

        // Get parent product name
        $parent_id = $product->get_parent_id();
        if ($parent_id) {
            $parent = wc_get_product($parent_id);
            $product_name = $parent ? $parent->get_name() : $product->get_name();
        } else {
            $product_name = $product->get_name();
        }

        $weight_label = $weight === '700-bold' ? 'Bold' : $weight;
        $license_label = strtoupper($license ?? 'unknown');

        return "{$product_name}, {$weight_label}, {$license_label}";
    }
}

// ============================================================================
// Order Processor - WooCommerce CRUD API
// ============================================================================

class MugrabiOrderProcessor {
    private MugrabiVariationFinder $finder;
    private MugrabiOrderItemManager $item_manager;
    private MugrabiStats $stats;
    private bool $dry_run;

    public function __construct(
        MugrabiVariationFinder $finder,
        MugrabiOrderItemManager $item_manager,
        MugrabiStats $stats,
        bool $dry_run
    ) {
        $this->finder = $finder;
        $this->item_manager = $item_manager;
        $this->stats = $stats;
        $this->dry_run = $dry_run;
    }

    /**
     * Get source items (Mugrabi) from order
     *
     * @param \WC_Order $order
     *
     * @return array Array of \WC_Order_Item_Product
     */
    private function getSourceItems(\WC_Order $order): array {
        $items = [];

        foreach ($order->get_items() as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();
            $parent_id = $product->get_parent_id();

            // Check if product is source or child of source
            if ($product_id === SOURCE_PRODUCT_ID || $parent_id === SOURCE_PRODUCT_ID) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Process a single order for upgrades
     *
     * Per-order error handling: if anything fails, skip this order and continue with next.
     * No transactions needed - WooCommerce doesn't support true transactions at this level.
     * Error isolation: if one product fails, others in same order are still processed.
     *
     * CRITICAL: Handles multiple licenses per order correctly.
     * Example: Order with 300-light/otf-2 AND 300-light/web-100k adds BOTH upgrades.
     *
     * If ANY permission creation fails, order is marked as error and not counted as updated.
     *
     * @param \WC_Order $order
     *
     * @return bool
     */
    public function processOrder(\WC_Order $order): bool {
        $this->stats->orders_scanned++;
        $order_id = $order->get_id();

        try {
            // Get source items in this order
            $source_items = $this->getSourceItems($order);
            if (empty($source_items)) {
                $this->stats->skipped++;
                return true;
            }

            // Get existing variation IDs to avoid duplicates
            // Key: variation_id (unique per product instance)
            $existing_variation_ids = $this->item_manager->getOrderVariationsByVariationId($order);
            // Determine what to add - keyed by "type_license" to allow multiple licenses per product
            $to_add = []; // [key => variation_id]

            foreach ($source_items as $item) {
                $items_for_source = $this->item_manager->getItemsToAdd($item, $existing_variation_ids);

                foreach ($items_for_source as $key => $variation_id) {
                    // Key is already "display_license" or "text_license"
                    // This prevents duplicate additions of same license/type combo
                    if (!isset($to_add[$key])) {
                        $to_add[$key] = $variation_id;
                    }
                }
            }
            if (empty($to_add)) {
                $this->stats->skipped++;
                return true;
            }

            // Check for existing variations - variations already in order
            foreach ($to_add as $key => $var_id) {
                if (isset($existing_variation_ids[$var_id])) {
                    unset($to_add[$key]);
                }
            }

            if (empty($to_add)) {
                $this->stats->skipped++;
                return true;
            }

            // If dry run, just print and return
            if ($this->dry_run) {
                $this->printOrderSummary($order, $to_add);
                return true;
            }

            // ================================================================
            // Add items and create permissions
            // ================================================================

            $added_items = [];
            $errors_this_order = 0;

            try {
                // Add items to order
                foreach ($to_add as $key => $var_id) {
                    $item = $this->item_manager->addItemToOrder($order, $var_id);

                    if ($item) {
                        $added_items[] = $item;
                    } else {
                        $errors_this_order++;
                    }
                }

                // If no items were successfully added, abort
                if (empty($added_items)) {
                    $this->stats->errors++;
                    return false;
                }

                // Save order (persists added items)
                $order->save();

                // Grant download permissions for each added item
                // CRITICAL: If any permission fails, fail the entire order
                foreach ($added_items as $item) {
                    $success = $this->item_manager->grantDownloadPermissions($order, $item);
                    if (!$success) {
                        // Permission failed - order is now in bad state
                        // Items added but no download access
                        $product = $item->get_product();
                        $product_name = $product ? $product->get_name() : "Unknown";
                        WP_CLI::warning("Order #{$order_id}: Permission failed for {$product_name}");
                        $this->stats->errors++;
                        return false; // Fail order
                    }
                }

                // Add order note (must be done after save)
                $note_id = $this->addOrderNote($order, $added_items);

                if (!$note_id) {
                    WP_CLI::warning("Failed to add order note for order {$order_id}");
                }

                $this->stats->orders_updated++;
                $this->printOrderSummary($order, $to_add);

                return true;

            } catch (\Exception $e) {
                WP_CLI::warning("Error adding items to order {$order_id}: " . $e->getMessage());
                $this->stats->errors++;
                return false;
            }

        } catch (\Exception $e) {
            WP_CLI::warning("Unexpected error processing order {$order_id}: " . $e->getMessage());
            $this->stats->errors++;
            return false;
        }
    }

    /**
     * Add Hebrew order note with details of added items
     *
     * @param \WC_Order                $order
     * @param \WC_Order_Item_Product[] $items
     *
     * @return int|false Note ID or false on failure
     */
    private function addOrderNote(\WC_Order $order, array $items) {
        if (empty($items)) {
            return false;
        }

        $items_text = '';

        foreach ($items as $item) {
            $formatted = $this->item_manager->formatItemForNote($item);
            $items_text .= "• {$formatted}\n";
        }

        $note = "שודרג אוטומטית במסגרת הרחבת משפחת מוגרבי (יולי 2026).\n\nנוספו:\n" . rtrim($items_text);

        // Add note as non-customer note (internal only, not sent to customer)
        return $order->add_order_note($note, false, true);
    }

    /**
     * Print order processing summary
     *
     * @param \WC_Order $order
     * @param array     $to_add Array of key => variation_id
     */
    private function printOrderSummary(\WC_Order $order, array $to_add): void {
        $order_id = $order->get_id();
        $date = $order->get_date_created()->format('Y-m-d H:i:s');
        $count = count($to_add);

        WP_CLI::log("Order #{$order_id} ({$date}) → {$count} item(s) added");

        foreach ($to_add as $key => $var_id) {
            $product = wc_get_product($var_id);
            if ($product) {
                $temp_item = new \WC_Order_Item_Product();
                $temp_item->set_product($product);
                $formatted = $this->item_manager->formatItemForNote($temp_item);
                WP_CLI::log("  → {$formatted}");
            }
        }
    }

    /**
     * Check if order contains source product
     *
     * @param \WC_Order $order
     *
     * @return bool
     */
    private function orderHasSourceProduct(\WC_Order $order): bool {
        foreach ($order->get_items() as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();
            $parent_id = $product->get_parent_id();

            if ($product_id === SOURCE_PRODUCT_ID || $parent_id === SOURCE_PRODUCT_ID) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all completed orders containing source product with pagination
     *
     * Properly paginates through all orders without limiting to a fixed number.
     * Continues until no more completed orders exist.
     *
     * @param int|null $order_id Specific order to process
     * @param int|null $limit    Maximum number of orders to process
     *
     * @return array Array of \WC_Order objects
     */
    public function getOrdersToProcess(?int $order_id = null, ?int $limit = null): array {
        $all_orders = [];
        $page = 1;
        $processed = 0;

        // If specific order requested, process only that one
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_status() === 'completed' && $this->orderHasSourceProduct($order)) {
                return [$order];
            }
            return [];
        }

        // Paginate through all completed orders
        while (true) {
            $args = [
                'status' => 'completed',
                'type' => 'shop_order',
                'limit' => ORDERS_PER_PAGE,
                'page' => $page,
            ];

            $orders = wc_get_orders($args);

            if (empty($orders)) {
                break; // No more orders
            }

            foreach ($orders as $order) {
                if ($this->orderHasSourceProduct($order)) {
                    $all_orders[] = $order;
                    $processed++;

                    if ($limit && $processed >= $limit) {
                        return $all_orders;
                    }
                }
            }

            $page++;
        }

        return $all_orders;
    }
}

// ============================================================================
// Rollback Handler - WooCommerce CRUD API
// ============================================================================

class MugrabiRollbackHandler {
    private MugrabiStats $stats;

    public function __construct(MugrabiStats $stats) {
        $this->stats = $stats;
    }

    /**
     * Rollback all upgrade items from orders with pagination
     *
     * @param int|null $order_id Specific order to rollback
     * @param bool     $dry_run  Show what would be rolled back
     */
    public function rollback(?int $order_id = null, bool $dry_run = false): void {
        $page = 1;
        $total_orders_found = 0;

        // First pass: count total orders
        while (true) {
            $args = [
                'status' => 'completed',
                'type' => 'shop_order',
                'limit' => ORDERS_PER_PAGE,
                'page' => $page,
            ];

            if ($order_id) {
                $args['include'] = [$order_id];
            }

            $orders = wc_get_orders($args);

            if (empty($orders)) {
                break;
            }

            $total_orders_found += count($orders);
            $page++;
        }

        // Reset for processing
        $page = 1;
        $progress = \WP_CLI\Utils\make_progress_bar('Scanning for upgrades', $total_orders_found);

        // Second pass: process orders
        while (true) {
            $args = [
                'status' => 'completed',
                'type' => 'shop_order',
                'limit' => ORDERS_PER_PAGE,
                'page' => $page,
            ];

            if ($order_id) {
                $args['include'] = [$order_id];
            }

            $orders = wc_get_orders($args);

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                $this->stats->orders_scanned++;
                $this->rollbackOrder($order, $dry_run);
                $progress->tick();
            }

            $page++;
        }

        $progress->finish();
        $this->stats->printSummary();
    }

    /**
     * Rollback a single order
     *
     * Removes only items with _aaa_upgrade = mugrabi_2026 meta.
     * WooCommerce's remove_item() does NOT remove download permissions.
     * We must manually query and remove them using official API.
     *
     * CRITICAL: Correctly handles multiple upgrade items in same order.
     * Removes each individually to prevent license loss.
     *
     * @param \WC_Order $order
     * @param bool      $dry_run
     */
    private function rollbackOrder(\WC_Order $order, bool $dry_run): void {
        $order_id = $order->get_id();
        $items_to_remove = [];

        // Find items with upgrade meta
        foreach ($order->get_items() as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $meta_value = $item->get_meta(UPGRADE_META_KEY);

            if ($meta_value === UPGRADE_META_VALUE) {
                $items_to_remove[] = [
                    'item_id' => $item_id,
                    'item' => $item,
                ];
            }
        }

        if (empty($items_to_remove)) {
            $this->stats->skipped++;
            return;
        }

        if ($dry_run) {
            WP_CLI::log("Order #{$order_id}: Would remove " . count($items_to_remove) . ' items');
            return;
        }

        try {
            // Remove download permissions before removing items
            // remove_item() does NOT remove permissions automatically
            // See: WooCommerce order class - remove_item() only marks item as deleted
            foreach ($items_to_remove as $item_data) {
                $this->removeDownloadPermissionsForItem($order, $item_data['item']);
            }

            // Remove items
            foreach ($items_to_remove as $item_data) {
                $order->remove_item($item_data['item_id']);
            }

            // Save order
            $order->save();

            $this->stats->orders_updated++;
            $removed_count = count($items_to_remove);
            $this->stats->permissions_created += $removed_count; // Count as cleanup
            WP_CLI::log("Order #{$order_id}: Removed {$removed_count} items");

        } catch (\Exception $e) {
            WP_CLI::warning("Failed to rollback order {$order_id}: " . $e->getMessage());
            $this->stats->errors++;
        }
    }

    /**
     * Remove download permissions for a specific order item
     *
     * Uses WooCommerce's official customer-download data store API.
     * This is the reverse of wc_downloadable_file_permission().
     *
     * @param \WC_Order              $order
     * @param \WC_Order_Item_Product $item
     */
    private function removeDownloadPermissionsForItem(\WC_Order $order, \WC_Order_Item_Product $item): void {
        try {
            $product = $item->get_product();

            if (!$product || !$product->is_downloadable()) {
                return; // No permissions to remove
            }

            // Get customer download data store
            $data_store = \WC_Data_Store::load('customer-download');

            // Query for permissions matching this order and product
            $downloads = $data_store->get_downloads([
                'order_id' => $order->get_id(),
                'product_id' => $product->get_id(),
            ]);

            // Remove each permission using official API
            if (!empty($downloads)) {
                foreach ($downloads as $download) {
                    $data_store->delete_by_id($download->get_id());
                }
            }
        } catch (\Exception $e) {
            WP_CLI::warning("Failed to remove permissions: " . $e->getMessage());
        }
    }
}

// ============================================================================
// Main Script
// ============================================================================

function mugrabi_upgrade_main() {
    if (!class_exists('WC_Order')) {
        WP_CLI::error('WooCommerce is not active');
        return;
    }

    $args = new MugrabiUpgradeArgs();
    $args->order_id = null;
$args->dry_run = false;
$args->yes = true;
$args->limit = null;
    $stats = new MugrabiStats();
    $finder = new MugrabiVariationFinder();

    // ========================================================================
    // ROLLBACK MODE
    // ========================================================================

    if ($args->rollback) {
        WP_CLI::line('=== MUGRABI ROLLBACK ===');
        WP_CLI::line('');
        WP_CLI::line('This will remove only items with _aaa_upgrade = mugrabi_2026');
        WP_CLI::line('');

        if ($args->dry_run) {
            WP_CLI::line('[DRY RUN] No changes will be made.');
            WP_CLI::line('');
        }

        if (!$args->yes) {
            WP_CLI::confirm('Continue with rollback?');
        }

        WP_CLI::line('');

        $rollback = new MugrabiRollbackHandler($stats);
        $rollback->rollback($args->order_id, $args->dry_run);

        return;
    }

    // ========================================================================
    // UPGRADE MODE
    // ========================================================================

    WP_CLI::line('=== MUGRABI UPGRADE ===');
    WP_CLI::line('');

    // Validate target variations exist with comprehensive checks BEFORE making any changes
    WP_CLI::log('Validating target variations...');
    WP_CLI::line('  - Checking existence');
    WP_CLI::line('  - Checking downloadable status');
    WP_CLI::line('  - Checking attached files');
    WP_CLI::line('');

    $validation_errors = $finder->validateAllVariationsExist();

    if (!empty($validation_errors)) {
        WP_CLI::line('');
        WP_CLI::error('Validation failed. Cannot proceed.');
        WP_CLI::line('');
        WP_CLI::error('Aborting before any changes');
        return;
    }

    WP_CLI::success('✓ All target variations validated (exist, downloadable, have files)');
    WP_CLI::line('');

    if ($args->dry_run) {
        WP_CLI::line('[DRY RUN] No changes will be made.');
        WP_CLI::line('');
    }

    if (!$args->yes) {
        WP_CLI::confirm('Continue with upgrade?');
    }

    WP_CLI::line('');

    // Get orders to process (with proper pagination)
    $processor = new MugrabiOrderProcessor($finder, new MugrabiOrderItemManager($finder, $stats), $stats, $args->dry_run);
    $orders = $processor->getOrdersToProcess($args->order_id, $args->limit);

    if (empty($orders)) {
        WP_CLI::warning('No completed orders found with Mugrabi products');
        return;
    }

    WP_CLI::line('Processing ' . count($orders) . ' orders...');
    WP_CLI::line('');

    // Process with progress bar
    $progress = \WP_CLI\Utils\make_progress_bar('Upgrading', count($orders));

    foreach ($orders as $order) {
        $processor->processOrder($order);
        $progress->tick();
    }

    $progress->finish();

    $stats->printSummary();
}

// Execute
mugrabi_upgrade_main();
