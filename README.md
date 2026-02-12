# PMPro Discharge Date Sync 

**Version:** 1.1.0  
**Author:** Your Name  
**License:** GPL v2 or later  
**Requires:** WordPress 5.3+, PHP 7.4+, Paid Memberships Pro 2.0+

---

## 📋 Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [User Experience](#user-experience)
- [Admin Experience](#admin-experience)
- [Technical Architecture](#technical-architecture)
- [Hooks & Filters](#hooks--filters)
- [Troubleshooting](#troubleshooting)
- [Extending the Plugin](#extending-the-plugin)
- [FAQ](#faq)
- [Changelog](#changelog)

---

## 🎯 Overview

This plugin adds a **discharge date** field to a specific Paid Memberships Pro (PMPro) membership level. The discharge date becomes the membership expiration date and **cannot be changed by the user** after initial entry (set-once behavior).

**Primary Use Case:** Military/veteran membership levels where the discharge date determines membership eligibility.

### Key Behavior

- User enters discharge date during checkout
- Date is validated (no past dates, max 5 years future)
- Membership expires on discharge date
- User **cannot modify** the date after setting it
- Admins can edit the date via user profile

---

## ✨ Features

### Core Functionality

- ✅ Custom checkout field for discharge date
- ✅ HTML5 date picker with client-side validation
- ✅ Server-side validation (format, past/future limits)
- ✅ Automatic sync to PMPro membership expiration
- ✅ Set-once enforcement (users cannot edit after checkout)
- ✅ Account page display with optional one-time form
- ✅ Admin profile field for editing

### Technical Features

- ✅ Timezone-aware date handling (WordPress timezone)
- ✅ End-of-day expiration (23:59:59)
- ✅ CSRF protection via nonces
- ✅ Proper input sanitization and output escaping
- ✅ Direct database updates for guaranteed sync
- ✅ Debug logging (when WP_DEBUG enabled)
- ✅ Filterable configuration

---

## 📦 Installation

### Method 1: Manual Upload

1. Download `pmpro-discharge-date-sync.php`
2. Upload to `/wp-content/plugins/` directory
3. Activate via WordPress admin → Plugins
4. Configure target level ID (see Configuration)

### Method 2: Direct File

1. Copy code to `/wp-content/plugins/pmpro-discharge-date-sync.php`
2. Activate via WordPress admin → Plugins
3. Configure target level ID (see Configuration)

---

## ⚙️ Configuration

### Basic Configuration

Open the plugin file and modify the class properties:

```php
/**
 * Target membership level ID.
 * Find this in: Memberships > Membership Levels
 */
private static int $TARGET_LEVEL_ID = 2;

/**
 * Maximum years into the future for discharge date.
 */
private static int $MAX_FUTURE_YEARS = 5;
```

### Advanced Configuration (Using Filters)

Override settings without editing the plugin file:

```php
// In your theme's functions.php or custom plugin:

// Change target level ID
add_filter( 'pmpro_discharge_date_level_id', function() {
    return 5; // Your level ID
});

// Change max future years
add_filter( 'pmpro_discharge_date_max_years', function() {
    return 10; // Allow 10 years instead of 5
});
```

---

## 🔄 How It Works

### Checkout Flow

```
1. User selects target level at checkout
   ↓
2. Plugin renders discharge date field
   ↓
3. User enters date (or sees existing saved date)
   ↓
4. Client-side validation (HTML5 date input)
   ↓
5. User submits checkout form
   ↓
6. Server-side validation (pmpro_registration_checks)
   ↓
7. Payment processing (PMPro core)
   ↓
8. Discharge date saved to user meta (pmpro_after_checkout)
   ↓
9. Membership expiration calculated (pmpro_calculate_enddate)
   → Uses discharge date as end date
   ↓
10. Backstop hook enforces correct enddate (pmpro_after_change_membership_level)
```

### Date Storage

- **User Meta Key:** `_pmpro_discharge_date`
- **Format:** `YYYY-MM-DD` (e.g., `2026-12-31`)
- **Database:** `wp_usermeta` table
- **Privacy:** Underscore prefix hides from REST API

### Expiration Sync

- **When:** During level assignment/change
- **How:** `pmpro_calculate_enddate` filter
- **Enddate:** Discharge date at 23:59:59
- **Example:** `2026-12-31` → `2026-12-31 23:59:59` in database

---

## 👤 User Experience

### First-Time Checkout

1. User navigates to checkout for target level
2. Sees "Discharge Date" section with date picker
3. Selects date (min: today, max: 5 years future)
4. Completes checkout
5. Date is saved and membership expires on that date

### Repeat Checkout (Already Has Date)

1. User navigates to checkout for target level
2. Sees "Discharge Date" section with **read-only display**:
   > **Discharge Date:** 2026-12-31  
   > _This date has already been set and cannot be changed. Contact an administrator if you need to update it._
3. User cannot modify the date
4. Completes checkout with existing date

### Account Page

**If discharge date is set:**
```
Membership Details
- Membership Level: Veteran
- Discharge Date: 2026-12-31
  This date cannot be changed. Contact an administrator if you need to update it.
```

**If discharge date is NOT set (edge case):**
```
Membership Details
- Membership Level: Veteran
- Discharge Date: [Date Picker] [Save Button]
```

---

## 👨‍💼 Admin Experience

### User Profile Edit

Admins can edit discharge dates:

1. Go to Users → Edit User
2. Scroll to "PMPro Discharge Date" section
3. Edit the date or leave blank to clear
4. Click "Update User"
5. If user has target level, expiration syncs automatically

**Admin Profile Field:**
```
PMPro Discharge Date
┌─────────────────────────────────┐
│ Discharge Date:  [2026-12-31]  │
└─────────────────────────────────┘
Admins can edit this value. If the user has membership level 2,
their membership expiration will be updated to match this date.
Leave blank to clear.
```

### Finding Target Level ID

1. Go to **Memberships → Membership Levels**
2. Hover over level name
3. Look at URL: `...post.php?post=2&action=edit`
4. The number after `post=` is the level ID (e.g., `2`)

---

## 🏗️ Technical Architecture

### Class Structure

```
PMPro_Discharge_Date_Sync (final class)
├── Configuration
│   ├── $TARGET_LEVEL_ID (level to apply logic)
│   ├── $META_KEY (user meta storage key)
│   └── $MAX_FUTURE_YEARS (validation limit)
│
├── Initialization
│   └── init() → Registers all WordPress/PMPro hooks
│
├── Date Utilities
│   ├── today_ymd() → Current date in YYYY-MM-DD
│   ├── max_future_ymd() → Max allowed date
│   ├── parse_ymd() → Validate date string
│   └── ymd_to_end_of_day_mysql() → Convert to MySQL datetime
│
├── Conditional Checks
│   ├── is_target_level_checkout() → Check if checkout is for target level
│   └── user_has_target_level() → Check if user has target level
│
├── Data Access
│   └── get_discharge_date() → Retrieve user's discharge date
│
├── Validation
│   └── validate_date_rules() → Validate date format and business rules
│
├── Checkout
│   ├── render_checkout_field() → Display field on checkout
│   ├── validate_checkout() → Server-side validation
│   └── save_after_checkout() → Save to user meta
│
├── Expiration Override
│   ├── filter_calculated_enddate() → Override enddate calculation
│   └── enforce_enddate_after_level_change() → Backstop enforcement
│
├── Account Page
│   ├── render_account_bullet() → Display on account page
│   └── handle_account_form_post() → Handle form submission
│
└── Admin Profile
    ├── render_admin_profile_field() → Render edit field
    └── save_admin_profile_field() → Save changes
```

### Hook Execution Order (Checkout)

```
WordPress/PMPro Hook                          Plugin Method
────────────────────────────────────────────────────────────────────
1. pmpro_checkout_after_billing_fields  →  render_checkout_field()
   (User sees the field)

2. pmpro_registration_checks            →  validate_checkout()
   (Validates date before payment)

3. (PMPro processes payment)

4. pmpro_calculate_enddate              →  filter_calculated_enddate()
   (Sets correct expiration during level assignment)

5. pmpro_after_checkout                 →  save_after_checkout()
   (Saves discharge date to user meta)

6. pmpro_after_change_membership_level  →  enforce_enddate_after_level_change()
   (Backstop: ensures enddate is correct in DB)
```

### Database Schema

**User Meta:**
```sql
wp_usermeta
├── meta_key = '_pmpro_discharge_date'
└── meta_value = '2026-12-31'  (YYYY-MM-DD format)
```

**Membership Record:**
```sql
wp_pmpro_memberships_users
├── user_id = 123
├── membership_id = 2
├── enddate = '2026-12-31 23:59:59'  (MySQL datetime)
└── status = 'active'
```

---

## 🎣 Hooks & Filters

### Available Filters

#### `pmpro_discharge_date_level_id`

Override the target level ID without editing the plugin.

**Parameters:**
- `int $level_id` - Default level ID from class property

**Example:**
```php
add_filter( 'pmpro_discharge_date_level_id', function( $level_id ) {
    return 5; // Use level 5 instead
});
```

#### `pmpro_discharge_date_max_years`

Override the maximum future years allowed.

**Parameters:**
- `int $years` - Default max years from class property

**Example:**
```php
add_filter( 'pmpro_discharge_date_max_years', function( $years ) {
    return 10; // Allow 10 years instead of 5
});
```

### Plugin Hooks Into PMPro

The plugin hooks into these PMPro actions/filters:

| Hook | Type | Purpose |
|------|------|---------|
| `pmpro_checkout_after_billing_fields` | Action | Render checkout field |
| `pmpro_registration_checks` | Filter | Validate date during checkout |
| `pmpro_after_checkout` | Action | Save date to user meta |
| `pmpro_calculate_enddate` | Filter | Override expiration calculation |
| `pmpro_after_change_membership_level` | Action | Enforce enddate after level change |
| `pmpro_account_bullets_bottom` | Action | Display on account page |

### WordPress Core Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `template_redirect` | Action | Handle account page form POST |
| `show_user_profile` | Action | Render admin field (own profile) |
| `edit_user_profile` | Action | Render admin field (other profile) |
| `personal_options_update` | Action | Save admin field (own profile) |
| `edit_user_profile_update` | Action | Save admin field (other profile) |

---

## 🔧 Troubleshooting

### Discharge Date Not Syncing to Expiration

**Symptoms:**
- Discharge date saves to user meta
- Membership expiration shows wrong date

**Diagnosis:**
1. Enable debug logging:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   ```
2. Trigger the issue (checkout or admin save)
3. Check `/wp-content/debug.log` for entries like:
   ```
   [PMPro Discharge Date] Enddate calculated from discharge date: User 123 → 2026-12-31
   ```

**Solutions:**
- Verify `TARGET_LEVEL_ID` matches your actual level ID
- Check if another plugin is filtering `pmpro_calculate_enddate`
- Manually trigger sync via admin profile save

### Date Field Not Showing on Checkout

**Symptoms:**
- Checkout page doesn't show discharge date field

**Possible Causes:**
1. **Wrong level:** User is checking out for different level
   - Verify `TARGET_LEVEL_ID` is correct
2. **Custom checkout template:** Template doesn't call `pmpro_checkout_after_billing_fields`
   - Add hook to your custom template
3. **JavaScript conflict:** Date input not rendering
   - Check browser console for errors

### Users Can Edit Date After Checkout

**Symptoms:**
- Date field is editable on subsequent checkouts

**Cause:**
- Set-once logic not working

**Diagnosis:**
```php
// Check if user meta is being saved:
$user_id = 123; // Replace with actual user ID
$date = get_user_meta( $user_id, '_pmpro_discharge_date', true );
echo $date; // Should output: 2026-12-31
```

**Solution:**
- Verify user meta is saving correctly
- Check for code that deletes the meta key

### PMPro Version Compatibility Error

**Symptoms:**
```
Fatal error: Call to undefined function pmpro_url()
```

**Cause:**
- Using PMPro version < 2.5

**Solution:**
Already handled in v1.1.0 with fallback:
```php
// Plugin automatically falls back to:
get_permalink( pmpro_getOption('account_page_id') )
```

If error persists, update to latest plugin version.

---

## 🔌 Extending the Plugin

### Adding Recurring Billing Support

**⚠️ IMPORTANT:** This plugin is designed for **non-renewable** membership levels. If you need recurring billing support, add these hooks:

```php
/**
 * Enforce discharge date on subscription renewals.
 * Add this to your theme's functions.php or custom plugin.
 */
add_action( 'pmpro_subscription_payment_completed', function( $order, $membership_level ) {
    $target_level_id = 2; // Match your target level
    
    if ( empty( $order->user_id ) || (int) $membership_level->id !== $target_level_id ) {
        return;
    }
    
    $discharge_date = get_user_meta( (int) $order->user_id, '_pmpro_discharge_date', true );
    
    if ( empty( $discharge_date ) ) {
        return;
    }
    
    // Convert to end of day MySQL datetime
    $dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $discharge_date, wp_timezone() );
    if ( ! $dt ) {
        return;
    }
    $mysql_enddate = $dt->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
    
    // Update membership enddate directly
    global $wpdb;
    $table = $wpdb->prefix . 'pmpro_memberships_users';
    $wpdb->update(
        $table,
        array( 'enddate' => $mysql_enddate ),
        array(
            'user_id'       => (int) $order->user_id,
            'membership_id' => $target_level_id,
            'status'        => 'active'
        ),
        array( '%s' ),
        array( '%d', '%d', '%s' )
    );
    
    if ( function_exists( 'pmpro_clearMemberCache' ) ) {
        pmpro_clearMemberCache( (int) $order->user_id );
    }
}, 999, 2 );
```

### Custom Validation Rules

Add custom validation beyond the built-in rules:

```php
/**
 * Add custom discharge date validation.
 */
add_filter( 'pmpro_registration_checks', function( $continue ) {
    if ( ! $continue ) {
        return $continue;
    }
    
    // Example: Discharge date must be on first of month
    if ( isset( $_REQUEST['pmpro_discharge_date'] ) ) {
        $date = sanitize_text_field( wp_unslash( $_REQUEST['pmpro_discharge_date'] ) );
        $dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, wp_timezone() );
        
        if ( $dt && $dt->format( 'd' ) !== '01' ) {
            global $pmpro_msg, $pmpro_msgt;
            $pmpro_msg = 'Discharge date must be the first day of the month.';
            $pmpro_msgt = 'pmpro_error';
            return false;
        }
    }
    
    return $continue;
}, 20 ); // Priority 20 runs after plugin's validation
```

### Email Notifications

Send admin notification when discharge date is set:

```php
/**
 * Email admin when discharge date is saved.
 */
add_action( 'pmpro_after_checkout', function( $user_id, $order ) {
    $discharge_date = get_user_meta( $user_id, '_pmpro_discharge_date', true );
    
    if ( empty( $discharge_date ) ) {
        return;
    }
    
    $user = get_userdata( $user_id );
    
    wp_mail(
        get_option( 'admin_email' ),
        'New Discharge Date Set',
        sprintf(
            "User %s (%s) set discharge date to %s\n\nMembership expires: %s",
            $user->display_name,
            $user->user_email,
            $discharge_date,
            $discharge_date . ' 23:59:59'
        )
    );
}, 20, 2 );
```

### Multiple Level Support

Extend plugin to support multiple levels:

```php
/**
 * Apply discharge date logic to multiple levels.
 */
add_filter( 'pmpro_discharge_date_level_id', function( $level_id ) {
    // Return array for multiple levels
    // Note: You'd need to modify the plugin to support array checking
    return array( 2, 5, 8 ); // Veteran, Military, Reserves
});

// Then modify plugin's is_target_level_checkout() to handle arrays:
// if ( is_array( $target_id ) ) {
//     return in_array( (int) $level->id, array_map( 'intval', $target_id ), true );
// }
```

---

## ❓ FAQ

### Can users change their discharge date after checkout?

**No.** This is by design (set-once behavior). Only administrators can modify discharge dates via the user profile edit page.

### What happens if a user deletes their membership and re-signs up?

The discharge date remains in their user meta. When they re-checkout, they will see the existing date as read-only and cannot change it.

### Does this work with recurring billing?

The plugin is designed for **non-renewable** membership levels. If you enable recurring billing on the target level, renewals may bypass the discharge date logic. See [Extending the Plugin](#extending-the-plugin) for how to add renewal support.

### Can I apply this to multiple membership levels?

Currently, the plugin targets a single level ID. To support multiple levels, you would need to modify the conditional checks to handle arrays. See [Multiple Level Support](#multiple-level-support) for guidance.

### What timezone is used for date validation?

All dates use the **WordPress timezone** set in Settings → General. The plugin uses `wp_timezone()` for consistency.

### How does end-of-day expiration work?

Discharge dates are stored as `YYYY-MM-DD` but converted to `YYYY-MM-DD 23:59:59` in the database. This ensures users have access through the entire discharge date, expiring at 11:59:59 PM.

### Can I change the 5-year limit?

Yes, use the filter:
```php
add_filter( 'pmpro_discharge_date_max_years', function() { return 10; } );
```

### What if PMPro's expiration cron runs?

PMPro's daily cron reads the `enddate` from the database. Since the plugin syncs the discharge date to `enddate`, the cron will correctly expire memberships.

### Is this compatible with PMPro add-ons?

Generally yes, but depends on the add-on:
- ✅ **PMPro Email Templates** - Works normally
- ✅ **PMPro Import/Export** - Works if import calls `pmpro_changeMembershipLevel()`
- ⚠️ **PMPro Subscription Delays** - May conflict, test thoroughly
- ⚠️ **Gateway-specific add-ons** - May need additional hooks

### How do I debug issues?

Enable WordPress debug logging:
```php
// Add to wp-config.php:
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Then check `/wp-content/debug.log` for entries starting with `[PMPro Discharge Date]`.

---

## 📝 Changelog

### Version 1.1.0 (Current)

**Changed:**
- ✅ Fixed `pmpro_url()` compatibility for PMPro < 2.5
- ✅ Removed redundant `sync_enddate_via_pmpro_api()` call in checkout
- ✅ Changed meta key to `_pmpro_discharge_date` (private)
- ✅ Optimized `template_redirect` hook with early account page check
- ✅ Increased `pmpro_calculate_enddate` priority to 999
- ✅ Added debug logging when WP_DEBUG enabled
- ✅ Made configuration filterable via `pmpro_discharge_date_level_id` and `pmpro_discharge_date_max_years`
- ✅ Replaced complex `sync_enddate_via_pmpro_api()` with direct database update
- ✅ Comprehensive inline documentation
- ✅ Added helper methods: `get_target_level_id()`, `get_max_future_years()`, `get_account_page_url()`, `log_debug()`

**Security:**
- ✅ Meta key now private (underscore prefix)
- ✅ All inputs sanitized and outputs escaped
- ✅ CSRF protection via nonces

### Version 1.0.0 (Original)

**Features:**
- ✅ Checkout field for discharge date
- ✅ Date validation (no past, max 5 years)
- ✅ Set-once enforcement
- ✅ Automatic expiration sync
- ✅ Account page display
- ✅ Admin profile editing

---

## 📄 License

This plugin is licensed under GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

---

## 🤝 Support

For bug reports, feature requests, or questions:

1. Check the [Troubleshooting](#troubleshooting) section
2. Review the [FAQ](#faq)
3. Enable debug logging and check logs
4. Contact your WordPress developer

---

## 🙏 Credits

Built for Paid Memberships Pro by [Your Name]

Special thanks to the PMPro team for their excellent plugin and documentation.
