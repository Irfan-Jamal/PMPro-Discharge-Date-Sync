<?php
/**
 * Plugin Name: PMPro Discharge Date Sync
 * Description: Adds a discharge date field for a specific PMPro membership level and syncs it to membership expiration. The date is set once and cannot be changed by users.
 * Version: 1.1.0
 * Author: Muhammad Jamal
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * License: GPL v2 or later
 *
 * @package PMPro_Discharge_Date_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PMPro Discharge Date Sync
 *
 * OVERVIEW:
 * This plugin manages a "discharge date" for military/veteran membership levels.
 * The discharge date becomes the membership expiration date and cannot be changed
 * by the user after initial checkout (set-once behavior).
 *
 * FEATURES:
 * - Checkout field for target level
 * - Date validation (no past dates, max 5 years future)
 * - Set-once enforcement (users cannot modify after saving)
 * - Admin can edit via user profile
 * - Automatic sync to PMPro membership expiration
 * - Account page display with optional one-time form
 *
 * WORKFLOW:
 * 1. User checks out for target level → sees discharge date field
 * 2. User enters date (or sees existing saved date if set)
 * 3. Date validates on server-side
 * 4. Date saves to user meta
 * 5. Membership expiration syncs to discharge date
 * 6. User cannot change date (admin can via profile)
 *
 * TECHNICAL ARCHITECTURE:
 * - Uses pmpro_calculate_enddate filter to override expiration calculation
 * - Backstop hook (pmpro_after_change_membership_level) catches edge cases
 * - Direct database updates for guaranteed sync
 * - Timezone-aware date handling using WordPress timezone
 *
 * IMPORTANT NOTES:
 * - Target level should be NON-RENEWABLE (no recurring billing)
 * - If you enable recurring billing, renewals may bypass the discharge date
 * - For recurring levels, additional hooks are needed (see EXTENDING section)
 *
 * @since 1.0.0
 */
final class PMPro_Discharge_Date_Sync {

	/**
	 * ========================================================================
	 * CONFIGURATION
	 * ========================================================================
	 */

	/**
	 * Target membership level ID.
	 *
	 * IMPORTANT: Change this to match your actual level ID.
	 * You can find this in Memberships > Membership Levels in WordPress admin.
	 *
	 * @var int
	 */
	private static int $TARGET_LEVEL_ID = 2;

	/**
	 * User meta key for storing discharge date.
	 *
	 * Stores the date in YYYY-MM-DD format.
	 * The underscore prefix makes this meta key "private" (hidden from REST API).
	 *
	 * @var string
	 */
	private static string $META_KEY = '_pmpro_discharge_date';

	/**
	 * Maximum years into the future for discharge date.
	 *
	 * @var int
	 */
	private static int $MAX_FUTURE_YEARS = 5;

	/**
	 * ========================================================================
	 * INITIALIZATION
	 * ========================================================================
	 */

