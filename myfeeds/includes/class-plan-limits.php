<?php
/**
 * MyFeeds Plan Limits
 * Central helper class for Free/Pro/Trial feature gating.
 * 
 * Free:  1 feed, 3 active products in blog posts, no auto-sync
 * Pro/Trial: Unlimited feeds, unlimited products, auto-sync enabled
 * 
 * Downgrade (Pro → Free): Nothing is deleted. Existing feeds, products and
 * embedded blocks stay. But: no new feed addable, no new product selectable
 * beyond the limit. Auto-sync stops. Frontend keeps showing all already
 * embedded products.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyFeeds_Plan_Limits {

    /**
     * Check if user has Pro access (paying or trial)
     */
    public static function is_pro() {
        if (!function_exists('my_pp')) return false;
        return my_pp()->is_paying() || my_pp()->is_trial();
    }

    /**
     * Check if user is on Free plan
     */
    public static function is_free() {
        return !self::is_pro();
    }

    /**
     * Check if user has Premium access (highest tier).
     * Premium includes everything Pro has, plus Design Editor and Custom Fonts.
     */
    public static function is_premium() {
        if (!function_exists('my_pp')) return false;
        
        $fs = my_pp();
        
        // Check if on the Premium plan (slug: 'premium')
        if (method_exists($fs, 'is_plan') && $fs->is_plan('premium')) {
            return true;
        }
        
        // Premium trial
        if ($fs->is_trial() && method_exists($fs, 'is_plan') && $fs->is_plan('premium', true)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if Design Editor features are available (Premium only)
     */
    public static function design_editor_allowed() {
        return self::is_premium();
    }

    /**
     * Get max allowed ACTIVE feeds
     */
    public static function max_feeds() {
        if (self::is_premium()) return PHP_INT_MAX;
        if (self::is_pro()) return 5;
        return 1;
    }

    /**
     * Get max allowed active products in blog posts
     */
    public static function max_active_products() {
        return self::is_pro() ? PHP_INT_MAX : 3;
    }

    /**
     * Check if auto-sync is allowed
     */
    public static function auto_sync_allowed() {
        return self::is_pro();
    }

    /**
     * Check if user has more active feeds than their plan allows.
     * Returns true if user needs to deactivate some feeds.
     */
    public static function feeds_over_limit() {
        $feeds = get_option('myfeeds_feeds', array());
        $active_count = 0;
        foreach ($feeds as $feed) {
            if (!isset($feed['plan_active']) || $feed['plan_active'] === true) {
                $active_count++;
            }
        }
        return $active_count > self::max_feeds();
    }

    /**
     * Get number of currently active feeds
     */
    public static function get_active_feed_count() {
        $feeds = get_option('myfeeds_feeds', array());
        $count = 0;
        foreach ($feeds as $feed) {
            if (!isset($feed['plan_active']) || $feed['plan_active'] === true) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Check if a specific feed is active (allowed to import)
     */
    public static function is_feed_active($feed) {
        return !isset($feed['plan_active']) || $feed['plan_active'] === true;
    }

    /**
     * Auto-reactivate feeds when plan allows more than currently active.
     * Called on every admin page load to handle plan upgrades.
     * 
     * Cases:
     * 1. Upgraded to Premium (unlimited): reactivate ALL feeds
     * 2. Upgraded to Pro (5 feeds) with <=5 total feeds: reactivate ALL feeds
     * 3. Upgraded to Pro (5 feeds) with >5 total feeds: do NOT auto-reactivate — modal will handle it
     * 4. Still over limit: do nothing — modal will handle it
     */
    public static function maybe_reactivate_feeds_on_upgrade() {
        $feeds = get_option('myfeeds_feeds', array());
        if (empty($feeds)) return;
        
        $max_allowed = self::max_feeds();
        $total_feeds = count($feeds);
        
        // Count currently inactive feeds
        $inactive_count = 0;
        foreach ($feeds as $feed) {
            if (isset($feed['plan_active']) && $feed['plan_active'] === false) {
                $inactive_count++;
            }
        }
        
        // No inactive feeds — nothing to reactivate
        if ($inactive_count === 0) return;
        
        // Case 1 & 2: Plan allows all feeds — reactivate everything
        if ($total_feeds <= $max_allowed) {
            $reactivated = 0;
            foreach ($feeds as $key => &$feed) {
                if (isset($feed['plan_active']) && $feed['plan_active'] === false) {
                    $feed['plan_active'] = true;
                    $reactivated++;
                }
            }
            unset($feed);
            
            if ($reactivated > 0) {
                update_option('myfeeds_feeds', $feeds);
                myfeeds_log("Plan upgrade detected: Reactivated {$reactivated} feeds (plan allows {$max_allowed}, total {$total_feeds})", 'info');
            }
            return;
        }
        
        // Case 3: Plan allows some but not all feeds — don't auto-reactivate.
        // feeds_over_limit() will return true, and the modal will appear.
        // No action needed here.
    }

    /**
     * Check if user needs to select which feeds to keep active.
     * Returns true ONLY if:
     * - Total feeds exceed the plan limit AND
     * - The number of currently active feeds is NOT within the allowed limit
     * 
     * Once the user has selected the correct number of feeds,
     * this returns false and the modal won't reappear.
     */
    public static function needs_feed_selection() {
        $feeds = get_option('myfeeds_feeds', array());
        if (empty($feeds)) return false;
        
        $max_allowed = self::max_feeds();
        $total_feeds = count($feeds);
        
        // If plan allows all feeds, no selection needed
        if ($total_feeds <= $max_allowed) return false;
        
        // Total exceeds limit — but has the user already chosen?
        // If active feed count is within the limit, selection is done.
        $active_count = self::get_active_feed_count();
        if ($active_count <= $max_allowed && $active_count > 0) return false;
        
        // Active count is 0 (all deactivated, needs selection) 
        // or exceeds limit (downgrade, needs selection)
        return true;
    }

    /**
     * Check if user can add another feed.
     * New feeds count as active, so check against the active limit.
     */
    public static function can_add_feed() {
        return self::get_active_feed_count() < self::max_feeds();
    }

    /**
     * Check if user can select another product in the Product Picker
     * $current_count = number of products currently selected across ALL posts
     */
    public static function can_select_product($current_count = 0) {
        if (self::is_pro()) return true;
        return $current_count < self::max_active_products();
    }

    /**
     * Get current active product count (products used in blog posts)
     * Uses the Discovery mechanism that already scans posts
     */
    public static function get_active_product_count() {
        $active_ids = get_option('myfeeds_active_product_ids', array());
        return count($active_ids);
    }

    /**
     * Get upgrade URL from Freemius
     */
    public static function get_upgrade_url() {
        if (!function_exists('my_pp')) return '#';
        return my_pp()->get_upgrade_url();
    }

    /**
     * Get human-readable plan name
     */
    public static function get_plan_label() {
        if (self::is_premium()) {
            return 'Premium';
        }
        if (self::is_pro()) {
            if (function_exists('my_pp') && my_pp()->is_trial()) {
                return 'Trial';
            }
            return 'Pro';
        }
        return 'Free';
    }

    /**
     * Get upgrade URL specifically for Premium plan
     */
    public static function get_premium_upgrade_url() {
        if (!function_exists('my_pp')) return '#';
        return my_pp()->get_upgrade_url();
    }
}
