<?php
/**
 * Uninstall handler — opt-in data removal (AC-051).
 *
 * Deleting the plugin removes nothing unless a site owner ticked the
 * data-removal box on the settings screen: without
 * `gamelib_uninstall_remove_data` set to `1` this file exits silently and
 * every table, option, transient, user meta value, and game post survives
 * (AC-051c). With the opt-in set, it removes exactly what AC-051(b)
 * enumerates: every table `GameLib_Schema` declares (eight today), every
 * `gamelib_*` option and transient, the plugin's user meta, and every
 * `glib_game` post.
 *
 * One honest limit on "every transient" (VIP-6). Transients are found by a
 * `LIKE` over `wp_options`, and an install with an external object cache — VIP,
 * or any site running Memcached or Redis — writes no options row for one at
 * all. The two whose keys are fixed literals (`gamelib_igdb_token`,
 * `gamelib_ext_source`) are therefore deleted unconditionally by name, before
 * the sweep. The prefixed ones (`gamelib_search_*`, `gamelib_search_fail_*`,
 * `gamelib_owned_*`) cannot be enumerated on such an install — Memcached
 * exposes no key listing — and are left to expire on their own TTLs, the
 * longest of which is thirty minutes.
 *
 * The plugin's own code is not loaded here — WordPress includes this file on
 * its own — so nothing may reference a `GameLib_*` class or a `GAMELIB_*`
 * constant without requiring it first. One file is required below for exactly
 * that reason: {@see GameLib_Schema} declares itself the single source of table
 * names for the whole plugin, and a second hand-maintained list here would
 * falsify that claim — a ninth table would be created and migrated, then
 * silently left behind on uninstall (AC-051b). Meta keys stay literals: their
 * owning classes are not include-safe in this context.
 *
 * Every deletion runs in batches of 500: at the C-REQ-17 volume band
 * (activity rows in the hundreds of thousands, and one game post per game a
 * member ever added) an unbounded query would exhaust memory or the request
 * timeout and leave the teardown half-finished.
 *
 * @package GameLibrary
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// AC-051(c): unset or '0' means remove nothing at all.
if ( '1' !== (string) get_option( 'gamelib_uninstall_remove_data', '0' ) ) {
	return;
}

/*
 * The table list, from the class that owns it. Safe to include here: the file
 * declares one class and nothing else — no file-scope `add_action()`, no
 * `register_*()` call — and needs only ABSPATH, which is defined throughout
 * uninstallation. Nothing below calls a method that touches `GAMELIB_*`.
 */
require_once __DIR__ . '/includes/class-schema.php';

global $wpdb;

/** Rows handled per pass. */
$gamelib_batch = 500;

/*
 * Upper bound on passes per deletion loop — a stop against an unexpected
 * non-deleting query, not a capacity plan.
 *
 * At 500 rows a pass this ceiling is 10 million rows in one request, which no
 * request will reach (PB-3): uninstallation is a deliberate administrator
 * action on a plugin whose code is about to stop loading, it has no member
 * waiting on a response, and — unlike the eraser, which pages because core
 * calls it again — there is no second entry point that could resume it. A
 * teardown interrupted by a timeout leaves rows in tables the site no longer
 * reads; re-running the uninstall finishes the job, because every loop below
 * re-selects from the current state rather than from a stored cursor.
 */
$gamelib_max_passes = 20000;

/*
 * 1. Game posts.
 *
 * wp_delete_post() (force delete) is used rather than raw SQL so postmeta,
 * term relationships, and the object cache go with each post. Every status is
 * in scope, trashed posts included.
 */
$gamelib_pass = 0;

do {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot teardown of a post type whose plugin is no longer loaded; caching a list that is being deleted is pointless.
	$gamelib_post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT %d",
			'glib_game',
			$gamelib_batch
		)
	);

	foreach ( $gamelib_post_ids as $gamelib_post_id ) {
		wp_delete_post( (int) $gamelib_post_id, true );
	}

	++$gamelib_pass;
} while ( count( $gamelib_post_ids ) === $gamelib_batch && $gamelib_pass < $gamelib_max_passes );

/*
 * 2. User meta.
 *
 * Each pass takes the lowest umeta_id rows for one key and deletes exactly
 * that window (`umeta_id <= ` the batch's highest id), which keeps the DELETE
 * fully prepared — no interpolated id list.
 */
$gamelib_meta_keys = array( 'gamelib_visibility', 'gamelib_steamid', 'gamelib_invites_disabled' );