	/**
	 * Initialize the plugin by hooking into WordPress and PMPro.
	 *
	 * This runs once when the plugin loads. All hooks are registered here.
	 *
	 * HOOK EXECUTION ORDER (during checkout):
	 * 1. pmpro_checkout_after_billing_fields → render field
	 * 2. pmpro_registration_checks → validate input
	 * 3. pmpro_calculate_enddate → set correct expiration (during level assignment)
	 * 4. pmpro_after_checkout → save discharge date to user meta
	 * 5. pmpro_after_change_membership_level → backstop to ensure enddate is correct
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		// Checkout field rendering.
		add_action( 'pmpro_checkout_after_billing_fields', array( __CLASS__, 'render_checkout_field' ) );

		// Checkout server-side validation.
		add_filter( 'pmpro_registration_checks', array( __CLASS__, 'validate_checkout' ) );

		// Save discharge date after successful checkout.
		add_action( 'pmpro_after_checkout', array( __CLASS__, 'save_after_checkout' ), 10, 2 );

		// Primary expiration override - runs during pmpro_changeMembershipLevel().
		// Priority 999 ensures this runs AFTER other plugins that might filter enddate.
		add_filter( 'pmpro_calculate_enddate', array( __CLASS__, 'filter_calculated_enddate' ), 999, 4 );

		// Backstop hook to catch any edge cases where pmpro_calculate_enddate didn't fire.
		// This ensures the enddate is always correct after a level change.
		add_action( 'pmpro_after_change_membership_level', array( __CLASS__, 'enforce_enddate_after_level_change' ), 10, 2 );

		// Account page display + optional one-time form (if date not yet set).
		add_action( 'pmpro_account_bullets_bottom', array( __CLASS__, 'render_account_bullet' ) );

		// Handle form submission from account page.
		// Priority 5 runs earlier than default to avoid conflicts with other redirects.
		add_action( 'template_redirect', array( __CLASS__, 'handle_account_form_post' ), 5 );

		// Admin profile UI (for admins to view/edit discharge date).
		add_action( 'show_user_profile', array( __CLASS__, 'render_admin_profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_admin_profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_admin_profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_profile_field' ) );
	}

	/**
	 * ========================================================================
	 * CONFIGURATION HELPERS (Filterable)
	 * ========================================================================
	 */

	/**
	 * Get the target level ID.
	 *
	 * Filterable to allow other plugins/themes to override.
	 *
	 * Example:
	 * add_filter( 'pmpro_discharge_date_level_id', function() { return 5; } );
	 *
	 * @since 1.1.0
	 * @return int Target membership level ID.
	 */
	public static function get_target_level_id(): int {
		return (int) apply_filters( 'pmpro_discharge_date_level_id', self::$TARGET_LEVEL_ID );
	}

	/**
	 * Get maximum years into the future allowed for discharge date.
	 *
	 * Filterable to allow customization.
	 *
	 * @since 1.1.0
	 * @return int Maximum years.
	 */
	public static function get_max_future_years(): int {
		return (int) apply_filters( 'pmpro_discharge_date_max_years', self::$MAX_FUTURE_YEARS );
	}

	/**
	 * ========================================================================
	 * DATE UTILITY METHODS
	 * ========================================================================
	 */

	/**
	 * Get today's date in YYYY-MM-DD format using WordPress timezone.
	 *
	 * Uses wp_date() and wp_timezone() for proper timezone handling.
	 *
	 * @since 1.0.0
	 * @return string Today's date in YYYY-MM-DD format.
	 */
	private static function today_ymd(): string {
		return wp_date( 'Y-m-d', null, wp_timezone() );
	}

	/**
	 * Get maximum allowed future date in YYYY-MM-DD format.
	 *
	 * Calculated as today + MAX_FUTURE_YEARS.
	 *
	 * @since 1.0.0
	 * @return string Maximum future date in YYYY-MM-DD format.
	 */
	private static function max_future_ymd(): string {
		$tz = wp_timezone();
		$dt = new DateTimeImmutable( self::today_ymd(), $tz );
		$dt = $dt->modify( '+' . self::get_max_future_years() . ' years' );
		return $dt->format( 'Y-m-d' );
	}

	/**
	 * Parse and validate a YYYY-MM-DD date string.
	 *
	 * Validates:
	 * - Format matches YYYY-MM-DD regex
	 * - Date is valid (no 2026-02-31, etc.)
	 * - No PHP date parsing warnings/errors
	 *
	 * @since 1.0.0
	 * @param string $ymd Date string to validate.
	 * @return DateTimeImmutable|null DateTimeImmutable object if valid, null if invalid.
	 */
	private static function parse_ymd( string $ymd ): ?DateTimeImmutable {
		$ymd = trim( $ymd );

		// Check format: YYYY-MM-DD.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return null;
		}

