<?php
/**
 * Plugin Name:       KI-Kennzeichnung für Medien
 * Plugin URI:        https://example.com/
 * Description:       Markiert Bilder und Medien in der Mediathek als KI-generiert und blendet im Frontend automatisch einen Hinweis ein – dauerhaft, beim Hover oder als Bildunterschrift.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            —
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ki-kennzeichnung
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIKZ_Plugin {

	const VERSION   = '1.2.0';
	const META_FLAG = '_aikz_is_ai';
	const META_TEXT = '_aikz_text';
	const OPTION    = 'aikz_settings';

	/** @var AIKZ_Plugin|null */
	private static $instance = null;

	/** @var array|null */
	private $settings = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// --- Meta / REST -----------------------------------------------------
		add_action( 'init', array( $this, 'register_meta' ) );

		// --- Mediathek: Felder im Detail-/Modalbereich -----------------------
		add_filter( 'attachment_fields_to_edit', array( $this, 'fields_to_edit' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'fields_to_save' ), 10, 2 );

		// --- Mediathek: Listenansicht (Spalte, Bulk, Filter) -----------------
		add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'restrict_manage_posts', array( $this, 'media_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( $this, 'media_filter_query' ) );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_action( 'wp_ajax_aikz_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// --- Frontend --------------------------------------------------------
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 5 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_image_attributes' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );

		// --- Einstellungen ---------------------------------------------------
		add_action( 'admin_menu', array( $this, 'settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
	}

	/* =====================================================================
	 * Einstellungen
	 * ===================================================================== */

	public function defaults() {
		return array(
			'label'          => 'KI-generiert',
			'mode'           => 'always',         // always | both | caption | hover
			'layout'         => 'auto',           // auto | fill | nowrap
			'position'       => 'bottom-right',   // top-left | top-right | bottom-left | bottom-right
			'bg'             => '#000000',
			'opacity'        => 72,
			'color'          => '#ffffff',
			'font_size'      => 12,
			'radius'         => 4,
			'icon'           => 1,
			'apply_content'  => 1,   // Bilder im Beitragsinhalt
			'apply_template' => 1,   // wp_get_attachment_image() / Beitragsbilder
			'alt_suffix'     => 0,   // Hinweis an alt-Attribut anhängen
		);
	}

	public function settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
		}
		return $this->settings;
	}

	public function register_settings() {
		register_setting(
			'aikz_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->defaults(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$d   = $this->defaults();
		$out = array();

		$out['label'] = isset( $input['label'] ) ? sanitize_text_field( $input['label'] ) : $d['label'];
		if ( '' === $out['label'] ) {
			$out['label'] = $d['label'];
		}

		$out['mode']     = in_array( $input['mode'] ?? '', array( 'always', 'both', 'caption', 'hover' ), true ) ? $input['mode'] : $d['mode'];
		$out['layout']   = in_array( $input['layout'] ?? '', array( 'auto', 'fill', 'nowrap' ), true ) ? $input['layout'] : $d['layout'];
		$out['position'] = in_array( $input['position'] ?? '', array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ), true ) ? $input['position'] : $d['position'];

		$out['bg']    = sanitize_hex_color( $input['bg'] ?? '' ) ?: $d['bg'];
		$out['color'] = sanitize_hex_color( $input['color'] ?? '' ) ?: $d['color'];

		$out['opacity']   = max( 0, min( 100, (int) ( $input['opacity'] ?? $d['opacity'] ) ) );
		$out['font_size'] = max( 8, min( 32, (int) ( $input['font_size'] ?? $d['font_size'] ) ) );
		$out['radius']    = max( 0, min( 40, (int) ( $input['radius'] ?? $d['radius'] ) ) );

		foreach ( array( 'icon', 'apply_content', 'apply_template', 'alt_suffix' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		return $out;
	}

	/**
	 * Einmalige Migration: Der Hover-Modus war bis Version 1.0.0 Standard, erfüllt
	 * eine Kennzeichnungspflicht aber nicht. Bestehende Installationen werden
	 * einmalig auf die dauerhaft sichtbare Variante umgestellt.
	 */
	public function maybe_upgrade() {
		$installed = get_option( 'aikz_version', '1.0.0' );
		if ( version_compare( $installed, self::VERSION, '>=' ) ) {
			return;
		}

		if ( version_compare( $installed, '1.1.0', '<' ) ) {
			$saved = get_option( self::OPTION, array() );
			if ( is_array( $saved ) && isset( $saved['mode'] ) && 'hover' === $saved['mode'] ) {
				$saved['mode'] = 'always';
				update_option( self::OPTION, $saved );
				set_transient( 'aikz_upgrade_notice', 1, HOUR_IN_SECONDS * 12 );
			}
		}

		update_option( 'aikz_version', self::VERSION );
	}

	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=ki-kennzeichnung' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Einstellungen', 'ki-kennzeichnung' ) . '</a>' );
		return $links;
	}

	public function settings_page() {
		add_options_page(
			__( 'KI-Kennzeichnung', 'ki-kennzeichnung' ),
			__( 'KI-Kennzeichnung', 'ki-kennzeichnung' ),
			'manage_options',
			'ki-kennzeichnung',
			array( $this, 'render_settings_page' )
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = $this->settings();
		$n = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'KI-Kennzeichnung für Medien', 'ki-kennzeichnung' ); ?></h1>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Medien werden in der Mediathek einzeln als KI-generiert markiert. Der Hinweis wird danach im Frontend automatisch über dem Bild eingeblendet.', 'ki-kennzeichnung' ); ?>
			</p>

			<?php if ( 'hover' === $s['mode'] ) : ?>
				<div class="notice notice-warning inline" style="margin:15px 0;max-width:46em">
					<p>
						<strong><?php esc_html_e( 'Aktuell wird der Hinweis nur beim Hover angezeigt.', 'ki-kennzeichnung' ); ?></strong><br>
						<?php esc_html_e( 'Eine Kennzeichnung, die erst durch Mausbewegung sichtbar wird, ist nicht „ohne Weiteres erkennbar“ und erfüllt eine bestehende Kennzeichnungspflicht nicht. Auf Touch-Geräten gibt es zudem gar kein Hover. Wähle unten „Dauerhaft im Bild sichtbar“.', 'ki-kennzeichnung' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields( 'aikz_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Anzeige', 'ki-kennzeichnung' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aikz_label"><?php esc_html_e( 'Standard-Hinweistext', 'ki-kennzeichnung' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="aikz_label" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>">
							<p class="description"><?php esc_html_e( 'Kann pro Medium in der Mediathek überschrieben werden. Üblich: „KI-generiert“, „Mit KI erstellt“, „Bild: KI-generiert“.', 'ki-kennzeichnung' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Darstellung', 'ki-kennzeichnung' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[mode]" value="always" <?php checked( $s['mode'], 'always' ); ?>> <strong><?php esc_html_e( 'Dauerhaft im Bild sichtbar', 'ki-kennzeichnung' ); ?></strong> <?php esc_html_e( '(empfohlen)', 'ki-kennzeichnung' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[mode]" value="both" <?php checked( $s['mode'], 'both' ); ?>> <?php esc_html_e( 'Dauerhaft im Bild + Textzeile darunter', 'ki-kennzeichnung' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[mode]" value="caption" <?php checked( $s['mode'], 'caption' ); ?>> <?php esc_html_e( 'Nur Textzeile unter dem Bild', 'ki-kennzeichnung' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[mode]" value="hover" <?php checked( $s['mode'], 'hover' ); ?>> <?php esc_html_e( 'Nur beim Hover / Tastaturfokus', 'ki-kennzeichnung' ); ?> — <span style="color:#b32d2e"><?php esc_html_e( 'nicht für die Kennzeichnungspflicht geeignet', 'ki-kennzeichnung' ); ?></span></label>
							</fieldset>
							<p class="description">
								<?php esc_html_e( 'Wo eine Kennzeichnungspflicht besteht, muss der Hinweis ohne Zutun der Nutzer erkennbar sein. Der Hover-Modus versteckt ihn bis zur Mausbewegung und ist deshalb nur für rein dekorative Zwecke gedacht.', 'ki-kennzeichnung' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Einbindung ins Layout', 'ki-kennzeichnung' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[layout]" value="auto" <?php checked( $s['layout'], 'auto' ); ?>> <strong><?php esc_html_e( 'Automatisch', 'ki-kennzeichnung' ); ?></strong> — <?php esc_html_e( 'Hinweis-Container passt sich dem Bild an (Standard)', 'ki-kennzeichnung' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[layout]" value="fill" <?php checked( $s['layout'], 'fill' ); ?>> <?php esc_html_e( 'Container füllen', 'ki-kennzeichnung' ); ?> — <?php esc_html_e( 'für Karten, Slider und Raster mit fester Bildhöhe', 'ki-kennzeichnung' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[layout]" value="nowrap" <?php checked( $s['layout'], 'nowrap' ); ?>> <?php esc_html_e( 'Ohne Container (JavaScript)', 'ki-kennzeichnung' ); ?> — <?php esc_html_e( 'Bild bleibt unverändert im HTML, Layout wird garantiert nicht beeinflusst', 'ki-kennzeichnung' ); ?></label>
							</fieldset>
							<p class="description">
								<?php esc_html_e( 'Ändert sich die Bildgröße durch die Kennzeichnung, liegt es an diesem Container. Reihenfolge zum Ausprobieren: „Automatisch“ → „Container füllen“ → „Ohne Container“.', 'ki-kennzeichnung' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aikz_pos"><?php esc_html_e( 'Position im Bild', 'ki-kennzeichnung' ); ?></label></th>
						<td>
							<select id="aikz_pos" name="<?php echo esc_attr( $n ); ?>[position]">
								<?php
								$positions = array(
									'top-left'     => __( 'oben links', 'ki-kennzeichnung' ),
									'top-right'    => __( 'oben rechts', 'ki-kennzeichnung' ),
									'bottom-left'  => __( 'unten links', 'ki-kennzeichnung' ),
									'bottom-right' => __( 'unten rechts', 'ki-kennzeichnung' ),
								);
								foreach ( $positions as $key => $labelText ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $key ),
										selected( $s['position'], $key, false ),
										esc_html( $labelText )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Optik', 'ki-kennzeichnung' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Hintergrund', 'ki-kennzeichnung' ); ?>
								<input type="color" name="<?php echo esc_attr( $n ); ?>[bg]" value="<?php echo esc_attr( $s['bg'] ); ?>">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'Deckkraft %', 'ki-kennzeichnung' ); ?>
								<input type="number" min="0" max="100" step="1" style="width:5em" name="<?php echo esc_attr( $n ); ?>[opacity]" value="<?php echo esc_attr( $s['opacity'] ); ?>">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'Schrift', 'ki-kennzeichnung' ); ?>
								<input type="color" name="<?php echo esc_attr( $n ); ?>[color]" value="<?php echo esc_attr( $s['color'] ); ?>">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'Größe px', 'ki-kennzeichnung' ); ?>
								<input type="number" min="8" max="32" step="1" style="width:5em" name="<?php echo esc_attr( $n ); ?>[font_size]" value="<?php echo esc_attr( $s['font_size'] ); ?>">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'Radius px', 'ki-kennzeichnung' ); ?>
								<input type="number" min="0" max="40" step="1" style="width:5em" name="<?php echo esc_attr( $n ); ?>[radius]" value="<?php echo esc_attr( $s['radius'] ); ?>">
							</label>
							<p>
								<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[icon]" value="1" <?php checked( $s['icon'], 1 ); ?>> <?php esc_html_e( 'Symbol vor dem Text anzeigen', 'ki-kennzeichnung' ); ?></label>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Wo soll der Hinweis erscheinen?', 'ki-kennzeichnung' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Ausgabeorte', 'ki-kennzeichnung' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[apply_content]" value="1" <?php checked( $s['apply_content'], 1 ); ?>> <?php esc_html_e( 'Bilder im Beitrags-/Seiteninhalt (Blöcke, Galerien)', 'ki-kennzeichnung' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[apply_template]" value="1" <?php checked( $s['apply_template'], 1 ); ?>> <?php esc_html_e( 'Beitragsbilder und Theme-Ausgaben (wp_get_attachment_image)', 'ki-kennzeichnung' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[alt_suffix]" value="1" <?php checked( $s['alt_suffix'], 1 ); ?>> <?php esc_html_e( 'Hinweis zusätzlich an das alt-Attribut anhängen', 'ki-kennzeichnung' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Vorschau', 'ki-kennzeichnung' ); ?></h2>
				<p class="description"><?php esc_html_e( 'So sieht der Hinweis mit den gespeicherten Einstellungen aus:', 'ki-kennzeichnung' ); ?></p>
				<div style="background:#f0f0f1;padding:20px;display:inline-block;border:1px solid #dcdcde">
					<style><?php echo $this->css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
					<span class="aikz-wrap aikz-mode-<?php echo esc_attr( $s['mode'] ); ?> aikz-pos-<?php echo esc_attr( $s['position'] ); ?>">
						<span style="display:block;width:320px;height:180px;background:linear-gradient(135deg,#7c8ea0,#3d4d5c)"></span>
						<?php
						if ( 'caption' !== $s['mode'] ) {
							echo $this->badge_html( 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						if ( in_array( $s['mode'], array( 'caption', 'both' ), true ) ) {
							echo $this->caption_html( 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</span>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* =====================================================================
	 * Meta-Registrierung
	 * ===================================================================== */

	public function register_meta() {
		$auth = function ( $allowed, $meta_key, $object_id ) {
			return current_user_can( 'edit_post', $object_id );
		};

		register_post_meta(
			'attachment',
			self::META_FLAG,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			'attachment',
			self::META_TEXT,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth,
			)
		);
	}

	public function is_ai( $attachment_id ) {
		$flag = '1' === get_post_meta( (int) $attachment_id, self::META_FLAG, true );
		/**
		 * Filter: Ist dieses Medium als KI-generiert zu kennzeichnen?
		 */
		return (bool) apply_filters( 'aikz_is_ai', $flag, (int) $attachment_id );
	}

	public function label_for( $attachment_id ) {
		$text = (string) get_post_meta( (int) $attachment_id, self::META_TEXT, true );
		if ( '' === trim( $text ) ) {
			$text = $this->settings()['label'];
		}
		return apply_filters( 'aikz_label', $text, (int) $attachment_id );
	}

	/* =====================================================================
	 * Mediathek – Felder im Anhang-Detail (Modal + Bearbeiten-Seite)
	 * ===================================================================== */

	public function fields_to_edit( $form_fields, $post ) {
		$id    = (int) $post->ID;
		$is_ai = $this->is_ai( $id );
		$text  = (string) get_post_meta( $id, self::META_TEXT, true );

		$form_fields['aikz_is_ai'] = array(
			'label' => __( 'KI-Kennzeichnung', 'ki-kennzeichnung' ),
			'input' => 'html',
			'html'  => sprintf(
				'<input type="hidden" name="attachments[%1$d][aikz_is_ai]" value="0" />
				 <label style="display:block"><input type="checkbox" name="attachments[%1$d][aikz_is_ai]" value="1" %2$s /> %3$s</label>',
				$id,
				checked( $is_ai, true, false ),
				esc_html__( 'Mit KI erstellt oder verändert', 'ki-kennzeichnung' )
			),
			'helps' => __( 'Blendet im Frontend automatisch den KI-Hinweis ein.', 'ki-kennzeichnung' ),
		);

		$form_fields['aikz_text'] = array(
			'label' => __( 'Eigener Hinweistext', 'ki-kennzeichnung' ),
			'input' => 'text',
			'value' => $text,
			'helps' => sprintf(
				/* translators: %s: default label */
				__( 'Optional. Leer lassen für den Standardtext: „%s“.', 'ki-kennzeichnung' ),
				esc_html( $this->settings()['label'] )
			),
		);

		return $form_fields;
	}

	public function fields_to_save( $post, $attachment ) {
		$id = (int) $post['ID'];

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}

		if ( isset( $attachment['aikz_is_ai'] ) ) {
			if ( '1' === (string) $attachment['aikz_is_ai'] ) {
				update_post_meta( $id, self::META_FLAG, '1' );
			} else {
				delete_post_meta( $id, self::META_FLAG );
			}
		}

		if ( isset( $attachment['aikz_text'] ) ) {
			$text = sanitize_text_field( $attachment['aikz_text'] );
			if ( '' === $text ) {
				delete_post_meta( $id, self::META_TEXT );
			} else {
				update_post_meta( $id, self::META_TEXT, $text );
			}
		}

		return $post;
	}

	/* =====================================================================
	 * Mediathek – Listenansicht
	 * ===================================================================== */

	public function media_column( $columns ) {
		$columns['aikz'] = __( 'KI', 'ki-kennzeichnung' );
		return $columns;
	}

	public function media_column_content( $column, $post_id ) {
		if ( 'aikz' !== $column ) {
			return;
		}
		$is_ai = $this->is_ai( $post_id );
		$can   = current_user_can( 'edit_post', $post_id );

		printf(
			'<button type="button" class="button-link aikz-toggle %1$s" data-id="%2$d" %3$s title="%4$s" aria-pressed="%5$s">%6$s</button>',
			$is_ai ? 'is-ai' : '',
			(int) $post_id,
			disabled( $can, false, false ),
			esc_attr__( 'KI-Kennzeichnung umschalten', 'ki-kennzeichnung' ),
			$is_ai ? 'true' : 'false',
			$is_ai ? '<span class="dashicons dashicons-yes-alt"></span>' : '<span class="dashicons dashicons-minus"></span>'
		);
	}

	public function bulk_actions( $actions ) {
		$actions['aikz_mark']   = __( 'Als KI-generiert markieren', 'ki-kennzeichnung' );
		$actions['aikz_unmark'] = __( 'KI-Kennzeichnung entfernen', 'ki-kennzeichnung' );
		return $actions;
	}

	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( 'aikz_mark' !== $action && 'aikz_unmark' !== $action ) {
			return $redirect_to;
		}

		$count = 0;
		foreach ( (array) $post_ids as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( 'aikz_mark' === $action ) {
				update_post_meta( $id, self::META_FLAG, '1' );
			} else {
				delete_post_meta( $id, self::META_FLAG );
			}
			$count++;
		}

		return add_query_arg( 'aikz_done', $count, $redirect_to );
	}

	public function bulk_notice() {
		if ( get_transient( 'aikz_upgrade_notice' ) && current_user_can( 'manage_options' ) ) {
			delete_transient( 'aikz_upgrade_notice' );
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'KI-Kennzeichnung: Die Anzeige wurde von „nur beim Hover“ auf „dauerhaft im Bild sichtbar“ umgestellt, damit der Hinweis ohne Zutun der Besucher erkennbar ist.', 'ki-kennzeichnung' ),
				esc_url( admin_url( 'options-general.php?page=ki-kennzeichnung' ) ),
				esc_html__( 'Einstellungen prüfen', 'ki-kennzeichnung' )
			);
		}

		if ( ! isset( $_GET['aikz_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$count = (int) $_GET['aikz_done']; // phpcs:ignore WordPress.Security.NonceVerification
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( _n( '%d Medium aktualisiert.', '%d Medien aktualisiert.', $count, 'ki-kennzeichnung' ), $count ) )
		);
	}

	public function media_filter_dropdown() {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		$current = isset( $_GET['aikz_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['aikz_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<select name="aikz_filter">
			<option value=""><?php esc_html_e( 'KI-Status: alle', 'ki-kennzeichnung' ); ?></option>
			<option value="ai" <?php selected( $current, 'ai' ); ?>><?php esc_html_e( 'Nur KI-generierte', 'ki-kennzeichnung' ); ?></option>
			<option value="noai" <?php selected( $current, 'noai' ); ?>><?php esc_html_e( 'Nur nicht gekennzeichnete', 'ki-kennzeichnung' ); ?></option>
		</select>
		<?php
	}

	public function media_filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		$filter = isset( $_GET['aikz_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['aikz_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'ai' === $filter ) {
			$query->set( 'meta_query', array( array( 'key' => self::META_FLAG, 'value' => '1' ) ) );
		} elseif ( 'noai' === $filter ) {
			$query->set( 'meta_query', array( array( 'key' => self::META_FLAG, 'compare' => 'NOT EXISTS' ) ) );
		}
	}

	public function ajax_toggle() {
		check_ajax_referer( 'aikz_toggle', 'nonce' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'ki-kennzeichnung' ) ), 403 );
		}

		if ( $this->is_ai( $id ) ) {
			delete_post_meta( $id, self::META_FLAG );
			$state = false;
		} else {
			update_post_meta( $id, self::META_FLAG, '1' );
			$state = true;
		}

		wp_send_json_success( array( 'is_ai' => $state ) );
	}

	public function admin_assets( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}

		wp_register_style( 'aikz-admin', false, array(), self::VERSION );
		wp_enqueue_style( 'aikz-admin' );
		wp_add_inline_style(
			'aikz-admin',
			'.column-aikz{width:52px;text-align:center}
			 .aikz-toggle{cursor:pointer;color:#a7aaad}
			 .aikz-toggle.is-ai{color:#2271b1}
			 .aikz-toggle[disabled]{cursor:default;opacity:.5}
			 .aikz-toggle.is-busy{opacity:.4}'
		);

		wp_register_script( 'aikz-admin', false, array( 'jquery' ), self::VERSION, true );
		wp_enqueue_script( 'aikz-admin' );
		wp_add_inline_script(
			'aikz-admin',
			'jQuery(function($){
				$(document).on("click",".aikz-toggle",function(){
					var $b=$(this);
					if($b.prop("disabled")||$b.hasClass("is-busy"))return;
					$b.addClass("is-busy");
					$.post(ajaxurl,{action:"aikz_toggle",id:$b.data("id"),nonce:"' . esc_js( wp_create_nonce( 'aikz_toggle' ) ) . '"})
					.done(function(r){
						if(!r||!r.success)return;
						var on=r.data.is_ai;
						$b.toggleClass("is-ai",on).attr("aria-pressed",on?"true":"false")
						  .html(on?\'<span class="dashicons dashicons-yes-alt"></span>\':\'<span class="dashicons dashicons-minus"></span>\');
					})
					.always(function(){$b.removeClass("is-busy");});
				});
			});'
		);
	}

	/* =====================================================================
	 * Frontend – Ausgabe
	 * ===================================================================== */

	public function frontend_assets() {
		wp_register_style( 'aikz', false, array(), self::VERSION );
		wp_enqueue_style( 'aikz' );
		wp_add_inline_style( 'aikz', $this->css() );

		$s = $this->settings();
		if ( 'nowrap' !== $s['layout'] ) {
			return;
		}

		$config = array(
			'mode'    => $s['mode'],
			'pos'     => $s['position'],
			'icon'    => $s['icon'] ? $this->icon_svg() : '',
			'caption' => in_array( $s['mode'], array( 'caption', 'both' ), true ),
			'overlay' => 'caption' !== $s['mode'],
		);

		wp_register_script( 'aikz-front', false, array(), self::VERSION, true );
		wp_enqueue_script( 'aikz-front' );
		wp_add_inline_script(
			'aikz-front',
			'(function(){var c=' . wp_json_encode( $config ) . ';
			function badge(t){var b=document.createElement("span");
				b.className="aikz-badge";b.setAttribute("role","note");
				if(c.icon){b.innerHTML=c.icon;}
				var s=document.createElement("span");s.className="aikz-badge__text";s.textContent=t;
				b.appendChild(s);return b;}
			function caption(t){var s=document.createElement("span");
				s.className="aikz-caption";s.textContent=t;return s;}
			function init(){
				var imgs=document.querySelectorAll("img[data-aikz-label]");
				for(var i=0;i<imgs.length;i++){
					var img=imgs[i];
					if(img.getAttribute("data-aikz-done"))continue;
					img.setAttribute("data-aikz-done","1");
					var t=img.getAttribute("data-aikz-label")||"";
					var host=img.parentElement;
					if(host&&host.tagName==="PICTURE"&&host.parentElement)host=host.parentElement;
					if(!host)continue;
					if(c.overlay){
						host.classList.add("aikz-host","aikz-mode-"+c.mode,"aikz-pos-"+c.pos);
						host.appendChild(badge(t));
					}
					if(c.caption){
						var cap=caption(t);
						if(img.nextSibling)img.parentNode.insertBefore(cap,img.nextSibling);
						else img.parentNode.appendChild(cap);
					}
				}
			}
			if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init);
			else init();
			document.addEventListener("aikz:refresh",init);
			})();'
		);
	}

	private function hex_to_rgba( $hex, $opacity ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			$hex = '000000';
		}
		return sprintf(
			'rgba(%d,%d,%d,%s)',
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
			number_format( max( 0, min( 100, (int) $opacity ) ) / 100, 2, '.', '' )
		);
	}

	public function css() {
		$s  = $this->settings();
		$bg = $this->hex_to_rgba( $s['bg'], $s['opacity'] );

		$css = '
		.aikz-wrap{position:relative;display:inline-block;max-width:100%;line-height:0;vertical-align:top}
		/* Bewusst KEINE Größenangaben am Bild – sonst überschreiben wir Theme-Layouts. */
		.aikz-layout-fill.aikz-wrap{display:block;width:100%;height:100%;max-width:none}
		.aikz-layout-fill.aikz-wrap>img{display:block;width:100%;height:100%;object-fit:cover}
		.aikz-host{position:relative}
		.aikz-badge{position:absolute;z-index:2;display:inline-flex;align-items:center;gap:.35em;
			padding:.32em .6em;margin:0;line-height:1.25;white-space:nowrap;max-width:calc(100% - 1em);
			overflow:hidden;text-overflow:ellipsis;pointer-events:none;text-decoration:none;
			font-family:inherit;font-weight:500;font-style:normal;letter-spacing:.01em;
			background:' . $bg . ';color:' . esc_attr( $s['color'] ) . ';
			font-size:' . (int) $s['font_size'] . 'px;border-radius:' . (int) $s['radius'] . 'px;
			-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}
		.aikz-badge__icon{flex:0 0 auto;width:1em;height:1em;display:block}
		.aikz-pos-top-left .aikz-badge{top:.5em;left:.5em}
		.aikz-pos-top-right .aikz-badge{top:.5em;right:.5em}
		.aikz-pos-bottom-left .aikz-badge{bottom:.5em;left:.5em}
		.aikz-pos-bottom-right .aikz-badge{bottom:.5em;right:.5em}
		.aikz-mode-hover .aikz-badge{opacity:0;transform:translateY(.25em);transition:opacity .15s ease,transform .15s ease}
		.aikz-mode-hover:hover .aikz-badge,
		.aikz-mode-hover:focus-within .aikz-badge{opacity:1;transform:none}
		@media (hover:none){.aikz-mode-hover .aikz-badge{opacity:1;transform:none}}
		@media (prefers-reduced-motion:reduce){.aikz-badge{transition:none}}
		.aikz-caption{display:block;max-width:100%;margin:.4em 0 0;line-height:1.4;color:inherit;opacity:.8;
			font-size:' . max( 10, (int) $s['font_size'] - 1 ) . 'px}
		/* Sichtbarkeit der Kennzeichnung gegen Theme-Overrides absichern. */
		.aikz-mode-always .aikz-badge,.aikz-mode-both .aikz-badge{
			opacity:1!important;visibility:visible!important;display:inline-flex!important}
		.aikz-caption{visibility:visible!important}
		@media print{
			.aikz-badge,.aikz-caption{opacity:1!important;visibility:visible!important;transform:none!important;
				-webkit-print-color-adjust:exact;print-color-adjust:exact}
		}
		';

		return preg_replace( '/\s*\n\s*/', ' ', trim( $css ) );
	}

	public function icon_svg() {
		return '<svg class="aikz-badge__icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">'
			. '<path d="M12 2l1.9 5.6L19.5 9.5 13.9 11.4 12 17l-1.9-5.6L4.5 9.5l5.6-1.9L12 2z"/>'
			. '<path d="M18.5 14l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9.9-2.6z" opacity=".7"/>'
			. '</svg>';
	}

	public function badge_html( $attachment_id ) {
		$s    = $this->settings();
		$text = $this->label_for( $attachment_id );

		$icon = ! empty( $s['icon'] ) ? $this->icon_svg() : '';

		$html = '<span class="aikz-badge" role="note">' . $icon . '<span class="aikz-badge__text">' . esc_html( $text ) . '</span></span>';

		/**
		 * Filter: HTML des KI-Hinweises.
		 */
		return apply_filters( 'aikz_badge_html', $html, (int) $attachment_id, $text );
	}

	public function caption_html( $attachment_id ) {
		$text = $this->label_for( $attachment_id );
		return '<span class="aikz-caption">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Fügt dem <img>-Tag die Marker-Attribute hinzu, ohne das Markup zu verändern.
	 */
	private function mark_img( $html, $attachment_id ) {
		if ( false !== stripos( $html, 'data-aikz-label' ) ) {
			return $html;
		}
		$add = sprintf( ' data-aikz-label="%s"', esc_attr( $this->label_for( $attachment_id ) ) );
		if ( false === stripos( $html, 'data-ai-generated' ) ) {
			$add .= ' data-ai-generated="true"';
		}
		return preg_replace( '#<img\b#i', '<img' . $add, $html, 1 );
	}

	private function wrap( $html, $attachment_id ) {
		if ( false !== strpos( $html, 'aikz-wrap' ) ) {
			return $html; // Bereits gekennzeichnet.
		}
		$s    = $this->settings();
		$mode = $s['mode'];

		// Ohne Wrapper: Bild bleibt exakt an seiner Stelle im DOM, das Badge
		// wird per JS an das Elternelement gehängt. Kein Layout-Eingriff.
		if ( 'nowrap' === $s['layout'] ) {
			return $this->mark_img( $html, $attachment_id );
		}

		$classes = sprintf(
			'aikz-wrap aikz-layout-%s aikz-mode-%s aikz-pos-%s',
			$s['layout'],
			$mode,
			$s['position']
		);

		$overlay = ( 'caption' === $mode ) ? '' : $this->badge_html( $attachment_id );
		$caption = in_array( $mode, array( 'caption', 'both' ), true ) ? $this->caption_html( $attachment_id ) : '';

		return '<span class="' . esc_attr( $classes ) . '" data-ai-generated="true">'
			. $html . $overlay . $caption
			. '</span>';
	}

	private function skip_output() {
		return is_admin() && ! wp_doing_ajax() ? true : ( is_feed() || is_embed() );
	}

	public function filter_attachment_image( $html, $attachment_id, $size = null, $icon = false, $attr = array() ) {
		if ( $this->skip_output() || empty( $this->settings()['apply_template'] ) ) {
			return $html;
		}
		if ( ! $this->is_ai( $attachment_id ) ) {
			return $html;
		}
		return $this->wrap( $html, (int) $attachment_id );
	}

	public function filter_content( $content ) {
		if ( empty( $this->settings()['apply_content'] ) ) {
			return $content;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $content;
		}
		if ( '' === $content || false === strpos( $content, 'wp-image-' ) ) {
			return $content;
		}

		$is_feed = is_feed();

		return preg_replace_callback(
			'#<img\b[^>]*?wp-image-(\d+)[^>]*>#i',
			function ( $m ) use ( $is_feed ) {
				$id = (int) $m[1];
				if ( ! $id || ! $this->is_ai( $id ) ) {
					return $m[0];
				}

				// In Feeds gibt es kein CSS – Hinweis direkt als Text hinter das Bild.
				if ( $is_feed ) {
					return $m[0] . ' <span>(' . esc_html( $this->label_for( $id ) ) . ')</span>';
				}

				$img = $m[0];
				if ( false === stripos( $img, 'data-ai-generated' ) ) {
					$img = preg_replace( '#<img\b#i', '<img data-ai-generated="true"', $img, 1 );
				}

				return $this->wrap( $img, $id );
			},
			$content
		);
	}

	public function filter_image_attributes( $attr, $attachment ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $attr;
		}
		if ( ! $attachment || ! $this->is_ai( $attachment->ID ) ) {
			return $attr;
		}

		// Maschinenlesbarer Marker – unabhängig von der optischen Darstellung.
		$attr['data-ai-generated'] = 'true';

		if ( empty( $this->settings()['alt_suffix'] ) ) {
			return $attr;
		}

		$label = $this->label_for( $attachment->ID );
		$alt   = isset( $attr['alt'] ) ? trim( $attr['alt'] ) : '';

		if ( '' !== $alt && false !== stripos( $alt, $label ) ) {
			return $attr;
		}
		$attr['alt'] = '' === $alt ? $label : $alt . ' (' . $label . ')';

		return $attr;
	}

	/* =====================================================================
	 * Deinstallation
	 * ===================================================================== */

	public static function uninstall() {
		delete_option( self::OPTION );
		delete_option( 'aikz_version' );
		delete_transient( 'aikz_upgrade_notice' );
		// Die Meta-Kennzeichnungen an den Medien bleiben bewusst erhalten.
	}
}

AIKZ_Plugin::instance();

register_uninstall_hook( __FILE__, array( 'AIKZ_Plugin', 'uninstall' ) );

/**
 * Template-Helfer: gibt den Hinweis-Badge für ein Medium zurück (oder '').
 *
 * @param int $attachment_id Anhang-ID.
 * @return string
 */
function aikz_badge( $attachment_id ) {
	$p = AIKZ_Plugin::instance();
	return $p->is_ai( $attachment_id ) ? $p->badge_html( $attachment_id ) : '';
}
