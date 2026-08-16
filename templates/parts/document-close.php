<?php
/**
 * Closing half of a plugin-owned full document.
 *
 * Pairs with `document-open`; see that part for why the plugin renders whole
 * documents instead of borrowing the theme's header and footer.
 *
 * Takes no `$args`.
 *
 * @package GameLibrary
 */

defined( 'ABSPATH' ) || exit;

?>
	</main>
</div>
<?php wp_footer(); ?>
</body>
</html>