		$tz = wp_timezone();
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $ymd, $tz );

		if ( ! $dt ) {
			return null;
		}

		// Check for parsing errors (invalid dates like 2026-02-31).
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
			return null;
		}

		return $dt;
	}

	/**
	 * Convert YYYY-MM-DD date to MySQL datetime at end of day (23:59:59).
	 *
	 * PMPro memberships expire at end of day, so we set to 23:59:59.
	 * This ensures users have access through the entire discharge date.
	 *
	 * @since 1.0.0
	 * @param string $ymd Date in YYYY-MM-DD format.
	 * @return string|null MySQL datetime string (YYYY-MM-DD 23:59:59) or null if invalid.
	 */
	private static function ymd_to_end_of_day_mysql( string $ymd ): ?string {
		$dt = self::parse_ymd( $ymd );
		if ( ! $dt ) {
			return null;
		}
		return $dt->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * ========================================================================
	 * CONDITIONAL CHECKS
	 * ========================================================================
	 */

	/**
	 * Check if current checkout is for the target level.
	 *
	 * Checks both pmpro_getLevelAtCheckout() (preferred) and global $pmpro_level.
	 *
	 * @since 1.0.0
	 * @return bool True if checking out for target level.
	 */
	private static function is_target_level_checkout(): bool {
		$target_id = self::get_target_level_id();

		// Preferred method (PMPro 2.0+).
		if ( function_exists( 'pmpro_getLevelAtCheckout' ) ) {
			$level = pmpro_getLevelAtCheckout();
			return ( ! empty( $level ) && (int) $level->id === $target_id );
		}

		// Fallback to global.
		global $pmpro_level;
		return ( ! empty( $pmpro_level ) && (int) $pmpro_level->id === $target_id );
	}

	/**
	 * Check if a user has the target membership level.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID to check.
	 * @return bool True if user has the target level.
	 */
	private static function user_has_target_level( int $user_id ): bool {
		return function_exists( 'pmpro_hasMembershipLevel' ) 
			&& pmpro_hasMembershipLevel( self::get_target_level_id(), $user_id );
	}

	/**
	 * ========================================================================
	 * DATA ACCESS
	 * ========================================================================
	 */

	/**
	 * Get discharge date for a user.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return string Discharge date in YYYY-MM-DD format, or empty string if not set.
	 */
	private static function get_discharge_date( int $user_id ): string {
		$val = get_user_meta( $user_id, self::$META_KEY, true );
		return is_string( $val ) ? trim( $val ) : '';
	}

	/**
	 * ========================================================================
	 * VALIDATION
	 * ========================================================================
	 */

	/**
	 * Validate discharge date against business rules.
	 *
	 * Rules:
	 * - Must be valid YYYY-MM-DD format
	 * - Cannot be in the past
	 * - Cannot be more than MAX_FUTURE_YEARS in the future
	 *
	 * Errors are added to the passed array by reference.
	 *
	 * @since 1.0.0
	 * @param string $ymd Date to validate (YYYY-MM-DD format).
	 * @param array  $errors Array to populate with error messages (passed by reference).
	 * @return void
	 */
	private static function validate_date_rules( string $ymd, array &$errors ): void {
		$dt = self::parse_ymd( $ymd );
		if ( ! $dt ) {
			$errors[] = __( 'Please enter a valid Discharge Date in YYYY-MM-DD format.', 'pmpro' );
			return;
		}

		$today = self::parse_ymd( self::today_ymd() );
		$max   = self::parse_ymd( self::max_future_ymd() );

		if ( $today && $dt < $today ) {
			$errors[] = __( 'Discharge Date cannot be in the past.', 'pmpro' );
		}

		if ( $max && $dt > $max ) {
			$errors[] = sprintf(
				/* translators: %d: number of years */
				__( 'Discharge Date cannot be more than %d years in the future.', 'pmpro' ),
				self::get_max_future_years()
			);
		}
	}

	/**
	 * ========================================================================
	 * CHECKOUT FIELD RENDERING
	 * ========================================================================
	 */

	/**
	 * Render discharge date field on checkout page.
	 *
	 * BEHAVIOR:
	 * - Only shows for target level checkout
	 * - If user already has discharge date saved: shows read-only display
	 * - If not saved: shows HTML5 date input with validation
	 *
	 * MARKUP:
	 * - Matches PMPro's core checkout section structure
	 * - Uses PMPro CSS classes for consistent styling
	 * - Includes fieldset > card > card_content wrapper (PMPro 2.x pattern)
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_checkout_field(): void {
		if ( ! self::is_target_level_checkout() ) {
			return;
		}

		$user_id = get_current_user_id();
		$saved   = $user_id ? self::get_discharge_date( $user_id ) : '';

		$min = esc_attr( self::today_ymd() );
		$max = esc_attr( self::max_future_ymd() );

		// Match PMPro core checkout section markup.
		echo '<fieldset id="pmpro_discharge_date" class="pmpro_form_fieldset pmpro_checkout-field pmpro_checkout-field-discharge-date">';
		echo '	<div class="pmpro_card">';
		echo '		<div class="pmpro_card_content">';
		echo '			<legend class="pmpro_form_legend">';
		echo '				<h2 class="pmpro_form_heading pmpro_font-large">' . esc_html__( 'Discharge Date', 'pmpro' ) . '</h2>';
		echo '			</legend>';

		if ( ! empty( $saved ) ) {
			// User already has discharge date set - show read-only.
			echo '			<p><strong>' . esc_html__( 'Discharge Date:', 'pmpro' ) . '</strong> ' . esc_html( $saved ) . '</p>';
			echo '			<p class="pmpro_hint">' . esc_html__( 'This date has already been set and cannot be changed. Contact an administrator if you need to update it.', 'pmpro' ) . '</p>';
		} else {
			// User needs to set discharge date - show input field.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated later during checkout
			$posted = isset( $_REQUEST['pmpro_discharge_date'] )
				? sanitize_text_field( wp_unslash( $_REQUEST['pmpro_discharge_date'] ) )
				: '';

			echo '			<div class="pmpro_form_field pmpro_form_field-date">';
			echo '				<label for="pmpro_discharge_date_field">' . esc_html__( 'Discharge Date', 'pmpro' ) . '</label>';
			echo '				<input type="date" id="pmpro_discharge_date_field" name="pmpro_discharge_date" value="' . esc_attr( $posted ) . '" min="' . $min . '" max="' . $max . '" required />';
			echo '				<p class="pmpro_hint">' . sprintf(
				/* translators: %d: number of years */
				esc_html__( 'Choose a date from today up to %d years in the future.', 'pmpro' ),
				self::get_max_future_years()
			) . '</p>';
			echo '			</div>';
		}

		echo '		</div>'; // .pmpro_card_content
		echo '	</div>';     // .pmpro_card
		echo '</fieldset>';
	}

	/**
	 * ========================================================================
	 * CHECKOUT VALIDATION
	 * ========================================================================
	 */

	/**
	 * Validate discharge date during checkout.
	 *
	 * HOOK: pmpro_registration_checks (filter)
	 * RUNS: During checkout, before payment processing
	 *
	 * LOGIC:
	 * - Only validates for target level checkout
	 * - If user already has discharge date: skip validation (allow re-checkout)
	 * - If no date entered: show error
	 * - If invalid date: show error
	 *
	 * @since 1.0.0
	 * @param bool $continue Current validation status.
	 * @return bool False to stop checkout, true to continue.
	 */
	public static function validate_checkout( $continue ) {
		// Only validate if checkout is still valid and it's the target level.
		if ( ! $continue || ! self::is_target_level_checkout() ) {
			return $continue;
		}

		// If user already has discharge date set, skip validation.
		$user_id = get_current_user_id();
		if ( $user_id && ! empty( self::get_discharge_date( $user_id ) ) ) {
			return $continue;
		}

		// Validate submitted discharge date.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PMPro handles nonce validation
		$raw = isset( $_REQUEST['pmpro_discharge_date'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['pmpro_discharge_date'] ) )
			: '';

		$errors = array();

		if ( trim( $raw ) === '' ) {
			$errors[] = __( 'Please enter your Discharge Date.', 'pmpro' );
		} else {
			self::validate_date_rules( $raw, $errors );
		}

		// If validation errors, stop checkout and display message.
		if ( ! empty( $errors ) ) {
			global $pmpro_msg, $pmpro_msgt;
			$pmpro_msg  = implode( ' ', array_map( 'esc_html', $errors ) );
			$pmpro_msgt = 'pmpro_error';
			return false;
		}

		return $continue;
	}

	/**
	 * ========================================================================
	 * CHECKOUT SAVE
	 * ========================================================================
	 */

	/**
	 * Save discharge date after successful checkout.
	 *
	 * HOOK: pmpro_after_checkout (action)
	 * RUNS: After successful checkout, after payment processed
	 *
	 * LOGIC:
	 * - Only saves for target level checkout
	 * - If user already has discharge date: skip (set-once enforcement)
	 * - Saves date to user meta
	 * - Does NOT call pmpro_changeMembershipLevel() - enddate already set by filter
	 *
	 * NOTE: We don't sync enddate here because pmpro_calculate_enddate filter
	 * already set it correctly during the checkout process.
	 *
	 * @since 1.0.0
	 * @param int   $user_id User ID.
	 * @param mixed $morder  PMPro order object.
	 * @return void
	 */
	public static function save_after_checkout( int $user_id, $morder ): void {
		if ( empty( $user_id ) || ! self::is_target_level_checkout() ) {
			return;
		}

		// Set-once enforcement: if already set, don't overwrite.
		if ( ! empty( self::get_discharge_date( $user_id ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PMPro handles nonce validation
		$raw = isset( $_REQUEST['pmpro_discharge_date'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['pmpro_discharge_date'] ) )
			: '';

		if ( trim( $raw ) === '' ) {
			return;
		}

		// Validate one more time (defense in depth).
		$errors = array();
		self::validate_date_rules( $raw, $errors );

		if ( empty( $errors ) ) {
			update_user_meta( $user_id, self::$META_KEY, $raw );
			self::log_debug( 'Discharge date saved during checkout', $user_id, $raw );
		}
	}

	/**
	 * ========================================================================
	 * EXPIRATION DATE OVERRIDE
	 * ========================================================================
	 */

	/**
	 * Filter membership enddate calculation to use discharge date.
	 *
	 * HOOK: pmpro_calculate_enddate (filter)
	 * RUNS: During pmpro_changeMembershipLevel() when calculating expiration
	 *
	 * PRIORITY: 999 (runs after other plugins)
	 *
	 * LOGIC:
	 * - Only applies to target level
	 * - During checkout: uses submitted discharge date
	 * - After checkout: uses saved user meta
	 * - Returns end of day (23:59:59) for proper expiration
	 *
	 * WHY THIS WORKS:
	 * - pmpro_changeMembershipLevel() calls this filter to calculate enddate
	 * - This runs during checkout, admin level assignment, and programmatic changes
	 *
	 * LIMITATIONS:
	 * - May not fire during gateway-initiated renewals (not applicable for non-renewable levels)
	 *
	 * @since 1.0.0
	 * @param string $enddate   Default calculated enddate.
	 * @param string $startdate Membership start date.
	 * @param int    $user_id   User ID.
	 * @param int    $level_id  Membership level ID.
	 * @return string Modified enddate (MySQL datetime) or original if not applicable.
	 */
	public static function filter_calculated_enddate( $enddate, $startdate, $user_id, $level_id ) {
		// Only apply to target level.
		if ( (int) $level_id !== self::get_target_level_id() ) {
			return $enddate;
		}

		$ymd = '';

		// During checkout, get from submitted form data.
		if ( self::is_target_level_checkout() && isset( $_REQUEST['pmpro_discharge_date'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PMPro handles nonce validation
			$ymd = sanitize_text_field( wp_unslash( $_REQUEST['pmpro_discharge_date'] ) );
		} elseif ( $user_id > 0 ) {
			// Otherwise, get from saved user meta.
			$ymd = self::get_discharge_date( (int) $user_id );
		}

		// If no discharge date, return original enddate.
		if ( empty( $ymd ) ) {
			return $enddate;
		}

		// Convert to MySQL datetime at end of day.
		$mysql = self::ymd_to_end_of_day_mysql( $ymd );

		if ( $mysql ) {
			self::log_debug( 'Enddate calculated from discharge date', $user_id, $ymd );
			return $mysql;
		}

		return $enddate;
	}

	/**
	 * Enforce discharge date after membership level change.
	 *
	 * HOOK: pmpro_after_change_membership_level (action)
	 * RUNS: After any membership level change (backstop)
	 *
	 * PURPOSE:
	 * - Catches edge cases where pmpro_calculate_enddate might not have fired
	 * - Ensures enddate is always correct after level assignment
	 * - Direct database update for guaranteed sync
	 *
	 * WHEN THIS HELPS:
	 * - Admin assigns level via Members List page
	 * - Import/migration scripts
	 * - Third-party integrations
	 *
	 * @since 1.0.0
	 * @param int $level_id New level ID.
	 * @param int $user_id  User ID.
	 * @return void
	 */
	public static function enforce_enddate_after_level_change( int $level_id, int $user_id ): void {
		// Only apply to target level.
		if ( (int) $level_id !== self::get_target_level_id() || empty( $user_id ) ) {
			return;
		}

		// Get saved discharge date.
		$ymd = self::get_discharge_date( $user_id );

		if ( $ymd !== '' ) {
			$mysql = self::ymd_to_end_of_day_mysql( $ymd );
			if ( $mysql ) {
				self::update_membership_enddate_direct( $user_id, self::get_target_level_id(), $mysql );
				self::log_debug( 'Enddate enforced after level change (backstop)', $user_id, $ymd );
			}
		}
	}

	/**
	 * Update membership enddate directly in database.
	 *
	 * USED BY: enforce_enddate_after_level_change()
	 *
	 * WHY DIRECT UPDATE:
	 * - Bypasses pmpro_changeMembershipLevel() to avoid recursion
	 * - Faster than full level re-assignment
	 * - Guaranteed to update the database
	 *
	 * SAFETY:
	 * - Uses $wpdb->update() with prepared statements
	 * - Only updates active memberships
	 * - Clears PMPro cache after update
	 *
	 * @since 1.0.0
	 * @param int    $user_id        User ID.
	 * @param int    $level_id       Membership level ID.
	 * @param string $mysql_enddate  MySQL datetime string (YYYY-MM-DD HH:MM:SS).
	 * @return void
	 */
	private static function update_membership_enddate_direct( int $user_id, int $level_id, string $mysql_enddate ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pmpro_memberships_users';

		$wpdb->update(
			$table,
			array( 'enddate' => $mysql_enddate ),
			array(
				'user_id'       => $user_id,
				'membership_id' => $level_id,
				'status'        => 'active',
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);

		// Clear PMPro's member cache to ensure fresh data.
		if ( function_exists( 'pmpro_clearMemberCache' ) ) {
			pmpro_clearMemberCache( $user_id );
		}
	}

	/**
	 * ========================================================================
	 * ACCOUNT PAGE DISPLAY
	 * ========================================================================
	 */

	/**
	 * Render discharge date on account page.
	 *
	 * HOOK: pmpro_account_bullets_bottom (action)
	 * RUNS: At the bottom of membership details on account page
	 *
	 * BEHAVIOR:
	 * - Only shows if user has target level
	 * - If discharge date is set: shows read-only display
	 * - If not set: shows one-time form to set it
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_account_bullet(): void {
		$user_id = get_current_user_id();

		// Only show for users with target level.
		if ( ! $user_id || ! self::user_has_target_level( $user_id ) ) {
			return;
		}

		$saved = self::get_discharge_date( $user_id );

		echo '<li class="pmpro_list_item pmpro_account-discharge-date">';

		if ( $saved !== '' ) {
			// Discharge date already set - show read-only.
			echo '<strong>' . esc_html__( 'Discharge Date:', 'pmpro' ) . '</strong> ' . esc_html( $saved );
			echo '<div class="pmpro_hint">' . esc_html__( 'This date cannot be changed. Contact an administrator if you need to update it.', 'pmpro' ) . '</div>';
		} else {
			// Discharge date not set - show one-time form.
			echo '<strong>' . esc_html__( 'Discharge Date:', 'pmpro' ) . '</strong> ';
			echo '<form class="pmpro_form" style="display: inline-block; margin-top: 10px;" method="post">';

			wp_nonce_field( 'pmpro_set_discharge_date', 'pmpro_set_discharge_date_nonce' );

			echo '<input type="date" name="pmpro_discharge_date" min="' . esc_attr( self::today_ymd() ) . '" max="' . esc_attr( self::max_future_ymd() ) . '" required class="pmpro_form_input pmpro_form_input-date" style="width: auto; margin-right: 10px;" />';
			echo '<input type="hidden" name="pmpro_discharge_date_action" value="set" />';
			echo '<button type="submit" class="pmpro_btn pmpro_btn-submit">' . esc_html__( 'Save', 'pmpro' ) . '</button>';

			echo '</form>';
		}

		echo '</li>';
	}

	/**
	 * Handle discharge date form submission from account page.
	 *
	 * HOOK: template_redirect (action)
	 * RUNS: Before template loads, on every page (early check to avoid processing)
	 *
	 * SECURITY:
	 * - Nonce validation
	 * - User login check
	 * - Level ownership check
	 * - Set-once enforcement
	 *
	 * REDIRECT:
	 * - On success: redirect to account page with success message
	 * - On error: validation happens, user sees errors on reload
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle_account_form_post(): void {
		// Early exit: only process on account page to avoid unnecessary checks on every page.
		if ( ! is_page( pmpro_getOption( 'account_page_id' ) ) ) {
			return;
		}

		// Only process if form was submitted.
		if ( ! is_user_logged_in() || empty( $_POST['pmpro_discharge_date_action'] ) ) {
			return;
		}

		// Verify nonce.
		check_admin_referer( 'pmpro_set_discharge_date', 'pmpro_set_discharge_date_nonce' );

		$user_id = get_current_user_id();

		// Verify user has target level and hasn't already set discharge date.
		if ( ! self::user_has_target_level( $user_id ) || self::get_discharge_date( $user_id ) !== '' ) {
			return;
		}

		// Validate submitted date.
		$raw = isset( $_POST['pmpro_discharge_date'] )
			? sanitize_text_field( wp_unslash( $_POST['pmpro_discharge_date'] ) )
			: '';

		$errors = array();
		self::validate_date_rules( $raw, $errors );

		// If valid, save and sync.
		if ( empty( $errors ) ) {
			update_user_meta( $user_id, self::$META_KEY, $raw );
			self::sync_enddate_direct( $user_id, self::get_target_level_id(), $raw );
			self::log_debug( 'Discharge date saved from account page', $user_id, $raw );

			// Redirect to account page with success message.
			$account_url = self::get_account_page_url();
			wp_safe_redirect( add_query_arg( 'pmpro_msg', 'discharge_saved', $account_url ) );
			exit;
		}

		// If errors, let the page reload and show validation errors.
		// (In a production plugin, you might store errors in a transient to display them.)
	}

	/**
	 * ========================================================================
	 * ADMIN PROFILE FIELD
	 * ========================================================================
	 */

	/**
	 * Render discharge date field on user profile edit page (admin).
	 *
	 * HOOK: show_user_profile, edit_user_profile (actions)
	 * RUNS: On user profile edit page in admin
	 *
	 * CAPABILITY: Requires 'edit_user' capability
	 *
	 * BEHAVIOR:
	 * - Shows discharge date field for all users
	 * - Admins can edit/delete the date
	 * - If user has target level, enddate will sync on save
	 *
	 * @since 1.0.0
	 * @param WP_User $user User object being edited.
	 * @return void
	 */
	public static function render_admin_profile_field( WP_User $user ): void {
		// Only show if current user can edit this user.
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$value = self::get_discharge_date( (int) $user->ID );
		?>
		<h2><?php esc_html_e( 'PMPro Discharge Date', 'pmpro' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="pmpro_discharge_date_admin"><?php esc_html_e( 'Discharge Date', 'pmpro' ); ?></label></th>
				<td>
					<input id="pmpro_discharge_date_admin" class="regular-text" type="date" name="pmpro_discharge_date_admin" value="<?php echo esc_attr( $value ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %d: target level ID */
							esc_html__( 'Admins can edit this value. If the user has membership level %d, their membership expiration will be updated to match this date. Leave blank to clear.', 'pmpro' ),
							self::get_target_level_id()
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save discharge date from admin profile edit page.
	 *
	 * HOOK: personal_options_update, edit_user_profile_update (actions)
	 * RUNS: When admin saves user profile
	 *
	 * SECURITY:
	 * - Capability check for 'edit_user'
	 * - WordPress core handles nonce validation for profile updates
	 *
	 * BEHAVIOR:
	 * - If blank: deletes user meta
	 * - If set: saves to user meta
	 * - If user has target level: syncs enddate
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID being saved.
	 * @return void
	 */
	public static function save_admin_profile_field( int $user_id ): void {
		// Verify capability.
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['pmpro_discharge_date_admin'] ) ) {
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_POST['pmpro_discharge_date_admin'] ) );

		// If blank, delete the meta.
		if ( $raw === '' ) {
			delete_user_meta( $user_id, self::$META_KEY );
			self::log_debug( 'Discharge date cleared by admin', $user_id, '' );
		} else {
			// Save the date.
			update_user_meta( $user_id, self::$META_KEY, $raw );

			// If user has target level, sync enddate.
			if ( self::user_has_target_level( $user_id ) ) {
				self::sync_enddate_direct( $user_id, self::get_target_level_id(), $raw );
				self::log_debug( 'Discharge date updated by admin', $user_id, $raw );
			}
		}
	}

	/**
	 * ========================================================================
	 * HELPER METHODS
	 * ========================================================================
	 */

	/**
	 * Sync enddate directly without calling pmpro_changeMembershipLevel().
	 *
	 * This is a safer alternative to calling pmpro_changeMembershipLevel()
	 * which would trigger hooks and potentially cause recursion.
	 *
	 * @since 1.1.0
	 * @param int    $user_id  User ID.
	 * @param int    $level_id Membership level ID.
	 * @param string $ymd      Discharge date in YYYY-MM-DD format.
	 * @return void
	 */
	private static function sync_enddate_direct( int $user_id, int $level_id, string $ymd ): void {
		$mysql_eod = self::ymd_to_end_of_day_mysql( $ymd );
		if ( ! $mysql_eod ) {
			return;
		}
		self::update_membership_enddate_direct( $user_id, $level_id, $mysql_eod );
	}

	/**
	 * Get account page URL (compatible with PMPro 2.5+ and older versions).
	 *
	 * @since 1.1.0
	 * @return string Account page URL.
	 */
	private static function get_account_page_url(): string {
		if ( function_exists( 'pmpro_url' ) ) {
			return pmpro_url( 'account' );
		}
		// Fallback for older PMPro versions.
		return get_permalink( pmpro_getOption( 'account_page_id' ) );
	}

	/**
	 * Log debug message (only if WP_DEBUG is enabled).
	 *
	 * Helps with troubleshooting without cluttering production logs.
	 *
	 * @since 1.1.0
	 * @param string $context  Context/action being performed.
	 * @param int    $user_id  User ID.
	 * @param string $ymd      Discharge date in YYYY-MM-DD format.
	 * @return void
	 */
	private static function log_debug( string $context, int $user_id, string $ymd ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[PMPro Discharge Date] %s: User %d → %s',
					$context,
					$user_id,
					$ymd ?: '(empty)'
				)
			);
		}
	}
}

// Initialize the plugin.
PMPro_Discharge_Date_Sync::init();
