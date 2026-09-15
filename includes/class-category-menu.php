<?php
/**
 * Category Mega Menu Shortcode Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Category_Menu {
    
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_shortcode('brandflow_mega_menu', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Clear transient cache when product categories are modified
        add_action('created_product_cat', array($this, 'clear_menu_cache'));
        add_action('edited_product_cat', array($this, 'clear_menu_cache'));
        add_action('delete_product_cat', array($this, 'clear_menu_cache'));
    }

    public function clear_menu_cache() {
        delete_transient('bf_mega_menu_standard');
        delete_transient('bf_mega_menu_accordion');
        delete_transient('brandflow_mega_menu_html'); // clean up old cache
    }

    public function enqueue_assets() {
        wp_register_style('brandflow-mega-menu', BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/css/category-menu.css', array(), BYTEMASH_WOO_SYNC_VERSION);
        wp_register_script('brandflow-mega-menu', BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/js/category-menu.js', array('jquery'), BYTEMASH_WOO_SYNC_VERSION, true);
    }

    private function group_terms_by_name($terms, $sort = true) {
        $grouped = array();
        foreach ($terms as $term) {
            if (!isset($grouped[$term->name])) {
                $grouped[$term->name] = array(
                    'ids' => array(),
                    'terms' => array(),
                    'name' => $term->name
                );
            }
            $grouped[$term->name]['ids'][] = $term->term_id;
            $grouped[$term->name]['terms'][] = $term;
        }
        if ($sort) {
            ksort($grouped);
        }
        return $grouped;
    }

    /**
     * Build the mega menu's top-level list strictly from the saved order -
     * a category NOT in $saved_order_ids is NOT shown, full stop. This is
     * what makes the menu persist across syncs: a sync can create new
     * WooCommerce categories, but it never touches
     * bytemash_mega_menu_top_order, so a brand new category simply won't
     * appear here until an admin explicitly adds it via the Settings
     * arranger. (Previously this appended any category missing from the
     * saved order instead of excluding it, which is why new categories from
     * every sync kept showing up in the menu unasked.)
     *
     * @param WP_Term[] $terms           Available top-level terms
     * @param int[]     $saved_order_ids The admin-configured, ordered list
     * @return WP_Term[]
     */
    private function sort_terms_by_saved_order($terms, $saved_order_ids) {
        $terms = is_array($terms) ? $terms : array();
        $saved_order_ids = is_array($saved_order_ids) ? array_values(array_filter(array_map('intval', $saved_order_ids))) : array();

        if (empty($terms) || empty($saved_order_ids)) {
            return array();
        }

        $term_by_id = array();
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $term_by_id[(int) $term->term_id] = $term;
            }
        }

        $ordered = array();
        foreach ($saved_order_ids as $term_id) {
            if (isset($term_by_id[$term_id])) {
                $ordered[] = $term_by_id[$term_id];
            }
        }

        return $ordered;
    }

    /**
     * One-time migration: if the mega menu order has never been saved
     * before (the option doesn't exist at all), seed it with every
     * top-level category that exists right now, so switching to a strict
     * inclusion list doesn't blank out an existing site's menu on upgrade.
     * After this runs once, the option is the sole source of truth and is
     * only ever changed by an admin via Settings.
     *
     * @param WP_Term[] $top_level_terms
     * @return int[] The order to use (freshly seeded, or the existing saved one)
     */
    private function get_or_seed_top_order($top_level_terms) {
        $sentinel = '__bytemash_not_set__';
        $saved = get_option('bytemash_mega_menu_top_order', $sentinel);

        if ($saved !== $sentinel) {
            return is_array($saved) ? $saved : array();
        }

        $seeded = array();
        foreach ($top_level_terms as $term) {
            if ($term instanceof WP_Term) {
                $seeded[] = (int) $term->term_id;
            }
        }

        update_option('bytemash_mega_menu_top_order', $seeded, false);

        return $seeded;
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'layout' => 'standard' // Defaults to standard, can be 'accordion' for sidebars
        ), $atts, 'brandflow_mega_menu');

        wp_enqueue_style('brandflow-mega-menu');
        wp_enqueue_script('brandflow-mega-menu');

        // Use a distinct cache key for each layout mode
        $cache_key = 'bf_mega_menu_' . sanitize_key($atts['layout']);
        $cached_menu = get_transient($cache_key);
        if (false !== $cached_menu) {
            return $cached_menu;
        }

        // Fetch ALL categories in a single query to prevent N+1 query performance issues
        $all_categories = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC'
        ));

        if (is_wp_error($all_categories) || empty($all_categories)) {
            return '<p>No categories found.</p>';
        }

        // Build a hierarchy array in memory
        $terms_by_parent = array();
        foreach ($all_categories as $term) {
            if ($term->slug === 'uncategorized') {
                continue;
            }
            $terms_by_parent[$term->parent][] = $term; // index by parent_id
        }

        if (empty($terms_by_parent[0])) {
            return '<p>No top-level categories found.</p>';
        }

        // The menu shows ONLY categories an admin has explicitly added via
        // the Settings arranger (bytemash_mega_menu_top_order) - a category
        // created or updated by a sync never appears here on its own. See
        // get_or_seed_top_order()/sort_terms_by_saved_order() for why.
        $saved_top_order = $this->get_or_seed_top_order($terms_by_parent[0]);
        $top_level_terms_ordered = $this->sort_terms_by_saved_order($terms_by_parent[0], $saved_top_order);

        if (empty($top_level_terms_ordered)) {
            return '<p>No top-level categories found.</p>';
        }
        $top_level_grouped = $this->group_terms_by_name($top_level_terms_ordered, false);
        $mode_class = $atts['layout'] === 'accordion' ? 'bf-accordion-mode' : '';

        ob_start();
        ?>
        <div class="brandflow-mega-menu-container <?php echo esc_attr($mode_class); ?>">
            <?php if ($atts['layout'] !== 'accordion'): ?>
            <div class="bf-mobile-toggle">
                <div class="bf-hamburger"><span></span><span></span><span></span></div>
                <div class="bf-mobile-text">Categories</div>
            </div>
            <?php endif; ?>
            <nav class="brandflow-mega-menu">
                <ul class="bf-menu-level-1">
                    <?php foreach ($top_level_grouped as $category_name => $top_group) : 
                        $children = array();
                        foreach ($top_group['ids'] as $id) {
                            if (isset($terms_by_parent[$id])) {
                                $children = array_merge($children, $terms_by_parent[$id]);
                            }
                        }
                        $children_grouped = $this->group_terms_by_name($children);
                        $has_children = !empty($children_grouped);
                        
                        $top_link = count($top_group['ids']) > 1 ? site_url('/shop/?bf_cat_group=' . $top_group['ids'][0]) : get_term_link($top_group['terms'][0]);
                    ?>
                        <li class="bf-menu-item <?php echo $has_children ? 'bf-has-dropdown' : ''; ?>">
                            <div class="bf-menu-item-header">
                                <a href="<?php echo esc_url($top_link); ?>">
                                    <?php echo esc_html($category_name); ?>
                                </a>
                                <?php if ($has_children): ?>
                                    <span class="bf-toggle-btn">
                                        <svg viewBox="0 0 24 24" class="bf-dropdown-icon" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($has_children): ?>
                                <div class="bf-mega-dropdown">
                                    <div class="bf-mega-dropdown-inner">
                                        <?php foreach ($children_grouped as $child_name => $child_group) : 
                                            $child_link = count($child_group['ids']) > 1 ? site_url('/shop/?bf_cat_group=' . $child_group['ids'][0]) : get_term_link($child_group['terms'][0]);
                                        ?>
                                            <div class="bf-mega-column">
                                                <h4 class="bf-column-title">
                                                    <a href="<?php echo esc_url($child_link); ?>"><?php echo esc_html($child_name); ?></a>
                                                </h4>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </div>
        <?php
        $html = ob_get_clean();
        
        // Cache the layout-specific HTML for 24 hours
        set_transient($cache_key, $html, DAY_IN_SECONDS);
        
        return $html;
    }
}
