<?php
/**
 * Real-estate edition — admin screens: Booking (availability + Google Calendar)
 * and Projects (gallery curation + seed-from-site). Self-contained so the edition's
 * UI doesn't entangle the shared Wisply admin class.
 */
defined( 'ABSPATH' ) || exit;

class Realestate_Admin {

	private static ?self $instance = null;
	private Wisply_Database $db;

	const DOW = [ 'ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת' ];

	private function __construct() {
		$this->db = Wisply_Database::get_instance();
		add_action( 'admin_menu', [ $this, 'menu' ], 99 );
		add_action( 'admin_post_wisply_save_booking', [ $this, 'save_booking' ] );
		add_action( 'admin_post_wisply_save_projects', [ $this, 'save_projects' ] );
		add_action( 'admin_post_wisply_seed_projects', [ $this, 'seed_projects' ] );
	}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function menu(): void {
		add_submenu_page( 'wisply-chatbot', 'פגישות', 'פגישות', 'manage_options', 'wisply-booking', [ $this, 'page_booking' ] );
		add_submenu_page( 'wisply-chatbot', 'פרויקטים', 'פרויקטים', 'manage_options', 'wisply-projects', [ $this, 'page_projects' ] );
	}

	// ─── Booking page ────────────────────────────────────────────────────────
	public function page_booking(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$gcal   = class_exists( 'Wisply_Google_Calendar' ) ? Wisply_Google_Calendar::get_instance() : null;
		$conn   = $gcal && $gcal->is_connected();
		$redir  = $gcal ? $gcal->redirect_uri() : '';
		$avail  = json_decode( (string) $this->db->get_setting( 'booking_availability', '' ), true );
		$avail  = is_array( $avail ) ? $avail : [];
		$notice = isset( $_GET['gcal'] ) ? sanitize_text_field( wp_unslash( $_GET['gcal'] ) ) : '';
		?>
		<div class="wrap">
			<h1>פגישות ויומן</h1>
			<?php if ( $notice === 'connected' ) : ?><div class="notice notice-success"><p>יומן Google חובר בהצלחה ✓</p></div>
			<?php elseif ( $notice === 'error' ) : ?><div class="notice notice-error"><p>חיבור היומן נכשל. בדקו את מזהה הלקוח/הסוד ונסו שוב.</p></div>
			<?php elseif ( $notice === 'disconnected' ) : ?><div class="notice notice-info"><p>יומן Google נותק.</p></div>
			<?php elseif ( $notice === 'saved' ) : ?><div class="notice notice-success"><p>נשמר.</p></div><?php endif; ?>

			<h2>סנכרון יומן Google</h2>
			<p>סטטוס: <strong><?php echo $conn ? 'מחובר ✓' : 'לא מחובר'; ?></strong></p>
			<p>בקונסולת Google Cloud צרו OAuth client (סוג Web), והוסיפו את כתובת ההחזרה הבאה תחת Authorized redirect URIs:</p>
			<p><code style="user-select:all"><?php echo esc_html( $redir ); ?></code></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wisply_save_booking' ); ?>
				<input type="hidden" name="action" value="wisply_save_booking">
				<table class="form-table">
					<tr><th>Google Client ID</th><td><input type="text" name="gcal_client_id" class="regular-text" value="<?php echo esc_attr( $this->db->get_setting( 'gcal_client_id', '' ) ); ?>"></td></tr>
					<tr><th>Google Client Secret</th><td><input type="password" name="gcal_client_secret" class="regular-text" value="<?php echo esc_attr( $this->db->get_setting( 'gcal_client_secret', '' ) ); ?>"></td></tr>
					<tr><th>Calendar ID</th><td><input type="text" name="gcal_calendar_id" class="regular-text" value="<?php echo esc_attr( $this->db->get_setting( 'gcal_calendar_id', 'primary' ) ); ?>"> <span class="description">primary = היומן הראשי</span></td></tr>
				</table>

				<h2>זמינות שבועית</h2>
				<p class="description">טווחי שעות לכל יום, מופרדים בפסיק. לדוגמה: <code>09:00-13:00, 16:00-19:00</code>. יום ריק = לא מקבלים פגישות.</p>
				<table class="form-table">
					<?php foreach ( self::DOW as $dow => $label ) :
						$ranges = $avail[ (string) $dow ] ?? [];
						$str = implode( ', ', array_map( fn( $r ) => ( $r[0] ?? '' ) . '-' . ( $r[1] ?? '' ), $ranges ) );
						?>
						<tr>
							<th><?php echo esc_html( $label ); ?></th>
							<td><input type="text" name="avail[<?php echo (int) $dow; ?>]" class="regular-text" value="<?php echo esc_attr( $str ); ?>" placeholder="09:00-18:00"></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2>הגדרות פגישה</h2>
				<table class="form-table">
					<tr><th>משך פגישה (דקות)</th><td><input type="number" name="booking_duration_min" value="<?php echo esc_attr( $this->db->get_setting( 'booking_duration_min', '30' ) ); ?>" min="10" step="5"></td></tr>
					<tr><th>מרווח בין פגישות (דקות)</th><td><input type="number" name="booking_buffer_min" value="<?php echo esc_attr( $this->db->get_setting( 'booking_buffer_min', '15' ) ); ?>" min="0" step="5"></td></tr>
					<tr><th>כמה ימים קדימה</th><td><input type="number" name="booking_days_ahead" value="<?php echo esc_attr( $this->db->get_setting( 'booking_days_ahead', '14' ) ); ?>" min="1" max="60"></td></tr>
					<tr><th>אימייל להתראות</th><td><input type="email" name="booking_notify_email" class="regular-text" value="<?php echo esc_attr( $this->db->get_setting( 'booking_notify_email', '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td></tr>
				</table>
				<?php submit_button( 'שמירה' ); ?>
			</form>

			<h2>חיבור / ניתוק יומן</h2>
			<?php if ( $conn ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'wisply_gcal_disconnect' ); ?>
					<input type="hidden" name="action" value="wisply_gcal_disconnect">
					<?php submit_button( 'ניתוק יומן Google', 'secondary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin-post.php?action=wisply_gcal_connect' ) ); ?>">חיבור יומן Google</a>
				<p class="description">שמרו קודם את מזהה הלקוח והסוד, ואז חברו.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function save_booking(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden' );
		check_admin_referer( 'wisply_save_booking' );

		$this->db->set_setting( 'gcal_client_id', sanitize_text_field( wp_unslash( $_POST['gcal_client_id'] ?? '' ) ) );
		// Only overwrite the secret when a new value is typed (the field may be left as-is).
		$secret = (string) wp_unslash( $_POST['gcal_client_secret'] ?? '' );
		if ( $secret !== '' ) $this->db->set_setting( 'gcal_client_secret', sanitize_text_field( $secret ) );
		$this->db->set_setting( 'gcal_calendar_id', sanitize_text_field( wp_unslash( $_POST['gcal_calendar_id'] ?? 'primary' ) ) );

		// Availability: parse "HH:MM-HH:MM, HH:MM-HH:MM" per day into the JSON shape.
		$avail_in = (array) ( $_POST['avail'] ?? [] );
		$week = [];
		foreach ( $avail_in as $dow => $str ) {
			$dow = (int) $dow;
			$ranges = [];
			foreach ( explode( ',', (string) $str ) as $part ) {
				$part = trim( $part );
				if ( preg_match( '/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $part, $m ) ) {
					$ranges[] = [ $m[1], $m[2] ];
				}
			}
			if ( $ranges ) $week[ (string) $dow ] = $ranges;
		}
		$this->db->set_setting( 'booking_availability', wp_json_encode( $week ) );

		$this->db->set_setting( 'booking_duration_min', (string) max( 10, (int) ( $_POST['booking_duration_min'] ?? 30 ) ) );
		$this->db->set_setting( 'booking_buffer_min', (string) max( 0, (int) ( $_POST['booking_buffer_min'] ?? 0 ) ) );
		$this->db->set_setting( 'booking_days_ahead', (string) min( 60, max( 1, (int) ( $_POST['booking_days_ahead'] ?? 14 ) ) ) );
		$email = sanitize_email( wp_unslash( $_POST['booking_notify_email'] ?? '' ) );
		$this->db->set_setting( 'booking_notify_email', $email );

		wp_safe_redirect( admin_url( 'admin.php?page=wisply-booking&gcal=saved' ) );
		exit;
	}

	// ─── Projects page ───────────────────────────────────────────────────────
	public function page_projects(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$projects = class_exists( 'Wisply_Projects' ) ? Wisply_Projects::get_instance()->get_projects() : [];
		$notice   = isset( $_GET['pr'] ) ? sanitize_text_field( wp_unslash( $_GET['pr'] ) ) : '';
		// A spare blank row so there's always somewhere to add a new project.
		$projects[] = [ 'id' => '', 'name' => '', 'location' => '', 'price' => '', 'image' => '', 'url' => '', 'enabled' => true ];
		?>
		<div class="wrap">
			<h1>פרויקטים</h1>
			<?php if ( $notice === 'saved' ) : ?><div class="notice notice-success"><p>נשמר.</p></div>
			<?php elseif ( preg_match( '/^seeded-(\d+)$/', $notice, $m ) ) : ?><div class="notice notice-success"><p>נוספו <?php echo (int) $m[1]; ?> פרויקטים מהאתר.</p></div><?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=wisply_seed_projects' ) ); ?>" style="margin-bottom:12px">
				<?php wp_nonce_field( 'wisply_seed_projects' ); ?>
				<button class="button">משיכה אוטומטית מהאתר</button>
				<span class="description">מוסיף כל עמוד/פוסט עם תמונה ראשית כפרויקט. לא דורס פרויקטים קיימים.</span>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wisply_save_projects' ); ?>
				<input type="hidden" name="action" value="wisply_save_projects">
				<table class="widefat striped">
					<thead><tr><th>פעיל</th><th>שם</th><th>מיקום</th><th>מחיר</th><th>תמונה (URL)</th><th>קישור</th></tr></thead>
					<tbody>
					<?php foreach ( $projects as $i => $p ) : ?>
						<tr>
							<td><input type="checkbox" name="p[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( ! empty( $p['enabled'] ) ); ?>></td>
							<td><input type="text" name="p[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $p['name'] ?? '' ); ?>" class="regular-text"></td>
							<td><input type="text" name="p[<?php echo (int) $i; ?>][location]" value="<?php echo esc_attr( $p['location'] ?? '' ); ?>"></td>
							<td><input type="text" name="p[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( $p['price'] ?? '' ); ?>"></td>
							<td><input type="url" name="p[<?php echo (int) $i; ?>][image]" value="<?php echo esc_attr( $p['image'] ?? '' ); ?>" class="regular-text"></td>
							<td><input type="url" name="p[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $p['url'] ?? '' ); ?>" class="regular-text"></td>
							<input type="hidden" name="p[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $p['id'] ?? '' ); ?>">
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( 'שמירה' ); ?>
			</form>
		</div>
		<?php
	}

	public function save_projects(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden' );
		check_admin_referer( 'wisply_save_projects' );
		$rows = (array) ( $_POST['p'] ?? [] );
		$clean = [];
		foreach ( $rows as $r ) {
			$clean[] = [
				'id'       => sanitize_text_field( (string) ( $r['id'] ?? '' ) ),
				'name'     => (string) ( $r['name'] ?? '' ),
				'location' => (string) ( $r['location'] ?? '' ),
				'price'    => (string) ( $r['price'] ?? '' ),
				'image'    => (string) ( $r['image'] ?? '' ),
				'url'      => (string) ( $r['url'] ?? '' ),
				'enabled'  => ! empty( $r['enabled'] ),
			];
		}
		Wisply_Projects::get_instance()->save_projects( $clean );
		wp_safe_redirect( admin_url( 'admin.php?page=wisply-projects&pr=saved' ) );
		exit;
	}

	public function seed_projects(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden' );
		check_admin_referer( 'wisply_seed_projects' );
		$n = Wisply_Projects::get_instance()->seed_from_site();
		wp_safe_redirect( admin_url( 'admin.php?page=wisply-projects&pr=seeded-' . (int) $n ) );
		exit;
	}
}