foreach ( $gamelib_meta_keys as $gamelib_meta_key ) {
	$gamelib_pass = 0;

	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot teardown; delete_metadata() cannot batch and would load every row at once.
		$gamelib_meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, user_id FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY umeta_id ASC LIMIT %d",
				$gamelib_meta_key,
				$gamelib_batch
			)
		);

		if ( empty( $gamelib_meta_rows ) ) {
			break;
		}

		$gamelib_last_meta_id = 0;

		foreach ( $gamelib_meta_rows as $gamelib_meta_row ) {
			$gamelib_last_meta_id = max( $gamelib_last_meta_id, (int) $gamelib_meta_row->umeta_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot teardown of the rows just selected.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s AND umeta_id <= %d",
				$gamelib_meta_key,
				$gamelib_last_meta_id
			)
		);

		/*
		 * Core's own user-meta cache group, not a plugin cache: the rows it
		 * mirrors have just been deleted underneath it. (The plugin's object
		 * cache discipline lives in GameLib_Cache, which is not loaded here.)
		 */
		foreach ( $gamelib_meta_rows as $gamelib_meta_row ) {
			wp_cache_delete( (int) $gamelib_meta_row->user_id, 'user_meta' );
		}

		++$gamelib_pass;
	} while ( count( $gamelib_meta_rows ) === $gamelib_batch && $gamelib_pass < $gamelib_max_passes );
}

/*
 * 3. Tables.
 *
 * Fixed identifiers, already carrying $wpdb->prefix — GameLib_Schema::tables()
 * returns fully-qualified names, so nothing is concatenated here. This is the
 * one place in the plugin where a query is not run through $wpdb->prepare(),
 * because a table name is an identifier and prepare() only binds values.
 */
foreach ( GameLib_Schema::tables() as $gamelib_table_name ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- DROP TABLE takes an identifier, which prepare() cannot bind; the name is $wpdb->prefix plus a class constant from GameLib_Schema::TABLES, never user input.
	$wpdb->query( "DROP TABLE IF EXISTS {$gamelib_table_name}" );
}

/*
 * 4a. The two transients whose keys are literals.
 *
 * Deleted unconditionally, before the sweep below, because the sweep cannot
 * reach them on an object-cache-backed install (VIP-6): with an external object
 * cache `set_transient()` writes no options row at all, so a `LIKE` over
 * `wp_options` returns nothing and AC-051(b)'s "deletes all gamelib_*
 * transients" would silently be unmet. These two need no enumeration.
 */
delete_transient( 'gamelib_igdb_token' );
delete_transient( 'gamelib_ext_source' );

/*
 * 4b. Options and transients.
 *
 * A prefix sweep rather than a hand-maintained list, so every `gamelib_*`
 * option — including ones added after this file was written — is covered.
 * Transients are removed through delete_transient()/delete_site_transient()
 * so an external object cache drops its copy too, not just the options table.
 *
 * Scope limit, stated rather than implied: the *prefixed* transients
 * (`gamelib_search_*`, `gamelib_search_fail_*`, `gamelib_owned_*`) are found
 * only where they have an options row. On an object-cache-backed install they
 * are left to expire on their own TTLs — 15, 1.5 and 30 minutes — because
 * Memcached exposes no key enumeration and there is no list of the keys that
 * were written. Nothing reads them once the plugin's files are gone.
 */
$gamelib_option_patterns = array(
	$wpdb->esc_like( '_transient_gamelib_' ) . '%',
	$wpdb->esc_like( '_site_transient_gamelib_' ) . '%',
	$wpdb->esc_like( 'gamelib_' ) . '%',
);

foreach ( $gamelib_option_patterns as $gamelib_pattern ) {
	$gamelib_pass = 0;

	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot teardown; option names are not enumerable through any core API.
		$gamelib_option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
				$gamelib_pattern,
				$gamelib_batch
			)
		);

		foreach ( $gamelib_option_names as $gamelib_option_name ) {
			if ( 0 === strpos( $gamelib_option_name, '_transient_' ) ) {
				// Also removes the paired _transient_timeout_* row.
				delete_transient( substr( $gamelib_option_name, strlen( '_transient_' ) ) );
			} elseif ( 0 === strpos( $gamelib_option_name, '_site_transient_' ) ) {
				delete_site_transient( substr( $gamelib_option_name, strlen( '_site_transient_' ) ) );
			} else {
				delete_option( $gamelib_option_name );
			}
		}

		++$gamelib_pass;
	} while ( count( $gamelib_option_names ) === $gamelib_batch && $gamelib_pass < $gamelib_max_passes );
}

/*
 * Timeout rows for any transient whose value row had already expired away are
 * left behind by the pass above; sweep them separately.
 */
$gamelib_timeout_patterns = array(
	$wpdb->esc_like( '_transient_timeout_gamelib_' ) . '%',
	$wpdb->esc_like( '_site_transient_timeout_gamelib_' ) . '%',
);

foreach ( $gamelib_timeout_patterns as $gamelib_pattern ) {
	$gamelib_pass = 0;

	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot teardown; option names are not enumerable through any core API.
		$gamelib_option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
				$gamelib_pattern,
				$gamelib_batch
			)
		);

		foreach ( $gamelib_option_names as $gamelib_option_name ) {
			delete_option( $gamelib_option_name );
		}

		++$gamelib_pass;
	} while ( count( $gamelib_option_names ) === $gamelib_batch && $gamelib_pass < $gamelib_max_passes );
}

/*
 * Member capabilities are deliberately left alone here: AC-051(b) enumerates
 * tables, options/transients, user meta, and game posts, and the capability
 * grants are owned by GameLib_Capabilities (T3), which also owns their
 * removal.
 */
